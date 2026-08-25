<?php
/**
 * Shared administrator mutation boundary.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Storage\ValidationException;

/**
 * Verifies POST, nonce, capability, and confirmation gates before mutations.
 */
final class AdminPostBoundary {
	/**
	 * Require the standard administrator mutation gates.
	 *
	 * @param string $nonce_action Nonce action.
	 * @param string $capability Capability.
	 * @param string $capability_message Denial message.
	 */
	public static function check( string $nonce_action, string $capability, string $capability_message ): void {
		if ( function_exists( 'current_user_can' ) && ! current_user_can( $capability ) ) {
			wp_die( esc_html( $capability_message ) );
		}

		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== strtoupper( $request_method ) ) {
			wp_die( esc_html__( 'PressQueue changes require POST.', 'pressqueue' ) );
		}

		check_admin_referer( $nonce_action );
	}

	/**
	 * Require exact confirmation text for destructive or expansive effects.
	 *
	 * @param string $field Field name.
	 * @param string $expected Expected confirmation value.
	 */
	public static function require_exact_confirmation( string $field, string $expected ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The shared boundary must be checked before this helper is called.
		$value = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';

		if ( $expected !== $value ) {
			throw new ValidationException( 'The confirmation text did not match the requested effect.' );
		}
	}
}
