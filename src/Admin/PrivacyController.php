<?php
/**
 * Privacy administration.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Privacy\ExportService;
use PressQueue\Privacy\PrivacySettings;
use PressQueue\Privacy\RetentionService;
use PressQueue\Privacy\RetentionStateStore;
use PressQueue\Runtime\RuntimeDisabledLatch;
use PressQueue\Security\Capabilities;
use PressQueue\Security\SafeOperatorError;
use PressQueue\Storage\Database;
use PressQueue\Storage\SchemaStatus;
use Throwable;

/**
 * Renders and mutates privacy, export, retention, and uninstall settings.
 */
final class PrivacyController {
	private const SLUG = 'pressqueue-privacy';

	/**
	 * Register hooks.
	 */
	public static function register_hooks(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( 'admin_post_pressqueue_save_privacy_settings', array( self::class, 'handle_save_settings' ) );
		add_action( 'admin_post_pressqueue_export_data', array( self::class, 'handle_export' ) );
		add_action( 'admin_post_pressqueue_run_retention', array( self::class, 'handle_run_retention' ) );
	}

	/**
	 * Register privacy menu page.
	 *
	 * @param string $parent_slug Parent slug.
	 */
	public static function register_menu( string $parent_slug ): void {
		add_submenu_page(
			$parent_slug,
			__( 'PressQueue Privacy', 'pressqueue' ),
			__( 'Privacy', 'pressqueue' ),
			Capabilities::MANAGE_SETTINGS,
			self::SLUG,
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Render the privacy page.
	 */
	public static function render_page(): void {
		self::require_capability( Capabilities::MANAGE_SETTINGS, __( 'You are not allowed to manage PressQueue privacy settings.', 'pressqueue' ) );

		try {
			$settings = ( new PrivacySettings() )->read();
			$state    = ( new RetentionStateStore() )->read();
		} catch ( Throwable $throwable ) {
			$settings = PrivacySettings::defaults();
			$state    = RetentionStateStore::defaults();
			echo '<div class="notice notice-error"><p>' . esc_html( SafeOperatorError::message( $throwable, __( 'Stored PressQueue privacy state is invalid. Runtime delivery remains disabled until it is repaired.', 'pressqueue' ) ) ) . '</p></div>';
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'PressQueue Privacy', 'pressqueue' ) . '</h1>';
		self::render_notice();
		self::render_runtime_state();
		self::render_settings_form( $settings );
		self::render_export_form();
		self::render_retention_form( $state );
		echo '</div>';
	}

	/**
	 * Handle privacy settings save.
	 */
	public static function handle_save_settings(): void {
		AdminPostBoundary::check(
			'pressqueue_save_privacy_settings',
			Capabilities::MANAGE_SETTINGS,
			__( 'You are not allowed to manage PressQueue privacy settings.', 'pressqueue' )
		);

		try {
			$repository = new PrivacySettings();
			$current    = self::current_settings_for_save( $repository );
			$next       = array(
				'delete_data_on_uninstall' => isset( $_POST['delete_data_on_uninstall'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Shared boundary checked above.
				'retention_days'           => self::post_text( 'retention_days', (string) PrivacySettings::DEFAULT_RETENTION_DAYS ),
				'retention_enabled'        => isset( $_POST['retention_enabled'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Shared boundary checked above.
				'schema_version'           => 1,
			);

			if ( ! $current['retention_enabled'] && $next['retention_enabled'] ) {
				AdminPostBoundary::require_exact_confirmation( 'confirm_retention_effect', PrivacySettings::RETENTION_CONFIRMATION );
			}

			if ( ! $current['delete_data_on_uninstall'] && $next['delete_data_on_uninstall'] ) {
				AdminPostBoundary::require_exact_confirmation( 'confirm_uninstall_effect', PrivacySettings::UNINSTALL_CONFIRMATION );
			}

			$repository->save( $next );
			self::maybe_reopen_runtime_after_repair();
			self::redirect( 'privacy_saved' );
		} catch ( Throwable $throwable ) {
			self::redirect( 'privacy_error', SafeOperatorError::redirect_detail( $throwable ) );
		}
	}

	/**
	 * Handle full-site export download.
	 */
	public static function handle_export(): void {
		AdminPostBoundary::check(
			'pressqueue_export_data',
			Capabilities::EXPORT_DATA,
			__( 'You are not allowed to export PressQueue data.', 'pressqueue' )
		);

		try {
			$include_rendered_payloads = isset( $_POST['include_rendered_payloads'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Shared boundary checked above.
			if ( $include_rendered_payloads ) {
				AdminPostBoundary::require_exact_confirmation( 'confirm_export_effect', PrivacySettings::EXPORT_CONFIRMATION );
			}

			( new ExportService() )->stream_download( $include_rendered_payloads, self::post_text( 'export_cursor' ) );
			exit;
		} catch ( Throwable $throwable ) {
			wp_die( esc_html( SafeOperatorError::message( $throwable, __( 'PressQueue data export failed.', 'pressqueue' ) ) ) );
		}
	}

	/**
	 * Run one bounded retention chunk.
	 */
	public static function handle_run_retention(): void {
		AdminPostBoundary::check(
			'pressqueue_run_retention',
			Capabilities::MANAGE_SETTINGS,
			__( 'You are not allowed to run PressQueue retention.', 'pressqueue' )
		);

		try {
			$result = ( new RetentionService() )->run();
			self::redirect( 'retention_' . sanitize_key( (string) ( $result['status'] ?? 'complete' ) ) );
		} catch ( Throwable $throwable ) {
			self::redirect( 'privacy_error', SafeOperatorError::redirect_detail( $throwable ) );
		}
	}

	/**
	 * Render runtime state.
	 */
	private static function render_runtime_state(): void {
		echo '<h2>' . esc_html__( 'Runtime State', 'pressqueue' ) . '</h2>';
		echo '<p><code>' . esc_html( RuntimeDisabledLatch::is_open() ? 'open' : 'closed' ) . '</code></p>';
	}

	/**
	 * Return current settings for transition checks, tolerating malformed stored settings.
	 *
	 * @param PrivacySettings $repository Repository.
	 * @return array<string,mixed>
	 */
	private static function current_settings_for_save( PrivacySettings $repository ): array {
		try {
			return $repository->read();
		} catch ( Throwable $throwable ) {
			unset( $throwable );

			return PrivacySettings::defaults();
		}
	}

	/**
	 * Reopen runtime after an administrator repairs privacy state.
	 */
	private static function maybe_reopen_runtime_after_repair(): void {
		RuntimeDisabledLatch::ensure_default_closed();
		RetentionStateStore::ensure_defaults_for_current_site();

		if ( ! Database::has_global_database() ) {
			RuntimeDisabledLatch::close();
			return;
		}

		$status = new SchemaStatus();
		if ( ! $status->is_current() || $status->has_active_migration_lock() || $status->has_migration_failed() ) {
			RuntimeDisabledLatch::close();
			return;
		}

		( new PrivacySettings() )->read();
		( new RetentionStateStore() )->read();
		RuntimeDisabledLatch::open();
	}

	/**
	 * Render settings form.
	 *
	 * @param array<string,mixed> $settings Settings.
	 */
	private static function render_settings_form( array $settings ): void {
		echo '<h2>' . esc_html__( 'Retention and Uninstall', 'pressqueue' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'pressqueue_save_privacy_settings' );
		echo '<input type="hidden" name="action" value="pressqueue_save_privacy_settings" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="pressqueue-retention-enabled">' . esc_html__( 'Retention', 'pressqueue' ) . '</label></th><td><label><input id="pressqueue-retention-enabled" type="checkbox" name="retention_enabled" value="1" ' . checked( (bool) $settings['retention_enabled'], true, false ) . ' /> ' . esc_html__( 'Delete or redact eligible resolved local records after the retention period.', 'pressqueue' ) . '</label></td></tr>';
		echo '<tr><th scope="row"><label for="pressqueue-retention-days">' . esc_html__( 'Retention days', 'pressqueue' ) . '</label></th><td><input id="pressqueue-retention-days" name="retention_days" type="number" min="' . esc_attr( (string) PrivacySettings::MIN_RETENTION_DAYS ) . '" max="' . esc_attr( (string) PrivacySettings::MAX_RETENTION_DAYS ) . '" value="' . esc_attr( (string) $settings['retention_days'] ) . '" /></td></tr>';
		echo '<tr><th scope="row"><label for="pressqueue-delete-data-on-uninstall">' . esc_html__( 'Uninstall deletion', 'pressqueue' ) . '</label></th><td><label><input id="pressqueue-delete-data-on-uninstall" type="checkbox" name="delete_data_on_uninstall" value="1" ' . checked( (bool) $settings['delete_data_on_uninstall'], true, false ) . ' /> ' . esc_html__( 'Delete local plugin data when PressQueue is uninstalled.', 'pressqueue' ) . '</label></td></tr>';
		echo '<tr><th scope="row"><label for="pressqueue-confirm-retention-effect">' . esc_html__( 'Retention confirmation', 'pressqueue' ) . '</label></th><td><p><code>' . esc_html( PrivacySettings::RETENTION_CONFIRMATION ) . '</code></p><input id="pressqueue-confirm-retention-effect" name="confirm_retention_effect" type="text" class="large-text" /></td></tr>';
		echo '<tr><th scope="row"><label for="pressqueue-confirm-uninstall-effect">' . esc_html__( 'Uninstall confirmation', 'pressqueue' ) . '</label></th><td><p><code>' . esc_html( PrivacySettings::UNINSTALL_CONFIRMATION ) . '</code></p><input id="pressqueue-confirm-uninstall-effect" name="confirm_uninstall_effect" type="text" class="large-text" /></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Save Privacy Settings', 'pressqueue' ) );
		echo '</form>';
	}

	/**
	 * Render export form.
	 */
	private static function render_export_form(): void {
		echo '<h2>' . esc_html__( 'Export', 'pressqueue' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'pressqueue_export_data' );
		echo '<input type="hidden" name="action" value="pressqueue_export_data" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="pressqueue-export-cursor">' . esc_html__( 'Cursor', 'pressqueue' ) . '</label></th><td><input id="pressqueue-export-cursor" name="export_cursor" type="text" class="large-text" /></td></tr>';
		echo '<tr><th scope="row"><label for="pressqueue-include-rendered-payloads">' . esc_html__( 'Rendered payloads', 'pressqueue' ) . '</label></th><td><label><input id="pressqueue-include-rendered-payloads" name="include_rendered_payloads" type="checkbox" value="1" /> ' . esc_html__( 'Include rendered social text and media URLs.', 'pressqueue' ) . '</label></td></tr>';
		echo '<tr><th scope="row"><label for="pressqueue-confirm-export-effect">' . esc_html__( 'Export confirmation', 'pressqueue' ) . '</label></th><td><p><code>' . esc_html( PrivacySettings::EXPORT_CONFIRMATION ) . '</code></p><input id="pressqueue-confirm-export-effect" name="confirm_export_effect" type="text" class="large-text" /></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Download Export', 'pressqueue' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * Render retention action form.
	 *
	 * @param array<string,mixed> $state Retention state.
	 */
	private static function render_retention_form( array $state ): void {
		echo '<h2>' . esc_html__( 'Retention Run', 'pressqueue' ) . '</h2>';
		echo '<pre>' . esc_html( wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</pre>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'pressqueue_run_retention' );
		echo '<input type="hidden" name="action" value="pressqueue_run_retention" />';
		submit_button( __( 'Run Retention Chunk', 'pressqueue' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * Render notice from request.
	 */
	private static function render_notice(): void {
		$message  = self::query_text( 'pressqueue_message', 64 );
		$messages = array(
			'privacy_error'                => __( 'Privacy operation failed.', 'pressqueue' ),
			'privacy_saved'                => __( 'Privacy settings saved.', 'pressqueue' ),
			'retention_already_running'    => __( 'Retention is already running.', 'pressqueue' ),
			'retention_complete'           => __( 'Retention completed.', 'pressqueue' ),
			'retention_partial'            => __( 'Retention processed one chunk.', 'pressqueue' ),
			'retention_retention_disabled' => __( 'Retention is disabled.', 'pressqueue' ),
			'retention_runtime_disabled'   => __( 'Retention did not run because runtime delivery is disabled.', 'pressqueue' ),
			'retention_storage_not_ready'  => __( 'Retention did not run because storage is not ready.', 'pressqueue' ),
		);

		if ( ! isset( $messages[ $message ] ) ) {
			return;
		}

		$type = str_contains( $message, 'error' ) ? 'error' : 'success';
		echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . esc_html( $messages[ $message ] );
		$detail = SafeOperatorError::notice_detail( self::query_text( 'pressqueue_detail', 191 ) );
		if ( '' !== $detail ) {
			echo ' ' . esc_html( $detail );
		}
		echo '</p></div>';
	}

	/**
	 * Redirect to privacy page.
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
	 * Return POST text.
	 *
	 * @param string $key Key.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	private static function post_text( string $key, string $fallback = '' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Shared boundary checked by callers.
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : $fallback;
	}

	/**
	 * Return bounded query text.
	 *
	 * @param string $key Key.
	 * @param int    $maximum Maximum bytes.
	 * @return string
	 */
	private static function query_text( string $key, int $maximum ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice query args.
		$value = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';

		return strlen( $value ) > $maximum ? substr( $value, 0, $maximum ) : $value;
	}

	/**
	 * Require capability for a page render.
	 *
	 * @param string $capability Capability.
	 * @param string $message Denial message.
	 */
	private static function require_capability( string $capability, string $message ): void {
		if ( function_exists( 'current_user_can' ) && ! current_user_can( $capability ) ) {
			wp_die( esc_html( $message ) );
		}
	}
}
