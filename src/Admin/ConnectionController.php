<?php
/**
 * Connection administration controller.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Provider\Dto\ProviderAccount;
use PressQueue\Provider\ProviderException;
use PressQueue\Provider\ProviderConnectionService;
use PressQueue\Provider\ProviderRegistry;
use PressQueue\Security\Capabilities;
use PressQueue\Security\CredentialEnvelope;
use PressQueue\Security\CredentialEnvelopeException;
use PressQueue\Security\SafeOperatorError;
use PressQueue\Storage\Database;
use PressQueue\Storage\PublicIds;
use PressQueue\Storage\Record\ConnectionRecord;
use PressQueue\Storage\Repository\ConnectionRepository;
use PressQueue\Storage\StaleRecordException;
use PressQueue\Storage\ValidationException;

/**
 * Handles Buffer connection management.
 */
final class ConnectionController {
	public const SLUG = 'pressqueue-connections';

	/**
	 * Provider used by the one-step connect flow.
	 */
	private const PROVIDER = 'buffer';

	/**
	 * Authentication mode used by the one-step connect flow.
	 */
	private const AUTHENTICATION_MODE = 'personal_api_key';

	/**
	 * Fixed public ID used only to check that credential encryption works on this server.
	 */
	private const READINESS_PUBLIC_ID = '00000000000000000000000000';

	/**
	 * Provider failure classifications that may be shown as technical details.
	 */
	public const PROVIDER_REASONS = array(
		'forbidden',
		'invalid_credential',
		'malformed_response',
		'network_failure',
		'not_found',
		'rate_limited',
		'unexpected_provider_failure',
	);

	/**
	 * Test override registry.
	 *
	 * @var ProviderRegistry|null
	 */
	private static ?ProviderRegistry $registry = null;

	/**
	 * Test override credential envelope.
	 *
	 * @var CredentialEnvelope|null
	 */
	private static ?CredentialEnvelope $credential_envelope = null;

	/**
	 * Register hooks.
	 */
	public static function register_hooks(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( 'admin_post_pressqueue_connect_buffer', array( self::class, 'handle_connect_buffer' ) );
		add_action( 'admin_post_pressqueue_finish_connection', array( self::class, 'handle_finish_connection' ) );
		add_action( 'admin_post_pressqueue_create_connection', array( self::class, 'handle_create_connection' ) );
		add_action( 'admin_post_pressqueue_test_connection', array( self::class, 'handle_test_connection' ) );
		add_action( 'admin_post_pressqueue_sync_connection', array( self::class, 'handle_sync_connection' ) );
		add_action( 'admin_post_pressqueue_rotate_connection', array( self::class, 'handle_rotate_connection' ) );
		add_action( 'admin_post_pressqueue_enable_connection', array( self::class, 'handle_enable_connection' ) );
		add_action( 'admin_post_pressqueue_disable_connection', array( self::class, 'handle_disable_connection' ) );
		add_action( 'admin_post_pressqueue_disconnect_connection', array( self::class, 'handle_disconnect_connection' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
	}

	/**
	 * Register menu pages.
	 *
	 * @param string $parent_slug Parent slug.
	 */
	public static function register_menu( string $parent_slug ): void {
		add_submenu_page(
			$parent_slug,
			__( 'PressQueue Channels', 'pressqueue' ),
			__( 'Channels', 'pressqueue' ),
			Capabilities::MANAGE_CONNECTIONS,
			self::SLUG,
			array( self::class, 'render_connections_page' )
		);
	}

	/**
	 * Enqueue the Channels screen styles.
	 */
	public static function enqueue_assets(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Page routing is read-only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( self::SLUG !== $page || ! defined( 'PRESSQUEUE_FILE' ) || ! function_exists( 'wp_enqueue_style' ) ) {
			return;
		}

		wp_enqueue_style( 'pressqueue-channels', plugins_url( 'assets/admin/channels.css', PRESSQUEUE_FILE ), array( 'pressqueue-admin' ), Ui::asset_version( 'assets/admin/channels.css' ) );
		wp_enqueue_script( 'pressqueue-channels', plugins_url( 'assets/admin/channels.js', PRESSQUEUE_FILE ), array( 'pressqueue-admin' ), Ui::asset_version( 'assets/admin/channels.js' ), true );
	}

	/**
	 * Set a registry for tests.
	 *
	 * @param ProviderRegistry|null $registry Registry.
	 */
	public static function set_registry( ?ProviderRegistry $registry ): void {
		self::$registry = $registry;
	}

	/**
	 * Set a credential envelope for tests.
	 *
	 * @param CredentialEnvelope|null $credential_envelope Credential envelope.
	 */
	public static function set_credential_envelope( ?CredentialEnvelope $credential_envelope ): void {
		self::$credential_envelope = $credential_envelope;
	}

	/**
	 * Render the Channels page.
	 */
	public static function render_connections_page(): void {
		self::require_capability();
		ChannelsPage::render();
	}

	/**
	 * Report why this server cannot store a provider key, if it cannot.
	 *
	 * @return string|null "sodium" when the PHP Sodium extension is missing, "salts" when WordPress keys and salts are not set, or null when storage works.
	 */
	public static function credential_storage_problem(): ?string {
		$probe = 'pressqueue-readiness-check';

		try {
			self::credential_envelope()->encrypt( self::PROVIDER, self::READINESS_PUBLIC_ID, $probe );
		} catch ( CredentialEnvelopeException $exception ) {
			return self::storage_problem_from( $exception );
		}

		return null;
	}

	/**
	 * Handle the one-step Buffer connect form.
	 *
	 * The key is checked with Buffer before anything is stored, so a rejected key leaves nothing behind.
	 */
	public static function handle_connect_buffer(): void {
		self::mutation_boundary( 'pressqueue_connect_buffer' );

		$secret = '';
		try {
			$problem = self::credential_storage_problem();
			$secret  = self::post_secret( 'api_key' );

			if ( null !== $problem ) {
				$result = array( 'error' => 'storage_' . $problem );
			} elseif ( '' === $secret ) {
				$result = array( 'error' => 'key_missing' );
			} else {
				$account = self::registry()->discovery_provider( self::PROVIDER )->account( $secret );
				$result  = self::connect_account( $secret, $account, null );
			}
		} catch ( \Throwable $throwable ) {
			$result = self::error_result( $throwable );
		} finally {
			self::memzero( $secret );
			unset( $secret );
		}

		if ( isset( $result['error'] ) ) {
			$result['form'] = 'connect';
		}

		self::redirect_to_page( $result );
	}

	/**
	 * Finish setting up a saved connection: choose or switch the organization, load channels, and turn it on.
	 */
	public static function handle_finish_connection(): void {
		self::mutation_boundary( 'pressqueue_finish_connection' );

		$credential = '';
		$result     = array();
		try {
			$repository = new ConnectionRepository();
			$connection = self::requested_connection( $repository );
			$result     = array( 'connection' => $connection->public_id );
			$credential = self::decrypt_connection_credential( $connection, $repository );
			$account    = self::fetch_saved_account( $connection, $credential, $repository );

			if ( null !== $connection->remote_account_id && $connection->remote_account_id !== $account->remote_account_id ) {
				$result['error'] = 'other_account';
			} else {
				$organization_id = self::post_text( 'remote_organization_id' );
				if ( '' === $organization_id && null !== $connection->remote_organization_id && $account->has_organization( $connection->remote_organization_id ) ) {
					$organization_id = $connection->remote_organization_id;
				}

				$result = array_merge(
					$result,
					'' === $organization_id
						? self::connect_account( $credential, $account, $connection )
						: self::connect_organization( $credential, $account, $organization_id, $connection )
				);
			}
		} catch ( \Throwable $throwable ) {
			$result = array_merge( $result, self::error_result( $throwable ) );
		} finally {
			self::memzero( $credential );
			unset( $credential );
		}

		self::redirect_to_page( $result );
	}

	/**
	 * Handle creating a disabled Buffer connection.
	 */
	public static function handle_create_connection(): void {
		self::mutation_boundary( 'pressqueue_create_connection' );

		try {
			$secret = self::post_secret( 'api_key' );
			try {
				$connection = self::connection_service()->create_disabled_connection(
					self::PROVIDER,
					self::post_text( 'label', __( 'Buffer connection', 'pressqueue' ) ),
					$secret,
					self::AUTHENTICATION_MODE
				);
			} finally {
				self::memzero( $secret );
				unset( $secret );
			}

			$result = array(
				'message'    => 'connection_created',
				'connection' => $connection->public_id,
			);
		} catch ( \Throwable $throwable ) {
			$result = self::error_result( $throwable );
		}

		self::redirect_to_page( $result );
	}

	/**
	 * Handle credential test.
	 */
	public static function handle_test_connection(): void {
		self::mutation_boundary( 'pressqueue_test_connection' );

		try {
			$repository = new ConnectionRepository();
			$connection = self::requested_connection( $repository );
			$credential = self::decrypt_connection_credential( $connection, $repository );
			try {
				self::connection_service()->verify_account( $connection, $credential );
			} finally {
				self::memzero( $credential );
				unset( $credential );
			}

			$result = array(
				'message'    => 'credential_verified',
				'connection' => $connection->public_id,
			);
		} catch ( \Throwable $throwable ) {
			$result = self::error_result( $throwable );
		}

		self::redirect_to_page( $result );
	}

	/**
	 * Handle organization selection and channel sync ("Refresh channels").
	 */
	public static function handle_sync_connection(): void {
		self::mutation_boundary( 'pressqueue_sync_connection' );

		$result = array();
		try {
			$repository      = new ConnectionRepository();
			$connection      = self::requested_connection( $repository );
			$result          = array( 'connection' => $connection->public_id );
			$service         = self::connection_service();
			$credential      = self::decrypt_connection_credential( $connection, $repository );
			$organization_id = self::post_text( 'remote_organization_id', (string) $connection->remote_organization_id );

			try {
				$account = self::fetch_saved_account( $connection, $credential, $repository );
				$service->sync_channels( $connection, $credential, $account, $organization_id, self::can_preserve_enabled_on_sync( $connection, $organization_id ) );
			} finally {
				self::memzero( $credential );
				unset( $credential );
			}

			$result['message'] = 'channels_synced';
		} catch ( \Throwable $throwable ) {
			$result = array_merge( $result, self::error_result( $throwable ) );
		}

		self::redirect_to_page( $result );
	}

	/**
	 * Handle credential rotation ("Replace API key").
	 */
	public static function handle_rotate_connection(): void {
		self::mutation_boundary( 'pressqueue_rotate_connection' );

		$result = array();
		try {
			$repository = new ConnectionRepository();
			$connection = self::requested_connection( $repository );
			$result     = array(
				'connection' => $connection->public_id,
				'form'       => 'replace',
			);
			$result     = array_merge( $result, self::rotate_credential( $repository, $connection ) );
		} catch ( \Throwable $throwable ) {
			$result = array_merge( $result, self::error_result( $throwable ) );
		}

		if ( ! isset( $result['error'] ) ) {
			unset( $result['form'] );
		}

		self::redirect_to_page( $result );
	}

	/**
	 * Replace a saved key with a new key for the same Buffer account.
	 *
	 * @param ConnectionRepository $repository Repository.
	 * @param ConnectionRecord     $connection Connection.
	 * @return array<string,string> Redirect result.
	 */
	private static function rotate_credential( ConnectionRepository $repository, ConnectionRecord $connection ): array {
		if ( null === $connection->credential_envelope_hash ) {
			throw new ValidationException( 'Connection has no credential to rotate.' );
		}

		$problem = self::credential_storage_problem();
		if ( null !== $problem ) {
			return array( 'error' => 'storage_' . $problem );
		}

		$service = self::connection_service();
		$secret  = self::post_secret( 'api_key' );
		try {
			if ( '' === $secret ) {
				return array( 'error' => 'key_missing' );
			}

			$account = $service->fetch_account( $connection, $secret );
			if ( null !== $connection->remote_account_id && $connection->remote_account_id !== $account->remote_account_id ) {
				return array( 'error' => 'other_account' );
			}

			$envelope = self::credential_envelope();
			$json     = $envelope->encode_for_storage( $envelope->encrypt( $connection->provider, $connection->public_id, $secret ) );
			$hash     = $envelope->storage_hash( $json );
		} finally {
			self::memzero( $secret );
			unset( $secret );
		}

		$expected               = self::post_text( 'credential_rotated_at' );
		$organization_visible   = null !== $connection->remote_organization_id && $account->has_organization( $connection->remote_organization_id );
		$expected_envelope_hash = $connection->credential_envelope_hash;
		$reconnect              = '1' === self::post_text( 'reconnect' );

		$rotated = $repository->replace_credential_if_current( $connection->public_id, $expected, $expected_envelope_hash, $json, $hash, $account->remote_account_id, $account->to_storage_document(), $organization_visible );

		if ( ! $organization_visible || null === $rotated->remote_organization_id ) {
			return array( 'message' => 'credential_rotated' );
		}

		$credential = self::decrypt_connection_credential( $rotated, $repository );
		try {
			$service->sync_channels( $rotated, $credential, $account, $rotated->remote_organization_id, $connection->enabled );
		} finally {
			self::memzero( $credential );
			unset( $credential );
		}

		if ( $reconnect ) {
			$repository->enable( $rotated->public_id );

			return array( 'message' => 'credential_reconnected' );
		}

		return array( 'message' => 'credential_rotated' );
	}

	/**
	 * Handle enabling a connection.
	 */
	public static function handle_enable_connection(): void {
		self::mutation_boundary( 'pressqueue_enable_connection' );

		try {
			$connection = ( new ConnectionRepository() )->enable( self::post_text( 'connection_public_id' ) );
			$result     = array(
				'message'    => 'connection_enabled',
				'connection' => $connection->public_id,
			);
		} catch ( \Throwable $throwable ) {
			$result = self::error_result( $throwable );
		}

		self::redirect_to_page( $result );
	}

	/**
	 * Handle disabling a connection.
	 */
	public static function handle_disable_connection(): void {
		self::mutation_boundary( 'pressqueue_disable_connection' );

		try {
			$connection = ( new ConnectionRepository() )->disable( self::post_text( 'connection_public_id' ) );
			$result     = array(
				'message'    => 'connection_disabled',
				'connection' => $connection->public_id,
			);
		} catch ( \Throwable $throwable ) {
			$result = self::error_result( $throwable );
		}

		self::redirect_to_page( $result );
	}

	/**
	 * Handle disconnecting a credential.
	 */
	public static function handle_disconnect_connection(): void {
		self::mutation_boundary( 'pressqueue_disconnect_connection' );

		try {
			$connection = ( new ConnectionRepository() )->disconnect( self::post_text( 'connection_public_id' ) );
			$result     = array(
				'message'    => 'cancel' === self::post_text( 'intent' ) ? 'setup_cancelled' : 'connection_disconnected',
				'connection' => $connection->public_id,
			);
		} catch ( \Throwable $throwable ) {
			$result = self::error_result( $throwable );
		}

		self::redirect_to_page( $result );
	}

	/**
	 * Map a failure to a plain-language error code plus safe technical details.
	 *
	 * @param \Throwable $throwable Failure.
	 * @return array<string,string> Redirect result with error, and optionally reason or detail.
	 */
	public static function error_result( \Throwable $throwable ): array {
		if ( $throwable instanceof ProviderException ) {
			$codes = array(
				'invalid_credential' => 'key_rejected',
				'forbidden'          => 'key_forbidden',
				'network_failure'    => 'network',
				'rate_limited'       => 'rate_limited',
			);

			return array(
				'error'  => $codes[ $throwable->classification() ] ?? 'provider_failure',
				'reason' => in_array( $throwable->classification(), self::PROVIDER_REASONS, true ) ? $throwable->classification() : 'unexpected_provider_failure',
			);
		}

		if ( $throwable instanceof CredentialEnvelopeException ) {
			$problem = self::storage_problem_from( $throwable );

			return array( 'error' => null === $problem ? 'key_unreadable' : 'storage_' . $problem );
		}

		if ( $throwable instanceof StaleRecordException ) {
			return array(
				'error'  => 'stale',
				'detail' => SafeOperatorError::redirect_detail( $throwable ),
			);
		}

		if ( $throwable instanceof ValidationException ) {
			$known   = array(
				'Channel must be available with schema version 2 delivery capability before it can be enabled.' => 'channel_unavailable',
				'Connection has no saved credential.'     => 'no_key',
				'Connection has no credential to rotate.' => 'no_key',
				'Connection must be verified and synchronized before it can be enabled.' => 'needs_refresh',
				'Connection synchronization is already running.' => 'busy',
				'Replacement credential belongs to a different provider account.' => 'other_account',
				'Selected organization is not visible to this credential.' => 'organization_missing',
				'Verified credential belongs to a different provider account.' => 'other_account',
			);
			$message = $throwable->getMessage();

			if ( isset( $known[ $message ] ) ) {
				return array( 'error' => $known[ $message ] );
			}
		}

		return array(
			'error'  => 'failed',
			'detail' => SafeOperatorError::redirect_detail( $throwable ),
		);
	}

	/**
	 * Redirect back to the Channels page with a result.
	 *
	 * @param array<string,string> $result Result keys: message, error, detail, reason, connection, channel, form.
	 */
	public static function redirect_to_page( array $result ): void {
		$params = array(
			'message'    => 'pressqueue_message',
			'error'      => 'pressqueue_error',
			'detail'     => 'pressqueue_detail',
			'reason'     => 'pressqueue_reason',
			'connection' => 'pressqueue_connection',
			'channel'    => 'pressqueue_channel',
			'form'       => 'pressqueue_form',
		);
		$args   = array( 'page' => self::SLUG );

		foreach ( $params as $key => $param ) {
			if ( isset( $result[ $key ] ) && '' !== $result[ $key ] ) {
				$args[ $param ] = rawurlencode( $result[ $key ] );
			}
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Connect every organization a verified key can see, or ask which one to use.
	 *
	 * @param string                $secret Opaque provider credential.
	 * @param ProviderAccount       $account Verified account.
	 * @param ConnectionRecord|null $pending Saved connection that is waiting for setup, if any.
	 * @return array<string,string> Redirect result.
	 */
	private static function connect_account( string $secret, ProviderAccount $account, ?ConnectionRecord $pending ): array {
		$organizations = $account->organizations;

		if ( array() === $organizations ) {
			return array( 'error' => 'no_organization' );
		}

		if ( 1 === count( $organizations ) ) {
			return self::connect_organization( $secret, $account, $organizations[0]->remote_organization_id, $pending );
		}

		$repository  = new ConnectionRepository();
		$connections = $repository->find_all();
		$exclude     = null === $pending ? '' : $pending->public_id;
		$open        = 0;

		foreach ( $organizations as $organization ) {
			if ( null === self::connection_for_organization( $connections, $account->remote_account_id, $organization->remote_organization_id, $exclude ) ) {
				++$open;
			}
		}

		if ( 0 === $open ) {
			return array( 'error' => 'all_connected' );
		}

		// Several organizations: keep the verified key on one waiting connection and ask which organization to use.
		if ( null === $pending ) {
			$pending = self::waiting_connection( $connections, $account->remote_account_id );

			$spare = null === $pending ? self::disconnected_connection( $connections, $account->remote_account_id, null ) : null;

			if ( null !== $pending && null !== $pending->credential_rotated_at && null !== $pending->credential_envelope_hash ) {
				$envelope = self::credential_envelope();
				$json     = $envelope->encode_for_storage( $envelope->encrypt( $pending->provider, $pending->public_id, $secret ) );
				$pending  = $repository->replace_credential_if_current( $pending->public_id, $pending->credential_rotated_at->format( 'Y-m-d H:i:s' ), $pending->credential_envelope_hash, $json, $envelope->storage_hash( $json ), $account->remote_account_id, $account->to_storage_document(), false );
			} elseif ( null !== $spare ) {
				// Reuse an earlier, cancelled setup row instead of adding another one.
				$envelope = self::credential_envelope();
				$json     = $envelope->encode_for_storage( $envelope->encrypt( $spare->provider, $spare->public_id, $secret ) );
				$pending  = $repository->store_initial_credential( $spare->public_id, $json, $envelope->storage_hash( $json ) );
			} else {
				$pending = self::connection_service()->create_disabled_connection( self::PROVIDER, __( 'Buffer', 'pressqueue' ), $secret, self::AUTHENTICATION_MODE );
			}
		}

		$repository->store_verified_account( $pending->public_id, $account->remote_account_id, $account->to_storage_document() );

		return array(
			'message'    => 'choose_organization',
			'connection' => $pending->public_id,
		);
	}

	/**
	 * Load one organization's channels on a connection and turn the connection on.
	 *
	 * Channels keep their own switches; new ones start off. When the same Buffer account and
	 * organization was connected before and then disconnected, that connection is reused so its
	 * channels and the rules that use them keep working.
	 *
	 * @param string                $secret Opaque provider credential.
	 * @param ProviderAccount       $account Verified account.
	 * @param string                $organization_id Organization to use.
	 * @param ConnectionRecord|null $existing Connection being finished or switched, if any.
	 * @return array<string,string> Redirect result.
	 */
	private static function connect_organization( string $secret, ProviderAccount $account, string $organization_id, ?ConnectionRecord $existing ): array {
		if ( ! $account->has_organization( $organization_id ) ) {
			return array( 'error' => 'organization_missing' );
		}

		$repository  = new ConnectionRepository();
		$connections = $repository->find_all();
		$exclude     = null === $existing ? '' : $existing->public_id;
		$taken       = self::connection_for_organization( $connections, $account->remote_account_id, $organization_id, $exclude );

		if ( null !== $taken ) {
			return array(
				'error'      => 'already_connected',
				'connection' => $taken->public_id,
			);
		}

		$reusable  = null === $existing || null === $existing->remote_organization_id ? self::disconnected_connection( $connections, $account->remote_account_id, $organization_id ) : null;
		$switching = null !== $existing && null !== $existing->remote_organization_id && $existing->remote_organization_id !== $organization_id;
		$service   = self::connection_service();
		$fresh     = false;
		// A connection someone deliberately turned off stays off when it switches organization.
		$turn_on = null === $existing || 'verified' !== $existing->connection_status || $existing->enabled;

		if ( null !== $reusable ) {
			$envelope = self::credential_envelope();
			$json     = $envelope->encode_for_storage( $envelope->encrypt( $reusable->provider, $reusable->public_id, $secret ) );
			$target   = $repository->store_initial_credential( $reusable->public_id, $json, $envelope->storage_hash( $json ) );
		} elseif ( null !== $existing ) {
			$target = $existing;
		} else {
			$target = $service->create_disabled_connection( self::PROVIDER, __( 'Buffer', 'pressqueue' ), $secret, self::AUTHENTICATION_MODE );
			$fresh  = true;
		}

		try {
			$target = $service->sync_channels( $target, $secret, $account, $organization_id, false );
			if ( $turn_on ) {
				$repository->enable( $target->public_id );
			}
		} catch ( \Throwable $throwable ) {
			if ( $fresh || null !== $reusable ) {
				// Leave nothing half set up behind. The next attempt with this account picks the row up again.
				self::quietly_disconnect( $repository, $target->public_id );
			}

			throw $throwable;
		}

		if ( null !== $reusable && null !== $existing ) {
			// The waiting connection handed its key to the earlier connection.
			self::quietly_disconnect( $repository, $existing->public_id );
		}

		if ( null !== $reusable ) {
			$message = 'buffer_reconnected';
		} elseif ( $switching ) {
			$message = 'organization_switched';
		} elseif ( null !== $existing && 'verified' === $existing->connection_status ) {
			$message = 'channels_synced';
		} else {
			$message = 'buffer_connected';
		}

		return array(
			'message'    => $message,
			'connection' => $target->public_id,
		);
	}

	/**
	 * Find an active connection that already uses an organization.
	 *
	 * @param array<int,ConnectionRecord> $connections Connections.
	 * @param string                      $account_id Remote account ID.
	 * @param string                      $organization_id Remote organization ID.
	 * @param string                      $exclude Connection public ID to ignore.
	 * @return ConnectionRecord|null
	 */
	private static function connection_for_organization( array $connections, string $account_id, string $organization_id, string $exclude ): ?ConnectionRecord {
		foreach ( $connections as $connection ) {
			if (
				self::PROVIDER === $connection->provider
				&& 'disconnected' !== $connection->connection_status
				&& $connection->public_id !== $exclude
				&& $connection->remote_account_id === $account_id
				&& $connection->remote_organization_id === $organization_id
			) {
				return $connection;
			}
		}

		return null;
	}

	/**
	 * Find the most recent disconnected connection for an account and organization.
	 *
	 * @param array<int,ConnectionRecord> $connections Connections.
	 * @param string                      $account_id Remote account ID.
	 * @param string|null                 $organization_id Remote organization ID, or null for a setup that never chose one.
	 * @return ConnectionRecord|null
	 */
	private static function disconnected_connection( array $connections, string $account_id, ?string $organization_id ): ?ConnectionRecord {
		$found = null;

		foreach ( $connections as $connection ) {
			if (
				self::PROVIDER === $connection->provider
				&& 'disconnected' === $connection->connection_status
				&& null === $connection->credential_envelope_hash
				&& $connection->remote_account_id === $account_id
				&& $connection->remote_organization_id === $organization_id
				&& ( null === $found || $connection->updated_at > $found->updated_at )
			) {
				$found = $connection;
			}
		}

		return $found;
	}

	/**
	 * Find a saved connection for an account that still waits for an organization choice.
	 *
	 * @param array<int,ConnectionRecord> $connections Connections.
	 * @param string                      $account_id Remote account ID.
	 * @return ConnectionRecord|null
	 */
	private static function waiting_connection( array $connections, string $account_id ): ?ConnectionRecord {
		foreach ( $connections as $connection ) {
			if (
				self::PROVIDER === $connection->provider
				&& 'unverified' === $connection->connection_status
				&& $connection->has_credential
				&& null === $connection->remote_organization_id
				&& $connection->remote_account_id === $account_id
			) {
				return $connection;
			}
		}

		return null;
	}

	/**
	 * Disconnect a connection without masking the original failure.
	 *
	 * @param ConnectionRepository $repository Repository.
	 * @param string               $public_id Connection public ID.
	 */
	private static function quietly_disconnect( ConnectionRepository $repository, string $public_id ): void {
		try {
			$repository->disconnect( $public_id );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
		}
	}

	/**
	 * Fetch the account for a saved key and remember when Buffer rejects it.
	 *
	 * @param ConnectionRecord     $connection Connection.
	 * @param string               $credential Saved credential.
	 * @param ConnectionRepository $repository Repository.
	 * @return ProviderAccount
	 */
	private static function fetch_saved_account( ConnectionRecord $connection, string $credential, ConnectionRepository $repository ): ProviderAccount {
		try {
			return self::connection_service()->fetch_account( $connection, $credential );
		} catch ( ProviderException $exception ) {
			if ( 'invalid_credential' === $exception->classification() ) {
				$repository->mark_credential_invalid( $connection->public_id, $exception->to_redacted_document() );
			}

			throw $exception;
		}
	}

	/**
	 * Map a credential envelope failure to a storage problem.
	 *
	 * @param CredentialEnvelopeException $exception Failure.
	 * @return string|null
	 */
	private static function storage_problem_from( CredentialEnvelopeException $exception ): ?string {
		$message = $exception->getMessage();

		if ( str_contains( $message, 'Sodium' ) ) {
			return 'sodium';
		}

		if ( str_contains( $message, 'secret key material' ) ) {
			return 'salts';
		}

		return null;
	}

	/**
	 * Check whether synchronization may keep a connection enabled.
	 *
	 * @param ConnectionRecord $connection Connection.
	 * @param string           $organization_id Selected organization ID.
	 * @return bool
	 */
	private static function can_preserve_enabled_on_sync( ConnectionRecord $connection, string $organization_id ): bool {
		return $connection->enabled
			&& 'verified' === $connection->connection_status
			&& null !== $connection->remote_organization_id
			&& $connection->remote_organization_id === $organization_id
			&& null !== $connection->verified_identity_hash;
	}

	/**
	 * Load a requested connection.
	 *
	 * @param ConnectionRepository $repository Repository.
	 * @return ConnectionRecord
	 */
	private static function requested_connection( ConnectionRepository $repository ): ConnectionRecord {
		$public_id  = self::post_text( 'connection_public_id' );
		$connection = PublicIds::is_valid( $public_id ) ? $repository->find_by_public_id( $public_id ) : null;

		if ( null === $connection ) {
			throw new ValidationException( 'Connection does not exist.' );
		}

		return $connection;
	}

	/**
	 * Decrypt one connection credential.
	 *
	 * @param ConnectionRecord     $connection Connection.
	 * @param ConnectionRepository $repository Repository.
	 * @return string
	 */
	private static function decrypt_connection_credential( ConnectionRecord $connection, ConnectionRepository $repository ): string {
		$json = $repository->credential_envelope_json( $connection->public_id );

		if ( null === $json || '' === $json ) {
			throw new ValidationException( 'Connection has no saved credential.' );
		}

		$envelope = self::credential_envelope();

		return $envelope->decrypt( $connection->provider, $connection->public_id, $envelope->decode_from_storage( $json ) );
	}

	/**
	 * Return the provider registry.
	 *
	 * @return ProviderRegistry
	 */
	private static function registry(): ProviderRegistry {
		return self::$registry ?? ProviderRegistry::defaults();
	}

	/**
	 * Return the credential envelope service.
	 *
	 * @return CredentialEnvelope
	 */
	private static function credential_envelope(): CredentialEnvelope {
		return self::$credential_envelope ?? new CredentialEnvelope();
	}

	/**
	 * Return a provider connection service.
	 *
	 * @return ProviderConnectionService
	 */
	private static function connection_service(): ProviderConnectionService {
		return new ProviderConnectionService( Database::from_globals(), self::registry(), self::credential_envelope() );
	}

	/**
	 * Assert mutation request requirements.
	 *
	 * @param string $nonce_action Nonce action.
	 */
	private static function mutation_boundary( string $nonce_action ): void {
		AdminPostBoundary::check( $nonce_action, Capabilities::MANAGE_CONNECTIONS, __( 'You are not allowed to manage PressQueue connections.', 'pressqueue' ) );
	}

	/**
	 * Require connection capability.
	 */
	private static function require_capability(): void {
		if ( ! current_user_can( Capabilities::MANAGE_CONNECTIONS ) ) {
			wp_die( esc_html__( 'You are not allowed to manage PressQueue connections.', 'pressqueue' ) );
		}
	}

	/**
	 * Return a POST text field.
	 *
	 * @param string $key Key.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	private static function post_text( string $key, string $fallback = '' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked by caller.
		$value = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';

		return '' === $value ? $fallback : $value;
	}

	/**
	 * Return a POST secret field without text sanitization.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private static function post_secret( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Provider credentials are opaque and validated by the envelope service.
		$value = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';

		if ( ! is_string( $value ) ) {
			throw new ValidationException( 'Credential input is invalid.' );
		}

		return trim( $value );
	}

	/**
	 * Best-effort local string zeroing.
	 *
	 * @param string|null $value Secret-bearing value.
	 */
	private static function memzero( ?string &$value ): void {
		if ( null !== $value && '' !== $value && extension_loaded( 'sodium' ) && function_exists( 'sodium_memzero' ) ) {
			sodium_memzero( $value );
		}
	}
}
