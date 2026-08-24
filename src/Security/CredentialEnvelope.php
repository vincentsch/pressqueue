<?php
/**
 * Encrypted provider credential envelopes.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Security;

use DateTimeImmutable;
use DateTimeZone;
use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\PublicIds;
use PressQueue\Storage\Schema;
use PressQueue\Storage\StorageValidator;

/**
 * Encrypts opaque provider credentials with authenticated associated data.
 *
 * PHP strings may be copied by the runtime, so memory clearing here is a
 * best-effort cleanup of local variables rather than a full secret erasure
 * guarantee.
 */
final class CredentialEnvelope {
	private const SCHEMA_VERSION       = 1;
	private const ALGORITHM            = 'xchacha20poly1305_ietf';
	private const KDF                  = 'hkdf-sha256';
	private const KEY_CONTEXT_VERSION  = 1;
	private const NONCE_BYTES          = 24;
	private const KEY_BYTES            = 32;
	private const MAX_SECRET_BYTES     = 4096;
	private const MAX_CIPHERTEXT_BYTES = 4112;
	private const KDF_SALT             = 'pressqueue:credential-envelope:v1:kdf-salt';
	private const KDF_INFO             = 'pressqueue:credential-envelope:v1';
	private const PLACEHOLDER_SECRET   = 'put your unique phrase here';
	private const WORDPRESS_CONSTANTS  = array(
		'AUTH_KEY',
		'SECURE_AUTH_KEY',
		'LOGGED_IN_KEY',
		'NONCE_KEY',
		'AUTH_SALT',
		'SECURE_AUTH_SALT',
		'LOGGED_IN_SALT',
		'NONCE_SALT',
	);

	/**
	 * Optional injected key material for tests.
	 *
	 * @var array<string,string>|null
	 */
	private ?array $key_material;

	/**
	 * Optional injected Sodium availability for tests.
	 *
	 * @var bool|null
	 */
	private ?bool $sodium_available;

	/**
	 * Constructor.
	 *
	 * @param array<string,string>|null $key_material Test key material.
	 * @param bool|null                 $sodium_available Test Sodium availability.
	 */
	public function __construct( ?array $key_material = null, ?bool $sodium_available = null ) {
		$this->key_material     = $key_material;
		$this->sodium_available = $sodium_available;
	}

	/**
	 * Encrypt an opaque credential into an envelope document.
	 *
	 * @param string $provider Provider key.
	 * @param string $connection_public_id Connection public ID.
	 * @param string $credential Opaque credential input.
	 * @return array<string,mixed>
	 */
	public function encrypt( string $provider, string $connection_public_id, string $credential ): array {
		StorageValidator::assert_text_key( 'Provider', $provider );
		PublicIds::assert( $connection_public_id );
		$this->assert_sodium_available();

		$secret = trim( $credential );
		if ( '' === $secret || strlen( $secret ) > self::MAX_SECRET_BYTES ) {
			$this->memzero( $secret );
			unset( $secret );
			throw new CredentialEnvelopeException( 'Credential input is empty or too large.' );
		}

		$key   = $this->derive_key();
		$nonce = random_bytes( self::NONCE_BYTES );
		$ad    = $this->associated_data( $provider, $connection_public_id );

		$ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $secret, $ad, $nonce, $key );
		$this->memzero( $secret );
		$this->memzero( $key );
		unset( $secret, $key );

		return array(
			'algorithm'           => self::ALGORITHM,
			'ciphertext'          => $this->base64url_encode( $ciphertext ),
			'created_at'          => ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d\TH:i:s\Z' ),
			'kdf'                 => self::KDF,
			'key_context_version' => self::KEY_CONTEXT_VERSION,
			'nonce'               => $this->base64url_encode( $nonce ),
			'schema_version'      => self::SCHEMA_VERSION,
		);
	}

	/**
	 * Decrypt an envelope document.
	 *
	 * @param string              $provider Provider key.
	 * @param string              $connection_public_id Connection public ID.
	 * @param array<string,mixed> $envelope Envelope document.
	 * @return string
	 */
	public function decrypt( string $provider, string $connection_public_id, array $envelope ): string {
		StorageValidator::assert_text_key( 'Provider', $provider );
		PublicIds::assert( $connection_public_id );
		$this->assert_sodium_available();
		$this->validate_envelope( $envelope );

		$nonce      = $this->base64url_decode( (string) $envelope['nonce'] );
		$ciphertext = $this->base64url_decode( (string) $envelope['ciphertext'] );
		$key        = $this->derive_key();
		$ad         = $this->associated_data( $provider, $connection_public_id );
		$plaintext  = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( $ciphertext, $ad, $nonce, $key );

		$this->memzero( $key );
		$this->memzero( $ciphertext );
		$this->memzero( $nonce );
		unset( $key, $ciphertext, $nonce );

		if ( false === $plaintext ) {
			throw new CredentialEnvelopeException( 'Credential envelope could not be decrypted.' );
		}

		return $plaintext;
	}

	/**
	 * Encode an envelope for storage.
	 *
	 * @param array<string,mixed> $envelope Envelope document.
	 * @return string
	 */
	public function encode_for_storage( array $envelope ): string {
		$this->validate_envelope( $envelope );
		$json = CanonicalJson::encode_object( $envelope );
		CanonicalJson::assert_maximum_size( $json, Schema::PROVIDER_JSON_MAX_BYTES );

		return $json;
	}

	/**
	 * Decode an envelope from storage.
	 *
	 * @param string $json Envelope JSON.
	 * @return array<string,mixed>
	 */
	public function decode_from_storage( string $json ): array {
		CanonicalJson::assert_maximum_size( $json, Schema::PROVIDER_JSON_MAX_BYTES );
		$envelope = CanonicalJson::decode_object( $json );
		$this->validate_envelope( $envelope );

		return $envelope;
	}

	/**
	 * Hash an envelope JSON document for compare and set writes.
	 *
	 * @param string $json Envelope JSON.
	 * @return string
	 */
	public function storage_hash( string $json ): string {
		CanonicalJson::assert_maximum_size( $json, Schema::PROVIDER_JSON_MAX_BYTES );

		return hash( 'sha256', $json );
	}

	/**
	 * Validate one envelope before storage or decryption.
	 *
	 * @param array<string,mixed> $envelope Envelope document.
	 */
	private function validate_envelope( array $envelope ): void {
		$expected_keys = array(
			'algorithm',
			'ciphertext',
			'created_at',
			'kdf',
			'key_context_version',
			'nonce',
			'schema_version',
		);
		$actual_keys   = array_keys( $envelope );
		sort( $actual_keys, SORT_STRING );

		if ( $expected_keys !== $actual_keys ) {
			throw new CredentialEnvelopeException( 'Credential envelope shape is unsupported.' );
		}

		if (
			self::SCHEMA_VERSION !== $envelope['schema_version']
			|| self::ALGORITHM !== $envelope['algorithm']
			|| self::KDF !== $envelope['kdf']
			|| self::KEY_CONTEXT_VERSION !== $envelope['key_context_version']
			|| ! is_string( $envelope['nonce'] )
			|| ! is_string( $envelope['ciphertext'] )
			|| ! is_string( $envelope['created_at'] )
		) {
			throw new CredentialEnvelopeException( 'Credential envelope metadata is unsupported.' );
		}

		$nonce      = $this->base64url_decode( $envelope['nonce'] );
		$ciphertext = $this->base64url_decode( $envelope['ciphertext'] );

		if ( self::NONCE_BYTES !== strlen( $nonce ) ) {
			throw new CredentialEnvelopeException( 'Credential envelope nonce length is invalid.' );
		}

		if ( '' === $ciphertext || strlen( $ciphertext ) > self::MAX_CIPHERTEXT_BYTES ) {
			throw new CredentialEnvelopeException( 'Credential envelope ciphertext size is invalid.' );
		}

		$this->memzero( $nonce );
		$this->memzero( $ciphertext );
		unset( $nonce, $ciphertext );

		$created_at = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $envelope['created_at'], new DateTimeZone( 'UTC' ) );
		if ( false === $created_at || $created_at->format( 'Y-m-d\TH:i:s\Z' ) !== $envelope['created_at'] ) {
			throw new CredentialEnvelopeException( 'Credential envelope timestamp is invalid.' );
		}
	}

	/**
	 * Build associated data.
	 *
	 * @param string $provider Provider key.
	 * @param string $connection_public_id Connection public ID.
	 * @return string
	 */
	private function associated_data( string $provider, string $connection_public_id ): string {
		return $provider . "\n" . $connection_public_id . "\n" . (string) self::SCHEMA_VERSION;
	}

	/**
	 * Derive the encryption key from WordPress secret constants.
	 *
	 * @return string
	 */
	private function derive_key(): string {
		$values     = array();
		$normalized = array();

		foreach ( self::WORDPRESS_CONSTANTS as $constant ) {
			$value   = $this->constant_value( $constant );
			$trimmed = trim( $value );

			if ( '' === $trimmed || self::PLACEHOLDER_SECRET === $trimmed ) {
				throw new CredentialEnvelopeException( 'WordPress secret key material is not configured.' );
			}

			$values[]     = $value;
			$normalized[] = $trimmed;
		}

		if ( 1 === count( array_unique( $normalized ) ) ) {
			throw new CredentialEnvelopeException( 'WordPress secret key material is not unique.' );
		}

		$input = implode( "\n", $values );
		$key   = $this->hkdf_sha256( $input, self::KDF_SALT, self::KDF_INFO, self::KEY_BYTES );
		$this->memzero( $input );
		unset( $input );

		return $key;
	}

	/**
	 * Return one configured constant value.
	 *
	 * @param string $constant Constant name.
	 * @return string
	 */
	private function constant_value( string $constant ): string {
		if ( null !== $this->key_material ) {
			if ( ! array_key_exists( $constant, $this->key_material ) ) {
				throw new CredentialEnvelopeException( 'WordPress secret key material is missing.' );
			}

			return (string) $this->key_material[ $constant ];
		}

		if ( ! defined( $constant ) ) {
			throw new CredentialEnvelopeException( 'WordPress secret key material is missing.' );
		}

		return (string) constant( $constant );
	}

	/**
	 * HKDF-SHA256 implementation with a single 32 byte output block.
	 *
	 * @param string $input Input key material.
	 * @param string $salt Salt.
	 * @param string $info Info string.
	 * @param int    $length Output length.
	 * @return string
	 */
	private function hkdf_sha256( string $input, string $salt, string $info, int $length ): string {
		$prk = hash_hmac( 'sha256', $input, $salt, true );
		$okm = hash_hmac( 'sha256', $info . "\x01", $prk, true );
		$this->memzero( $prk );
		unset( $prk );

		$key = substr( $okm, 0, $length );
		$this->memzero( $okm );
		unset( $okm );

		return $key;
	}

	/**
	 * Encode base64url without padding.
	 *
	 * @param string $value Raw bytes.
	 * @return string
	 */
	private function base64url_encode( string $value ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Base64url is required for credential envelope binary fields.
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	/**
	 * Decode strict base64url without padding.
	 *
	 * @param string $value Encoded value.
	 * @return string
	 */
	private function base64url_decode( string $value ): string {
		if ( '' === $value || 1 === preg_match( '/=|[^A-Za-z0-9_-]/', $value ) ) {
			throw new CredentialEnvelopeException( 'Credential envelope uses invalid base64url encoding.' );
		}

		$padding = ( 4 - ( strlen( $value ) % 4 ) ) % 4;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Base64url is required for credential envelope binary fields.
		$decoded = base64_decode( strtr( $value, '-_', '+/' ) . str_repeat( '=', $padding ), true );

		if ( ! is_string( $decoded ) || $this->base64url_encode( $decoded ) !== $value ) {
			throw new CredentialEnvelopeException( 'Credential envelope uses invalid base64url encoding.' );
		}

		return $decoded;
	}

	/**
	 * Fail closed when Sodium support is unavailable.
	 */
	private function assert_sodium_available(): void {
		$available = $this->sodium_available ?? (
			function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' )
			&& function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt' )
		);

		if ( ! $available ) {
			throw new CredentialEnvelopeException( 'The PHP Sodium extension is required for provider credentials.' );
		}
	}

	/**
	 * Best-effort local string zeroing.
	 *
	 * @param string|null $value Secret-bearing value.
	 */
	private function memzero( ?string &$value ): void {
		if ( null !== $value && function_exists( 'sodium_memzero' ) ) {
			sodium_memzero( $value );
		}
	}
}
