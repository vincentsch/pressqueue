<?php
/**
 * PressQueue settings repository.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage;

/**
 * Stores fail-closed policy options.
 */
final class SettingsRepository {
	public const KILL_SWITCH_OPTION = 'pressqueue_kill_switch_enabled';
	public const DRY_RUN_OPTION     = 'pressqueue_dry_run_enabled';

	/**
	 * Default activation callback.
	 */
	public static function ensure_defaults_for_current_site(): void {
		if ( ! function_exists( 'add_option' ) ) {
			return;
		}

		( new self() )->ensure_defaults();
	}

	/**
	 * Ensure non-autoloaded default options exist.
	 */
	public function ensure_defaults(): void {
		$this->add_boolean_default( self::KILL_SWITCH_OPTION, false );
		$this->add_boolean_default( self::DRY_RUN_OPTION, true );
	}

	/**
	 * Read the current policy snapshot.
	 *
	 * @return PolicySettings
	 */
	public function read(): PolicySettings {
		$dry_run     = $this->is_dry_run_enabled();
		$kill_switch = $this->is_kill_switch_enabled();

		if ( $dry_run ) {
			return new PolicySettings( $kill_switch, true, 'dry_run', 'dry_run' );
		}

		if ( $kill_switch ) {
			return new PolicySettings( true, false, 'live', 'held' );
		}

		return new PolicySettings( false, false, 'live', 'proposed' );
	}

	/**
	 * Check whether the global kill switch is enabled.
	 *
	 * @return bool
	 */
	public function is_kill_switch_enabled(): bool {
		return $this->read_boolean( self::KILL_SWITCH_OPTION, false );
	}

	/**
	 * Set the global kill switch.
	 *
	 * @param bool $enabled Whether the kill switch is enabled.
	 */
	public function set_kill_switch_enabled( bool $enabled ): void {
		$this->write_boolean( self::KILL_SWITCH_OPTION, $enabled );
	}

	/**
	 * Check whether dry-run mode is enabled.
	 *
	 * @return bool
	 */
	public function is_dry_run_enabled(): bool {
		return $this->read_boolean( self::DRY_RUN_OPTION, true );
	}

	/**
	 * Set dry-run mode.
	 *
	 * @param bool $enabled Whether dry-run mode is enabled.
	 */
	public function set_dry_run_enabled( bool $enabled ): void {
		$this->write_boolean( self::DRY_RUN_OPTION, $enabled );
	}

	/**
	 * Add a boolean default as a non-autoloaded option.
	 *
	 * @param string $option Option name.
	 * @param bool   $default_value Default value.
	 */
	private function add_boolean_default( string $option, bool $default_value ): void {
		add_option( $option, $default_value ? '1' : '0', '', false );
	}

	/**
	 * Read a boolean option.
	 *
	 * @param string $option Option name.
	 * @param bool   $default_value Default value.
	 * @return bool
	 */
	private function read_boolean( string $option, bool $default_value ): bool {
		if ( ! function_exists( 'get_option' ) ) {
			return $default_value;
		}

		self::refresh_option( $option );
		$value = get_option( $option, $default_value ? '1' : '0' );

		if ( '1' === $value ) {
			return true;
		}

		if ( '0' === $value ) {
			return false;
		}

		return true;
	}

	/**
	 * Refresh non-autoloaded safety controls in long-running workers.
	 *
	 * @param string $option Option name.
	 */
	public static function refresh_option( string $option ): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $option, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( 'alloptions', 'options' );
		}
	}

	/**
	 * Write a boolean option as non-autoloaded storage.
	 *
	 * @param string $option Option name.
	 * @param bool   $enabled Enabled value.
	 */
	private function write_boolean( string $option, bool $enabled ): void {
		if ( false === get_option( $option, false ) ) {
			add_option( $option, $enabled ? '1' : '0', '', false );
			return;
		}

		update_option( $option, $enabled ? '1' : '0', false );
	}
}
