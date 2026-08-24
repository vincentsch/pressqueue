<?php
/**
 * Buffer GraphQL HTTP client.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Provider\Buffer;

use JsonException;
use PressQueue\Provider\ProviderException;

/**
 * Executes redacted Buffer GraphQL read requests.
 */
final class BufferGraphqlClient {
	private const ENDPOINT           = 'https://api.buffer.com';
	private const TIMEOUT_SECONDS    = 10;
	private const MAX_RESPONSE_BYTES = 1048576;

	/**
	 * Optional HTTP transport.
	 *
	 * @var callable|null
	 */
	private $transport;

	/**
	 * Constructor.
	 *
	 * @param callable|null $transport Test transport accepting URL and args.
	 */
	public function __construct( ?callable $transport = null ) {
		$this->transport = $transport;
	}

	/**
	 * Execute a GraphQL request and return the data object.
	 *
	 * @param string              $operation_name Operation name.
	 * @param string              $query GraphQL document.
	 * @param array<string,mixed> $variables GraphQL variables.
	 * @param string              $api_key API key.
	 * @return array<string,mixed>
	 */
	public function request( string $operation_name, string $query, array $variables, string $api_key ): array {
		$body = $this->json_encode(
			array(
				'operationName' => $operation_name,
				'query'         => $query,
				'variables'     => $variables,
			)
		);

		$args = array(
			'body'                => $body,
			'headers'             => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'limit_response_size' => self::MAX_RESPONSE_BYTES + 1,
			'redirection'         => 0,
			'sslverify'           => true,
			'timeout'             => self::TIMEOUT_SECONDS,
		);

		$response = $this->send( self::ENDPOINT, $args );
		unset( $api_key, $args );

		$status_code = $this->response_code( $response );
		if ( $status_code >= 300 && $status_code < 400 ) {
			throw new ProviderException( 'unexpected_provider_failure', 'Provider redirects are refused.' );
		}

		if ( 401 === $status_code ) {
			throw new ProviderException( 'invalid_credential', 'Provider rejected the credential.' );
		}

		if ( 403 === $status_code ) {
			throw new ProviderException( 'forbidden', 'Provider denied access to the requested resource.' );
		}

		if ( 404 === $status_code ) {
			throw new ProviderException( 'not_found', 'Provider resource was not found.' );
		}

		if ( 429 === $status_code ) {
			throw new ProviderException( 'rate_limited', 'Provider rate limit was reached.' );
		}

		if ( $status_code < 200 || $status_code >= 300 ) {
			throw new ProviderException( 'unexpected_provider_failure', 'Provider returned an unexpected HTTP status.' );
		}

		$raw_body = $this->response_body( $response );
		if ( strlen( $raw_body ) > self::MAX_RESPONSE_BYTES ) {
			throw new ProviderException( 'malformed_response', 'Provider response exceeded the allowed size.' );
		}

		$decoded = $this->json_decode( $raw_body );
		unset( $raw_body );

		if ( isset( $decoded['errors'] ) ) {
			$this->throw_graphql_error( $decoded['errors'] );
		}

		if ( ! isset( $decoded['data'] ) || ! is_array( $decoded['data'] ) || array_is_list( $decoded['data'] ) ) {
			throw new ProviderException( 'malformed_response', 'Provider response did not contain a data object.' );
		}

		return $decoded['data'];
	}

	/**
	 * Send one request.
	 *
	 * @param string              $url URL.
	 * @param array<string,mixed> $args Request args.
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
			throw new ProviderException( 'network_failure', 'Provider request failed before a response was available.' );
		}

		return $response;
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

		if ( is_array( $response ) && isset( $response['body'] ) ) {
			return (string) $response['body'];
		}

		throw new ProviderException( 'network_failure', 'Provider response body is unavailable.' );
	}

	/**
	 * Encode a JSON request.
	 *
	 * @param array<string,mixed> $value Value.
	 * @return string
	 */
	private function json_encode( array $value ): string {
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- GraphQL requests need JSON_THROW_ON_ERROR.
			return json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
		} catch ( JsonException $exception ) {
			unset( $exception );

			throw new ProviderException( 'unexpected_provider_failure', 'Provider request could not be encoded.' );
		}
	}

	/**
	 * Decode a JSON response.
	 *
	 * @param string $json Raw response.
	 * @return array<string,mixed>
	 */
	private function json_decode( string $json ): array {
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
	 * Classify GraphQL errors.
	 *
	 * @param mixed $errors GraphQL errors.
	 */
	private function throw_graphql_error( $errors ): void {
		if ( ! is_array( $errors ) || array() === $errors ) {
			throw new ProviderException( 'malformed_response', 'Provider response contained malformed GraphQL errors.' );
		}

		$first = reset( $errors );
		$code  = is_array( $first ) && isset( $first['extensions']['code'] ) && is_string( $first['extensions']['code'] )
			? strtoupper( $first['extensions']['code'] )
			: '';

		$classification = array(
			'AUTHENTICATION_ERROR' => 'invalid_credential',
			'FORBIDDEN'            => 'forbidden',
			'NOT_FOUND'            => 'not_found',
			'RATE_LIMIT_EXCEEDED'  => 'rate_limited',
			'UNAUTHORIZED'         => 'invalid_credential',
		)[ $code ] ?? 'unexpected_provider_failure';

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Classification is a fixed internal value and not rendered here.
		throw new ProviderException( $classification, 'Provider returned a redacted GraphQL error.' );
	}
}
