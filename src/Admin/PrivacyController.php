<?php
/**
 * Data and privacy settings tab.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use DomainException;
use PressQueue\Privacy\ExportCursor;
use PressQueue\Privacy\ExportService;
use PressQueue\Privacy\PrivacySettings;
use PressQueue\Privacy\RetentionService;
use PressQueue\Privacy\RetentionStateStore;
use PressQueue\Runtime\RuntimeDisabledLatch;
use PressQueue\Security\Capabilities;
use PressQueue\Security\SafeOperatorError;
use PressQueue\Storage\Database;
use PressQueue\Storage\SchemaStatus;
use PressQueue\Storage\ValidationException;
use Throwable;

/**
 * Renders and saves history cleanup, uninstall deletion and the data export.
 *
 * The screen is the second tab of Settings. Turning on cleanup or uninstall
 * deletion stays a deliberate, explicit opt-in: an acknowledgment checkbox
 * replaces the old typed sentence, and the old typed sentence still works.
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
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ), 20 );
	}

	/**
	 * Register privacy menu page.
	 *
	 * @param string $parent_slug Parent slug.
	 */
	public static function register_menu( string $parent_slug ): void {
		add_submenu_page(
			$parent_slug,
			__( 'Data and privacy', 'pressqueue' ),
			__( 'Data and privacy', 'pressqueue' ),
			Capabilities::MANAGE_SETTINGS,
			self::SLUG,
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Enqueue the tab's own styles.
	 */
	public static function enqueue_assets(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing query arg.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::SLUG !== $page || ! defined( 'PRESSQUEUE_FILE' ) || ! function_exists( 'wp_enqueue_style' ) ) {
			return;
		}

		wp_enqueue_style( 'pressqueue-privacy', plugins_url( 'assets/admin/privacy.css', PRESSQUEUE_FILE ), array( 'pressqueue-admin' ), Ui::asset_version( 'assets/admin/privacy.css' ) );
	}

	/**
	 * Render the Data and privacy tab.
	 */
	public static function render_page(): void {
		self::require_capability( Capabilities::MANAGE_SETTINGS, __( 'You are not allowed to manage PressQueue privacy settings.', 'pressqueue' ) );

		$settings_valid = true;
		try {
			$settings = ( new PrivacySettings() )->read();
			$state    = ( new RetentionStateStore() )->read();
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			$settings       = PrivacySettings::defaults();
			$state          = RetentionStateStore::defaults();
			$settings_valid = false;
		}

		Ui::page_start( __( 'Settings', 'pressqueue' ) );
		SettingsController::render_tabs( 'privacy' );
		self::render_notice();
		self::render_runtime_state( $settings_valid );

		echo '<div class="pq-stack pq-privacy">';
		self::render_retention_card( $settings, $state );
		self::render_uninstall_card( $settings );
		if ( current_user_can( Capabilities::EXPORT_DATA ) ) {
			self::render_export_card();
		}
		echo '</div>';

		Ui::page_end();
	}

	/**
	 * Handle privacy settings save.
	 *
	 * A form may send privacy_section=retention or privacy_section=uninstall to
	 * change only its own fields. Without it, every field is read, as before.
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
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Shared boundary checked above; fields are sanitized one by one.
			$next = self::settings_from_request( $current, self::post_text( 'privacy_section' ), wp_unslash( $_POST ) );

			if ( ! $current['retention_enabled'] && $next['retention_enabled'] ) {
				self::require_acknowledgment( 'acknowledge_retention_effect', 'confirm_retention_effect', PrivacySettings::RETENTION_CONFIRMATION, 'retention_ack' );
			}

			if ( ! $current['delete_data_on_uninstall'] && $next['delete_data_on_uninstall'] ) {
				self::require_acknowledgment( 'acknowledge_uninstall_effect', 'confirm_uninstall_effect', PrivacySettings::UNINSTALL_CONFIRMATION, 'uninstall_ack' );
			}

			$repository->save( $next );
			self::maybe_reopen_runtime_after_repair();
			self::redirect( 'privacy_saved' );
		} catch ( Throwable $throwable ) {
			self::redirect_error( $throwable );
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
				self::require_acknowledgment( 'acknowledge_export_effect', 'confirm_export_effect', PrivacySettings::EXPORT_CONFIRMATION, 'export_ack' );
			}

			$cursor = trim( self::post_text( 'export_cursor' ) );
			if ( '' !== $cursor ) {
				try {
					ExportCursor::from_string( $cursor );
				} catch ( Throwable $throwable ) {
					unset( $throwable );
					throw new DomainException( 'export_cursor' );
				}
			}

			( new ExportService() )->stream_download( $include_rendered_payloads, $cursor );
			exit;
		} catch ( Throwable $throwable ) {
			if ( headers_sent() || ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
				wp_die( esc_html( self::error_messages()[ self::error_code( $throwable ) ] ?? __( 'PressQueue data export failed.', 'pressqueue' ) ) );
			}

			self::redirect_error( $throwable );
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
			self::redirect_error( $throwable );
		}
	}

	/**
	 * Build the next settings document from a request.
	 *
	 * @param array<string,mixed> $current Current settings.
	 * @param string              $section Section being saved: retention, uninstall, or empty for all.
	 * @param mixed               $post Unslashed POST data.
	 * @return array<string,mixed>
	 */
	private static function settings_from_request( array $current, string $section, $post ): array {
		$post = is_array( $post ) ? $post : array();
		$next = array(
			'delete_data_on_uninstall' => (bool) $current['delete_data_on_uninstall'],
			'retention_days'           => $current['retention_days'],
			'retention_enabled'        => (bool) $current['retention_enabled'],
			'schema_version'           => 1,
		);

		if ( '' === $section || 'retention' === $section ) {
			$days                      = isset( $post['retention_days'] ) && is_string( $post['retention_days'] ) ? sanitize_text_field( $post['retention_days'] ) : (string) PrivacySettings::DEFAULT_RETENTION_DAYS;
			$next['retention_days']    = $days;
			$next['retention_enabled'] = isset( $post['retention_enabled'] );
		}

		if ( '' === $section || 'uninstall' === $section ) {
			$next['delete_data_on_uninstall'] = isset( $post['delete_data_on_uninstall'] );
		}

		return $next;
	}

	/**
	 * Require a ticked acknowledgment box, or the older exact confirmation sentence.
	 *
	 * @param string $checkbox Acknowledgment checkbox field.
	 * @param string $text_field Older typed confirmation field.
	 * @param string $sentence Exact confirmation sentence.
	 * @param string $error_code Error code when neither is present.
	 */
	private static function require_acknowledgment( string $checkbox, string $text_field, string $sentence, string $error_code ): void {
		if ( '1' === self::post_text( $checkbox ) ) {
			return;
		}

		try {
			AdminPostBoundary::require_exact_confirmation( $text_field, $sentence );
		} catch ( ValidationException $exception ) {
			unset( $exception );
			throw new DomainException( esc_html( $error_code ) );
		}
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

	/*
	 * ---------------------------------------------------------------------
	 * Rendering
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Render the runtime status line.
	 *
	 * @param bool $settings_valid Whether stored privacy state could be read.
	 */
	private static function render_runtime_state( bool $settings_valid ): void {
		$status = self::runtime_status( $settings_valid );

		echo '<div class="pq-callout pq-callout--' . esc_attr( $status['tone'] ) . ' pq-privacy-status" role="status">';
		echo '<p><span class="' . ( 'ok' === $status['tone'] ? 'pq-yes' : 'pq-no' ) . '" aria-hidden="true">' . ( 'ok' === $status['tone'] ? '&#x2713;' : '&#x2715;' ) . '</span> <strong>' . esc_html( $status['title'] ) . '</strong>';
		if ( '' !== $status['text'] ) {
			echo ' ' . esc_html( $status['text'] );
		}
		echo '</p></div>';
	}

	/**
	 * Explain the runtime state in words.
	 *
	 * @param bool $settings_valid Whether stored privacy state could be read.
	 * @return array{tone:string,title:string,text:string}
	 */
	private static function runtime_status( bool $settings_valid ): array {
		if ( RuntimeDisabledLatch::is_open() && $settings_valid ) {
			return array(
				'text'  => __( 'Posts are checked as they are published, and waiting shares go out in the background.', 'pressqueue' ),
				'title' => __( 'Background sharing is running normally.', 'pressqueue' ),
				'tone'  => 'ok',
			);
		}

		$title = __( 'Background sharing is stopped.', 'pressqueue' );

		if ( ! $settings_valid ) {
			$text = __( 'The saved data and privacy settings are damaged. Check the settings below and click Save in each section to repair them.', 'pressqueue' );
		} elseif ( ! Database::has_global_database() ) {
			$text = __( 'PressQueue cannot reach the WordPress database. Reload this page in a minute.', 'pressqueue' );
		} else {
			try {
				$schema = new SchemaStatus();
				if ( $schema->has_migration_failed() ) {
					$text = __( 'A database update did not finish. Deactivate PressQueue and activate it again to retry the update.', 'pressqueue' );
				} elseif ( $schema->has_active_migration_lock() ) {
					$text = __( 'PressQueue is updating its database. This usually takes a minute. Reload this page to check again.', 'pressqueue' );
				} elseif ( ! $schema->is_current() ) {
					$text = __( 'PressQueue needs to update its database first. Reload this page, or visit any admin page, to start the update.', 'pressqueue' );
				} else {
					$text = __( 'Click Save in Keep history below. PressQueue checks its settings and starts again when everything is in order.', 'pressqueue' );
				}
			} catch ( Throwable $throwable ) {
				unset( $throwable );
				$text = __( 'PressQueue could not read its database status. Reload this page in a minute.', 'pressqueue' );
			}
		}

		return array(
			'text'  => $text . ' ' . __( 'Nothing is lost: shares wait until it runs again.', 'pressqueue' ),
			'title' => $title,
			'tone'  => 'bad',
		);
	}

	/**
	 * Render the Keep history card.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @param array<string,mixed> $state Retention state.
	 */
	private static function render_retention_card( array $settings, array $state ): void {
		$enabled = (bool) $settings['retention_enabled'];

		echo '<div class="pq-card pq-privacy-retention">';
		Ui::form_start( 'pressqueue_save_privacy_settings', 'pressqueue_save_privacy_settings', array( 'privacy_section' => 'retention' ) );
		echo '<div class="pq-section">';
		echo '<h2>' . esc_html__( 'Keep history', 'pressqueue' ) . '</h2>';
		echo '<p class="pq-sub">' . esc_html__( 'PressQueue keeps a record of every post it checks and every share it creates, so Activity can explain what happened. You can have old records removed automatically.', 'pressqueue' ) . '</p>';

		echo '<label class="pq-privacy-switch"><input type="checkbox" id="pq-retention-enabled" name="retention_enabled" value="1" ' . checked( $enabled, true, false ) . ' /> <span>' . esc_html__( 'Remove old history automatically', 'pressqueue' ) . '</span></label>';

		echo '<div class="pq-privacy-days"><label class="pq-label" for="pq-retention-days">' . esc_html__( 'Keep history for', 'pressqueue' ) . '</label>';
		echo '<span class="pq-privacy-unit"><input class="pq-field" id="pq-retention-days" name="retention_days" type="number" min="' . esc_attr( (string) PrivacySettings::MIN_RETENTION_DAYS ) . '" max="' . esc_attr( (string) PrivacySettings::MAX_RETENTION_DAYS ) . '" step="1" value="' . esc_attr( (string) $settings['retention_days'] ) . '" aria-describedby="pq-retention-days-help" /><span aria-hidden="true">' . esc_html__( 'days', 'pressqueue' ) . '</span></span>';
		echo '<p class="pq-help" id="pq-retention-days-help">' . esc_html(
			sprintf(
				/* translators: 1: minimum days, 2: maximum days. */
				__( 'Between %1$d and %2$d days. Cleanup runs once a day in the background.', 'pressqueue' ),
				PrivacySettings::MIN_RETENTION_DAYS,
				PrivacySettings::MAX_RETENTION_DAYS
			)
		) . '</p></div>';

		echo '<div class="pq-callout pq-privacy-explain">';
		echo '<p><strong>' . esc_html__( 'What is removed:', 'pressqueue' ) . '</strong> ' . esc_html__( 'test runs, cancelled shares, finished batches and the checks behind them, once they are older than this. Buffer error details on old shares are cleared, and so is the message text of old shares Buffer accepted.', 'pressqueue' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'What stays:', 'pressqueue' ) . '</strong> ' . esc_html__( 'shares that are waiting, failed or need checking. For every share Buffer accepted, PressQueue keeps a small record without the message, so duplicate protection keeps working and no post is shared twice by accident. Your rules, channels and settings are never removed.', 'pressqueue' ) . '</p>';
		echo '</div>';

		if ( ! $enabled ) {
			echo '<label class="pq-privacy-ack"><input type="checkbox" name="acknowledge_retention_effect" value="1" /> <span>' . esc_html__( 'I understand that old history is deleted for good once it is removed.', 'pressqueue' ) . '</span></label>';
		}

		echo '<div><button type="submit" class="button button-primary">' . esc_html__( 'Save history settings', 'pressqueue' ) . '</button></div>';
		echo '</div>';
		Ui::form_end();

		echo '<div class="pq-section pq-privacy-cleanup">';
		echo '<h3>' . esc_html__( 'Last cleanup', 'pressqueue' ) . '</h3>';
		echo '<p class="pq-muted">' . esc_html( self::cleanup_text( $state ) ) . '</p>';
		echo '<div class="pq-row">';
		if ( $enabled ) {
			Ui::button_form( 'pressqueue_run_retention', 'pressqueue_run_retention', array(), __( 'Clean up now', 'pressqueue' ), 'button', __( 'Remove history older than the period you chose now? Removed records cannot be brought back.', 'pressqueue' ) );
			echo '<span class="pq-muted pq-small">' . esc_html__( 'Removes the oldest records first, a part at a time.', 'pressqueue' ) . '</span>';
		} else {
			echo '<button type="button" class="button" disabled="disabled" aria-describedby="pq-cleanup-off">' . esc_html__( 'Clean up now', 'pressqueue' ) . '</button>';
			echo '<span class="pq-muted pq-small" id="pq-cleanup-off">' . esc_html__( 'Turn on automatic removal and save first.', 'pressqueue' ) . '</span>';
		}
		echo '</div></div>';
		echo '</div>';
	}

	/**
	 * Render the uninstall card.
	 *
	 * @param array<string,mixed> $settings Settings.
	 */
	private static function render_uninstall_card( array $settings ): void {
		$enabled = (bool) $settings['delete_data_on_uninstall'];

		echo '<div class="pq-card pq-privacy-uninstall">';
		Ui::form_start( 'pressqueue_save_privacy_settings', 'pressqueue_save_privacy_settings', array( 'privacy_section' => 'uninstall' ) );
		echo '<div class="pq-section">';
		echo '<h2>' . esc_html__( 'When you delete PressQueue', 'pressqueue' ) . '</h2>';
		echo '<p class="pq-sub">' . esc_html__( 'Deactivating PressQueue never deletes anything. By default, deleting the plugin keeps your data too, so you can install it again and pick up where you left off.', 'pressqueue' ) . '</p>';

		if ( $enabled ) {
			echo '<div class="pq-callout pq-callout--warn"><p><strong>' . esc_html__( 'Deleting the plugin deletes all PressQueue data on this site.', 'pressqueue' ) . '</strong> ' . esc_html__( 'Untick the box below and save to keep your data instead.', 'pressqueue' ) . '</p></div>';
		}

		echo '<label class="pq-privacy-switch"><input type="checkbox" id="pq-delete-on-uninstall" name="delete_data_on_uninstall" value="1" ' . checked( $enabled, true, false ) . ' aria-describedby="pq-delete-on-uninstall-help" /> <span>' . esc_html__( 'Delete all PressQueue data when the plugin is deleted', 'pressqueue' ) . '</span></label>';
		echo '<p class="pq-help" id="pq-delete-on-uninstall-help">' . esc_html__( 'Removes rules, channels, history, settings, the saved Buffer key, sharing settings on posts and scheduled background tasks from this site. Posts Buffer already accepted stay in Buffer.', 'pressqueue' ) . '</p>';

		if ( ! $enabled ) {
			echo '<label class="pq-privacy-ack"><input type="checkbox" name="acknowledge_uninstall_effect" value="1" /> <span>' . esc_html__( 'I understand this permanently deletes PressQueue\'s rules, history, settings and saved Buffer key when the plugin is deleted.', 'pressqueue' ) . '</span></label>';
		}

		echo '<div><button type="submit" class="button">' . esc_html__( 'Save', 'pressqueue' ) . '</button></div>';
		echo '</div>';
		Ui::form_end();
		echo '</div>';
	}

	/**
	 * Render the download card.
	 */
	private static function render_export_card(): void {
		echo '<div class="pq-card pq-privacy-export">';
		Ui::form_start( 'pressqueue_export_data', 'pressqueue_export_data' );
		echo '<div class="pq-section">';
		echo '<h2>' . esc_html__( 'Download your data', 'pressqueue' ) . '</h2>';
		echo '<p class="pq-sub">' . esc_html__( 'Download a JSON file with your rules, channels, settings and sharing history. The saved Buffer key is never included.', 'pressqueue' ) . '</p>';

		echo '<label class="pq-privacy-switch"><input type="checkbox" id="pq-export-rendered" name="include_rendered_payloads" value="1" aria-describedby="pq-export-rendered-help" /> <span>' . esc_html__( 'Include message text and image URLs', 'pressqueue' ) . '</span></label>';
		echo '<p class="pq-help" id="pq-export-rendered-help">' . esc_html__( 'Without this, the text of each share and the links to its images are left out of the file.', 'pressqueue' ) . '</p>';
		echo '<label class="pq-privacy-ack pq-privacy-export-ack"><input type="checkbox" name="acknowledge_export_effect" value="1" /> <span>' . esc_html__( 'I understand the file then contains the text of my social posts and links to images, and I will keep it private.', 'pressqueue' ) . '</span></label>';

		echo '<details class="pq-more"><summary>' . esc_html__( 'Very large sites', 'pressqueue' ) . '</summary><div class="pq-more-body">';
		echo '<p class="pq-help" id="pq-export-cursor-help">' . esc_html__( 'One download holds up to 10,000 records. If a file ends with "truncated": true, copy its "next_cursor" value and paste it here to download the next part.', 'pressqueue' ) . '</p>';
		echo '<label class="pq-label" for="pq-export-cursor">' . esc_html__( 'Continue from', 'pressqueue' ) . '</label><input class="pq-field pq-field--narrow" id="pq-export-cursor" name="export_cursor" type="text" autocomplete="off" spellcheck="false" aria-describedby="pq-export-cursor-help" />';
		echo '</div></details>';

		echo '<div><button type="submit" class="button">' . esc_html__( 'Download data', 'pressqueue' ) . '</button></div>';

		$links = array();
		if ( current_user_can( 'export_others_personal_data' ) ) {
			$links[] = '<a href="' . esc_url( admin_url( 'export-personal-data.php' ) ) . '">' . esc_html__( 'Export Personal Data', 'pressqueue' ) . '</a>';
		}
		if ( current_user_can( 'erase_others_personal_data' ) ) {
			$links[] = '<a href="' . esc_url( admin_url( 'erase-personal-data.php' ) ) . '">' . esc_html__( 'Erase Personal Data', 'pressqueue' ) . '</a>';
		}
		echo '<p class="pq-help">' . esc_html__( 'To answer a request from one person, use the WordPress privacy tools under Tools. They include PressQueue records linked to that person.', 'pressqueue' );
		if ( array() !== $links ) {
			echo ' ' . implode( ' &middot; ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Links are escaped above.
		}
		echo '</p>';

		echo '</div>';
		Ui::form_end();
		echo '</div>';
	}

	/**
	 * Describe the last cleanup in words.
	 *
	 * @param array<string,mixed> $state Retention state.
	 * @return string
	 */
	private static function cleanup_text( array $state ): string {
		$active = isset( $state['active_run'] ) && is_array( $state['active_run'] ) ? $state['active_run'] : null;
		if ( null !== $active ) {
			$started = is_string( $active['started_at_utc'] ?? null ) ? self::relative( $active['started_at_utc'] ) : '';
			$counts  = self::cleanup_counts( $active['counters'] ?? null );

			return sprintf(
				/* translators: %s: relative time such as "5 minutes ago". */
				__( 'A cleanup started %s and continues in the background.', 'pressqueue' ),
				'' === $started ? __( 'recently', 'pressqueue' ) : $started
			) . ( '' === $counts ? '' : ' ' . $counts );
		}

		$last = isset( $state['last_completion'] ) && is_array( $state['last_completion'] ) ? $state['last_completion'] : null;
		if ( null === $last ) {
			return __( 'No cleanup has run yet.', 'pressqueue' );
		}

		$when = is_string( $last['completed_at_utc'] ?? null ) ? self::relative( $last['completed_at_utc'] ) : '';
		if ( 'failed' === ( $last['state'] ?? '' ) ) {
			return sprintf(
				/* translators: %s: relative time such as "5 minutes ago". */
				__( 'The last cleanup stopped with an error %s. Nothing else was changed. It tries again with the next daily cleanup.', 'pressqueue' ),
				$when
			);
		}

		$counts = self::cleanup_counts( $last['counters'] ?? null );

		return sprintf(
			/* translators: %s: relative time such as "5 minutes ago". */
			__( 'Finished %s.', 'pressqueue' ),
			$when
		) . ' ' . ( '' === $counts ? __( 'Nothing was old enough to remove.', 'pressqueue' ) : $counts );
	}

	/**
	 * Summarize cleanup counters.
	 *
	 * @param mixed $counters Counters by family.
	 * @return string
	 */
	private static function cleanup_counts( $counters ): string {
		$deleted  = 0;
		$redacted = 0;

		if ( is_array( $counters ) ) {
			foreach ( $counters as $family ) {
				if ( is_array( $family ) ) {
					$deleted  += (int) ( $family['deleted'] ?? 0 );
					$redacted += (int) ( $family['redacted'] ?? 0 );
				}
			}
		}

		$parts = array();
		if ( $deleted > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of records. */
				_n( 'Removed %d old record.', 'Removed %d old records.', $deleted, 'pressqueue' ),
				$deleted
			);
		}
		if ( $redacted > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of records. */
				_n( 'Cleared the details of %d record.', 'Cleared the details of %d records.', $redacted, 'pressqueue' ),
				$redacted
			);
		}

		return implode( ' ', $parts );
	}

	/**
	 * Return a relative time for a UTC timestamp string.
	 *
	 * @param string $utc UTC timestamp.
	 * @return string
	 */
	private static function relative( string $utc ): string {
		try {
			return strtolower( Ui::relative_time( new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) ) ) );
		} catch ( \Exception $exception ) {
			unset( $exception );
			return '';
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Notices and redirects
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Render notice from request.
	 */
	private static function render_notice(): void {
		$message  = self::query_text( 'pressqueue_message', 64 );
		$messages = array(
			'privacy_saved'                => array( 'success', __( 'Saved.', 'pressqueue' ) ),
			'retention_already_running'    => array( 'info', __( 'A cleanup is already running. It finishes on its own in the background.', 'pressqueue' ) ),
			'retention_complete'           => array( 'success', __( 'Cleanup finished.', 'pressqueue' ) ),
			'retention_partial'            => array( 'success', __( 'PressQueue removed the first part of the old history. The rest is removed in the background.', 'pressqueue' ) ),
			'retention_retention_disabled' => array( 'warning', __( 'Automatic removal is off. Turn it on and save before cleaning up.', 'pressqueue' ) ),
			'retention_runtime_disabled'   => array( 'warning', __( 'Cleanup did not run because background sharing is stopped. See the status above.', 'pressqueue' ) ),
			'retention_storage_not_ready'  => array( 'warning', __( 'Cleanup did not run because PressQueue is updating its database. Try again in a few minutes.', 'pressqueue' ) ),
			'retention_failed'             => array( 'error', __( 'Cleanup stopped with an error. Nothing else was changed. Try again later.', 'pressqueue' ) ),
		);

		if ( isset( $messages[ $message ] ) ) {
			Ui::notice( $messages[ $message ][0], $messages[ $message ][1] );
			return;
		}

		if ( 'privacy_error' !== $message ) {
			return;
		}

		$errors = self::error_messages();
		$code   = self::query_text( 'pressqueue_error', 32 );
		$detail = SafeOperatorError::notice_detail( self::query_text( 'pressqueue_detail', 191 ) );

		echo '<div class="notice notice-error"><p>' . esc_html( $errors[ $code ] ?? $errors['unknown'] ) . '</p>';
		if ( '' !== $detail ) {
			echo '<details class="pq-more pq-small"><summary>' . esc_html__( 'Technical details', 'pressqueue' ) . '</summary><p class="pq-more-body pq-mono">' . esc_html( $detail ) . '</p></details>';
		}
		echo '</div>';
	}

	/**
	 * Return plain error messages by code.
	 *
	 * @return array<string,string>
	 */
	private static function error_messages(): array {
		return array(
			'days'          => sprintf(
				/* translators: 1: minimum days, 2: maximum days. */
				__( 'Keep history for a number of days between %1$d and %2$d.', 'pressqueue' ),
				PrivacySettings::MIN_RETENTION_DAYS,
				PrivacySettings::MAX_RETENTION_DAYS
			),
			'export_ack'    => __( 'To include message text and image URLs, tick the box that confirms the file contains them, then download again.', 'pressqueue' ),
			'export_cursor' => __( 'The Continue from value is not valid. Copy the whole "next_cursor" value from the last file, or leave the field empty.', 'pressqueue' ),
			'export_size'   => __( 'One record is too large to export. Contact your site administrator.', 'pressqueue' ),
			'retention_ack' => __( 'To turn on automatic removal, tick the box that confirms old history is deleted for good, then save again.', 'pressqueue' ),
			'uninstall_ack' => __( 'To delete data with the plugin, tick the box that confirms you understand what is deleted, then save again.', 'pressqueue' ),
			'unknown'       => __( 'PressQueue could not do that. Try again in a moment.', 'pressqueue' ),
		);
	}

	/**
	 * Map a failure to a plain error code.
	 *
	 * @param Throwable $throwable Failure.
	 * @return string
	 */
	private static function error_code( Throwable $throwable ): string {
		if ( $throwable instanceof DomainException ) {
			return isset( self::error_messages()[ $throwable->getMessage() ] ) ? $throwable->getMessage() : 'unknown';
		}

		$map = array(
			'Export cursor is invalid.'                   => 'export_cursor',
			'Export cursor table is invalid.'             => 'export_cursor',
			'One export row exceeds the byte limit.'      => 'export_size',
			'Retention days must be a number.'            => 'days',
			'Retention days must be between 30 and 3650.' => 'days',
		);

		if ( $throwable instanceof ValidationException && isset( $map[ $throwable->getMessage() ] ) ) {
			return $map[ $throwable->getMessage() ];
		}

		return 'unknown';
	}

	/**
	 * Redirect after a failure.
	 *
	 * @param Throwable $throwable Failure.
	 */
	private static function redirect_error( Throwable $throwable ): void {
		$code = self::error_code( $throwable );
		self::redirect( 'privacy_error', 'unknown' === $code ? SafeOperatorError::redirect_detail( $throwable ) : null, $code );
	}

	/**
	 * Redirect to the privacy tab.
	 *
	 * @param string      $message Message key.
	 * @param string|null $detail Optional detail.
	 * @param string|null $error Optional error code.
	 */
	private static function redirect( string $message, ?string $detail = null, ?string $error = null ): void {
		$args = array(
			'page'               => self::SLUG,
			'pressqueue_message' => $message,
		);

		if ( null !== $error && '' !== $error ) {
			$args['pressqueue_error'] = $error;
		}

		if ( null !== $detail && '' !== $detail ) {
			$args['pressqueue_detail'] = rawurlencode( $detail );
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
