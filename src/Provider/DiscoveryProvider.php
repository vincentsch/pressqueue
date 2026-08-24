<?php
/**
 * Provider discovery contract.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Provider;

use PressQueue\Provider\Dto\ProviderAccount;

/**
 * Reads provider account and channel metadata without mutating posts.
 */
interface DiscoveryProvider {
	/**
	 * Fetch the authenticated account.
	 *
	 * @param string $credential Opaque provider credential.
	 * @return ProviderAccount
	 */
	public function account( string $credential ): ProviderAccount;

	/**
	 * Fetch channels for one organization.
	 *
	 * @param string $credential Opaque provider credential.
	 * @param string $remote_organization_id Remote organization ID.
	 * @return array<int,\PressQueue\Provider\Dto\ProviderChannel>
	 */
	public function channels( string $credential, string $remote_organization_id ): array;
}
