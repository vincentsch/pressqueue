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
				'headers'                => array(),
				'limit_response_size'    => self::MAX_TRANSFER_BYTES,
				'pressqueue_resolved_ip' => $resolved_ip,
				'redirection'            => 0,
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
	 * Issue a pinned HTTPS request to the validated address.
	 *
	 * @param string              $method Method.
	 * @param string              $url URL.
	 * @param array<string,mixed> $args Args.
	 * @return array<string,mixed>
	 */
	private function pinned_transport( string $method, string $url, array $args ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Needed for a pinned socket transport.
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) || ! is_string( $parts['host'] ) ) {
			throw new ValidationException( 'Media URL is invalid.' );
		}

		$host        = $parts['host'];
		$resolved_ip = isset( $args['pressqueue_resolved_ip'] ) && is_string( $args['pressqueue_resolved_ip'] ) ? $args['pressqueue_resolved_ip'] : '';
		if ( '' === $resolved_ip || ! $this->is_public_ip( $resolved_ip ) ) {
			throw new ValidationException( 'Media URL resolved address is unsafe.' );
		}

		if ( class_exists( '\\WP_HTTP_Proxy' ) ) {
			$proxy = new \WP_HTTP_Proxy();
			if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
				throw new ValidationException( 'Pinned media URL checks cannot use the configured HTTP proxy.' );
			}
		}
		if ( ! function_exists( 'stream_socket_client' ) ) {
			throw new ValidationException( 'Media URL checks require TLS socket support.' );
		}

		$context = stream_context_create(
			array(
				'ssl' => array(
					'cafile'           => $this->certificate_authority_file(),
					'SNI_enabled'      => true,
					'SNI_server_name'  => $host,
					'peer_name'        => $host,
					'verify_peer'      => true,
					'verify_peer_name' => true,
				),
			)
		);

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Raw socket failures are converted to validation failures below.
		$socket = @stream_socket_client( 'tls://' . $this->socket_address( $resolved_ip ) . ':443', $errno, $error, self::TIMEOUT_SECONDS, STREAM_CLIENT_CONNECT, $context );
		unset( $errno, $error );

		if ( ! is_resource( $socket ) ) {
			throw new ValidationException( 'Media URL probe transport failed.' );
		}

		stream_set_timeout( $socket, self::TIMEOUT_SECONDS );

		$path = isset( $parts['path'] ) && is_string( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Raw socket writes pin the validated DNS result.
		fwrite(
			$socket,
			$method . ' ' . $path . " HTTP/1.1\r\n"
			. 'Host: ' . $host . "\r\n"
			. "Accept: image/*\r\n"
			. "User-Agent: PressQueue-MediaProbe/1.0\r\n"
			. "Connection: close\r\n\r\n"
		);

		$deadline           = microtime( true ) + self::TIMEOUT_SECONDS;
		$raw_headers        = '';
		$raw_headers_length = 0;
		while ( ! feof( $socket ) && ! str_contains( $raw_headers, "\r\n\r\n" ) && $raw_headers_length <= 32768 && microtime( true ) < $deadline ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Raw socket reads pin the validated DNS result.
			$chunk = fread( $socket, 1024 );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}

			$raw_headers        .= $chunk;
			$raw_headers_length += strlen( $chunk );
		}

		if ( ! str_contains( $raw_headers, "\r\n\r\n" ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Raw socket reads pin the validated DNS result.
			fclose( $socket );
			throw new ValidationException( 'Media URL probe response headers are invalid.' );
		}

		list( $header_block, $body ) = explode( "\r\n\r\n", $raw_headers, 2 );
		$body                        = 'GET' === $method ? $body : '';
		$body_length                 = strlen( $body );

		while ( 'GET' === $method && ! feof( $socket ) && $body_length < self::MAX_TRANSFER_BYTES && microtime( true ) < $deadline ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Raw socket reads pin the validated DNS result.
			$chunk = fread( $socket, min( 8192, self::MAX_TRANSFER_BYTES - $body_length ) );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}

			$body        .= $chunk;
			$body_length += strlen( $chunk );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Raw socket reads pin the validated DNS result.
		fclose( $socket );

		$headers     = $this->parse_headers( $header_block );
		$status      = $this->parse_status( $header_block );
		$size        = isset( $headers['content-length'] ) ? (int) $headers['content-length'] : ( '' === $body ? 0 : strlen( $body ) );
		$header_mime = isset( $headers['content-type'] ) ? strtok( $headers['content-type'], ';' ) : '';
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid image bytes are handled as an unconfirmed image below.
		$image        = '' === $body ? false : @getimagesizefromstring( $body );
		$sniffed_mime = is_array( $image ) ? strtolower( $image['mime'] ) : '';
		$mime_type    = is_string( $header_mime ) && '' !== $header_mime ? strtolower( $header_mime ) : $sniffed_mime;

		return array(
			'body'      => $body,
			'byte_size' => $size,
			'height'    => is_array( $image ) ? (int) $image[1] : 0,
			'mime_type' => $mime_type,
			'status'    => $status,
			'width'     => is_array( $image ) ? (int) $image[0] : 0,
		);
	}

	/**
	 * Use WordPress's bundled trust store for the pinned TLS connection.
	 *
	 * @return string
	 */
	private function certificate_authority_file(): string {
		if ( defined( 'ABSPATH' ) && defined( 'WPINC' ) ) {
			return ABSPATH . WPINC . '/certificates/ca-bundle.crt';
		}
		$file = ini_get( 'openssl.cafile' );
		return false === $file ? '' : $file;
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
	 * Return a socket-safe address.
	 *
	 * @param string $address IP address.
	 * @return string
	 */
	private function socket_address( string $address ): string {
		return false !== filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? '[' . $address . ']' : $address;
	}

	/**
	 * Parse response headers.
	 *
	 * @param string $header_block Header block.
	 * @return array<string,string>
	 */
	private function parse_headers( string $header_block ): array {
		$headers = array();
		foreach ( explode( "\r\n", $header_block ) as $index => $line ) {
			if ( 0 === $index || ! str_contains( $line, ':' ) ) {
				continue;
			}

			list( $name, $value )                   = explode( ':', $line, 2 );
			$headers[ strtolower( trim( $name ) ) ] = trim( $value );
		}

		return $headers;
	}

	/**
	 * Parse HTTP status from the response.
	 *
	 * @param string $header_block Header block.
	 * @return int
	 */
	private function parse_status( string $header_block ): int {
		$line = strtok( $header_block, "\r\n" );
		if ( ! is_string( $line ) || 1 !== preg_match( '/^HTTP\/\d(?:\.\d)?\s+(\d{3})\b/', $line, $matches ) ) {
			return 0;
		}

		return (int) $matches[1];
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
