<?php
/**
 * Inline template renderer.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Planning;

use PressQueue\Provider\Capability\DeliveryCapability;
use PressQueue\Storage\ValidationException;

/**
 * Renders safe plain-text payload previews from allowlisted tags.
 */
final class TemplateRenderer {
	private const MAX_RENDERED_LENGTH = 1000;

	/**
	 * Render a template message.
	 *
	 * @param array<string,mixed> $template Template document.
	 * @param NormalizedEvent     $event Event.
	 * @return string
	 */
	public function render( array $template, NormalizedEvent $event ): string {
		$message = $template['message'] ?? null;

		if ( ! is_string( $message ) || '' === trim( $message ) ) {
			throw new ValidationException( 'Inline template message is invalid.' );
		}

		$rendered = preg_replace_callback(
			'/\{\{\s*([^}]+?)\s*\}\}/',
			function ( array $matches ) use ( $template, $event ): string {
				return $this->render_tag( trim( (string) $matches[1] ), $template, $event );
			},
			$message
		);

		if ( null === $rendered ) {
			throw new ValidationException( 'Inline template could not be rendered.' );
		}

		if ( preg_match( '/\{\{|\}\}/', $rendered ) ) {
			throw new ValidationException( 'Inline template contains malformed tags.' );
		}

		$rendered = $this->normalize_plain_text( $rendered );

		if ( $this->length( $rendered ) > self::MAX_RENDERED_LENGTH ) {
			$rendered = $this->truncate( $rendered, self::MAX_RENDERED_LENGTH );
		}

		return $rendered;
	}

	/**
	 * Build a rendered payload preview document.
	 *
	 * @param array<string,mixed> $template Template document.
	 * @param NormalizedEvent     $event Event.
	 * @param array<string,mixed> $destination Destination facts.
	 * @param string              $rule_public_id Rule public ID.
	 * @param int                 $rule_version Rule version.
	 * @return array<string,mixed>
	 */
	public function payload_preview( array $template, NormalizedEvent $event, array $destination, string $rule_public_id, int $rule_version ): array {
		$canonical_url = $event->canonical_url;

		if ( ! $this->is_valid_url( $canonical_url ) ) {
			throw new ValidationException( 'Canonical URL is invalid.' );
		}

		return array(
			'canonical_url'        => $canonical_url,
			'channel_id'           => (int) $destination['channel_id'],
			'channel_label'        => (string) $destination['channel_label'],
			'channel_public_id'    => (string) $destination['channel_public_id'],
			'connection_id'        => (int) $destination['connection_id'],
			'connection_label'     => (string) $destination['connection_label'],
			'connection_public_id' => (string) $destination['connection_public_id'],
			'event_mode'           => $event->mode,
			'message'              => $this->render( $template, $event ),
			'post_id'              => $event->post_id,
			'provider'             => (string) $destination['provider'],
			'remote_channel_id'    => (string) $destination['remote_channel_id'],
			'rule_public_id'       => $rule_public_id,
			'rule_version'         => $rule_version,
			'schedule_slot'        => 'immediate',
			'service'              => (string) $destination['service'],
			'trigger'              => $event->trigger,
		);
	}

	/**
	 * Build a payload schema version 2 preview document.
	 *
	 * @param string              $message_template Resolved message template.
	 * @param array<string,mixed> $template_set Template set snapshot.
	 * @param array<string,mixed> $template_provenance Template source provenance.
	 * @param array<int,string>   $custom_field_allowlist Rule custom field allowlist.
	 * @param NormalizedEvent     $event Event.
	 * @param array<string,mixed> $destination Destination facts.
	 * @param string              $rule_public_id Rule public ID.
	 * @param int                 $rule_version Rule version.
	 * @param SchedulePolicy      $schedule Resolved schedule.
	 * @param MediaSnapshot       $media Resolved media.
	 * @param array<string,mixed> $capability Channel capability document.
	 * @return array<string,mixed>
	 */
	public function payload_preview_v2(
		string $message_template,
		array $template_set,
		array $template_provenance,
		array $custom_field_allowlist,
		NormalizedEvent $event,
		array $destination,
		string $rule_public_id,
		int $rule_version,
		SchedulePolicy $schedule,
		MediaSnapshot $media,
		array $capability
	): array {
		$canonical_url = $event->canonical_url;

		if ( ! $this->is_valid_url( $canonical_url ) ) {
			throw new ValidationException( 'Canonical URL is invalid.' );
		}

		$template = array(
			'custom_field_allowlist' => $custom_field_allowlist,
			'message'                => $message_template,
			'schema_version'         => 1,
		);

		$rendered_message    = $this->render( $template, $event );
		$final_text          = trim( $rendered_message ) . "\n\n" . $canonical_url;
		$final_length        = $this->length( $final_text );
		$media_document      = $media->document();
		$capability_decision = DeliveryCapability::decision( $capability, $schedule->slot, $final_length, $media_document );

		if ( ! $capability_decision['compatible'] ) {
			throw new ValidationException( 'Destination capability does not support the rendered payload.' );
		}

		return array(
			'adapter_encoding_version'         => 2,
			'bound_capability_catalog_version' => DeliveryCapability::catalog_version( $capability ),
			'bound_capability_hash'            => DeliveryCapability::hash( $capability ),
			'canonical_url'                    => $canonical_url,
			'capability_decision'              => $capability_decision,
			'capability_schema_version'        => (int) ( $capability['schema_version'] ?? 0 ),
			'channel_id'                       => (int) $destination['channel_id'],
			'channel_label'                    => (string) $destination['channel_label'],
			'channel_public_id'                => (string) $destination['channel_public_id'],
			'connection_id'                    => (int) $destination['connection_id'],
			'connection_label'                 => (string) $destination['connection_label'],
			'connection_public_id'             => (string) $destination['connection_public_id'],
			'event_mode'                       => $event->mode,
			'final_encoded_text'               => $final_text,
			'final_encoded_text_length'        => $final_length,
			'media_identity_hash'              => $media->identity_hash(),
			'media_snapshot'                   => $media_document,
			'message'                          => $rendered_message,
			'payload_schema_version'           => 2,
			'post_id'                          => $event->post_id,
			'provider'                         => (string) $destination['provider'],
			'remote_channel_id'                => (string) $destination['remote_channel_id'],
			'rule_public_id'                   => $rule_public_id,
			'rule_version'                     => $rule_version,
			'schedule_slot'                    => $schedule->slot,
			'schedule_timezone'                => $schedule->timezone,
			'scheduled_for_utc'                => $schedule->scheduled_for_storage(),
			'service'                          => (string) $destination['service'],
			'template_provenance'              => $template_provenance,
			'template_snapshot'                => $template_set,
			'trigger'                          => $event->trigger,
		);
	}

	/**
	 * Render one tag expression.
	 *
	 * @param string              $expression Tag expression.
	 * @param array<string,mixed> $template Template document.
	 * @param NormalizedEvent     $event Event.
	 * @return string
	 */
	private function render_tag( string $expression, array $template, NormalizedEvent $event ): string {
		$parts = array_map( 'trim', explode( '|', $expression ) );
		$tag   = array_shift( $parts );

		if ( null === $tag || '' === $tag ) {
			throw new ValidationException( 'Template tag is invalid.' );
		}

		$value = $this->tag_value( $tag, $template, $event );

		foreach ( $parts as $modifier ) {
			$value = $this->apply_modifier( $value, $modifier );
		}

		return $value;
	}

	/**
	 * Return a tag value.
	 *
	 * @param string              $tag Tag.
	 * @param array<string,mixed> $template Template document.
	 * @param NormalizedEvent     $event Event.
	 * @return string
	 */
	private function tag_value( string $tag, array $template, NormalizedEvent $event ): string {
		switch ( $tag ) {
			case 'title':
			case 'excerpt':
			case 'content':
			case 'canonical_url':
			case 'published_at':
			case 'author':
				return $this->normalize_plain_text( (string) ( $event->template_values[ $tag ] ?? '' ) );
		}

		if ( str_starts_with( $tag, 'terms:' ) ) {
			$taxonomy = substr( $tag, 6 );

			return $this->terms_value( $taxonomy, $event );
		}

		if ( str_starts_with( $tag, 'meta:' ) ) {
			$key       = substr( $tag, 5 );
			$allowlist = $template['custom_field_allowlist'] ?? array();

			if ( ! is_array( $allowlist ) || ! in_array( $key, $allowlist, true ) ) {
				throw new ValidationException( 'Template custom field tag is not allowlisted.' );
			}

			if ( ! array_key_exists( $key, $event->metadata ) ) {
				throw new ValidationException( 'Template custom field is missing.' );
			}

			$value = $event->metadata[ $key ];

			if ( null === $value ) {
				throw new ValidationException( 'Template custom field is missing.' );
			}

			if ( is_array( $value ) || is_object( $value ) ) {
				throw new ValidationException( 'Template custom field value is not scalar.' );
			}

			return $this->normalize_plain_text( $this->scalar_to_text( $value ) );
		}

		throw new ValidationException( 'Template tag is unknown.' );
	}

	/**
	 * Convert scalar metadata to stable text.
	 *
	 * @param mixed $value Scalar value.
	 * @return string
	 */
	private function scalar_to_text( $value ): string {
		if ( true === $value ) {
			return 'true';
		}

		if ( false === $value ) {
			return 'false';
		}

		return (string) $value;
	}

	/**
	 * Apply a supported modifier.
	 *
	 * @param string $value Value.
	 * @param string $modifier Modifier expression.
	 * @return string
	 */
	private function apply_modifier( string $value, string $modifier ): string {
		if ( 1 !== preg_match( '/^truncate:([1-9][0-9]*)$/', $modifier, $matches ) ) {
			throw new ValidationException( 'Template modifier is invalid.' );
		}

		return $this->truncate( $value, (int) $matches[1] );
	}

	/**
	 * Build a sorted terms value.
	 *
	 * @param string          $taxonomy Taxonomy.
	 * @param NormalizedEvent $event Event.
	 * @return string
	 */
	private function terms_value( string $taxonomy, NormalizedEvent $event ): string {
		if ( '' === $taxonomy ) {
			throw new ValidationException( 'Template terms tag is invalid.' );
		}

		$terms = array();
		foreach ( $event->terms as $term ) {
			if ( ( $term['taxonomy'] ?? null ) === $taxonomy ) {
				$terms[] = (string) ( $term['name'] ?? '' );
			}
		}

		$terms = array_filter(
			$terms,
			static function ( string $term ): bool {
				return '' !== $term;
			}
		);

		return $this->normalize_plain_text( implode( ', ', $terms ) );
	}

	/**
	 * Normalize plain text.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public function normalize_plain_text( string $value ): string {
		$value = $this->clean_utf8( $value );
		$value = $this->strip_shortcode_markup( $value );
		$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		if ( function_exists( 'wp_strip_all_tags' ) ) {
			$value = wp_strip_all_tags( $value, true );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Fallback is used only when WordPress is unavailable in isolated tests.
			$value = strip_tags( $value );
		}

		$value = preg_replace( '/\s+/u', ' ', $value );

		return trim( null === $value ? '' : $value );
	}

	/**
	 * Strip shortcode-like markup without executing callbacks.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function strip_shortcode_markup( string $value ): string {
		if ( function_exists( 'strip_shortcodes' ) ) {
			return strip_shortcodes( $value );
		}

		$value = preg_replace( '/\[[^\]]+\]/u', '', $value );

		return null === $value ? '' : $value;
	}

	/**
	 * Replace invalid UTF-8 where the runtime provides mbstring, otherwise drop invalid bytes.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function clean_utf8( string $value ): string {
		if ( 1 === preg_match( '//u', $value ) ) {
			return $value;
		}

		if ( function_exists( 'mb_convert_encoding' ) ) {
			return mb_convert_encoding( $value, 'UTF-8', 'UTF-8' );
		}

		$clean = iconv( 'UTF-8', 'UTF-8//IGNORE', $value );

		return false === $clean ? '' : $clean;
	}

	/**
	 * Truncate to a maximum character count.
	 *
	 * @param string $value Value.
	 * @param int    $maximum Maximum output characters.
	 * @return string
	 */
	private function truncate( string $value, int $maximum ): string {
		if ( $maximum <= 0 || $this->length( $value ) <= $maximum ) {
			return $value;
		}

		if ( $maximum <= 3 ) {
			return str_repeat( '.', $maximum );
		}

		return $this->substring( $value, 0, $maximum - 3 ) . '...';
	}

	/**
	 * Return Unicode character length.
	 *
	 * @param string $value Value.
	 * @return int
	 */
	private function length( string $value ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $value, 'UTF-8' );
		}

		preg_match_all( '/./us', $value, $matches );

		return count( $matches[0] );
	}

	/**
	 * Return Unicode substring.
	 *
	 * @param string $value Value.
	 * @param int    $start Start.
	 * @param int    $length Length.
	 * @return string
	 */
	private function substring( string $value, int $start, int $length ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, $start, $length, 'UTF-8' );
		}

		preg_match_all( '/./us', $value, $matches );

		return implode( '', array_slice( $matches[0], $start, $length ) );
	}

	/**
	 * Validate a rendered canonical URL.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function is_valid_url( string $url ): bool {
		if ( '' === $url || false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback is used only when WordPress is unavailable in isolated tests.
		$scheme = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url, PHP_URL_SCHEME ) : parse_url( $url, PHP_URL_SCHEME );

		return in_array( $scheme, array( 'http', 'https' ), true );
	}
}
