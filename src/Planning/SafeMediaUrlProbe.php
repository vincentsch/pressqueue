<?php
/**
 * Safe media URL probing.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Planning;

use PressQueue\Storage\ValidationException;

/**
 * Fakeable SSRF-safe image URL probe.
 */
final class SafeMediaUrlProbe {
	private const MAX_TRANSFER_BYTES = 1048576;
	private const MAX_IMAGE_BYTES    = 5 * 1024 * 1024;
	private const TIMEOUT_SECONDS    = 5;
	private const IMAGE_MIME_TYPES   = array( 'image/gif', 'image/jpeg', 'image/png' );

	/**
	 * Host resolver.
	 *
	 * @var callable
	 */
	private $resolver;

	/**
	 * HTTP transport.
	 *
	 * @var callable
	 */
	private $transport;

	/**
	 * Constructor.
	 *
	 * @param callable|null $resolver Host resolver returning IP strings.
	 * @param callable|null $transport HTTP transport.
	 */
	public function __construct( ?callable $resolver = null, ?callable $transport = null ) {
		$this->resolver  = $resolver ?? array( $this, 'default_resolver' );
		$this->transport = $transport ?? array( $this, 'pinned_transport' );
	}

	/**
	 * Probe an HTTPS image URL.
	 *
	 * @param string              $url Public URL.
	 * @param array<string,mixed> $known_image_facts Trusted local attachment facts.
	 * @return array<string,mixed>
	 */
	public function probe( string $url, array $known_image_facts = array() ): array {
		$parts = $this->validate_url( $url );
		if ( class_exists( '\\WP_Http' ) && ( new \WP_Http() )->block_request( $url ) ) {
			throw new ValidationException( 'Media URL checks are blocked by WordPress HTTP settings.' );
		}
		$addresses   = $this->resolve_safe_addresses( (string) $parts['host'] );
		$resolved_ip = $addresses[0];

		$response = $this->request( 'HEAD', $url, $resolved_ip );
		$status   = (int) ( $response['status'] ?? 0 );

		if ( in_array( $status, array( 405, 501 ), true ) ) {
			$response = $this->request( 'GET', $url, $resolved_ip );
			$status   = (int) ( $response['status'] ?? 0 );
		}

		if ( $status < 200 || $status >= 300 ) {
			throw new ValidationException( 'Media URL probe did not return a successful response.' );
		}

		if ( ! empty( $response['redirected'] ) ) {
			throw new ValidationException( 'Media URL probe rejected a redirect.' );
		}

		$mime_type = isset( $response['mime_type'] ) && is_string( $response['mime_type'] ) ? strtolower( $response['mime_type'] ) : '';
		if ( '' === $mime_type && isset( $known_image_facts['mime_type'] ) && is_string( $known_image_facts['mime_type'] ) ) {
			$mime_type = strtolower( $known_image_facts['mime_type'] );
		}

		if ( ! in_array( $mime_type, self::IMAGE_MIME_TYPES, true ) ) {
			throw new ValidationException( 'Media URL probe did not confirm an image MIME type.' );
		}

		$byte_size = (int) ( $response['byte_size'] ?? 0 );
		if ( $byte_size <= 0 && isset( $known_image_facts['byte_size'] ) ) {
			$byte_size = (int) $known_image_facts['byte_size'];
		}

		if ( $byte_size <= 0 || $byte_size > self::MAX_IMAGE_BYTES ) {
			throw new ValidationException( 'Media URL probe could not confirm a bounded byte size.' );
		}

		$width  = (int) ( $response['width'] ?? 0 );
		$height = (int) ( $response['height'] ?? 0 );
		if ( $width <= 0 && isset( $known_image_facts['width'] ) ) {
			$width = (int) $known_image_facts['width'];
		}

		if ( $height <= 0 && isset( $known_image_facts['height'] ) ) {
			$height = (int) $known_image_facts['height'];
		}

		if ( $width <= 0 || $height <= 0 ) {
			throw new ValidationException( 'Media URL probe could not confirm image dimensions.' );
		}

		return array(
			'byte_size'      => $byte_size,
			'height'         => $height,
			'mime_type'      => $mime_type,
			'probed_at'      => gmdate( 'Y-m-d H:i:s' ),
			'url'            => $url,
			'width'          => $width,
			'schema_version' => 1,
		);
	}

	/**
	 * Validate the URL shape.
	 *
	 * @param string $url URL.
	 * @return array<string,mixed>
	 */
	private function validate_url( string $url ): array {
		if ( '' === $url || false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			throw new ValidationException( 'Media URL is invalid.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Needed outside WordPress too.
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) ) {
			throw new ValidationException( 'Media URL is invalid.' );
		}

		if ( ( $parts['scheme'] ?? '' ) !== 'https' || (int) ( $parts['port'] ?? 443 ) !== 443 ) {
			throw new ValidationException( 'Media URL must use HTTPS on port 443.' );
		}

		foreach ( array( 'user', 'pass', 'fragment', 'query' ) as $key ) {
			if ( array_key_exists( $key, $parts ) ) {
				throw new ValidationException( 'Media URL contains unsupported components.' );
			}
		}

		if ( ! isset( $parts['host'] ) || ! is_string( $parts['host'] ) || '' === $parts['host'] ) {
			throw new ValidationException( 'Media URL host is invalid.' );
		}

		return $parts;
	}

	/**
	 * Resolve and validate host addresses.
	 *
	 * @param string $host Host.
	 * @return array<int,string>
	 */
	private function resolve_safe_addresses( string $host ): array {
		$addresses = $this->resolve_host_addresses( $host );
		if ( array() === $addresses ) {
			throw new ValidationException( 'Media URL host could not be resolved.' );
		}

		foreach ( $addresses as $address ) {
			if ( ! $this->is_public_ip( $address ) ) {
				throw new ValidationException( 'Media URL resolved to an unsafe address.' );
			}
		}

		return $addresses;
	}

	/**
	 * Resolve host addresses, following CNAME-like resolver responses.
	 *
	 * @param string            $host Host.
	 * @param array<int,string> $seen Seen hostnames.
	 * @return array<int,string>
	 */
	private function resolve_host_addresses( string $host, array $seen = array() ): array {
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return array( $this->normalize_ip( $host ) );
		}

		$host = $this->normalize_host( $host );
		if ( in_array( $host, $seen, true ) || count( $seen ) >= 8 ) {
			throw new ValidationException( 'Media URL host CNAME chain is unsafe.' );
		}

		$records = call_user_func( $this->resolver, $host );
		if ( ! is_array( $records ) ) {
			throw new ValidationException( 'Media URL host could not be resolved.' );
		}

		$seen[]    = $host;
		$addresses = array();

		foreach ( $records as $record ) {
			if ( ! is_string( $record ) || '' === $record ) {
				throw new ValidationException( 'Media URL host resolver returned an invalid record.' );
			}

			if ( false !== filter_var( $record, FILTER_VALIDATE_IP ) ) {
				$addresses[] = $this->normalize_ip( $record );
				continue;
			}

			$addresses = array_merge( $addresses, $this->resolve_host_addresses( $record, $seen ) );
		}

		return array_values( array_unique( $addresses ) );
	}

	/**
	 * Issue one bounded request through the injected transport.
	 *
	 * @param string $method Method.
	 * @param string $url URL.
	 * @param string $resolved_ip Prevalidated remote address.
	 * @return array<string,mixed>
	 */
	private function request( string $method, string $url, string $resolved_ip ): array {
		$response = call_user_func(
			$this->transport,
			$method,
			$url,
			array(
				'headers'                => array( 'Accept' => 'image/*' ),
				'limit_response_size'    => self::MAX_TRANSFER_BYTES,
				'pressqueue_resolved_ip' => $resolved_ip,
				'redirection'            => 0,
				'reject_unsafe_urls'     => true,
				'sslverify'              => true,
				'timeout'                => self::TIMEOUT_SECONDS,
			)
		);

		if ( ! is_array( $response ) ) {
			throw new ValidationException( 'Media URL probe transport failed.' );
		}

		return $response;
	}

	/**
	 * Default DNS resolver.
	 *
	 * @param string $host Host.
	 * @return array<int,string>
	 */
	private function default_resolver( string $host ): array {
		if ( ! function_exists( 'dns_get_record' ) ) {
			throw new ValidationException( 'Media URL checks require DNS lookup support.' );
		}
		$records = dns_get_record( $host, DNS_A + DNS_AAAA + DNS_CNAME );
		if ( false === $records ) {
			return array();
		}

		$addresses = array();
		foreach ( $records as $record ) {
			foreach ( array( 'ip', 'ipv6', 'target' ) as $key ) {
				if ( isset( $record[ $key ] ) && is_string( $record[ $key ] ) ) {
					$addresses[] = $record[ $key ];
				}
			}
		}

		return array_values( array_unique( $addresses ) );
	}

	/**
	 * Use the WordPress HTTP API with a pinned cURL connection.
	 *
	 * @param string              $method Method.
	 * @param string              $url URL.
	 * @param array<string,mixed> $args Args.
	 * @return array<string,mixed>
	 */
	private function pinned_transport( string $method, string $url, array $args ): array {
		$resolved_ip = isset( $args['pressqueue_resolved_ip'] ) && is_string( $args['pressqueue_resolved_ip'] ) ? $args['pressqueue_resolved_ip'] : '';
		if ( '' === $resolved_ip || ! $this->is_public_ip( $resolved_ip ) ) {
			throw new ValidationException( 'Media URL resolved address is unsafe.' );
		}

		if ( ! defined( 'CURLOPT_CONNECT_TO' ) || ! class_exists( '\WpOrg\Requests\Transport\Curl' ) || ! \WpOrg\Requests\Transport\Curl::test( array( 'ssl' => true ) ) ) {
			throw new ValidationException( 'Media URL checks require address pinning with cURL.' );
		}

		// Refuse proxies rather than rely on their handling of a pinned destination.
		$proxy = new \WP_HTTP_Proxy();
		if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
			throw new ValidationException( 'Pinned media URL checks cannot use the configured HTTP proxy.' );
		}

		$args['method']                 = $method;
		$args['user-agent']             = 'PressQueue-MediaProbe/1.0';
		$args['pressqueue_probe_token'] = new \stdClass();
		$token                          = $args['pressqueue_probe_token'];
		$address                        = $this->curl_address( $resolved_ip );
		// Requests normalizes host case, default ports and paths before its hook.
		$request_url = ( new \WpOrg\Requests\Iri( $url ) )->uri;

		// Select WordPress's cURL transport before any connection can be opened.
		// Checking for cURL alone would still allow a different Requests transport.
		$select_transport = static function ( $normalized_url, $headers, $data, $type, array &$options ) use ( $request_url ): void {
			if ( $request_url !== $normalized_url || 'PressQueue-MediaProbe/1.0' !== ( $options['useragent'] ?? '' ) ) {
				return;
			}

			$options['transport']        = \WpOrg\Requests\Transport\Curl::class;
			$options['verify']           = defined( 'ABSPATH' ) && defined( 'WPINC' ) ? ABSPATH . WPINC . '/certificates/ca-bundle.crt' : true;
			$options['verifyname']       = true;
			$options['follow_redirects'] = false;
			$options['timeout']          = self::TIMEOUT_SECONDS;
			$options['connect_timeout']  = self::TIMEOUT_SECONDS;
			$options['max_bytes']        = self::MAX_TRANSFER_BYTES;
		};
		$pin_address      = static function ( $handle, array $request_args, string $request_url ) use ( $url, $address, $token ): void {
			if ( $url !== $request_url || ( $request_args['pressqueue_probe_token'] ?? null ) !== $token ) {
				return;
			}

			// Keep the hostname in the URL for Host, SNI and certificate checks.
			// A fresh direct connection also avoids cached connections and env proxies.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array -- Only pins the WordPress HTTP API handle; WordPress sends the request.
			if ( ! curl_setopt_array(
				$handle,
				array(
					// Match any hostname on port 443, including cURL's normalized spelling.
					CURLOPT_CONNECT_TO        => array( ':443:' . $address . ':443' ),
					CURLOPT_PROXY             => '',
					CURLOPT_FRESH_CONNECT     => true,
					CURLOPT_FORBID_REUSE      => true,
					CURLOPT_FOLLOWLOCATION    => false,
					CURLOPT_SSL_VERIFYPEER    => true,
					CURLOPT_SSL_VERIFYHOST    => 2,
					CURLOPT_CONNECTTIMEOUT_MS => self::TIMEOUT_SECONDS * 1000,
					CURLOPT_TIMEOUT_MS        => self::TIMEOUT_SECONDS * 1000,
				)
			) ) {
				throw new ValidationException( 'Media URL probe transport failed.' );
			}
		};

		add_action( 'requests-requests.before_request', $select_transport, PHP_INT_MAX, 5 );
		add_action( 'http_api_curl', $pin_address, PHP_INT_MAX, 3 );
		try {
			$response = wp_safe_remote_request( $url, $args );
		} finally {
			remove_action( 'requests-requests.before_request', $select_transport, PHP_INT_MAX );
			remove_action( 'http_api_curl', $pin_address, PHP_INT_MAX );
		}

		if ( is_wp_error( $response ) || ! is_array( $response ) ) {
			throw new ValidationException( 'Media URL probe transport failed.' );
		}

		$body        = 'GET' === $method ? substr( wp_remote_retrieve_body( $response ), 0, self::MAX_TRANSFER_BYTES ) : '';
		$length      = wp_remote_retrieve_header( $response, 'content-length' );
		$header_mime = wp_remote_retrieve_header( $response, 'content-type' );
		$header_mime = is_string( $header_mime ) ? strtok( $header_mime, ';' ) : '';
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid image bytes are handled as an unconfirmed image below.
		$image        = '' === $body ? false : @getimagesizefromstring( $body );
		$sniffed_mime = is_array( $image ) ? strtolower( $image['mime'] ) : '';
		$mime_type    = is_string( $header_mime ) && '' !== $header_mime ? strtolower( $header_mime ) : $sniffed_mime;

		return array(
			'byte_size' => '' !== $length ? (int) $length : strlen( $body ),
			'height'    => is_array( $image ) ? (int) $image[1] : 0,
			'mime_type' => $mime_type,
			'status'    => (int) wp_remote_retrieve_response_code( $response ),
			'width'     => is_array( $image ) ? (int) $image[0] : 0,
		);
	}

	/**
	 * Check whether an IP is globally routable.
	 *
	 * @param string $address IP address.
	 * @return bool
	 */
	private function is_public_ip( string $address ): bool {
		$address = $this->normalize_ip( $address );
		if ( false === filter_var( $address, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		// Only global unicast IPv6 can be a public image destination.
		if ( false !== filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) && ! $this->ip_in_cidr( $address, '2000::/3' ) ) {
			return false;
		}

		$blocked = false !== filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 )
			? array(
				'0.0.0.0/8',
				'10.0.0.0/8',
				'100.64.0.0/10',
				'127.0.0.0/8',
				'169.254.0.0/16',
				'172.16.0.0/12',
				'192.0.0.0/24',
				'192.0.2.0/24',
				'192.88.99.0/24',
				'192.168.0.0/16',
				'198.18.0.0/15',
				'198.51.100.0/24',
				'203.0.113.0/24',
				'224.0.0.0/4',
				'240.0.0.0/4',
				'255.255.255.255/32',
			)
			: array(
				'::/128',
				'::1/128',
				'::/96',
				'64:ff9b::/96',
				'64:ff9b:1::/48',
				'100::/64',
				'2001::/23',
				'2001:20::/28',
				'2001:db8::/32',
				'2002::/16',
				'3fff::/20',
				'fc00::/7',
				'fe80::/10',
				'fec0::/10',
				'ff00::/8',
			);

		foreach ( $blocked as $range ) {
			if ( $this->ip_in_cidr( $address, $range ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Normalize a hostname returned from DNS.
	 *
	 * @param string $host Hostname.
	 * @return string
	 */
	private function normalize_host( string $host ): string {
		$host = strtolower( rtrim( trim( $host ), '.' ) );
		// Reject alternate numeric IP spellings that HTTP clients may interpret as literals.
		if ( 1 === preg_match( '/^(?:(?:0x[0-9a-f]+|[0-9]+)\.)*(?:0x[0-9a-f]+|[0-9]+)$/', $host ) ) {
			throw new ValidationException( 'Media URL host is invalid.' );
		}

		if ( '' === $host || strlen( $host ) > 253 || 1 !== preg_match( '/^[a-z0-9.-]+$/', $host ) ) {
			throw new ValidationException( 'Media URL host is invalid.' );
		}

		foreach ( explode( '.', $host ) as $label ) {
			if ( '' === $label || strlen( $label ) > 63 || '-' === $label[0] || '-' === $label[ strlen( $label ) - 1 ] ) {
				throw new ValidationException( 'Media URL host is invalid.' );
			}
		}

		return $host;
	}

	/**
	 * Normalize IPv4-mapped IPv6 addresses to IPv4.
	 *
	 * @param string $address IP address.
	 * @return string
	 */
	private function normalize_ip( string $address ): string {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid addresses are handled by returning the original value.
		$packed = @inet_pton( $address );
		if ( false === $packed ) {
			return $address;
		}

		if ( 16 === strlen( $packed ) && str_repeat( "\0", 10 ) . "\xff\xff" === substr( $packed, 0, 12 ) ) {
			$mapped = inet_ntop( substr( $packed, 12 ) );

			return false === $mapped ? $address : $mapped;
		}

		$normalized = inet_ntop( $packed );

		return false === $normalized ? $address : $normalized;
	}

	/**
	 * Format an address for a cURL connection target.
	 *
	 * @param string $address IP address.
	 * @return string
	 */
	private function curl_address( string $address ): string {
		return false !== filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? '[' . $address . ']' : $address;
	}

	/**
	 * Check whether an IP is within a CIDR range.
	 *
	 * @param string $address IP address.
	 * @param string $cidr CIDR range.
	 * @return bool
	 */
	private function ip_in_cidr( string $address, string $cidr ): bool {
		list( $network, $prefix ) = explode( '/', $cidr, 2 );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid addresses fail the CIDR check below.
		$address_binary = @inet_pton( $address );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid networks fail the CIDR check below.
		$network_binary = @inet_pton( $network );

		if ( false === $address_binary || false === $network_binary || strlen( $address_binary ) !== strlen( $network_binary ) ) {
			return false;
		}

		$prefix_bits = (int) $prefix;
		$bytes       = intdiv( $prefix_bits, 8 );
		$bits        = $prefix_bits % 8;

		if ( $bytes > 0 && substr( $address_binary, 0, $bytes ) !== substr( $network_binary, 0, $bytes ) ) {
			return false;
		}

		if ( 0 === $bits ) {
			return true;
		}

		$mask = ( 0xff << ( 8 - $bits ) ) & 0xff;

		return ( ord( $address_binary[ $bytes ] ) & $mask ) === ( ord( $network_binary[ $bytes ] ) & $mask );
	}
}
