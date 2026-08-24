<?php
/**
 * Provider account DTO.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Provider\Dto;

// phpcs:disable Squiz.Commenting.VariableComment.Missing, Generic.Commenting.DocComment.MissingShort -- Constructor docs cover these immutable DTO fields.

/**
 * Represents the authenticated provider account.
 */
final class ProviderAccount {
	public string $provider;
	public string $remote_account_id;
	public string $display_label;
	/** @var array<int,ProviderOrganization> */
	public array $organizations;

	/**
	 * Constructor.
	 *
	 * @param string                          $provider Provider key.
	 * @param string                          $remote_account_id Remote account ID.
	 * @param string                          $display_label Display label.
	 * @param array<int,ProviderOrganization> $organizations Organizations.
	 */
	public function __construct( string $provider, string $remote_account_id, string $display_label, array $organizations ) {
		$this->provider          = $provider;
		$this->remote_account_id = $remote_account_id;
		$this->display_label     = $display_label;
		$this->organizations     = array_values( $organizations );
	}

	/**
	 * Return a storage-safe account document.
	 *
	 * @return array<string,mixed>
	 */
	public function to_storage_document(): array {
		return array(
			'display_label'     => $this->display_label,
			'organizations'     => array_map(
				static function ( ProviderOrganization $organization ): array {
					return $organization->to_array();
				},
				$this->organizations
			),
			'provider'          => $this->provider,
			'remote_account_id' => $this->remote_account_id,
			'schema_version'    => 1,
		);
	}

	/**
	 * Check whether an organization belongs to this account.
	 *
	 * @param string $remote_organization_id Remote organization ID.
	 * @return bool
	 */
	public function has_organization( string $remote_organization_id ): bool {
		foreach ( $this->organizations as $organization ) {
			if ( $organization->remote_organization_id === $remote_organization_id ) {
				return true;
			}
		}

		return false;
	}
}
