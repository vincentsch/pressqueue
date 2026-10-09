<?php
/**
 * Buffer discovery provider tests.
 *
 * @package PressQueue\Tests\Unit
 */

declare(strict_types=1);

namespace PressQueue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PressQueue\Provider\Buffer\BufferDiscoveryProvider;
use PressQueue\Provider\Buffer\BufferGraphqlClient;
use PressQueue\Provider\ProviderException;

/**
 * @covers \PressQueue\Provider\Buffer\BufferDiscoveryProvider
 * @covers \PressQueue\Provider\Buffer\BufferCapabilityCatalog
 * @covers \PressQueue\Provider\Buffer\BufferGraphqlClient
 * @covers \PressQueue\Provider\DiscoveryProvider
 * @covers \PressQueue\Provider\Dto\ProviderAccount
 * @covers \PressQueue\Provider\Dto\ProviderChannel
 * @covers \PressQueue\Provider\Dto\ProviderOrganization
 * @covers \PressQueue\Provider\ProviderException
 */
final class BufferDiscoveryProviderTest extends TestCase {
	public function test_discovers_account_and_sends_redacted_post_request_shape(): void {
		$requests = array();
		$provider = $this->provider(
			static function ( string $url, array $args ) use ( &$requests ): array {
				$requests[] = array( $url, $args );

				return self::jsonResponse(
					array(
						'data' => array(
							'account' => array(
								'id'            => 'acct_1',
								'email'         => 'owner@example.test',
								'name'          => 'Owner',
								'organizations' => array(
									array(
										'id'           => 'org_1',
										'name'         => 'Editorial',
										'channelCount' => 2,
									),
								),
							),
						),
					)
				);
			}
		);

		$account = $provider->account( 'fixture credential' );
		$body    = json_decode( $requests[0][1]['body'], false, 512, JSON_THROW_ON_ERROR );
		self::assertIsObject( $body->variables );

		self::assertSame( 'buffer', $account->provider );
		self::assertSame( 'acct_1', $account->remote_account_id );
		self::assertSame( 'Editorial', $account->organizations[0]->display_label );
		self::assertSame( 'https://api.buffer.com', $requests[0][0] );
		self::assertSame( 1048577, $requests[0][1]['limit_response_size'] );
		self::assertSame( 0, $requests[0][1]['redirection'] );
		self::assertTrue( $requests[0][1]['sslverify'] );
		self::assertSame( 10, $requests[0][1]['timeout'] );
		self::assertStringStartsWith( 'Bearer ', $requests[0][1]['headers']['Authorization'] );
	}

	public function test_discovers_channels_with_variables_and_normalizes_capabilities(): void {
		$requests = array();
		$provider = $this->provider(
			static function ( string $url, array $args ) use ( &$requests ): array {
				unset( $url );
				$requests[] = $args;

				return self::jsonResponse(
					array(
						'data' => array(
							'channels' => array(
								array(
									'allowedActions' => array( 'manageUpdates' ),
									'displayName'    => 'PressQueue Social',
									'externalLink'   => 'https://example.test/profile',
									'id'             => 'chan_1',
									'isDisconnected' => false,
									'isLocked'       => false,
									'isQueuePaused'  => false,
									'metadata'       => array(
										'maxCharacters' => 500,
									),
									'name'           => 'PressQueue',
									'organizationId' => 'org_1',
									'service'        => 'mastodon',
								),
								array(
									'allowedActions' => array(),
									'displayName'    => 'Read Only',
									'externalLink'   => null,
									'id'             => 'chan_2',
									'isDisconnected' => false,
									'isLocked'       => false,
									'isQueuePaused'  => false,
									'metadata'       => array(
										'maxCharacters' => 500,
									),
									'name'           => 'Read Only',
									'organizationId' => 'org_1',
									'service'        => 'mastodon',
								),
							),
						),
					)
				);
			}
		);

		$channels = $provider->channels( 'fixture credential', 'org_1' );
		$body     = json_decode( $requests[0]['body'], true, 512, JSON_THROW_ON_ERROR );

		self::assertSame( array( 'input' => array( 'organizationId' => 'org_1' ) ), $body['variables'] );
		self::assertStringContainsString( '$input', $body['query'] );
		self::assertStringContainsString( 'maxCharacters', $body['query'] );
		self::assertSame( 'available', $channels[0]->availability );
		self::assertSame( 2, $channels[0]->capability_document['schema_version'] );
		self::assertSame( array( 'immediate', 'queue_next', 'queue_end', 'explicit_time', 'draft' ), $channels[0]->capability_document['delivery']['schedule_slots'] );
		self::assertSame( 280, $channels[0]->capability_document['delivery']['max_text_characters'] );
		self::assertTrue( $channels[0]->capability_document['delivery']['text'] );
		self::assertSame( 1, $channels[0]->capability_document['delivery']['image']['max_items'] );
		self::assertSame( 'unsupported', $channels[1]->availability );
		self::assertSame( 'missing_manage_updates', $channels[1]->unavailable_reason );
		self::assertFalse( $channels[1]->capability_document['delivery']['text'] );
	}

	public function test_provider_failures_are_classified_without_raw_body(): void {
		$provider = $this->provider(
			static function (): array {
				return array(
					'body'     => '{"errors":[{"message":"contains fixture credential","extensions":{"code":"UNAUTHORIZED"}}]}',
					'response' => array( 'code' => 200 ),
				);
			}
		);

		try {
			$provider->account( 'fixture credential' );
			self::fail( 'GraphQL errors must be classified.' );
		} catch ( ProviderException $exception ) {
			self::assertSame( 'invalid_credential', $exception->classification() );
			self::assertStringNotContainsString( 'fixture credential', $exception->getMessage() );
			self::assertStringNotContainsString( 'contains', $exception->getMessage() );
		}
	}

	public function test_http_errors_and_malformed_responses_are_classified(): void {
		$invalid = $this->provider(
			static function (): array {
				return array(
					'body'     => '{}',
					'response' => array( 'code' => 401 ),
				);
			}
		);

		try {
			$invalid->account( 'fixture credential' );
			self::fail( 'HTTP 401 must fail.' );
		} catch ( ProviderException $exception ) {
			self::assertSame( 'invalid_credential', $exception->classification() );
		}

		$malformed = $this->provider(
			static function (): array {
				return array(
					'body'     => 'not json with fixture credential',
					'response' => array( 'code' => 200 ),
				);
			}
		);

		try {
			$malformed->account( 'fixture credential' );
			self::fail( 'Malformed JSON must fail.' );
		} catch ( ProviderException $exception ) {
			self::assertSame( 'malformed_response', $exception->classification() );
			self::assertStringNotContainsString( 'fixture credential', $exception->getMessage() );
		}
	}

	/**
	 * Create a provider with fake transport.
	 *
	 * @param callable $transport Transport.
	 * @return BufferDiscoveryProvider
	 */
	private function provider( callable $transport ): BufferDiscoveryProvider {
		return new BufferDiscoveryProvider( new BufferGraphqlClient( $transport ) );
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
