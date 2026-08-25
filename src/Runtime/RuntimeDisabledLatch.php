<?php
/**
 * Runtime disabled latch.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Runtime;

use PressQueue\Storage\ValidationException;

/**
 * Stores the fail-closed runtime latch.
 */
final class RuntimeDisabledLatch {
	public const OPTION = 'pressqueue_runtime_disabled';

	/**
	 * Ensure the latch option exists and starts closed.
	 */
	public static function ensure_default_closed(): void {
		if ( ! function_exists( 'add_option' ) || ! function_exists( 'get_option' ) ) {
			return;
		}

		if ( null === get_option( self::OPTION, null ) ) {
			add_option( self::OPTION, '1', '', false );
		}
	}

	/**
	 * Close the runtime latch.
	 */
	public static function close(): void {
		self::write( '1' );
	}

	/**
	 * Open the runtime latch.
	 */
	public static function open(): void {
		self::write( '0' );
	}

	/**
	 * Check whether the runtime is open.
	 *
	 * @return bool
	 */
	public static function is_open(): bool {
		if ( ! function_exists( 'get_option' ) ) {
			return false;
		}

		return '0' === get_option( self::OPTION, '1' );
	}

	/**
	 * Check whether the runtime is closed.
	 *
	 * @return bool
	 */
	public static function is_closed(): bool {
		return ! self::is_open();
	}

	/**
	 * Check whether the stored latch value is malformed.
	 *
	 * @return bool
	 */
	public static function is_malformed(): bool {
		if ( ! function_exists( 'get_option' ) ) {
			return true;
		}

		$value = get_option( self::OPTION, '1' );

		return ! in_array( $value, array( '0', '1' ), true );
	}

	/**
	 * Require an open runtime for a mutating operation.
	 *
	 * @param string $operation Operation label.
	 */
	public static function assert_open_for( string $operation ): void {
		if ( self::is_open() ) {
			return;
		}

		unset( $operation );

		throw new ValidationException( 'PressQueue runtime is disabled.' );
	}

	/**
	 * Persist a strict latch value.
	 *
	 * @param string $value Latch value.
	 */
	private static function write( string $value ): void {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'add_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}

		if ( null === get_option( self::OPTION, null ) ) {
			add_option( self::OPTION, $value, '', false );
			return;
		}

		update_option( self::OPTION, $value, false );
	}
}
