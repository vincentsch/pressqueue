<?php
/**
 * WordPress HTTP API media probe tests. No request reaches the network.
 *
 * @package PressQueue\Tests\Integration
 */

declare(strict_types=1);

namespace PressQueue\Planning {
	/**
	 * Capture options applied to the WordPress handle without opening a connection.
	 *
	 * @param mixed           $handle HTTP API handle.
	 * @param array<int,mixed> $options cURL options.
	 * @return bool
	 */
	function curl_setopt_array( $handle, array $options ): bool {
		\PressQueue\Tests\Integration\SafeMediaUrlProbeIntegrationTest::$curl_options[] = $options;
		if ( ! \PressQueue\Tests\Integration\SafeMediaUrlProbeIntegrationTest::$accept_curl_options ) {
			return false;
		}
		return $handle instanceof \CurlHandle ? \curl_setopt_array( $handle, $options ) : true;
	}
}

namespace WpOrg\Requests\Transport {
	/**
	 * Simulate WordPress's cURL capability check without changing PHP extensions.
	 *
	 * @param string $function Function name.
	 * @return bool
	 */
	function function_exists( string $function ): bool {
		if ( 'curl_init' === $function && ! \PressQueue\Tests\Integration\SafeMediaUrlProbeIntegrationTest::$curl_available ) {
			return false;
		}
		return \function_exists( $function );
	}
}

namespace PressQueue\Tests\Integration {
	use PressQueue\Planning\SafeMediaUrlProbe;
	use PressQueue\Storage\ValidationException;
	use PressQueue\Tests\Support\PressQueueIntegrationTestCase;

	/**
	 * @covers \PressQueue\Planning\SafeMediaUrlProbe
	 */
	final class SafeMediaUrlProbeIntegrationTest extends PressQueueIntegrationTestCase {
		public static array $curl_options = array();
		public static bool $accept_curl_options = true;
		public static bool $curl_available = true;

		protected function setUp(): void {
			parent::setUp();
			self::$curl_options = array();
			self::$accept_curl_options = true;
			$this->fake_http(
				static function () {
					throw new \RuntimeException( 'Unexpected HTTP request in a media probe test.' );
				}
			);
		}

		public function test_head_uses_safe_http_api_arguments_and_preserves_attachment_facts(): void {
			$calls = 0;
			$this->fake_http(
				static function ( $pre, array $args, string $url ) use ( &$calls ): array {
					++$calls;
					self::assertFalse( $pre );
					self::assertSame( 'https://media.example.test/image.png', $url );
					self::assertSame( 'HEAD', $args['method'] );
					self::assertSame( 0, $args['redirection'] );
					self::assertTrue( $args['reject_unsafe_urls'] );
					self::assertTrue( $args['sslverify'] );
					self::assertSame( 5, $args['timeout'] );
					self::assertSame( 1048576, $args['limit_response_size'] );
					self::assertFalse( $args['stream'] );
					self::assertSame( 'image/*', $args['headers']['Accept'] );
					return self::response( 200, array( 'content-type' => 'Image/PNG; charset=binary', 'content-length' => '2097152' ) );
				}
			);

			$result = $this->probe()->probe( 'https://media.example.test/image.png', array( 'width' => 640, 'height' => 480 ) );
			self::assertSame( 1, $calls );
			self::assertSame( 2097152, $result['byte_size'] );
			self::assertSame( 'image/png', $result['mime_type'] );
			self::assertSame( 640, $result['width'] );
			self::assertSame( 480, $result['height'] );
			self::assertSame( 1, $result['schema_version'] );
		}

		/**
		 * @dataProvider head_not_supported
		 */
		public function test_get_fallback_sniffs_image_and_keeps_the_original_pin( int $status ): void {
			$methods = array();
			$this->fake_http(
				function ( $pre, array $args, string $url ) use ( $status, &$methods ): array {
					$methods[] = $args['method'];
					$this->dispatch_transport_hooks( $url, $args );
					if ( 'HEAD' === $args['method'] ) {
						return self::response( $status );
					}
					return self::response( 200, array(), $this->png() );
				}
			);
			$lookups = 0;
			$probe   = new SafeMediaUrlProbe(
				static function () use ( &$lookups ): array {
					++$lookups;
					return 1 === $lookups ? array( '93.184.216.34' ) : array( '127.0.0.1' );
				}
			);

			$result = $probe->probe( 'https://media.example.test/image.png' );
			self::assertSame( array( 'HEAD', 'GET' ), $methods );
			self::assertSame( 1, $lookups );
			self::assertSame( strlen( $this->png() ), $result['byte_size'] );
			self::assertSame( 'image/png', $result['mime_type'] );
			self::assertSame( 1, $result['width'] );
			self::assertSame( 1, $result['height'] );
			self::assertCount( 2, self::$curl_options );
			self::assertSame( array( ':443:93.184.216.34:443' ), self::$curl_options[0][CURLOPT_CONNECT_TO] );
			self::assertSame( self::$curl_options[0], self::$curl_options[1] );
		}

		public static function head_not_supported(): array {
			return array( array( 405 ), array( 501 ) );
		}

		/**
		 * @dataProvider pin_addresses
		 */
		public function test_pins_curl_and_restores_strict_tls_and_limits( string $address, string $entry ): void {
			$before_select = has_action( 'requests-requests.before_request' );
			$before_pin    = has_action( 'http_api_curl' );
			$this->fake_http(
				function ( $pre, array $args, string $url ): array {
					$this->dispatch_transport_hooks( $url, $args );
					return self::response( 200 );
				}
			);

			$this->probe( $address )->probe( 'https://media.example.test/image.png', $this->facts() );
			self::assertCount( 1, self::$curl_options );
			$options = self::$curl_options[0];
			self::assertSame( array( $entry ), $options[CURLOPT_CONNECT_TO] );
			self::assertSame( '', $options[CURLOPT_PROXY] );
			self::assertTrue( $options[CURLOPT_FRESH_CONNECT] );
			self::assertTrue( $options[CURLOPT_FORBID_REUSE] );
			self::assertFalse( $options[CURLOPT_FOLLOWLOCATION] );
			self::assertTrue( $options[CURLOPT_SSL_VERIFYPEER] );
			self::assertSame( 2, $options[CURLOPT_SSL_VERIFYHOST] );
			self::assertSame( 5000, $options[CURLOPT_TIMEOUT_MS] );
			self::assertSame( 5000, $options[CURLOPT_CONNECTTIMEOUT_MS] );
			self::assertSame( $before_select, has_action( 'requests-requests.before_request' ) );
			self::assertSame( $before_pin, has_action( 'http_api_curl' ) );
		}

		public static function pin_addresses(): array {
			return array(
				array( '93.184.216.34', ':443:93.184.216.34:443' ),
				array( '2606:4700:4700::1111', ':443:[2606:4700:4700::1111]:443' ),
				array( '::ffff:93.184.216.34', ':443:93.184.216.34:443' ),
			);
		}

		public function test_real_wordpress_transport_is_stopped_before_network_io(): void {
			// A numeric public host lets WordPress validate the URL without DNS.
			// Fake both built-in transports at their earliest connection boundaries.
			$this->fake_http( static fn () => false );
			$select_stream = static function ( $url, $headers, $data, $type, array &$options ): void {
				$options['transport'] = \WpOrg\Requests\Transport\Fsockopen::class;
			};
			add_action( 'requests-requests.before_request', $select_stream, 10, 5 );
			$stop = static function (): void {
				throw new ValidationException( 'Stopped before network I/O.' );
			};
			add_action( 'requests-curl.before_send', $stop, PHP_INT_MAX );
			add_action( 'requests-fsockopen.before_request', $stop, PHP_INT_MIN );
			add_filter( 'https_ssl_verify', '__return_false' );
			try {
				$this->probe()->probe( 'https://93.184.216.34:443/folder/../image.png', $this->facts() );
				self::fail( 'The transport fake must stop the request.' );
			} catch ( ValidationException $exception ) {
				self::assertSame( 'Stopped before network I/O.', $exception->getMessage() );
				self::assertCount( 1, self::$curl_options );
			} finally {
				remove_action( 'requests-requests.before_request', $select_stream, 10 );
				remove_action( 'requests-curl.before_send', $stop, PHP_INT_MAX );
				remove_action( 'requests-fsockopen.before_request', $stop, PHP_INT_MIN );
				remove_filter( 'https_ssl_verify', '__return_false' );
			}
			self::assertFalse( has_action( 'http_api_curl' ) );
			self::assertFalse( has_action( 'requests-requests.before_request' ) );
		}

		public function test_normalized_urls_keep_the_transport_guard_and_original_result_url(): void {
			$this->fake_http(
				function ( $pre, array $args, string $url ): array {
					$this->dispatch_transport_hooks( $url, $args );
					return self::response( 200 );
				}
			);
			$url = 'https://MEDIA.EXAMPLE.TEST:443/folder/../image.png';
			$result = $this->probe()->probe( $url, $this->facts() );
			self::assertSame( $url, $result['url'] );
			self::assertSame( array( ':443:93.184.216.34:443' ), self::$curl_options[0][CURLOPT_CONNECT_TO] );
		}

		public function test_unavailable_curl_is_rejected_before_http(): void {
			self::$curl_available = false;
			try {
				$this->probe()->probe( 'https://media.example.test/image.png', $this->facts() );
				self::fail( 'The probe must refuse a transport that cannot pin its address.' );
			} catch ( ValidationException $exception ) {
				self::assertSame( 'Media URL checks require address pinning with cURL.', $exception->getMessage() );
				self::assertSame( 0, $exception->getCode() );
			} finally {
				self::$curl_available = true;
			}
			self::assertFalse( has_action( 'http_api_curl' ) );
			self::assertFalse( has_action( 'requests-requests.before_request' ) );
		}

		public function test_body_processing_is_bounded_even_for_a_preempted_response(): void {
			$this->fake_http(
				function ( $pre, array $args ): array {
					return 'HEAD' === $args['method'] ? self::response( 405 ) : self::response( 200, array(), $this->png() . str_repeat( 'x', 1048576 ) );
				}
			);
			$result = $this->probe()->probe( 'https://media.example.test/image.png' );
			self::assertSame( 1048576, $result['byte_size'] );
			self::assertSame( 1, $result['width'] );
			self::assertSame( 1, $result['height'] );
		}

		public function test_pin_failure_stops_request_and_removes_hooks(): void {
			self::$accept_curl_options = false;
			$this->fake_http(
				function ( $pre, array $args, string $url ): array {
					$this->dispatch_transport_hooks( $url, $args );
					self::fail( 'A failed address pin must stop the request.' );
				}
			);
			try {
				$this->probe()->probe( 'https://media.example.test/image.png', $this->facts() );
				self::fail( 'A failed address pin must fail closed.' );
			} catch ( ValidationException $exception ) {
				self::assertSame( 'Media URL probe transport failed.', $exception->getMessage() );
				self::assertSame( 0, $exception->getCode() );
			}
			self::assertFalse( has_action( 'http_api_curl' ) );
			self::assertFalse( has_action( 'requests-requests.before_request' ) );
		}

		public function test_hooks_do_not_change_unrelated_requests_or_another_request_to_the_same_url(): void {
			$this->fake_http(
				static function ( $pre, array $args, string $url ): array {
					$options = array( 'transport' => 'unchanged' );
					$other   = 'https://unrelated.example.test/';
					$headers = array();
					$data    = '';
					$type    = 'GET';
					$hooks   = new \WP_HTTP_Requests_Hooks( $other, $args );
					$hooks->dispatch( 'requests.before_request', array( &$other, &$headers, &$data, &$type, &$options ) );
					self::assertSame( array( 'transport' => 'unchanged' ), $options );
					$hooks->dispatch( 'curl.before_send', array( new \stdClass() ) );
					$options['useragent'] = 'Another plugin';
					$hooks = new \WP_HTTP_Requests_Hooks( $url, array() );
					$hooks->dispatch( 'requests.before_request', array( &$url, &$headers, &$data, &$type, &$options ) );
					self::assertSame( 'unchanged', $options['transport'] );
					$hooks->dispatch( 'curl.before_send', array( new \stdClass() ) );
					self::assertSame( array(), self::$curl_options );
					return self::response( 200 );
				}
			);
			$this->probe()->probe( 'https://media.example.test/image.png', $this->facts() );
		}

		/**
		 * @dataProvider rejected_http_responses
		 */
		public function test_http_errors_and_redirects_keep_the_existing_failure( int $status ): void {
			$calls = 0;
			$this->fake_http(
				static function () use ( $status, &$calls ) {
					++$calls;
					return 0 === $status ? new \WP_Error( 'http_request_failed', 'offline' ) : self::response( $status, array( 'location' => 'https://127.0.0.1/private' ) );
				}
			);
			try {
				$this->probe()->probe( 'https://media.example.test/image.png', $this->facts() );
				self::fail( 'An HTTP error or redirect must fail closed.' );
			} catch ( ValidationException $exception ) {
				self::assertSame( 0 === $status ? 'Media URL probe transport failed.' : 'Media URL probe did not return a successful response.', $exception->getMessage() );
				self::assertSame( 0, $exception->getCode() );
			}
			self::assertSame( 1, $calls );
			self::assertFalse( has_action( 'http_api_curl' ) );
			self::assertFalse( has_action( 'requests-requests.before_request' ) );
		}

		public static function rejected_http_responses(): array {
			return array( array( 0 ), array( 301 ), array( 302 ), array( 307 ), array( 308 ), array( 404 ), array( 500 ) );
		}

		public function test_unsafe_cname_target_is_rejected_before_wordpress_http(): void {
			$probe = new SafeMediaUrlProbe(
				static function ( string $host ): array {
					return 'media.example.test' === $host ? array( 'cdn.example.test' ) : array( '93.184.216.34', '169.254.169.254' );
				}
			);
			$this->expectException( ValidationException::class );
			$this->expectExceptionMessage( 'Media URL resolved to an unsafe address.' );
			$probe->probe( 'https://media.example.test/image.png', $this->facts() );
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_configured_proxy_cannot_bypass_address_pinning(): void {
			define( 'WP_PROXY_HOST', 'proxy.example.test' );
			define( 'WP_PROXY_PORT', 8080 );
			$this->expectException( ValidationException::class );
			$this->expectExceptionMessage( 'Pinned media URL checks cannot use the configured HTTP proxy.' );
			$this->probe()->probe( 'https://media.example.test/image.png', $this->facts() );
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_wordpress_http_blocking_prevents_dns_and_http(): void {
			define( 'WP_HTTP_BLOCK_EXTERNAL', true );
			$probe = new SafeMediaUrlProbe(
				static function (): array {
					self::fail( 'Blocked requests must stop before DNS lookup.' );
				}
			);
			$this->expectException( ValidationException::class );
			$this->expectExceptionMessage( 'Media URL checks are blocked by WordPress HTTP settings.' );
			$probe->probe( 'https://media.example.test/image.png', $this->facts() );
		}

		private function probe( string $address = '93.184.216.34' ): SafeMediaUrlProbe {
			return new SafeMediaUrlProbe( static fn (): array => array( $address ) );
		}

		private function fake_http( callable $callback ): void {
			remove_all_filters( 'pre_http_request' );
			add_filter( 'pre_http_request', $callback, 10, 3 );
		}

		private function dispatch_transport_hooks( string $url, array $args ): void {
			$hooks   = new \WP_HTTP_Requests_Hooks( $url, $args );
			$headers = array();
			$data    = '';
			$type    = $args['method'];
			$options = array(
				'useragent' => $args['user-agent'],
				'transport' => \WpOrg\Requests\Transport\Fsockopen::class,
				'verify' => false, 'verifyname' => false, 'follow_redirects' => true,
				'timeout' => 99, 'connect_timeout' => 99, 'max_bytes' => false,
			);
			$normalized_url = ( new \WpOrg\Requests\Iri( $url ) )->uri;
			$hooks->dispatch( 'requests.before_request', array( &$normalized_url, &$headers, &$data, &$type, &$options ) );
			self::assertSame( \WpOrg\Requests\Transport\Curl::class, $options['transport'] );
			self::assertSame( ABSPATH . WPINC . '/certificates/ca-bundle.crt', $options['verify'] );
			self::assertTrue( $options['verifyname'] );
			self::assertFalse( $options['follow_redirects'] );
			self::assertSame( 5, $options['timeout'] );
			self::assertSame( 5, $options['connect_timeout'] );
			self::assertSame( 1048576, $options['max_bytes'] );
			$hooks->dispatch( 'curl.before_send', array( new \stdClass() ) );
		}

		private static function response( int $status, array $headers = array(), string $body = '' ): array {
			return array( 'headers' => $headers, 'response' => array( 'code' => $status, 'message' => '' ), 'body' => $body, 'cookies' => array() );
		}

		private function facts(): array {
			return array( 'byte_size' => 100, 'mime_type' => 'image/png', 'width' => 640, 'height' => 480 );
		}

		private function png(): string {
			return (string) base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true );
		}
	}
}
