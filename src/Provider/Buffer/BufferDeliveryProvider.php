<?php
/**
 * Buffer delivery adapter.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Provider\Buffer;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PressQueue\Provider\DeliveryProvider;
use PressQueue\Provider\Dto\ProviderDeliveryRequest;
use PressQueue\Provider\Dto\ProviderDeliveryResult;
use PressQueue\Provider\Dto\ProviderReceipt;
use PressQueue\Provider\ProviderException;

/**
 * Submits scheduled or immediate Buffer deliveries with supported media.
 */
final class BufferDeliveryProvider implements DeliveryProvider {
	private const ENDPOINT           = 'https://api.buffer.com';
	private const TIMEOUT_SECONDS    = 10;
	private const MAX_RESPONSE_BYTES = 1048576;

	/**
	 * Encoder.
	 *
	 * @var BufferPostEncoder
	 */
	private BufferPostEncoder $encoder;

	/**
	 * Optional HTTP transport.
	 *
	 * @var callable|null
	 */
	private $transport;

	/**
	 * Constructor.
	 *
	 * @param BufferPostEncoder|null $encoder Encoder.
	 * @param callable|null          $transport Test transport accepting URL and args.
	 */
	public function __construct( ?BufferPostEncoder $encoder = null, ?callable $transport = null ) {
		$this->encoder   = $encoder ?? new BufferPostEncoder();
		$this->transport = $transport;
	}

	/**
	 * Submit one provider delivery.
	 *
	 * @param ProviderDeliveryRequest $request Delivery request.
	 * @param string                  $credential Opaque provider credential.
	 * @return ProviderDeliveryResult
	 */
	public function submit( ProviderDeliveryRequest $request, string $credential ): ProviderDeliveryResult {
		$body = $this->encoder->body( $request );
		$args = array(
			'body'                => $body,
			'headers'             => array(
				'Authorization' => 'Bearer ' . $credential,
				'Content-Type'  => 'application/json',
			),
			'limit_response_size' => self::MAX_RESPONSE_BYTES + 1,
			'redirection'         => 0,
			'sslverify'           => true,
			'timeout'             => self::TIMEOUT_SECONDS,
		);

		try {
			$response = $this->send( self::ENDPOINT, $args );
		} catch ( ProviderException $exception ) {
			unset( $credential, $args, $body );

			return $this->transport_failure(
				$request,
				$exception->classification(),
				$this->transport_exception_may_have_reached_provider( $exception )
			);
		}

		unset( $credential, $args, $body );

		$may_have_reached_provider = $this->may_have_reached_provider( $response );
		$status_code               = $this->response_code( $response );
		$retry_after               = $this->retry_after_from_headers( $response );

		if ( $status_code >= 300 && $status_code < 400 ) {
			return $this->failure( $request, 'permanent_failure', 'not_retryable', 'redirect_refused', $status_code, null, true, $may_have_reached_provider );
		}

		if ( 401 === $status_code ) {
			return $this->failure( $request, 'permanent_failure', 'not_retryable', 'invalid_credential', $status_code, null, true, true );
		}

		if ( 403 === $status_code ) {
			return $this->failure( $request, 'permanent_failure', 'not_retryable', 'forbidden', $status_code, null, true, true );
		}

		if ( 404 === $status_code ) {
			return $this->failure( $request, 'permanent_failure', 'manual_allowed', 'not_found', $status_code, null, true, true );
		}

		if ( 429 === $status_code ) {
			return $this->failure( $request, 'rate_limited', 'automatic_allowed', 'rate_limited', $status_code, $retry_after, true, true );
		}

		if ( $status_code >= 500 ) {
			return $this->failure( $request, 'retryable_failure', 'automatic_allowed', 'unexpected_provider_failure', $status_code, null, true, true );
		}

		if ( $status_code < 200 || $status_code >= 300 ) {
			return $this->failure( $request, 'permanent_failure', 'not_retryable', 'unexpected_http_status', $status_code, null, true, $may_have_reached_provider );
		}

		$raw_body = $this->response_body( $response );
		if ( strlen( $raw_body ) > self::MAX_RESPONSE_BYTES ) {
			return $this->ambiguous_or_retryable( $request, 'oversized_response', true, $may_have_reached_provider );
		}

		try {
			$decoded = $this->decode_response( $raw_body );
		} catch ( ProviderException $exception ) {
			unset( $exception, $raw_body );

			return $this->ambiguous_or_retryable( $request, 'malformed_response', true, $may_have_reached_provider );
		}

		unset( $raw_body );

		$success = $this->success_payload( $decoded );
		if ( null !== $success ) {
			$warnings                       = $this->warnings_from_errors( $decoded['errors'] ?? null );
			$success['receipt']['warnings'] = $warnings;

			return ProviderDeliveryResult::accepted(
				$request,
				$success['remote_id'],
				new ProviderReceipt( $success['receipt'] ),
				$warnings
			);
		}

		$error_code = $this->top_level_error_code( $decoded['errors'] ?? null );
		if ( null !== $error_code ) {
			return $this->classify_error_code( $request, $error_code, $retry_after );
		}

		$mutation = isset( $decoded['data']['createPost'] ) && is_array( $decoded['data']['createPost'] ) ? $decoded['data']['createPost'] : null;
		if ( null === $mutation || ! isset( $mutation['__typename'] ) || ! is_string( $mutation['__typename'] ) ) {
			return $this->ambiguous_or_retryable( $request, 'missing_mutation_payload', true, true );
		}

		return $this->classify_typename( $request, $mutation['__typename'], $retry_after );
	}

	/**
	 * Send one request.
	 *
	 * @param string              $url URL.
	 * @param array<string,mixed> $args Request arguments.
	 * @return mixed
	 */
	private function send( string $url, array $args ) {
		if ( null !== $this->transport ) {
			return call_user_func( $this->transport, $url, $args );
		}

		if ( ! function_exists( 'wp_remote_post' ) ) {
			throw new ProviderException( 'network_failure', 'WordPress HTTP transport is unavailable.' );
		}

		$response = wp_remote_post( $url, $args );

		if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
			throw new ProviderException( 'transport_indeterminate', 'Provider request ended with an indeterminate transport error.' );
		}

		return $response;
	}

	/**
	 * Check whether a transport exception may have reached the provider.
	 *
	 * @param ProviderException $exception Exception.
	 * @return bool
	 */
	private function transport_exception_may_have_reached_provider( ProviderException $exception ): bool {
		return in_array(
			$exception->classification(),
			array(
				'connection_lost_after_request',
				'network_timeout_after_request',
				'transport_indeterminate',
			),
			true
		);
	}

	/**
	 * Return response status code.
	 *
	 * @param mixed $response Response.
	 * @return int
	 */
	private function response_code( $response ): int {
		if ( function_exists( 'wp_remote_retrieve_response_code' ) ) {
			return (int) wp_remote_retrieve_response_code( $response );
		}

		if ( is_array( $response ) && isset( $response['response']['code'] ) ) {
			return (int) $response['response']['code'];
		}

		return 0;
	}

	/**
	 * Return response body.
	 *
	 * @param mixed $response Response.
	 * @return string
	 */
	private function response_body( $response ): string {
		if ( function_exists( 'wp_remote_retrieve_body' ) ) {
			return (string) wp_remote_retrieve_body( $response );
		}

		return is_array( $response ) && isset( $response['body'] ) ? (string) $response['body'] : '';
	}

	/**
	 * Check fake transport marker for ambiguous transport state.
	 *
	 * @param mixed $response Response.
	 * @return bool
	 */
	private function may_have_reached_provider( $response ): bool {
		return is_array( $response ) && ! empty( $response['pressqueue_request_may_have_reached_provider'] );
	}

	/**
	 * Decode a response object.
	 *
	 * @param string $json JSON.
	 * @return array<string,mixed>
	 */
	private function decode_response( string $json ): array {
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_decode_json_decode -- Provider responses need JSON_THROW_ON_ERROR.
			$value = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException $exception ) {
			unset( $exception );

			throw new ProviderException( 'malformed_response', 'Provider response was not valid JSON.' );
		}

		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			throw new ProviderException( 'malformed_response', 'Provider response was not a JSON object.' );
		}

		return $value;
	}

	/**
	 * Return success payload when present and complete.
	 *
	 * @param array<string,mixed> $decoded Decoded response.
	 * @return array{remote_id:string,receipt:array<string,mixed>}|null
	 */
	private function success_payload( array $decoded ): ?array {
		$mutation = $decoded['data']['createPost'] ?? null;
		if ( ! is_array( $mutation ) || ( $mutation['__typename'] ?? null ) !== 'PostActionSuccess' ) {
			return null;
		}

		$post      = $mutation['post'] ?? null;
		$remote_id = is_array( $post ) && isset( $post['id'] ) && is_string( $post['id'] ) && '' !== $post['id'] ? $post['id'] : null;

		if ( null === $remote_id ) {
			return null;
		}

		$text = isset( $post['text'] ) && is_string( $post['text'] ) ? $post['text'] : '';

		return array(
			'remote_id' => $remote_id,
			'receipt'   => array(
				'due_at'             => isset( $post['dueAt'] ) && is_string( $post['dueAt'] ) ? $post['dueAt'] : null,
				'provider'           => 'buffer',
				'remote_id'          => $remote_id,
				'returned_text_hash' => hash( 'sha256', $text ),
				'schema_version'     => 1,
				'scheduling_type'    => isset( $post['schedulingType'] ) && is_string( $post['schedulingType'] ) ? $post['schedulingType'] : null,
				'share_mode'         => isset( $post['shareMode'] ) && is_string( $post['shareMode'] ) ? $post['shareMode'] : null,
				'status'             => isset( $post['status'] ) && is_string( $post['status'] ) ? $post['status'] : null,
			),
		);
	}

	/**
	 * Classify a top-level GraphQL error code.
	 *
	 * @param ProviderDeliveryRequest $request Request.
	 * @param string                  $code Error code.
	 * @param DateTimeImmutable|null  $retry_after Retry-after timestamp.
	 * @return ProviderDeliveryResult
	 */
	private function classify_error_code( ProviderDeliveryRequest $request, string $code, ?DateTimeImmutable $retry_after ): ProviderDeliveryResult {
		$code = strtoupper( $code );

		if ( in_array( $code, array( 'AUTHENTICATION_ERROR', 'UNAUTHORIZED' ), true ) ) {
			return $this->failure( $request, 'permanent_failure', 'not_retryable', 'invalid_credential', null, null, true, true );
		}

		if ( 'FORBIDDEN' === $code ) {
			return $this->failure( $request, 'permanent_failure', 'not_retryable', 'forbidden', null, null, true, true );
		}

		if ( 'NOT_FOUND' === $code ) {
			return $this->failure( $request, 'permanent_failure', 'manual_allowed', 'not_found', null, null, true, true );
		}

		if ( 'RATE_LIMIT_EXCEEDED' === $code ) {
			return $this->failure( $request, 'rate_limited', 'automatic_allowed', 'rate_limited', null, $retry_after, true, true );
		}

		if ( 'UNEXPECTED' === $code ) {
			return $this->failure( $request, 'retryable_failure', 'automatic_allowed', 'unexpected_provider_failure', null, null, true, true );
		}

		return $this->ambiguous_or_retryable( $request, 'unknown_graphql_error', true, true );
	}

	/**
	 * Classify a typed mutation result.
	 *
	 * @param ProviderDeliveryRequest $request Request.
	 * @param string                  $typename Type name.
	 * @param DateTimeImmutable|null  $retry_after Retry-after timestamp.
	 * @return ProviderDeliveryResult
	 */
	private function classify_typename( ProviderDeliveryRequest $request, string $typename, ?DateTimeImmutable $retry_after ): ProviderDeliveryResult {
		if ( 'InvalidInputError' === $typename ) {
			return $this->failure( $request, 'permanent_failure', 'not_retryable', 'invalid_input', null, null, true, true );
		}

		if ( 'LimitReachedError' === $typename ) {
			return $this->failure( $request, 'rate_limited', 'automatic_allowed', 'rate_limited', null, $retry_after, true, true );
		}

		if ( 'UnauthorizedError' === $typename ) {
			return $this->failure( $request, 'permanent_failure', 'not_retryable', 'invalid_credential', null, null, true, true );
		}

		if ( 'NotFoundError' === $typename ) {
			return $this->failure( $request, 'permanent_failure', 'manual_allowed', 'not_found', null, null, true, true );
		}

		if ( in_array( $typename, array( 'UnexpectedError', 'RestProxyError' ), true ) ) {
			return $this->failure( $request, 'retryable_failure', 'automatic_allowed', 'unexpected_provider_failure', null, null, true, true );
		}

		if ( 'MutationError' === $typename ) {
			return $this->failure( $request, 'permanent_failure', 'not_retryable', 'mutation_error', null, null, true, true );
		}

		return $this->ambiguous_or_retryable( $request, 'unknown_mutation_result', true, true );
	}

	/**
	 * Return the first top-level GraphQL error code.
	 *
	 * @param mixed $errors GraphQL errors.
	 * @return string|null
	 */
	private function top_level_error_code( $errors ): ?string {
		if ( ! is_array( $errors ) || array() === $errors ) {
			return null;
		}

		$first = reset( $errors );

		return is_array( $first ) && isset( $first['extensions']['code'] ) && is_string( $first['extensions']['code'] )
			? $first['extensions']['code']
			: null;
	}

	/**
	 * Build redacted warnings from top-level GraphQL errors.
	 *
	 * @param mixed $errors GraphQL errors.
	 * @return array<int,string>
	 */
	private function warnings_from_errors( $errors ): array {
		if ( ! is_array( $errors ) ) {
			return array();
		}

		$warnings = array();
		foreach ( $errors as $error ) {
			if ( is_array( $error ) && isset( $error['extensions']['code'] ) && is_string( $error['extensions']['code'] ) ) {
				$warnings[] = strtoupper( $error['extensions']['code'] );
			}
		}

		return array_values( array_unique( $warnings ) );
	}

	/**
	 * Parse retry-after headers.
	 *
	 * @param mixed $response Response.
	 * @return DateTimeImmutable|null
	 */
	private function retry_after_from_headers( $response ): ?DateTimeImmutable {
		$headers = $this->response_headers( $response );

		foreach ( array( 'retry-after', 'ratelimit-reset', 'x-ratelimit-reset' ) as $header ) {
			if ( ! isset( $headers[ $header ] ) ) {
				continue;
			}

			$parsed = $this->parse_retry_after_value( $headers[ $header ] );
			if ( null !== $parsed ) {
				return $parsed;
			}
		}

		if ( isset( $headers['ratelimit'] ) && preg_match( '/reset="?([^",;]+)"?/i', $headers['ratelimit'], $matches ) ) {
			return $this->parse_retry_after_value( $matches[1] );
		}

		return null;
	}

	/**
	 * Return normalized response headers.
	 *
	 * @param mixed $response Response.
	 * @return array<string,string>
	 */
	private function response_headers( $response ): array {
		$headers = array();
		$raw     = is_array( $response ) && isset( $response['headers'] ) && is_array( $response['headers'] ) ? $response['headers'] : array();

		foreach ( $raw as $name => $value ) {
			if ( is_scalar( $value ) ) {
				$headers[ strtolower( (string) $name ) ] = (string) $value;
			}
		}

		return $headers;
	}

	/**
	 * Parse retry-after value as seconds, unix timestamp, or HTTP date.
	 *
	 * @param string $value Header value.
	 * @return DateTimeImmutable|null
	 */
	private function parse_retry_after_value( string $value ): ?DateTimeImmutable {
		$value = trim( $value );
		$now   = time();

		if ( ctype_digit( $value ) ) {
			$number    = (int) $value;
			$timestamp = $number > $now ? $number : $now + $number;

			return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( new DateTimeZone( 'UTC' ) );
		}

		$timestamp = strtotime( $value );

		return false === $timestamp ? null : ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Build a normalized failure result.
	 *
	 * @param ProviderDeliveryRequest $request Request.
	 * @param string                  $provider_result Result.
	 * @param string                  $retry_safety Retry safety.
	 * @param string                  $error_class Error class.
	 * @param int|null                $http_status HTTP status.
	 * @param DateTimeImmutable|null  $retry_after Retry-after timestamp.
	 * @param bool                    $request_started Request started.
	 * @param bool                    $may_have_reached_provider May have reached provider.
	 * @return ProviderDeliveryResult
	 */
	private function failure( ProviderDeliveryRequest $request, string $provider_result, string $retry_safety, string $error_class, ?int $http_status, ?DateTimeImmutable $retry_after, bool $request_started, bool $may_have_reached_provider ): ProviderDeliveryResult {
		$detail = array(
			'classification' => $error_class,
			'http_status'    => $http_status,
			'schema_version' => 1,
		);

		return ProviderDeliveryResult::failure( $request, $provider_result, $retry_safety, $error_class, $detail, $retry_after, $request_started, $may_have_reached_provider );
	}

	/**
	 * Classify malformed responses by provider reachability.
	 *
	 * @param ProviderDeliveryRequest $request Request.
	 * @param string                  $error_class Error class.
	 * @param bool                    $request_started Request started.
	 * @param bool                    $may_have_reached_provider May have reached provider.
	 * @return ProviderDeliveryResult
	 */
	private function ambiguous_or_retryable( ProviderDeliveryRequest $request, string $error_class, bool $request_started, bool $may_have_reached_provider ): ProviderDeliveryResult {
		return $this->failure(
			$request,
			$may_have_reached_provider ? 'ambiguous' : 'retryable_failure',
			$may_have_reached_provider ? 'reconcile_first' : 'automatic_allowed',
			$error_class,
			null,
			null,
			$request_started,
			$may_have_reached_provider
		);
	}

	/**
	 * Classify transport failures before a provider response.
	 *
	 * @param ProviderDeliveryRequest $request Request.
	 * @param string                  $classification Failure class.
	 * @param bool                    $may_have_reached_provider May have reached provider.
	 * @return ProviderDeliveryResult
	 */
	private function transport_failure( ProviderDeliveryRequest $request, string $classification, bool $may_have_reached_provider ): ProviderDeliveryResult {
		return $this->ambiguous_or_retryable( $request, $classification, true, $may_have_reached_provider );
	}
}
