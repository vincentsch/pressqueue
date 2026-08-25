<?php
/**
 * Buffer provider conformance tests.
 *
 * @package PressQueue\Tests\Unit
 */

declare(strict_types=1);

namespace PressQueue\Tests\Unit;

use PressQueue\Provider\Buffer\BufferDeliveryProvider;
use PressQueue\Provider\Buffer\BufferGraphqlClient;
use PressQueue\Provider\Buffer\BufferDiscoveryProvider;
use PressQueue\Provider\ProviderDescriptor;
use PressQueue\Tests\Support\ProviderConformanceTestCase;

/**
 * @covers \PressQueue\Provider\Buffer\BufferCapabilityCatalog
 * @covers \PressQueue\Provider\Buffer\BufferDeliveryProvider
 * @covers \PressQueue\Provider\Buffer\BufferDiscoveryProvider
 * @covers \PressQueue\Provider\Buffer\BufferGraphqlClient
 * @covers \PressQueue\Provider\Buffer\BufferPostEncoder
 */
final class BufferProviderConformanceTest extends ProviderConformanceTestCase {
	/**
	 * Return a descriptor for the Buffer adapter with fake transports.
	 *
	 * @return ProviderDescriptor
	 */
	protected function provider_descriptor(): ProviderDescriptor {
		return new ProviderDescriptor(
			'buffer',
			array( 'personal_api_key' ),
			function (): BufferDiscoveryProvider {
				return new BufferDiscoveryProvider( new BufferGraphqlClient( array( $this, 'discovery_transport' ) ) );
			},
			function (): BufferDeliveryProvider {
				return new BufferDeliveryProvider( null, array( $this, 'delivery_transport' ) );
			}
		);
	}

	/**
	 * Fake Buffer GraphQL discovery transport.
	 *
	 * @param string              $url URL.
	 * @param array<string,mixed> $args Request args.
	 * @return array<string,mixed>
	 */
	public function discovery_transport( string $url, array $args ): array {
		unset( $url );

		$credential = $this->credential_from_args( $args );
		if ( $this->invalid_credential() === $credential ) {
			return array(
				'body'     => '{}',
				'response' => array( 'code' => 401 ),
			);
		}

		if ( $this->malformed_credential() === $credential ) {
			return array(
				'body'     => 'not json with malformed credential',
				'response' => array( 'code' => 200 ),
			);
		}

		$body      = $this->json_body( $args );
		$operation = isset( $body['operationName'] ) && is_string( $body['operationName'] ) ? $body['operationName'] : '';

		if ( 'PressQueueChannels' === $operation ) {
			return $this->json_response(
				array(
					'data' => array(
						'channels' => array(
							array(
								'allowedActions' => array( 'manageUpdates' ),
								'displayName'    => 'PressQueue Social',
								'externalLink'   => 'https://example.test/profile',
								'id'             => 'chan_buffer',
								'isDisconnected' => false,
								'isLocked'       => false,
								'isQueuePaused'  => false,
								'metadata'       => array( 'maxCharacters' => 500 ),
								'name'           => 'PressQueue Social',
								'organizationId' => 'org_buffer',
								'service'        => 'mastodon',
							),
						),
					),
				)
			);
		}

		return $this->json_response(
			array(
				'data' => array(
					'account' => array(
						'email'         => 'owner@example.test',
						'id'            => 'acct_buffer',
						'name'          => 'Owner',
						'organizations' => array(
							array(
								'channelCount' => 1,
								'id'           => 'org_buffer',
								'name'         => 'Editorial',
							),
						),
					),
				),
			)
		);
	}

	/**
	 * Fake Buffer delivery transport.
	 *
	 * @param string              $url URL.
	 * @param array<string,mixed> $args Request args.
	 * @return array<string,mixed>
	 */
	public function delivery_transport( string $url, array $args ): array {
		unset( $url );

		switch ( $this->credential_from_args( $args ) ) {
			case 'retryable':
				return array(
					'body'     => '{}',
					'response' => array( 'code' => 500 ),
				);

			case 'rate_limited':
				return array(
					'body'     => '{}',
					'headers'  => array( 'Retry-After' => '120' ),
					'response' => array( 'code' => 429 ),
				);

			case 'permanent':
			case 'secret invalid delivery credential':
				return array(
					'body'     => '{}',
					'response' => array( 'code' => 401 ),
				);

			case 'ambiguous':
				return array(
					'body'     => '{bad json',
					'pressqueue_request_may_have_reached_provider' => true,
					'response' => array( 'code' => 200 ),
				);
		}

		return $this->json_response(
			array(
				'data' => array(
					'createPost' => array(
						'__typename' => 'PostActionSuccess',
						'post'       => array(
							'dueAt'          => '2026-08-25T12:00:00Z',
							'id'             => 'remote_buffer_post',
							'schedulingType' => 'automatic',
							'shareMode'      => 'shareNow',
							'status'         => 'accepted',
							'text'           => 'Hello',
						),
					),
				),
			)
		);
	}

	/**
	 * Return the bearer credential from request args.
	 *
	 * @param array<string,mixed> $args Request args.
	 * @return string
	 */
	private function credential_from_args( array $args ): string {
		$header = isset( $args['headers']['Authorization'] ) && is_string( $args['headers']['Authorization'] ) ? $args['headers']['Authorization'] : '';

		return preg_replace( '/^Bearer\s+/i', '', $header ) ?? '';
	}

	/**
	 * Decode a request body.
	 *
	 * @param array<string,mixed> $args Request args.
	 * @return array<string,mixed>
	 */
	private function json_body( array $args ): array {
		$decoded = json_decode( isset( $args['body'] ) && is_string( $args['body'] ) ? $args['body'] : '{}', true, 512, JSON_THROW_ON_ERROR );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Build a fake HTTP JSON response.
	 *
	 * @param array<string,mixed> $body Body.
	 * @return array<string,mixed>
	 */
	private function json_response( array $body ): array {
		return array(
			'body'     => json_encode( $body, JSON_THROW_ON_ERROR ),
			'response' => array( 'code' => 200 ),
		);
	}
}
