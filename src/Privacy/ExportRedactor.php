<?php
/**
 * Export redaction helpers.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Privacy;

use PressQueue\Storage\CanonicalJson;

/**
 * Redacts secrets and optional rendered payload fields from exports.
 */
final class ExportRedactor {
	/**
	 * Return a redaction marker.
	 *
	 * @param string $reason Reason.
	 * @return array<string,mixed>
	 */
	public static function marker( string $reason ): array {
		return array(
			'pressqueue_redacted' => true,
			'reason'              => $reason,
			'schema_version'      => 1,
		);
	}

	/**
	 * Decode and redact a JSON object or list.
	 *
	 * @param string $json JSON string.
	 * @param bool   $include_rendered_payloads Include rendered payload text and media URLs.
	 * @return mixed
	 */
	public function redact_json( string $json, bool $include_rendered_payloads = false ) {
		if ( '' === trim( $json ) ) {
			return null;
		}

		try {
			$value = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $exception ) {
			unset( $exception );
			return self::marker( 'invalid_json' );
		}

		return $this->redact_value( $value, $include_rendered_payloads );
	}

	/**
	 * Redact one value recursively.
	 *
	 * @param mixed       $value Value.
	 * @param bool        $include_rendered_payloads Include rendered payload text and media URLs.
	 * @param string|null $key Current key.
	 * @return mixed
	 */
	public function redact_value( $value, bool $include_rendered_payloads = false, ?string $key = null ) {
		if ( null !== $key && $this->is_secret_key( $key ) ) {
			return self::marker( 'credential_or_secret' );
		}

		if ( null !== $key && ! $include_rendered_payloads && $this->is_rendered_payload_key( $key ) ) {
			return self::marker( 'rendered_payload' );
		}

		if ( is_array( $value ) ) {
			$output = array();
			foreach ( $value as $child_key => $child ) {
				$output[ $child_key ] = $this->redact_value( $child, $include_rendered_payloads, is_string( $child_key ) ? $child_key : null );
			}

			return $output;
		}

		return $value;
	}

	/**
	 * Return a redacted JSON string for retained diagnostic fields.
	 *
	 * @param string $reason Redaction reason.
	 * @return string
	 */
	public static function marker_json( string $reason ): string {
		return CanonicalJson::encode_object( self::marker( $reason ) );
	}

	/**
	 * Return a retained diagnostic redaction marker.
	 *
	 * @return string
	 */
	public static function retention_marker_json(): string {
		return CanonicalJson::encode_object(
			array(
				'classification'   => 'retention_detail_redacted',
				'redacted_at_utc'  => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'redaction_reason' => 'retention',
				'schema_version'   => 1,
			)
		);
	}

	/**
	 * Check whether a key stores secrets.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	private function is_secret_key( string $key ): bool {
		$normalized = strtolower( $key );

		if ( in_array( $normalized, array( 'credential_envelope_hash', 'has_credential' ), true ) ) {
			return false;
		}

		if ( in_array( $normalized, array( 'credential_envelope_json', 'authorization', 'api_key', 'password', 'secret', 'token' ), true ) ) {
			return true;
		}

		return 1 === preg_match( '/(^|_)(api_key|authorization|bearer|credential|password|secret|token)($|_)/', $normalized );
	}

	/**
	 * Check whether a key carries rendered payload text or media URL facts.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	private function is_rendered_payload_key( string $key ): bool {
		$normalized = strtolower( $key );

		return in_array(
			$normalized,
			array(
				'canonical_url',
				'final_encoded_text',
				'media_url',
				'message',
				'signed_url',
				'source_url',
				'text',
				'url',
			),
			true
		);
	}
}
