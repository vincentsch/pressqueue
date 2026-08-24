<?php
/**
 * Credential envelope tests.
 *
 * @package PressQueue\Tests\Unit
 */

declare(strict_types=1);

namespace PressQueue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PressQueue\Security\CredentialEnvelope;
use PressQueue\Security\CredentialEnvelopeException;

/**
 * @covers \PressQueue\Security\CredentialEnvelope
 * @covers \PressQueue\Security\CredentialEnvelopeException
 */
final class CredentialEnvelopeTest extends TestCase {
	private const CONNECTION_ID = '01K8Z8D0PKH2N8J7S3K5A9C6DE';

	public function test_encrypts_and_decrypts_with_associated_data(): void {
		$service = new CredentialEnvelope( $this->keyMaterial() );
		$json    = $service->encode_for_storage( $service->encrypt( 'buffer', self::CONNECTION_ID, "  opaque test key\n" ) );

		self::assertSame( 64, strlen( $service->storage_hash( $json ) ) );
		self::assertStringNotContainsString( 'opaque test key', $json );
		self::assertSame( 'opaque test key', $service->decrypt( 'buffer', self::CONNECTION_ID, $service->decode_from_storage( $json ) ) );
	}

	public function test_associated_data_binding_rejects_other_connection(): void {
		$service  = new CredentialEnvelope( $this->keyMaterial() );
		$envelope = $service->encrypt( 'buffer', self::CONNECTION_ID, 'opaque test key' );

		$this->expectException( CredentialEnvelopeException::class );

		$service->decrypt( 'buffer', '01K8Z8D0PKH2N8J7S3K5A9C6DF', $envelope );
	}

	public function test_strict_base64url_rejects_padding(): void {
		$service  = new CredentialEnvelope( $this->keyMaterial() );
		$envelope = $service->encrypt( 'buffer', self::CONNECTION_ID, 'opaque test key' );
		$envelope['nonce'] .= '=';

		$this->expectException( CredentialEnvelopeException::class );

		$service->encode_for_storage( $envelope );
	}

	public function test_rejects_sodium_unavailable(): void {
		$service = new CredentialEnvelope( $this->keyMaterial(), false );

		$this->expectException( CredentialEnvelopeException::class );

		$service->encrypt( 'buffer', self::CONNECTION_ID, 'opaque test key' );
	}

	public function test_rejects_missing_placeholder_and_collapsed_key_material(): void {
		$this->expectEnvelopeFailure( array() );
		$this->expectEnvelopeFailure( array_merge( $this->keyMaterial(), array( 'AUTH_KEY' => 'put your unique phrase here' ) ) );

		$same = array();
		foreach ( $this->constantNames() as $name ) {
			$same[ $name ] = 'same configured value';
		}

		$this->expectEnvelopeFailure( $same );
	}

	public function test_changing_key_material_makes_envelope_undecryptable(): void {
		$first    = new CredentialEnvelope( $this->keyMaterial( 'one' ) );
		$second   = new CredentialEnvelope( $this->keyMaterial( 'two' ) );
		$envelope = $first->encrypt( 'buffer', self::CONNECTION_ID, 'opaque test key' );

		$this->expectException( CredentialEnvelopeException::class );

		$second->decrypt( 'buffer', self::CONNECTION_ID, $envelope );
	}

	public function test_oversized_credential_input_is_rejected(): void {
		$service = new CredentialEnvelope( $this->keyMaterial() );

		$this->expectException( CredentialEnvelopeException::class );

		$service->encrypt( 'buffer', self::CONNECTION_ID, str_repeat( 'a', 4097 ) );
	}

	/**
	 * Assert envelope failure.
	 *
	 * @param array<string,string> $key_material Key material.
	 */
	private function expectEnvelopeFailure( array $key_material ): void {
		try {
			( new CredentialEnvelope( $key_material ) )->encrypt( 'buffer', self::CONNECTION_ID, 'opaque test key' );
			self::fail( 'Credential envelope should have failed.' );
		} catch ( CredentialEnvelopeException $exception ) {
			self::assertNotSame( '', $exception->getMessage() );
		}
	}

	/**
	 * Return test key material.
	 *
	 * @param string $suffix Suffix.
	 * @return array<string,string>
	 */
	private function keyMaterial( string $suffix = 'default' ): array {
		$material = array();
		foreach ( $this->constantNames() as $index => $name ) {
			$material[ $name ] = 'pressqueue test key ' . $suffix . ' ' . (string) $index;
		}

		return $material;
	}

	/**
	 * Return WordPress secret constant names.
	 *
	 * @return array<int,string>
	 */
	private function constantNames(): array {
		return array(
			'AUTH_KEY',
			'SECURE_AUTH_KEY',
			'LOGGED_IN_KEY',
			'NONCE_KEY',
			'AUTH_SALT',
			'SECURE_AUTH_SALT',
			'LOGGED_IN_SALT',
			'NONCE_SALT',
		);
	}
}
