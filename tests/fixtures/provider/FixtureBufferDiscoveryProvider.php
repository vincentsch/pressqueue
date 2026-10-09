<?php
/**
 * Offline Buffer-shaped discovery provider for Channels screen tests.
 *
 * @package PressQueue\Tests
 */

declare(strict_types=1);

namespace PressQueue\Tests\Fixtures\Provider;

use PressQueue\Provider\Buffer\BufferCapabilityCatalog;
use PressQueue\Provider\DiscoveryProvider;
use PressQueue\Provider\Dto\ProviderAccount;
use PressQueue\Provider\Dto\ProviderChannel;
use PressQueue\Provider\Dto\ProviderOrganization;
use PressQueue\Provider\ProviderException;

/**
 * Answers like Buffer, decided by the key.
 *
 * Keys starting with "pq-invalid" are rejected, "pq-network" cannot reach Buffer,
 * "pq-multi" sees two organizations, "pq-other" belongs to another account, and every
 * other key sees one organization.
 */
final class FixtureBufferDiscoveryProvider implements DiscoveryProvider {
	public const ACCOUNT   = 'acct_one';
	public const ORG_ONE   = 'org_one';
	public const ORG_TWO   = 'org_two';
	public const LINKEDIN  = 'chan_linkedin';
	public const INSTAGRAM = 'chan_instagram';
	public const PERSONAL  = 'chan_personal';

	/**
	 * When true, every key is rejected, as if it was deleted in Buffer.
	 *
	 * @var bool
	 */
	public static bool $reject_all = false;

	/**
	 * Number of account requests made.
	 *
	 * @var int
	 */
	public static int $account_requests = 0;

	/**
	 * Return account metadata.
	 *
	 * @param string $credential Credential.
	 * @return ProviderAccount
	 */
	public function account( string $credential ): ProviderAccount {
		++self::$account_requests;
		$this->guard( $credential );

		$organizations = array( new ProviderOrganization( 'buffer', self::ORG_ONE, 'Larkfield Bakery', 2 ) );
		if ( str_starts_with( $credential, 'pq-multi' ) ) {
			$organizations[] = new ProviderOrganization( 'buffer', self::ORG_TWO, 'Mara (personal)', 1 );
		}

		$account = str_starts_with( $credential, 'pq-other' ) ? 'acct_two' : self::ACCOUNT;

		return new ProviderAccount( 'buffer', $account, 'Mara Lindqvist', $organizations );
	}

	/**
	 * Return channels for one organization.
	 *
	 * @param string $credential Credential.
	 * @param string $remote_organization_id Organization ID.
	 * @return array<int,ProviderChannel>
	 */
	public function channels( string $credential, string $remote_organization_id ): array {
		$this->guard( $credential );
		$catalog = new BufferCapabilityCatalog();
		$flags   = array(
			'isDisconnected' => false,
			'isLocked'       => false,
			'isQueuePaused'  => false,
		);

		if ( self::ORG_TWO === $remote_organization_id ) {
			return array(
				new ProviderChannel( 'buffer', self::ORG_TWO, self::PERSONAL, 'mara', 'Mara on LinkedIn', 'linkedin', null, $flags, array( 'manageUpdates' ), 'available', $catalog->capability_for_service( 'linkedin' ), null ),
			);
		}

		return array(
			new ProviderChannel( 'buffer', self::ORG_ONE, self::LINKEDIN, 'larkfield', 'Larkfield Bakery', 'linkedin', null, $flags, array( 'manageUpdates' ), 'available', $catalog->capability_for_service( 'linkedin' ), null ),
			new ProviderChannel( 'buffer', self::ORG_ONE, self::INSTAGRAM, 'larkfieldbakery', 'larkfieldbakery', 'instagram', null, $flags, array( 'manageUpdates' ), 'unsupported', $catalog->capability_for_service( 'instagram', array(), false ), 'unsupported_service' ),
		);
	}

	/**
	 * Fail like Buffer for special keys.
	 *
	 * @param string $credential Credential.
	 */
	private function guard( string $credential ): void {
		if ( self::$reject_all || str_starts_with( $credential, 'pq-invalid' ) ) {
			throw new ProviderException( 'invalid_credential', 'Provider rejected the credential.' );
		}

		if ( str_starts_with( $credential, 'pq-network' ) ) {
			throw new ProviderException( 'network_failure', 'Provider request failed before a response was available.' );
		}
	}
}
