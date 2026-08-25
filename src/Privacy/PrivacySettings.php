<?php
/**
 * Privacy settings storage.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Privacy;

use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\ValidationException;

/**
 * Stores privacy, retention, export, and uninstall policy settings.
 */
final class PrivacySettings {
	public const OPTION                 = 'pressqueue_privacy_settings';
	public const MIN_RETENTION_DAYS     = 30;
	public const MAX_RETENTION_DAYS     = 3650;
	public const DEFAULT_RETENTION_DAYS = 365;
	public const EXPORT_CONFIRMATION    = 'I understand this export can include rendered social text and media URLs.';
	public const RETENTION_CONFIRMATION = 'I understand retention can delete or redact old resolved PressQueue decisions, deliveries, delivery events, batches, batch items, and provider diagnostics.';
	public const UNINSTALL_CONFIRMATION = 'I understand uninstall deletion can remove local PressQueue tables, options, post metadata, credentials, Action Scheduler actions, and role capabilities for this site.';

	/**
	 * Ensure non-autoloaded privacy defaults exist.
	 */
	public static function ensure_defaults_for_current_site(): void {
		if ( ! function_exists( 'add_option' ) || ! function_exists( 'get_option' ) ) {
			return;
		}

		if ( null === get_option( self::OPTION, null ) ) {
			add_option( self::OPTION, self::defaults(), '', false );
		}
	}

	/**
	 * Return default privacy settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'delete_data_on_uninstall' => false,
			'retention_days'           => self::DEFAULT_RETENTION_DAYS,
			'retention_enabled'        => false,
			'schema_version'           => 1,
		);
	}

	/**
	 * Return a stable settings version hash.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return string
	 */
	public static function version( array $settings ): string {
		return CanonicalJson::hash( 'privacy_settings', self::normalize( $settings ) );
	}

	/**
	 * Read stored settings.
	 *
	 * @return array<string,mixed>
	 */
	public function read(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return self::defaults();
		}

		$value = get_option( self::OPTION, null );
		if ( null === $value ) {
			return self::defaults();
		}

		return self::normalize( $value );
	}

	/**
	 * Persist settings as non-autoloaded option storage.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return array<string,mixed>
	 */
	public function save( array $settings ): array {
		$normalized = self::normalize( $settings );

		if ( null === get_option( self::OPTION, null ) ) {
			add_option( self::OPTION, $normalized, '', false );
			return $normalized;
		}

		update_option( self::OPTION, $normalized, false );

		return $normalized;
	}

	/**
	 * Check whether retention is enabled.
	 *
	 * @return bool
	 */
	public function retention_enabled(): bool {
		return (bool) $this->read()['retention_enabled'];
	}

	/**
	 * Check whether uninstall should delete local plugin data.
	 *
	 * @return bool
	 */
	public function delete_data_on_uninstall(): bool {
		return (bool) $this->read()['delete_data_on_uninstall'];
	}

	/**
	 * Normalize a settings payload.
	 *
	 * @param mixed $settings Raw settings.
	 * @return array<string,mixed>
	 */
	public static function normalize( $settings ): array {
		if ( ! is_array( $settings ) || ( array() !== $settings && array_is_list( $settings ) ) ) {
			throw new ValidationException( 'Privacy settings must be an object.' );
		}

		$defaults = self::defaults();

		return array(
			'delete_data_on_uninstall' => self::normalize_bool( $settings['delete_data_on_uninstall'] ?? $defaults['delete_data_on_uninstall'] ),
			'retention_days'           => self::normalize_days( $settings['retention_days'] ?? $defaults['retention_days'] ),
			'retention_enabled'        => self::normalize_bool( $settings['retention_enabled'] ?? $defaults['retention_enabled'] ),
			'schema_version'           => self::normalize_schema_version( $settings['schema_version'] ?? $defaults['schema_version'] ),
		);
	}

	/**
	 * Normalize a strict boolean.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function normalize_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( in_array( $value, array( '0', '1' ), true ) ) {
			return '1' === $value;
		}

		throw new ValidationException( 'Privacy setting is invalid.' );
	}

	/**
	 * Normalize retention days.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private static function normalize_days( $value ): int {
		if ( is_string( $value ) && 1 === preg_match( '/^[0-9]+$/', $value ) ) {
			$value = (int) $value;
		}

		if ( ! is_int( $value ) ) {
			throw new ValidationException( 'Retention days must be a number.' );
		}

		if ( $value < self::MIN_RETENTION_DAYS || $value > self::MAX_RETENTION_DAYS ) {
			throw new ValidationException( 'Retention days must be between 30 and 3650.' );
		}

		return $value;
	}

	/**
	 * Normalize the schema version.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private static function normalize_schema_version( $value ): int {
		if ( 1 === $value || '1' === $value ) {
			return 1;
		}

		throw new ValidationException( 'Privacy settings schema version is unsupported.' );
	}
}
