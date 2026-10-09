<?php
/**
 * Connection administration controller.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Provider\ProviderException;
use PressQueue\Provider\ProviderConnectionService;
use PressQueue\Provider\ProviderRegistry;
use PressQueue\Security\Capabilities;
use PressQueue\Security\CredentialEnvelope;
use PressQueue\Security\CredentialEnvelopeException;
use PressQueue\Security\SafeOperatorError;
use PressQueue\Storage\Database;
use PressQueue\Storage\Record\ConnectionRecord;
use PressQueue\Storage\Repository\ChannelRepository;
use PressQueue\Storage\Repository\ConnectionRepository;
use PressQueue\Storage\StaleRecordException;
use PressQueue\Storage\ValidationException;

/**
 * Handles Buffer connection management.
 */
final class ConnectionController {
	public const SLUG = 'pressqueue-connections';

	/**
	 * Test override registry.
	 *
	 * @var ProviderRegistry|null
	 */
	private static ?ProviderRegistry $registry = null;

	/**
	 * Register hooks.
	 */
	public static function register_hooks(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( 'admin_post_pressqueue_create_connection', array( self::class, 'handle_create_connection' ) );
		add_action( 'admin_post_pressqueue_test_connection', array( self::class, 'handle_test_connection' ) );
		add_action( 'admin_post_pressqueue_sync_connection', array( self::class, 'handle_sync_connection' ) );
		add_action( 'admin_post_pressqueue_rotate_connection', array( self::class, 'handle_rotate_connection' ) );
		add_action( 'admin_post_pressqueue_enable_connection', array( self::class, 'handle_enable_connection' ) );
		add_action( 'admin_post_pressqueue_disable_connection', array( self::class, 'handle_disable_connection' ) );
		add_action( 'admin_post_pressqueue_disconnect_connection', array( self::class, 'handle_disconnect_connection' ) );
	}

	/**
	 * Register menu pages.
	 *
	 * @param string $parent_slug Parent slug.
	 */
	public static function register_menu( string $parent_slug ): void {
		add_submenu_page(
			$parent_slug,
			__( 'PressQueue Connections', 'pressqueue' ),
			__( 'Connections', 'pressqueue' ),
			Capabilities::MANAGE_CONNECTIONS,
			self::SLUG,
			array( self::class, 'render_connections_page' )
		);
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
	 * Render connection administration.
	 */
	public static function render_connections_page(): void {
		self::require_capability();
		$connections = new ConnectionRepository();
		$channels    = new ChannelRepository();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'PressQueue Connections', 'pressqueue' ) . '</h1>';
		self::render_notice();
		self::render_create_form();
		self::render_connections_table( $connections->find_all(), $channels );
		echo '</div>';
	}

	/**
	 * Handle creating a disabled Buffer connection.
	 */
	public static function handle_create_connection(): void {
		self::mutation_boundary( 'pressqueue_create_connection' );

		try {
			$secret = self::post_secret( 'api_key' );
			try {
				self::connection_service()->create_disabled_connection(
					'buffer',
					self::post_text( 'label', __( 'Buffer connection', 'pressqueue' ) ),
					$secret,
					'personal_api_key'
				);
			} finally {
				self::memzero( $secret );
				unset( $secret );
			}

			self::redirect( 'connection_created' );
		} catch ( \Throwable $throwable ) {
			self::redirect( 'connection_error', self::safe_error_detail( $throwable ) );
		}
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
			self::redirect( 'credential_verified' );
		} catch ( \Throwable $throwable ) {
			self::redirect( 'connection_error', self::safe_error_detail( $throwable ) );
		}
	}

	/**
	 * Handle organization selection and channel sync.
	 */
	public static function handle_sync_connection(): void {
		self::mutation_boundary( 'pressqueue_sync_connection' );

		try {
			$repository      = new ConnectionRepository();
			$connection      = self::requested_connection( $repository );
			$service         = self::connection_service();
			$credential      = self::decrypt_connection_credential( $connection, $repository );
			$organization_id = self::post_text( 'remote_organization_id' );

			try {
				$account = $service->fetch_account( $connection, $credential );
				$service->sync_channels( $connection, $credential, $account, $organization_id, self::can_preserve_enabled_on_sync( $connection, $organization_id ) );
			} finally {
				self::memzero( $credential );
				unset( $credential );
			}

			self::redirect( 'channels_synced' );
		} catch ( \Throwable $throwable ) {
			self::redirect( 'connection_error', self::safe_error_detail( $throwable ) );
		}
	}

	/**
	 * Handle credential rotation.
	 */
	public static function handle_rotate_connection(): void {
		self::mutation_boundary( 'pressqueue_rotate_connection' );

		try {
			$repository = new ConnectionRepository();
			$connection = self::requested_connection( $repository );
			if ( null === $connection->credential_envelope_hash ) {
				throw new ValidationException( 'Connection has no credential to rotate.' );
			}

			$service = self::connection_service();
			$secret  = self::post_secret( 'api_key' );
			try {
				$account  = $service->fetch_account( $connection, $secret );
				$envelope = new CredentialEnvelope();
				$json     = $envelope->encode_for_storage( $envelope->encrypt( $connection->provider, $connection->public_id, $secret ) );
				$hash     = $envelope->storage_hash( $json );
			} finally {
				self::memzero( $secret );
				unset( $secret );
			}

			$expected               = self::post_text( 'credential_rotated_at' );
			$organization_visible   = null !== $connection->remote_organization_id && $account->has_organization( $connection->remote_organization_id );
			$expected_envelope_hash = $connection->credential_envelope_hash;

			$rotated = $repository->replace_credential_if_current( $connection->public_id, $expected, $expected_envelope_hash, $json, $hash, $account->remote_account_id, $account->to_storage_document(), $organization_visible );

			if ( $organization_visible && null !== $rotated->remote_organization_id ) {
				$credential = self::decrypt_connection_credential( $rotated, $repository );
				try {
					$service->sync_channels( $rotated, $credential, $account, $rotated->remote_organization_id, $connection->enabled );
				} finally {
					self::memzero( $credential );
					unset( $credential );
				}
			}

			self::redirect( 'credential_rotated' );
		} catch ( \Throwable $throwable ) {
			self::redirect( 'connection_error', self::safe_error_detail( $throwable ) );
		}
	}

	/**
	 * Handle enabling a connection.
	 */
	public static function handle_enable_connection(): void {
		self::mutation_boundary( 'pressqueue_enable_connection' );

		try {
			( new ConnectionRepository() )->enable( self::post_text( 'connection_public_id' ) );
			self::redirect( 'connection_enabled' );
		} catch ( \Throwable $throwable ) {
			self::redirect( 'connection_error', self::safe_error_detail( $throwable ) );
		}
	}

	/**
	 * Handle disabling a connection.
	 */
	public static function handle_disable_connection(): void {
		self::mutation_boundary( 'pressqueue_disable_connection' );

		try {
			( new ConnectionRepository() )->disable( self::post_text( 'connection_public_id' ) );
			self::redirect( 'connection_disabled' );
		} catch ( \Throwable $throwable ) {
			self::redirect( 'connection_error', self::safe_error_detail( $throwable ) );
		}
	}

	/**
	 * Handle disconnecting a credential.
	 */
	public static function handle_disconnect_connection(): void {
		self::mutation_boundary( 'pressqueue_disconnect_connection' );

		try {
			( new ConnectionRepository() )->disconnect( self::post_text( 'connection_public_id' ) );
			self::redirect( 'connection_disconnected' );
		} catch ( \Throwable $throwable ) {
			self::redirect( 'connection_error', self::safe_error_detail( $throwable ) );
		}
	}

	/**
	 * Render create form.
	 */
	private static function render_create_form(): void {
		echo '<h2>' . esc_html__( 'Add Buffer Connection', 'pressqueue' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'pressqueue_create_connection' );
		echo '<input type="hidden" name="action" value="pressqueue_create_connection" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="pressqueue-connection-label">' . esc_html__( 'Label', 'pressqueue' ) . '</label></th><td><input id="pressqueue-connection-label" name="label" type="text" class="regular-text" required /></td></tr>';
		echo '<tr><th scope="row"><label for="pressqueue-buffer-key">' . esc_html__( 'Buffer API key', 'pressqueue' ) . '</label></th><td><input id="pressqueue-buffer-key" name="api_key" type="password" class="regular-text" autocomplete="off" required /></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Save Disabled Connection', 'pressqueue' ) );
		echo '</form>';
	}

	/**
	 * Render connection table.
	 *
	 * @param array<int,ConnectionRecord> $connections Connections.
	 * @param ChannelRepository           $channels Channel repository.
	 */
	private static function render_connections_table( array $connections, ChannelRepository $channels ): void {
		echo '<h2>' . esc_html__( 'Connections', 'pressqueue' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Connection', 'pressqueue' ), __( 'Status', 'pressqueue' ), __( 'Account', 'pressqueue' ), __( 'Organization', 'pressqueue' ), __( 'Actions', 'pressqueue' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( array() === $connections ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No connections have been created.', 'pressqueue' ) . '</td></tr>';
		}

		foreach ( $connections as $connection ) {
			echo '<tr>';
			echo '<td><strong>' . esc_html( $connection->label ) . '</strong><br /><code>' . esc_html( $connection->public_id ) . '</code><br />' . esc_html( $connection->provider ) . '</td>';
			echo '<td>' . esc_html( $connection->connection_status ) . '<br />' . esc_html( $connection->enabled ? __( 'Enabled', 'pressqueue' ) : __( 'Disabled', 'pressqueue' ) ) . '</td>';
			echo '<td>' . esc_html( $connection->account['display_label'] ?? '' ) . '<br /><code>' . esc_html( $connection->remote_account_id ?? '' ) . '</code></td>';
			echo '<td>' . esc_html( self::organization_label( $connection ) ) . '<br /><code>' . esc_html( $connection->remote_organization_id ?? '' ) . '</code></td>';
			echo '<td>';
			self::render_connection_actions( $connection );
			echo '</td>';
			echo '</tr>';
			echo '<tr><td colspan="5">';
			self::render_channel_table( $channels->find_by_connection( $connection->public_id ) );
			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Render connection action forms.
	 *
	 * @param ConnectionRecord $connection Connection.
	 */
	private static function render_connection_actions( ConnectionRecord $connection ): void {
		self::button_form( 'pressqueue_test_connection', 'pressqueue_test_connection', __( 'Test Credential', 'pressqueue' ), $connection );
		self::render_sync_form( $connection );
		self::render_rotation_form( $connection );

		if ( $connection->enabled ) {
			self::button_form( 'pressqueue_disable_connection', 'pressqueue_disable_connection', __( 'Disable Connection', 'pressqueue' ), $connection );
		} else {
			self::button_form( 'pressqueue_enable_connection', 'pressqueue_enable_connection', __( 'Enable Connection', 'pressqueue' ), $connection );
		}

		self::button_form( 'pressqueue_disconnect_connection', 'pressqueue_disconnect_connection', __( 'Disconnect Credential', 'pressqueue' ), $connection );
	}

	/**
	 * Render organization sync form.
	 *
	 * @param ConnectionRecord $connection Connection.
	 */
	private static function render_sync_form( ConnectionRecord $connection ): void {
		$organizations = self::organizations( $connection );

		if ( array() === $organizations ) {
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:.25em 0;">';
		wp_nonce_field( 'pressqueue_sync_connection' );
		echo '<input type="hidden" name="action" value="pressqueue_sync_connection" />';
		echo '<input type="hidden" name="connection_public_id" value="' . esc_attr( $connection->public_id ) . '" />';
		echo '<select name="remote_organization_id">';
		foreach ( $organizations as $organization ) {
			$id = (string) ( $organization['remote_organization_id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}
			echo '<option value="' . esc_attr( $id ) . '" ' . selected( $connection->remote_organization_id, $id, false ) . '>' . esc_html( (string) ( $organization['display_label'] ?? $id ) ) . '</option>';
		}
		echo '</select> ';
		submit_button( __( 'Sync Channels', 'pressqueue' ), 'secondary small', 'submit', false );
		echo '</form>';
	}

	/**
	 * Render rotation form.
	 *
	 * @param ConnectionRecord $connection Connection.
	 */
	private static function render_rotation_form( ConnectionRecord $connection ): void {
		if ( null === $connection->credential_envelope_hash ) {
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:.25em 0;">';
		wp_nonce_field( 'pressqueue_rotate_connection' );
		echo '<input type="hidden" name="action" value="pressqueue_rotate_connection" />';
		echo '<input type="hidden" name="connection_public_id" value="' . esc_attr( $connection->public_id ) . '" />';
		echo '<input type="hidden" name="credential_rotated_at" value="' . esc_attr( null === $connection->credential_rotated_at ? '' : $connection->credential_rotated_at->format( 'Y-m-d H:i:s' ) ) . '" />';
		echo '<input type="password" name="api_key" autocomplete="off" class="regular-text" placeholder="' . esc_attr__( 'New Buffer API key', 'pressqueue' ) . '" required /> ';
		submit_button( __( 'Rotate Credential', 'pressqueue' ), 'secondary small', 'submit', false );
		echo '</form>';
	}

	/**
	 * Render a simple button form.
	 *
	 * @param string           $action Action.
	 * @param string           $nonce Nonce action.
	 * @param string           $label Button label.
	 * @param ConnectionRecord $connection Connection.
	 */
	private static function button_form( string $action, string $nonce, string $label, ConnectionRecord $connection ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:.25em .25em .25em 0;">';
		wp_nonce_field( $nonce );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		echo '<input type="hidden" name="connection_public_id" value="' . esc_attr( $connection->public_id ) . '" />';
		submit_button( $label, 'secondary small', 'submit', false );
		echo '</form>';
	}

	/**
	 * Render channels.
	 *
	 * @param array<int,\PressQueue\Storage\Record\ChannelRecord> $channels Channels.
	 */
	private static function render_channel_table( array $channels ): void {
		if ( array() === $channels ) {
			echo '<p>' . esc_html__( 'No channels have been synchronized.', 'pressqueue' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Channel', 'pressqueue' ), __( 'Service', 'pressqueue' ), __( 'Availability', 'pressqueue' ), __( 'Enabled', 'pressqueue' ), __( 'Actions', 'pressqueue' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $channels as $channel ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $channel->display['display_name'] ?? $channel->public_id ) ) . '<br /><code>' . esc_html( $channel->remote_channel_id ) . '</code></td>';
			echo '<td>' . esc_html( $channel->service ) . '</td>';
			echo '<td>' . esc_html( $channel->availability ) . '</td>';
			echo '<td>' . esc_html( $channel->enabled ? __( 'Enabled', 'pressqueue' ) : __( 'Disabled', 'pressqueue' ) ) . '</td>';
			echo '<td>';
			ChannelController::render_channel_action( $channel );
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
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
	 * Return organizations stored on a connection.
	 *
	 * @param ConnectionRecord $connection Connection.
	 * @return array<int,array<string,mixed>>
	 */
	private static function organizations( ConnectionRecord $connection ): array {
		$organizations = $connection->account['organizations'] ?? array();

		return is_array( $organizations ) ? array_values( array_filter( $organizations, 'is_array' ) ) : array();
	}

	/**
	 * Return selected organization label.
	 *
	 * @param ConnectionRecord $connection Connection.
	 * @return string
	 */
	private static function organization_label( ConnectionRecord $connection ): string {
		foreach ( self::organizations( $connection ) as $organization ) {
			if ( ( $organization['remote_organization_id'] ?? null ) === $connection->remote_organization_id ) {
				return (string) ( $organization['display_label'] ?? $connection->remote_organization_id );
			}
		}

		return '';
	}

	/**
	 * Load a requested connection.
	 *
	 * @param ConnectionRepository $repository Repository.
	 * @return ConnectionRecord
	 */
	private static function requested_connection( ConnectionRepository $repository ): ConnectionRecord {
		$connection = $repository->find_by_public_id( self::post_text( 'connection_public_id' ) );

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

		$envelope = new CredentialEnvelope();

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
	 * Return a provider connection service.
	 *
	 * @return ProviderConnectionService
	 */
	private static function connection_service(): ProviderConnectionService {
		return new ProviderConnectionService( Database::from_globals(), self::registry() );
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
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : $fallback;
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
	 * Redirect to the connections page.
	 *
	 * @param string      $message Message key.
	 * @param string|null $detail Optional detail.
	 */
	private static function redirect( string $message, ?string $detail = null ): void {
		$args = array(
			'page'               => self::SLUG,
			'pressqueue_message' => $message,
		);

		if ( null !== $detail && '' !== $detail ) {
			$args['pressqueue_detail'] = $detail;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Render notice from request.
	 */
	private static function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Notice query args are display-only.
		$message  = isset( $_GET['pressqueue_message'] ) ? sanitize_key( wp_unslash( $_GET['pressqueue_message'] ) ) : '';
		$messages = array(
			'channel_disabled'        => __( 'Channel disabled.', 'pressqueue' ),
			'channel_enabled'         => __( 'Channel enabled.', 'pressqueue' ),
			'channels_synced'         => __( 'Channels synchronized.', 'pressqueue' ),
			'connection_created'      => __( 'Connection saved disabled.', 'pressqueue' ),
			'connection_disabled'     => __( 'Connection disabled.', 'pressqueue' ),
			'connection_disconnected' => __( 'Credential disconnected.', 'pressqueue' ),
			'connection_enabled'      => __( 'Connection enabled.', 'pressqueue' ),
			'connection_error'        => __( 'Connection change failed.', 'pressqueue' ),
			'credential_rotated'      => __( 'Credential rotated.', 'pressqueue' ),
			'credential_verified'     => __( 'Credential verified.', 'pressqueue' ),
		);

		if ( ! isset( $messages[ $message ] ) ) {
			return;
		}

		$type = str_contains( $message, 'error' ) ? 'error' : 'success';
		echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . esc_html( $messages[ $message ] );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Error text is sanitized for display only.
		if ( isset( $_GET['pressqueue_detail'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Error text is sanitized for display only.
			echo ' ' . esc_html( SafeOperatorError::notice_detail( sanitize_text_field( wp_unslash( $_GET['pressqueue_detail'] ) ) ) );
		}

		echo '</p></div>';
	}

	/**
	 * Return a redacted exception detail.
	 *
	 * @param \Throwable $throwable Throwable.
	 * @return string
	 */
	private static function safe_error_detail( \Throwable $throwable ): string {
		if ( $throwable instanceof ProviderException ) {
			return $throwable->classification();
		}

		if ( $throwable instanceof CredentialEnvelopeException || $throwable instanceof StaleRecordException || $throwable instanceof ValidationException ) {
			return SafeOperatorError::message( $throwable, 'redacted_error' );
		}

		return SafeOperatorError::redirect_detail( $throwable );
	}

	/**
	 * Best-effort local string zeroing.
	 *
	 * @param string|null $value Secret-bearing value.
	 */
	private static function memzero( ?string &$value ): void {
		if ( null !== $value && extension_loaded( 'sodium' ) && function_exists( 'sodium_memzero' ) ) {
			sodium_memzero( $value );
		}
	}
}
