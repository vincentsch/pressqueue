<?php
/**
 * WordPress event normalization.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Planning;

use PressQueue\Storage\Record\RuleRecord;

/**
 * Reads settled WordPress post facts into a normalized event.
 */
final class EventNormalizer {
	public const META_FIRST_PUBLISH_SEEN_AT    = '_pressqueue_first_publish_seen_at';
	public const META_LAST_RUNTIME_FINGERPRINT = '_pressqueue_last_runtime_fingerprint';

	/**
	 * Build a normalized event from a post ID.
	 *
	 * @param int               $post_id Post ID.
	 * @param string            $mode Event mode.
	 * @param string            $source Diagnostic source.
	 * @param string            $trigger Trigger.
	 * @param array<int,string> $metadata_allowlist Custom metadata allowlist.
	 * @return NormalizedEvent|null
	 */
	public function from_post_id( int $post_id, string $mode, string $source, string $trigger, array $metadata_allowlist = array() ): ?NormalizedEvent {
		if ( function_exists( 'wp_is_post_autosave' ) && wp_is_post_autosave( $post_id ) ) {
			return null;
		}

		if ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post_id ) ) {
			return null;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		if ( 'runtime' === $mode && 'publish' !== $post->post_status ) {
			return null;
		}

		if ( 'runtime' === $mode && ! in_array( $post->post_status, array( 'publish' ), true ) ) {
			return null;
		}

		if ( 'preview' === $mode && ! in_array( $post->post_status, array( 'publish', 'future', 'draft', 'pending', 'private' ), true ) ) {
			return null;
		}

		$metadata_allowlist = $this->normalize_metadata_allowlist( $metadata_allowlist );
		$canonical_url      = $this->canonical_url( $post );
		$terms              = $this->terms( $post );
		$metadata           = $this->metadata( (int) $post->ID, $metadata_allowlist );
		$template_values    = $this->template_values( $post, $canonical_url, $terms, $metadata );
		$previous           = $this->previous_runtime_fingerprint( (int) $post->ID );

		return new NormalizedEvent(
			$mode,
			$source,
			$trigger,
			(int) $post->ID,
			(string) $post->post_type,
			(string) $post->post_status,
			(int) $post->post_author,
			$canonical_url,
			$this->normalize_datetime( (string) $post->post_date_gmt, (string) $post->post_date ),
			$this->normalize_datetime( (string) $post->post_modified_gmt, (string) $post->post_modified ),
			$terms,
			$metadata,
			$template_values,
			$previous
		);
	}

	/**
	 * Build metadata allowlist for a set of rules.
	 *
	 * @param array<int,RuleRecord> $rules Rules.
	 * @return array<int,string>
	 */
	public function metadata_allowlist_for_rules( array $rules ): array {
		$allowlist = array( '_pressqueue_policy' );

		foreach ( $rules as $rule ) {
			$allowlist = array_merge( $allowlist, $rule->custom_field_allowlist );
		}

		return $this->normalize_metadata_allowlist( $allowlist );
	}

	/**
	 * Normalize metadata keys.
	 *
	 * @param array<int,string> $allowlist Allowlist.
	 * @return array<int,string>
	 */
	private function normalize_metadata_allowlist( array $allowlist ): array {
		$allowlist[] = '_pressqueue_policy';
		$allowlist   = array_values( array_unique( array_filter( $allowlist, 'is_string' ) ) );
		sort( $allowlist, SORT_STRING );

		return $allowlist;
	}

	/**
	 * Return canonical URL.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private function canonical_url( \WP_Post $post ): string {
		$url = get_permalink( $post );

		return is_string( $url ) ? $url : '';
	}

	/**
	 * Return sorted term facts.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<int,array<string,mixed>>
	 */
	private function terms( \WP_Post $post ): array {
		$taxonomies = get_object_taxonomies( $post->post_type );

		if ( ! is_array( $taxonomies ) || array() === $taxonomies ) {
			return array();
		}

		$terms = wp_get_object_terms( (int) $post->ID, $taxonomies, array( 'fields' => 'all' ) );

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		$facts = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			$ancestor_ids = get_ancestors( (int) $term->term_id, (string) $term->taxonomy, 'taxonomy' );
			$ancestor_ids = array_map( 'intval', is_array( $ancestor_ids ) ? $ancestor_ids : array() );
			sort( $ancestor_ids, SORT_NUMERIC );

			$facts[] = array(
				'ancestor_ids' => $ancestor_ids,
				'name'         => (string) $term->name,
				'slug'         => (string) $term->slug,
				'taxonomy'     => (string) $term->taxonomy,
				'term_id'      => (int) $term->term_id,
			);
		}

		usort(
			$facts,
			static function ( array $left, array $right ): int {
				$taxonomy = strcmp( (string) $left['taxonomy'], (string) $right['taxonomy'] );

				if ( 0 !== $taxonomy ) {
					return $taxonomy;
				}

				$name = strcmp( (string) $left['name'], (string) $right['name'] );

				if ( 0 !== $name ) {
					return $name;
				}

				return (int) $left['term_id'] <=> (int) $right['term_id'];
			}
		);

		return $facts;
	}

	/**
	 * Return allowlisted metadata.
	 *
	 * @param int               $post_id Post ID.
	 * @param array<int,string> $allowlist Allowlist.
	 * @return array<string,mixed>
	 */
	private function metadata( int $post_id, array $allowlist ): array {
		$metadata = array();

		foreach ( $allowlist as $key ) {
			$values = get_post_meta( $post_id, $key, false );

			if ( array() === $values ) {
				$metadata[ $key ] = null;
			} elseif ( 1 === count( $values ) ) {
				$metadata[ $key ] = reset( $values );
			} else {
				$metadata[ $key ] = array_values( $values );
			}
		}

		return $metadata;
	}

	/**
	 * Build template input values.
	 *
	 * @param \WP_Post                       $post Post.
	 * @param string                         $canonical_url Canonical URL.
	 * @param array<int,array<string,mixed>> $terms Terms.
	 * @param array<string,mixed>            $metadata Metadata.
	 * @return array<string,mixed>
	 */
	private function template_values( \WP_Post $post, string $canonical_url, array $terms, array $metadata ): array {
		$renderer = new TemplateRenderer();
		$user     = get_userdata( (int) $post->post_author );

		return array(
			'author'           => $user instanceof \WP_User ? $renderer->normalize_plain_text( (string) $user->display_name ) : '',
			'author_public_id' => $this->author_public_id( (int) $post->post_author ),
			'canonical_url'    => $canonical_url,
			'content'          => $renderer->normalize_plain_text( (string) $post->post_content ),
			'excerpt'          => $renderer->normalize_plain_text( (string) $post->post_excerpt ),
			'metadata'         => $metadata,
			'published_at'     => $this->published_at_display( (string) $post->post_date_gmt, (string) $post->post_date ),
			'terms'            => $terms,
			'title'            => $renderer->normalize_plain_text( (string) $post->post_title ),
		);
	}

	/**
	 * Return the optional PressQueue author public ID.
	 *
	 * @param int $author_id Author ID.
	 * @return string
	 */
	private function author_public_id( int $author_id ): string {
		$value = get_user_meta( $author_id, '_pressqueue_author_public_id', true );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Return previous runtime fingerprint.
	 *
	 * @param int $post_id Post ID.
	 * @return string|null
	 */
	private function previous_runtime_fingerprint( int $post_id ): ?string {
		$value = get_post_meta( $post_id, self::META_LAST_RUNTIME_FINGERPRINT, true );

		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $value ) ? $value : null;
	}

	/**
	 * Normalize WordPress date fields into UTC DATETIME.
	 *
	 * @param string $gmt GMT date.
	 * @param string $local Local date.
	 * @return string
	 */
	private function normalize_datetime( string $gmt, string $local ): string {
		if ( '' !== $gmt && '0000-00-00 00:00:00' !== $gmt ) {
			return $gmt;
		}

		if ( '' !== $local && '0000-00-00 00:00:00' !== $local && function_exists( 'get_gmt_from_date' ) ) {
			return get_gmt_from_date( $local );
		}

		return '0000-00-00 00:00:00';
	}

	/**
	 * Return published time for display input.
	 *
	 * @param string $gmt GMT date.
	 * @param string $local Local date.
	 * @return string
	 */
	private function published_at_display( string $gmt, string $local ): string {
		$utc = $this->normalize_datetime( $gmt, $local );

		if ( '0000-00-00 00:00:00' === $utc ) {
			return '';
		}

		$timestamp = strtotime( $utc . ' UTC' );

		if ( false === $timestamp ) {
			return '';
		}

		return gmdate( 'c', $timestamp ) . ' ' . $this->site_timezone_label();
	}

	/**
	 * Return site timezone label.
	 *
	 * @return string
	 */
	private function site_timezone_label(): string {
		if ( function_exists( 'wp_timezone_string' ) ) {
			$timezone = wp_timezone_string();

			if ( '' !== $timezone ) {
				return $timezone;
			}
		}

		return 'UTC';
	}
}
