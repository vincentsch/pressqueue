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
		self::read( $nonce_action, $capability, $capability_message );
	}

	/**
	 * Verify the request before capturing form data for the input readers.
	 *
	 * @param string $nonce_action Nonce action chosen by the handler.
	 * @param string $capability Required capability.
	 * @param string $capability_message Denial message.
	 * @return array<string,mixed> Verified, unslashed form data; individual readers validate its fields.
	 */
	public static function read( string $nonce_action, string $capability, string $capability_message ): array {
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html( $capability_message ) );
		}

		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== strtoupper( $request_method ) ) {
			wp_die( esc_html__( 'PressQueue changes require POST.', 'pressqueue' ) );
		}

		check_admin_referer( $nonce_action );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Field readers validate text, JSON, lists and opaque credentials separately.
		return wp_unslash( $_POST );
	}

	/**
	 * Require exact confirmation text for destructive or expansive effects.
	 *
	 * @param array<string,mixed> $post Verified, unslashed form data.
	 * @param string              $field Field name.
	 * @param string              $expected Expected confirmation value.
	 */
	public static function require_exact_confirmation( array $post, string $field, string $expected ): void {
		$value = isset( $post[ $field ] ) && is_string( $post[ $field ] ) ? sanitize_text_field( $post[ $field ] ) : '';

		if ( $expected !== $value ) {
			throw new ValidationException( 'The confirmation text did not match the requested effect.' );
		}
	}
}
