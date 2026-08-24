<?php
/**
 * Buffer delivery provider tests.
 *
 * @package PressQueue\Tests\Unit
 */

declare(strict_types=1);

namespace PressQueue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PressQueue\Delivery\RetryPolicy;
use PressQueue\Provider\Buffer\BufferDeliveryProvider;
use PressQueue\Provider\Buffer\BufferPostEncoder;
use PressQueue\Provider\Dto\ProviderDeliveryRequest;
use PressQueue\Provider\Dto\ProviderDeliveryResult;
use PressQueue\Storage\Record\DeliveryRecord;

/**
 * @covers \PressQueue\Delivery\RetryPolicy
 * @covers \PressQueue\Provider\Buffer\BufferDeliveryProvider
 * @covers \PressQueue\Provider\Buffer\BufferPostEncoder
 * @covers \PressQueue\Provider\Dto\ProviderDeliveryRequest
 * @covers \PressQueue\Provider\Dto\ProviderDeliveryResult
 * @covers \PressQueue\Provider\Dto\ProviderReceipt
 */
final class BufferDeliveryProviderTest extends TestCase {
	public function test_encoder_is_byte_stable_and_appends_canonical_url(): void {
		$request = $this->request();
		$encoder = new BufferPostEncoder();

		$first  = $encoder->body( $request );
		$second = $encoder->body( $request );
		$body   = json_decode( $first, true, 512, JSON_THROW_ON_ERROR );

		self::assertSame( $first, $second );
		self::assertSame( "Hello world\n\nhttps://example.test/post", $body['variables']['input']['text'] );
		self::assertSame( 'shareNow', $body['variables']['input']['mode'] );
		self::assertSame( 'automatic', $body['variables']['input']['schedulingType'] );
		self::assertArrayNotHasKey( 'metadata', $body['variables']['input'] );
	}

	public function test_accepted_response_stores_sanitized_receipt_and_redacted_warnings(): void {
		$requests = array();
		$provider = new BufferDeliveryProvider(
			null,
			static function ( string $url, array $args ) use ( &$requests ): array {
				$requests[] = array( $url, $args );

				return self::jsonResponse(
					array(
						'data'   => array(
							'createPost' => array(
								'__typename' => 'PostActionSuccess',
								'post'       => array(
									'dueAt'          => '2026-08-24T12:00:00Z',
									'id'             => 'remote-post-1',
									'schedulingType' => 'automatic',
									'shareMode'      => 'shareNow',
									'status'         => 'accepted',
									'text'           => 'Hello world',
								),
							),
						),
						'errors' => array(
							array(
								'message'    => 'contains fixture credential',
								'extensions' => array( 'code' => 'WARN_ONLY' ),
							),
						),
					)
				);
			}
		);

		$result = $provider->submit( $this->request(), 'fixture credential' );
		$receipt = $result->receipt ? $result->receipt->document() : array();

		self::assertSame( 'accepted', $result->provider_result );
		self::assertSame( 'remote-post-1', $result->remote_id );
		self::assertSame( array( 'WARN_ONLY' ), $result->provider_warnings );
		self::assertSame( array( 'WARN_ONLY' ), $receipt['warnings'] );
		self::assertSame( 'remote-post-1', $receipt['remote_id'] );
		self::assertArrayHasKey( 'returned_text_hash', $receipt );
		self::assertStringNotContainsString( 'fixture credential', json_encode( $receipt, JSON_THROW_ON_ERROR ) );
		self::assertSame( 'https://api.buffer.com', $requests[0][0] );
		self::assertSame( 0, $requests[0][1]['redirection'] );
		self::assertTrue( $requests[0][1]['sslverify'] );
		self::assertSame( 10, $requests[0][1]['timeout'] );
	}

	public function test_malformed_submitted_response_is_ambiguous_and_rate_limit_retry_after_is_parsed(): void {
		$ambiguous = new BufferDeliveryProvider(
			null,
			static function (): array {
				return array(
					'body'                                        => '{bad json',
					'pressqueue_request_may_have_reached_provider' => true,
					'response'                                    => array( 'code' => 200 ),
				);
			}
		);

		$ambiguous_result = $ambiguous->submit( $this->request(), 'fixture credential' );
		self::assertSame( 'ambiguous', $ambiguous_result->provider_result );
		self::assertSame( 'reconcile_first', $ambiguous_result->retry_safety );

		$limited = new BufferDeliveryProvider(
			null,
			static function (): array {
				return array(
					'body'     => '{}',
					'headers'  => array( 'Retry-After' => '120' ),
					'response' => array( 'code' => 429 ),
				);
			}
		);

		$limited_result = $limited->submit( $this->request(), 'fixture credential' );
		self::assertSame( 'rate_limited', $limited_result->provider_result );
		self::assertNotNull( $limited_result->retry_after_utc );
	}

	public function test_wordpress_transport_error_after_post_is_ambiguous(): void {
		$GLOBALS['pressqueue_test_http_post_response'] = new \WP_Error( 'http_request_failed', 'Operation timed out after sending request bytes.' );

		try {
			$result = ( new BufferDeliveryProvider() )->submit( $this->request(), 'fixture credential' );
		} finally {
			$GLOBALS['pressqueue_test_http_post_response'] = null;
		}

		self::assertSame( 'ambiguous', $result->provider_result );
		self::assertSame( 'reconcile_first', $result->retry_safety );
		self::assertSame( 'transport_indeterminate', $result->error_class );
		self::assertTrue( $result->request_started );
		self::assertTrue( $result->request_may_have_reached_provider );
	}

	public function test_retry_policy_caps_automatic_attempts_and_jitter(): void {
		$delivery = $this->deliveryWithAutomaticAttempts( 1 );
		$request  = $this->request();
		$result   = ProviderDeliveryResult::failure(
			$request,
			'retryable_failure',
			'automatic_allowed',
			'unexpected_provider_failure',
			array( 'schema_version' => 1 ),
			null,
			true,
			false
		);
		$policy   = new RetryPolicy(
			static function (): int {
				return 999999;
			}
		);

		$next = $policy->next_attempt_at( $delivery, $result );

		self::assertNotNull( $next );
		self::assertTrue( $policy->has_automatic_budget( $delivery ) );
		self::assertFalse( $policy->has_automatic_budget( $this->deliveryWithAutomaticAttempts( 5 ) ) );
	}

	private function request(): ProviderDeliveryRequest {
		return new ProviderDeliveryRequest(
			'buffer',
			'01K8Z8D0PKH2N8J7S3K5A9C6DE',
			str_repeat( 'a', 64 ),
			1,
			'01K8Z8D0PKH2N8J7S3K5A9C6DF',
			'acct_1',
			'org_1',
			'chan_1',
			1,
			1,
			'Hello world',
			'https://example.test/post'
		);
	}

	private function deliveryWithAutomaticAttempts( int $automatic_attempts ): DeliveryRecord {
		return new DeliveryRecord(
			1,
			'01K8Z8D0PKH2N8J7S3K5A9C6DE',
			1,
			'01K8Z8D0PKH2N8J7S3K5A9C6DG',
			str_repeat( 'b', 64 ),
			str_repeat( 'a', 64 ),
			'live',
			'buffer',
			1,
			'01K8Z8D0PKH2N8J7S3K5A9C6DF',
			1,
			'01K8Z8D0PKH2N8J7S3K5A9C6DH',
			'chan_1',
			'01K8Z8D0PKH2N8J7S3K5A9C6DJ',
			1,
			'leased',
			'immediate',
			array(
				'canonical_url' => 'https://example.test/post',
				'message'       => 'Hello world',
			),
			str_repeat( 'c', 64 ),
			new \DateTimeImmutable( '2026-08-24 12:00:00', new \DateTimeZone( 'UTC' ) ),
			new \DateTimeImmutable( '2026-08-24 12:00:00', new \DateTimeZone( 'UTC' ) ),
			'buffer',
			'01K8Z8D0PKH2N8J7S3K5A9C6DF',
			'01K8Z8D0PKH2N8J7S3K5A9C6DH',
			'acct_1',
			'org_1',
			'chan_1',
			1,
			null,
			null,
			$automatic_attempts,
			$automatic_attempts
		);
	}

	/**
	 * Build a fake JSON response.
	 *
	 * @param array<string,mixed> $body Body.
	 * @return array<string,mixed>
	 */
	private static function jsonResponse( array $body ): array {
		return array(
			'body'     => json_encode( $body, JSON_THROW_ON_ERROR ),
			'response' => array( 'code' => 200 ),
		);
	}
}
