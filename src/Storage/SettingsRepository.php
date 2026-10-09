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
	 * Whether new posts are shared automatically or only after an editor opts in.
	 */
	public const NEW_POST_SHARING_OPTION = 'pressqueue_new_post_sharing';
	public const NEW_POST_SHARING_VALUES = array( 'automatic', 'opt_in' );
	public const SHARING_MODES           = array( 'test', 'live', 'paused' );

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
	 * Return the combined sharing mode shown to people.
	 *
	 * Paused wins over everything because the kill switch holds all live work.
	 *
	 * @return string One of test, live, paused.
	 */
	public function sharing_mode(): string {
		if ( $this->is_kill_switch_enabled() ) {
			return 'paused';
		}

		return $this->is_dry_run_enabled() ? 'test' : 'live';
	}

	/**
	 * Store a combined sharing mode.
	 *
	 * @param string $mode One of test, live, paused.
	 */
	public function set_sharing_mode( string $mode ): void {
		if ( ! in_array( $mode, self::SHARING_MODES, true ) ) {
			throw new ValidationException( 'Sharing mode is unsupported.' );
		}

		if ( 'paused' === $mode ) {
			$this->set_kill_switch_enabled( true );
			return;
		}

		$this->set_dry_run_enabled( 'test' === $mode );
		$this->set_kill_switch_enabled( false );
	}

	/**
	 * Return how new posts are treated.
	 *
	 * @return string automatic or opt_in.
	 */
	public function new_post_sharing(): string {
		if ( ! function_exists( 'get_option' ) ) {
			return 'automatic';
		}

		$value = get_option( self::NEW_POST_SHARING_OPTION, 'automatic' );

		return is_string( $value ) && in_array( $value, self::NEW_POST_SHARING_VALUES, true ) ? $value : 'automatic';
	}

	/**
	 * Store how new posts are treated.
	 *
	 * @param string $value automatic or opt_in.
	 */
	public function set_new_post_sharing( string $value ): void {
		if ( ! in_array( $value, self::NEW_POST_SHARING_VALUES, true ) ) {
			throw new ValidationException( 'New post sharing value is unsupported.' );
		}

		if ( false === get_option( self::NEW_POST_SHARING_OPTION, false ) ) {
			add_option( self::NEW_POST_SHARING_OPTION, $value, '', true );
			return;
		}

		update_option( self::NEW_POST_SHARING_OPTION, $value, true );
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
