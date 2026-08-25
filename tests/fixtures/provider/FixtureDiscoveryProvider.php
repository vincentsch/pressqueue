<?php
/**
 * Fixture discovery provider.
 *
 * @package PressQueue\Tests
 */

declare(strict_types=1);

namespace PressQueue\Tests\Fixtures\Provider;

use PressQueue\Provider\DiscoveryProvider;
use PressQueue\Provider\Dto\ProviderAccount;
use PressQueue\Provider\Dto\ProviderChannel;
use PressQueue\Provider\Dto\ProviderOrganization;
use PressQueue\Provider\ProviderException;

/**
 * Deterministic discovery adapter for conformance tests.
 */
final class FixtureDiscoveryProvider implements DiscoveryProvider {
	/**
	 * Return fixture account metadata.
	 *
	 * @param string $credential Credential.
	 * @return ProviderAccount
	 */
	public function account( string $credential ): ProviderAccount {
		if ( 'invalid' === $credential ) {
			throw new ProviderException( 'invalid_credential', 'Invalid provider credential.' );
		}

		if ( 'malformed' === $credential ) {
			throw new ProviderException( 'malformed_response', 'Provider response could not be parsed.' );
		}

		return new ProviderAccount(
			'fixture',
			'acct_fixture',
			'Fixture Account',
			array(
				new ProviderOrganization( 'fixture', 'org_fixture', 'Fixture Organization', 1 ),
			)
		);
	}

	/**
	 * Return fixture channels.
	 *
	 * @param string $credential Credential.
	 * @param string $remote_organization_id Organization ID.
	 * @return array<int,ProviderChannel>
	 */
	public function channels( string $credential, string $remote_organization_id ): array {
		unset( $credential );

		return array(
			new ProviderChannel(
				'fixture',
				$remote_organization_id,
				'chan_fixture',
				'Fixture Channel',
				'Fixture Channel',
				'mastodon',
				null,
				array( 'fixture' => true ),
				array( 'manageUpdates' ),
				'available',
				array(
					'catalog_version' => 'fixture.1',
					'delivery'        => array(
						'image'               => null,
						'max_text_characters' => 500,
						'schedule_slots'      => array( 'immediate', 'queue_end', 'queue_next', 'explicit_time' ),
						'text'                => true,
					),
					'schema_version'  => 2,
					'service'         => 'mastodon',
					'supported'       => true,
				),
				null
			),
		);
	}
}
