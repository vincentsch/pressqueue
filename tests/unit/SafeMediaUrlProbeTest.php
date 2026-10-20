<?php
/**
 * Safe media URL probe tests without DNS or HTTP access.
 *
 * @package PressQueue\Tests\Unit
 */

declare(strict_types=1);

namespace PressQueue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PressQueue\Planning\SafeMediaUrlProbe;
use PressQueue\Storage\ValidationException;

/**
 * @covers \PressQueue\Planning\SafeMediaUrlProbe
 */
final class SafeMediaUrlProbeTest extends TestCase {
	/**
	 * @dataProvider unsafe_addresses
	 */
	public function test_rejects_every_unsafe_dns_answer_before_http( string $address ): void {
		$probe = new SafeMediaUrlProbe(
			static function () use ( $address ): array {
				return array( '93.184.216.34', $address );
			},
			static function (): array {
				self::fail( 'An unsafe DNS answer must prevent HTTP.' );
			}
		);

		$this->expectException( ValidationException::class );
		$this->expectExceptionMessage( 'Media URL resolved to an unsafe address.' );
		$probe->probe( 'https://media.example.test/image.png' );
	}

	public static function unsafe_addresses(): array {
		return array_map(
			static function ( string $address ): array {
				return array( $address );
			},
			array(
				'0.0.0.0', '10.0.0.1', '100.64.0.1', '127.0.0.1', '169.254.169.254',
				'172.16.0.1', '192.0.0.1', '192.0.2.1', '192.88.99.1', '192.168.0.1',
				'198.18.0.1', '198.51.100.1', '203.0.113.1', '224.0.0.1', '240.0.0.1', '255.255.255.255',
				'::', '::1', '::127.0.0.1', '::ffff:127.0.0.1', '::ffff:192.168.0.1',
				'64:ff9b::0808:0808', '64:ff9b:1::1', '100::1', '2001::1', '2001:20::1',
				'2001:db8::1', '2002::1', '3fff::1', '4000::1', 'fc00::1', 'fe80::1', 'fec0::1', 'ff02::1',
			)
		);
	}

	/**
	 * @dataProvider invalid_urls
	 */
	public function test_rejects_unsupported_urls_before_dns( string $url ): void {
		$probe = new SafeMediaUrlProbe(
			static function (): array {
				self::fail( 'An unsupported URL must prevent DNS lookup.' );
			},
			static function (): array {
				self::fail( 'An unsupported URL must prevent HTTP.' );
			}
		);

		$this->expectException( ValidationException::class );
		$probe->probe( $url );
	}

	public static function invalid_urls(): array {
		return array(
			array( '' ),
			array( 'https://127.1/image.png' ),
			array( 'https://2130706433/image.png' ),
			array( 'https://0x7f000001/image.png' ),
			array( 'https://0177.0.0.1/image.png' ),
			array( 'https://0x7f.0.0.1/image.png' ),
			array( 'http://media.example.test/image.png' ),
			array( 'https://media.example.test:8443/image.png' ),
			array( 'https://user:pass@media.example.test/image.png' ),
			array( 'https://media.example.test/image.png?secret=value' ),
			array( 'https://media.example.test/image.png#fragment' ),
		);
	}

	public function test_private_literal_address_never_uses_dns_or_http(): void {
		$probe = new SafeMediaUrlProbe(
			static function (): array {
				self::fail( 'A literal IP does not need DNS.' );
			},
			static function (): array {
				self::fail( 'A private IP must prevent HTTP.' );
			}
		);

		$this->expectException( ValidationException::class );
		$this->expectExceptionMessage( 'Media URL resolved to an unsafe address.' );
		$probe->probe( 'https://127.0.0.1/image.png' );
	}

	public function test_fallback_reuses_the_first_dns_result_and_request_limits(): void {
		$lookups = 0;
		$methods = array();
		$probe   = new SafeMediaUrlProbe(
			static function () use ( &$lookups ): array {
				++$lookups;
				return 1 === $lookups ? array( '2606:4700:4700::1111' ) : array( '127.0.0.1' );
			},
			static function ( string $method, string $url, array $args ) use ( &$methods ): array {
				$methods[] = $method;
				self::assertSame( '2606:4700:4700::1111', $args['pressqueue_resolved_ip'] );
				self::assertSame( 0, $args['redirection'] );
				self::assertTrue( $args['reject_unsafe_urls'] );
				self::assertTrue( $args['sslverify'] );
				self::assertSame( 5, $args['timeout'] );
				self::assertSame( 1048576, $args['limit_response_size'] );
				return 'HEAD' === $method ? array( 'status' => 405 ) : array(
					'status' => 200, 'mime_type' => 'image/png', 'byte_size' => 123, 'width' => 2, 'height' => 3,
				);
			}
		);

		$result = $probe->probe( 'https://media.example.test/image.png' );
		self::assertSame( 1, $lookups );
		self::assertSame( array( 'HEAD', 'GET' ), $methods );
		self::assertSame( 123, $result['byte_size'] );
		self::assertSame( 1, $result['schema_version'] );
		self::assertSame( 'https://media.example.test/image.png', $result['url'] );
	}

	/**
	 * @dataProvider unsafe_dns_records
	 */
	public function test_rejects_unresolved_invalid_or_cyclic_dns( array $records ): void {
		$probe = new SafeMediaUrlProbe(
			static function () use ( $records ): array {
				return $records;
			},
			static function (): array {
				self::fail( 'Invalid DNS must prevent HTTP.' );
			}
		);

		$this->expectException( ValidationException::class );
		$probe->probe( 'https://media.example.test/image.png' );
	}

	public static function unsafe_dns_records(): array {
		return array( array( array() ), array( array( '' ) ), array( array( 123 ) ), array( array( 'media.example.test' ) ) );
	}

	/**
	 * @dataProvider failed_responses
	 */
	public function test_keeps_validation_failures_and_exception_codes( $response, string $message ): void {
		$probe = new SafeMediaUrlProbe(
			static function (): array {
				return array( '93.184.216.34' );
			},
			static function () use ( $response ) {
				return $response;
			}
		);

		try {
			$probe->probe( 'https://media.example.test/image.png' );
			self::fail( 'The probe should fail closed.' );
		} catch ( ValidationException $exception ) {
			self::assertSame( $message, $exception->getMessage() );
			self::assertSame( 0, $exception->getCode() );
		}
	}

	public static function failed_responses(): array {
		return array(
			array( false, 'Media URL probe transport failed.' ),
			array( array( 'status' => 302 ), 'Media URL probe did not return a successful response.' ),
			array( array( 'status' => 200, 'redirected' => true ), 'Media URL probe rejected a redirect.' ),
			array( array( 'status' => 200, 'mime_type' => 'text/html' ), 'Media URL probe did not confirm an image MIME type.' ),
			array( array( 'status' => 200, 'mime_type' => 'image/png', 'byte_size' => 0 ), 'Media URL probe could not confirm a bounded byte size.' ),
			array( array( 'status' => 200, 'mime_type' => 'image/png', 'byte_size' => 5242881 ), 'Media URL probe could not confirm a bounded byte size.' ),
			array( array( 'status' => 200, 'mime_type' => 'image/png', 'byte_size' => 10 ), 'Media URL probe could not confirm image dimensions.' ),
		);
	}
}
