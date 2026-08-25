<?php
/**
 * Provider connection workflows.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Provider;

use PressQueue\Provider\Dto\ProviderAccount;
use PressQueue\Security\CredentialEnvelope;
use PressQueue\Storage\Database;
use PressQueue\Storage\Record\ConnectionRecord;
use PressQueue\Storage\Repository\ChannelRepository;
use PressQueue\Storage\Repository\ConnectionRepository;
use PressQueue\Storage\StaleRecordException;
use PressQueue\Storage\ValidationException;

/**
 * Coordinates provider verification and channel synchronization.
 */
final class ProviderConnectionService {
	/**
	 * Database wrapper.
	 *
	 * @var Database
	 */
	private Database $database;

	/**
	 * Provider registry.
	 *
	 * @var ProviderRegistry
	 */
	private ProviderRegistry $registry;

	/**
	 * Connection repository.
	 *
	 * @var ConnectionRepository
	 */
	private ConnectionRepository $connections;

	/**
	 * Channel repository.
	 *
	 * @var ChannelRepository
	 */
	private ChannelRepository $channels;

	/**
	 * Credential envelope service.
	 *
	 * @var CredentialEnvelope
	 */
	private CredentialEnvelope $credential_envelope;

	/**
	 * Constructor.
	 *
	 * @param Database|null           $database Database wrapper.
	 * @param ProviderRegistry|null   $registry Provider registry.
	 * @param CredentialEnvelope|null $credential_envelope Credential envelope service.
	 */
	public function __construct( ?Database $database = null, ?ProviderRegistry $registry = null, ?CredentialEnvelope $credential_envelope = null ) {
		$this->database            = $database ?? Database::from_globals();
		$this->registry            = $registry ?? ProviderRegistryFactory::registry();
		$this->connections         = new ConnectionRepository( $this->database );
		$this->channels            = new ChannelRepository( $this->database );
		$this->credential_envelope = $credential_envelope ?? new CredentialEnvelope();
	}

	/**
	 * Create a disabled connection with an opaque provider credential.
	 *
	 * @param string $provider_key Provider key.
	 * @param string $label Connection label.
	 * @param string $credential Opaque provider credential.
	 * @param string $authentication_mode Authentication mode.
	 * @return ConnectionRecord
	 */
	public function create_disabled_connection( string $provider_key, string $label, string $credential, string $authentication_mode ): ConnectionRecord {
		$this->assert_authentication_mode( $provider_key, $authentication_mode );

		return $this->connections->create_with_credential_factory(
			$provider_key,
			$label,
			function ( string $connection_public_id ) use ( $provider_key, $credential ): array {
				$json = $this->credential_envelope->encode_for_storage( $this->credential_envelope->encrypt( $provider_key, $connection_public_id, $credential ) );

				return array(
					'hash' => $this->credential_envelope->storage_hash( $json ),
					'json' => $json,
				);
			}
		);
	}

	/**
	 * Fetch account facts from the provider without persisting them.
	 *
	 * @param ConnectionRecord $connection Connection.
	 * @param string           $credential Opaque provider credential.
	 * @return ProviderAccount
	 */
	public function fetch_account( ConnectionRecord $connection, string $credential ): ProviderAccount {
		return $this->registry->discovery_provider( $connection->provider )->account( $credential );
	}

	/**
	 * Verify and store provider account facts.
	 *
	 * @param ConnectionRecord $connection Connection.
	 * @param string           $credential Opaque provider credential.
	 * @return ConnectionRecord
	 */
	public function verify_account( ConnectionRecord $connection, string $credential ): ConnectionRecord {
		$account = $this->fetch_account( $connection, $credential );

		return $this->connections->store_verified_account( $connection->public_id, $account->remote_account_id, $account->to_storage_document() );
	}

	/**
	 * Run a complete channel sync for a selected organization.
	 *
	 * @param ConnectionRecord $connection Connection.
	 * @param string           $credential Opaque provider credential.
	 * @param ProviderAccount  $account Verified account.
	 * @param string           $organization_id Organization ID.
	 * @param bool             $preserve_enabled Whether to keep enabled state.
	 * @return ConnectionRecord
	 */
	public function sync_channels( ConnectionRecord $connection, string $credential, ProviderAccount $account, string $organization_id, bool $preserve_enabled ): ConnectionRecord {
		if ( ! $account->has_organization( $organization_id ) ) {
			throw new ValidationException( 'Selected organization is not visible to this credential.' );
		}

		$previous_org_id  = $connection->remote_organization_id;
		$changing_org     = null !== $previous_org_id && $previous_org_id !== $organization_id;
		$preserve_enabled = $preserve_enabled && ! $changing_org;
		$this->connections->store_verified_account( $connection->public_id, $account->remote_account_id, $account->to_storage_document() );

		if ( null === $connection->remote_organization_id || $changing_org ) {
			$connection       = $this->connections->select_organization_unverified( $connection->public_id, $organization_id );
			$preserve_enabled = false;
		} else {
			$connection = $this->connections->find_by_public_id( $connection->public_id );
			if ( null === $connection ) {
				throw new ValidationException( 'Connection disappeared before synchronization.' );
			}
		}

		$lease = $this->connections->acquire_sync_lease( $connection->public_id );

		if ( null === $lease ) {
			throw new ValidationException( 'Connection synchronization is already running.' );
		}

		try {
			$provider_channels = $this->registry->discovery_provider( $connection->provider )->channels( $credential, $organization_id );
			return $this->database->transaction(
				function () use ( $connection, $lease, $provider_channels, $account, $organization_id, $previous_org_id, $changing_org, $preserve_enabled ): ConnectionRecord {
					$leased = $this->connections->find_by_public_id( $connection->public_id );
					if ( null === $leased || $leased->sync_lease_owner !== $lease ) {
						throw new StaleRecordException( 'Connection sync lease is no longer held.' );
					}

					if ( $changing_org && null !== $previous_org_id ) {
						$this->channels->mark_organization_channels_missing( $leased, $lease, $previous_org_id );
					}

					$this->channels->synchronize_provider_channels( $leased, $lease, $provider_channels );
					return $this->connections->complete_sync_success( $connection->public_id, $lease, $account->remote_account_id, $organization_id, $account->to_storage_document(), $preserve_enabled );
				}
			);
		} catch ( ProviderException $exception ) {
			$this->record_sync_failure( $connection->public_id, $lease, $exception->to_redacted_document() );
			throw $exception;
		} catch ( \Throwable $throwable ) {
			if ( ! $throwable instanceof StaleRecordException ) {
				$this->record_sync_failure(
					$connection->public_id,
					$lease,
					array(
						'classification' => 'unexpected_provider_failure',
						'message'        => 'Channel synchronization failed with redacted diagnostics.',
						'schema_version' => 1,
					)
				);
			}

			throw $throwable;
		}
	}

	/**
	 * Record a sync failure without masking stale-lease results.
	 *
	 * @param string              $connection_public_id Connection public ID.
	 * @param string              $lease Sync lease owner.
	 * @param array<string,mixed> $error Error document.
	 */
	private function record_sync_failure( string $connection_public_id, string $lease, array $error ): void {
		try {
			$this->connections->complete_sync_failure( $connection_public_id, $lease, $error );
		} catch ( StaleRecordException $exception ) {
			unset( $exception );
		}
	}

	/**
	 * Assert that a provider declares an authentication mode.
	 *
	 * @param string $provider_key Provider key.
	 * @param string $authentication_mode Authentication mode.
	 */
	private function assert_authentication_mode( string $provider_key, string $authentication_mode ): void {
		$summary = $this->registry->summary();

		if ( ! isset( $summary[ $provider_key ] ) ) {
			throw new ValidationException( 'Provider is not registered.' );
		}

		$modes = $summary[ $provider_key ]['authentication_modes'] ?? array();
		if ( ! is_array( $modes ) || ! in_array( $authentication_mode, $modes, true ) ) {
			throw new ValidationException( 'Provider authentication mode is not supported.' );
		}
	}
}
