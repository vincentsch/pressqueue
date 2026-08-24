<?php
/**
 * WordPress event normalization.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Planning;

use PressQueue\Editorial\PostControlAuthorization;
use PressQueue\Editorial\PostControlMetadata;
use PressQueue\Storage\Record\RuleRecord;
use PressQueue\Storage\StorageValidator;
use PressQueue\Storage\ValidationException;

/**
 * Reads settled WordPress post facts into a normalized event.
 */
final class EventNormalizer {
	public const META_FIRST_PUBLISH_SEEN_AT              = '_pressqueue_first_publish_seen_at';
	public const META_LAST_RUNTIME_FINGERPRINT           = '_pressqueue_last_runtime_fingerprint';
	public const META_LAST_TRIGGER_FINGERPRINT           = '_pressqueue_last_runtime_trigger_fingerprint';
	public const META_LAST_DELIVERY_REVISION_FINGERPRINT = '_pressqueue_last_delivery_revision_fingerprint';

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

		if ( ! PostControlAuthorization::is_supported_post( (int) $post->ID ) ) {
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
		$control_bundle     = PostControlMetadata::read_for_post( (int) $post->ID );
		$canonical_url      = $this->canonical_url( $post );
		$terms              = $this->terms( $post );
		$metadata           = $this->metadata( (int) $post->ID, $metadata_allowlist, $control_bundle );
		$template_values    = $this->template_values( $post, $canonical_url, $terms, $metadata );
		$legacy_metadata    = $this->legacy_metadata( (int) $post->ID, $metadata_allowlist );
		$legacy_templates   = $this->template_values( $post, $canonical_url, $terms, $legacy_metadata );
		$previous_trigger   = $this->previous_trigger_fingerprint( (int) $post->ID );
		$previous_runtime   = $this->previous_runtime_fingerprint( (int) $post->ID );

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
			$previous_trigger,
			null,
			$control_bundle,
			null,
			$previous_runtime,
			null,
			$legacy_metadata,
			$legacy_templates
		);
	}

	/**
	 * Build a preview event from a dirty editor snapshot.
	 *
	 * @param int                 $post_id Post ID.
	 * @param string              $source Diagnostic source.
	 * @param string              $trigger Simulated runtime trigger.
	 * @param array<string,mixed> $snapshot Dirty editor facts.
	 * @param array<string,mixed> $control_bundle Draft control bundle.
	 * @param array<int,string>   $metadata_allowlist Custom metadata allowlist.
	 * @return NormalizedEvent|null
	 */
	public function from_dirty_snapshot( int $post_id, string $source, string $trigger, array $snapshot, array $control_bundle, array $metadata_allowlist = array() ): ?NormalizedEvent {
		$post = function_exists( 'get_post' ) ? get_post( $post_id ) : null;

		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		if ( ! PostControlAuthorization::is_supported_post( $post_id ) ) {
			return null;
		}

		$control_bundle = PostControlMetadata::normalize_bundle( $control_bundle );
		$post_type      = $this->required_snapshot_slug( $snapshot, 'post_type' );
		$post_status    = $this->required_snapshot_slug( $snapshot, 'post_status' );

		if ( ! in_array( $post_status, array( 'publish', 'future', 'draft', 'pending', 'private' ), true ) ) {
			throw new ValidationException( 'Editor preview status is unsupported.' );
		}

		if ( ! PostControlAuthorization::is_supported_post_type( $post_type ) ) {
			throw new ValidationException( 'PressQueue controls are not supported for this post type.' );
		}

		$author_id = $this->required_snapshot_int( $snapshot, 'author_id', true );
		StorageValidator::assert_unsigned_id( 'Author ID', $author_id, true );

		$metadata_allowlist = $this->normalize_metadata_allowlist( $metadata_allowlist );
		$metadata           = $this->dirty_metadata( $snapshot, $metadata_allowlist, $control_bundle );
		$terms              = $this->dirty_terms( $snapshot );
		$canonical_url      = $this->required_snapshot_text( $snapshot, 'canonical_url', 2048 );
		$published_at_gmt   = $this->required_snapshot_datetime( $snapshot, 'published_at_gmt' );
		$modified_at_gmt    = $this->required_snapshot_datetime( $snapshot, 'modified_at_gmt' );
		$template_values    = $this->template_values_from_snapshot( $snapshot, $author_id, $canonical_url, $terms, $metadata, $published_at_gmt );

		return new NormalizedEvent(
			'preview',
			$source,
			$trigger,
			$post_id,
			$post_type,
			$post_status,
			$author_id,
			$canonical_url,
			$published_at_gmt,
			$modified_at_gmt,
			$terms,
			$metadata,
			$template_values,
			$this->previous_trigger_fingerprint( $post_id ),
			null,
			$control_bundle,
			null,
			$this->previous_runtime_fingerprint( $post_id )
		);
	}

	/**
	 * Build metadata allowlist for a set of rules.
	 *
	 * @param array<int,RuleRecord> $rules Rules.
	 * @return array<int,string>
	 */
	public function metadata_allowlist_for_rules( array $rules ): array {
		$allowlist = array( PostControlMetadata::LEGACY_META_KEY );

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
		$allowlist[] = PostControlMetadata::LEGACY_META_KEY;
		$allowlist   = array_values(
			array_unique(
				array_filter(
					$allowlist,
					static function ( $key ): bool {
						return is_string( $key ) && PostControlMetadata::META_KEY !== $key;
					}
				)
			)
		);
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
	 * @param int                 $post_id Post ID.
	 * @param array<int,string>   $allowlist Allowlist.
	 * @param array<string,mixed> $control_bundle Control bundle.
	 * @return array<string,mixed>
	 */
	private function metadata( int $post_id, array $allowlist, array $control_bundle ): array {
		$metadata = array();

		foreach ( $allowlist as $key ) {
			if ( PostControlMetadata::is_control_meta_key( $key ) ) {
				continue;
			}

			$values = get_post_meta( $post_id, $key, false );

			if ( array() === $values ) {
				$metadata[ $key ] = null;
			} elseif ( 1 === count( $values ) ) {
				$metadata[ $key ] = reset( $values );
			} else {
				$metadata[ $key ] = array_values( $values );
			}
		}

		return array_merge( $metadata, PostControlMetadata::metadata_facts( $control_bundle ) );
	}

	/**
	 * Return metadata using the legacy runtime fingerprint shape.
	 *
	 * @param int               $post_id Post ID.
	 * @param array<int,string> $allowlist Allowlist.
	 * @return array<string,mixed>
	 */
	private function legacy_metadata( int $post_id, array $allowlist ): array {
		$metadata = array();

		foreach ( $allowlist as $key ) {
			if ( PostControlMetadata::META_KEY === $key ) {
				continue;
			}

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
	 * Build template values from a dirty editor snapshot.
	 *
	 * @param array<string,mixed>            $snapshot Snapshot.
	 * @param int                            $author_id Author ID.
	 * @param string                         $canonical_url Canonical URL.
	 * @param array<int,array<string,mixed>> $terms Terms.
	 * @param array<string,mixed>            $metadata Metadata.
	 * @param string                         $published_at_gmt Published UTC.
	 * @return array<string,mixed>
	 */
	private function template_values_from_snapshot( array $snapshot, int $author_id, string $canonical_url, array $terms, array $metadata, string $published_at_gmt ): array {
		$renderer = new TemplateRenderer();
		$user     = function_exists( 'get_userdata' ) ? get_userdata( $author_id ) : null;

		return array(
			'author'           => $user instanceof \WP_User ? $renderer->normalize_plain_text( (string) $user->display_name ) : $this->optional_snapshot_text( $snapshot, 'author_name', '' ),
			'author_public_id' => $this->author_public_id( $author_id ),
			'canonical_url'    => $canonical_url,
			'content'          => $renderer->normalize_plain_text( $this->required_snapshot_text( $snapshot, 'content', 1000000 ) ),
			'excerpt'          => $renderer->normalize_plain_text( $this->required_snapshot_text( $snapshot, 'excerpt', 1000000 ) ),
			'metadata'         => $metadata,
			'published_at'     => $this->published_at_display( $published_at_gmt, $published_at_gmt ),
			'terms'            => $terms,
			'title'            => $renderer->normalize_plain_text( $this->required_snapshot_text( $snapshot, 'title', 1000000 ) ),
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
	private function previous_trigger_fingerprint( int $post_id ): ?string {
		$value = get_post_meta( $post_id, self::META_LAST_TRIGGER_FINGERPRINT, true );

		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $value ) ? $value : null;
	}

	/**
	 * Return previous legacy runtime fingerprint.
	 *
	 * @param int $post_id Post ID.
	 * @return string|null
	 */
	private function previous_runtime_fingerprint( int $post_id ): ?string {
		$value = get_post_meta( $post_id, self::META_LAST_RUNTIME_FINGERPRINT, true );

		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $value ) ? $value : null;
	}

	/**
	 * Return dirty snapshot metadata.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @param array<int,string>   $allowlist Metadata allowlist.
	 * @param array<string,mixed> $control_bundle Control bundle.
	 * @return array<string,mixed>
	 */
	private function dirty_metadata( array $snapshot, array $allowlist, array $control_bundle ): array {
		if ( ! isset( $snapshot['metadata'] ) || ! is_array( $snapshot['metadata'] ) || array_is_list( $snapshot['metadata'] ) ) {
			throw new ValidationException( 'Editor preview metadata must be an object.' );
		}

		$metadata = array();
		foreach ( $allowlist as $key ) {
			if ( PostControlMetadata::is_control_meta_key( $key ) ) {
				continue;
			}

			$metadata[ $key ] = array_key_exists( $key, $snapshot['metadata'] )
				? $this->json_safe_value( $snapshot['metadata'][ $key ] )
				: null;
		}

		return array_merge( $metadata, PostControlMetadata::metadata_facts( $control_bundle ) );
	}

	/**
	 * Return dirty snapshot terms.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @return array<int,array<string,mixed>>
	 */
	private function dirty_terms( array $snapshot ): array {
		if ( ! isset( $snapshot['terms'] ) || ! is_array( $snapshot['terms'] ) || ! array_is_list( $snapshot['terms'] ) ) {
			throw new ValidationException( 'Editor preview terms must be a list.' );
		}

		$facts = array();
		foreach ( $snapshot['terms'] as $term ) {
			if ( ! is_array( $term ) || array_is_list( $term ) ) {
				throw new ValidationException( 'Editor preview term entries must be objects.' );
			}

			$taxonomy = $this->term_snapshot_text( $term, 'taxonomy' );
			$term_id  = $this->term_snapshot_id( $term );
			$found    = $this->term_snapshot_record( $term_id, $taxonomy );

			$facts[] = array(
				'ancestor_ids' => $this->term_snapshot_ancestors( $term, $term_id, $taxonomy ),
				'name'         => $this->optional_term_snapshot_text( $term, 'name', $found['name'] ),
				'slug'         => $this->optional_term_snapshot_text( $term, 'slug', $found['slug'] ),
				'taxonomy'     => $taxonomy,
				'term_id'      => $term_id,
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
	 * Return a required snapshot slug.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @param string              $key Key.
	 * @return string
	 */
	private function required_snapshot_slug( array $snapshot, string $key ): string {
		$value = $this->required_snapshot_text( $snapshot, $key, 20 );
		StorageValidator::assert_wordpress_slug( $key, $value );

		return $value;
	}

	/**
	 * Return a required snapshot text field.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @param string              $key Key.
	 * @param int                 $maximum_bytes Maximum bytes.
	 * @return string
	 */
	private function required_snapshot_text( array $snapshot, string $key, int $maximum_bytes ): string {
		if ( ! array_key_exists( $key, $snapshot ) || ! is_string( $snapshot[ $key ] ) ) {
			throw new ValidationException( 'Editor preview snapshot is missing required text.' );
		}

		if ( strlen( $snapshot[ $key ] ) > $maximum_bytes ) {
			throw new ValidationException( 'Editor preview snapshot text is too large.' );
		}

		return $snapshot[ $key ];
	}

	/**
	 * Return optional snapshot text.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @param string              $key Key.
	 * @param string              $fallback Fallback value.
	 * @return string
	 */
	private function optional_snapshot_text( array $snapshot, string $key, string $fallback ): string {
		return isset( $snapshot[ $key ] ) && is_string( $snapshot[ $key ] ) ? $snapshot[ $key ] : $fallback;
	}

	/**
	 * Return a required snapshot integer.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @param string              $key Key.
	 * @param bool                $allow_zero Whether zero is allowed.
	 * @return int
	 */
	private function required_snapshot_int( array $snapshot, string $key, bool $allow_zero = false ): int {
		if ( ! array_key_exists( $key, $snapshot ) || ! is_int( $snapshot[ $key ] ) ) {
			throw new ValidationException( 'Editor preview snapshot is missing required ID.' );
		}

		if ( $allow_zero ? $snapshot[ $key ] < 0 : $snapshot[ $key ] <= 0 ) {
			throw new ValidationException( 'Editor preview snapshot ID is invalid.' );
		}

		return $snapshot[ $key ];
	}

	/**
	 * Return a required UTC datetime.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 * @param string              $key Key.
	 * @return string
	 */
	private function required_snapshot_datetime( array $snapshot, string $key ): string {
		$value = $this->required_snapshot_text( $snapshot, $key, 19 );

		if ( '0000-00-00 00:00:00' !== $value ) {
			StorageValidator::assert_utc_timestamp( $key, $value );
		}

		return $value;
	}

	/**
	 * Return one term text field.
	 *
	 * @param array<string,mixed> $term Term.
	 * @param string              $key Key.
	 * @return string
	 */
	private function term_snapshot_text( array $term, string $key ): string {
		if ( ! isset( $term[ $key ] ) || ! is_string( $term[ $key ] ) || '' === $term[ $key ] ) {
			throw new ValidationException( 'Editor preview term text is invalid.' );
		}

		return $term[ $key ];
	}

	/**
	 * Return an optional term text field.
	 *
	 * @param array<string,mixed> $term Term.
	 * @param string              $key Key.
	 * @param string              $fallback Fallback value.
	 * @return string
	 */
	private function optional_term_snapshot_text( array $term, string $key, string $fallback ): string {
		if ( isset( $term[ $key ] ) && is_string( $term[ $key ] ) && '' !== $term[ $key ] ) {
			return $term[ $key ];
		}

		if ( '' !== $fallback ) {
			return $fallback;
		}

		throw new ValidationException( 'Editor preview term text is invalid.' );
	}

	/**
	 * Return one term ID.
	 *
	 * @param array<string,mixed> $term Term.
	 * @return int
	 */
	private function term_snapshot_id( array $term ): int {
		if ( ! isset( $term['term_id'] ) || ! is_int( $term['term_id'] ) || $term['term_id'] <= 0 ) {
			throw new ValidationException( 'Editor preview term ID is invalid.' );
		}

		return $term['term_id'];
	}

	/**
	 * Return normalized term ancestors from the snapshot or WordPress.
	 *
	 * @param array<string,mixed> $term Term.
	 * @param int                 $term_id Term ID.
	 * @param string              $taxonomy Taxonomy.
	 * @return array<int,int>
	 */
	private function term_snapshot_ancestors( array $term, int $term_id, string $taxonomy ): array {
		$ancestor_ids = $term['ancestor_ids'] ?? null;

		if ( null === $ancestor_ids && function_exists( 'get_ancestors' ) ) {
			$ancestor_ids = get_ancestors( $term_id, $taxonomy, 'taxonomy' );
		}

		if ( null === $ancestor_ids ) {
			$ancestor_ids = array();
		}

		if ( ! is_array( $ancestor_ids ) || ! array_is_list( $ancestor_ids ) ) {
			throw new ValidationException( 'Editor preview term ancestors must be a list.' );
		}

		$ancestors = array();
		foreach ( $ancestor_ids as $ancestor_id ) {
			if ( is_string( $ancestor_id ) && ctype_digit( $ancestor_id ) ) {
				$ancestor_id = (int) $ancestor_id;
			}

			if ( ! is_int( $ancestor_id ) || $ancestor_id <= 0 ) {
				throw new ValidationException( 'Editor preview term ancestor IDs are invalid.' );
			}

			$ancestors[] = $ancestor_id;
		}

		$ancestors = array_values( array_unique( $ancestors ) );
		sort( $ancestors, SORT_NUMERIC );

		return $ancestors;
	}

	/**
	 * Load a term record for omitted dirty snapshot labels.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $taxonomy Taxonomy.
	 * @return array{name:string,slug:string}
	 */
	private function term_snapshot_record( int $term_id, string $taxonomy ): array {
		if ( function_exists( 'get_term' ) && function_exists( 'is_wp_error' ) ) {
			$term = get_term( $term_id, $taxonomy );
			if ( $term instanceof \WP_Term ) {
				return array(
					'name' => (string) $term->name,
					'slug' => (string) $term->slug,
				);
			}
		}

		return array(
			'name' => '',
			'slug' => '',
		);
	}

	/**
	 * Ensure dirty metadata is JSON-safe.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function json_safe_value( $value ) {
		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value ) || is_string( $value ) ) {
			return $value;
		}

		if ( is_array( $value ) ) {
			if ( ! array_is_list( $value ) ) {
				throw new ValidationException( 'Editor preview metadata objects are not supported.' );
			}

			return array_map( array( $this, 'json_safe_value' ), $value );
		}

		throw new ValidationException( 'Editor preview metadata contains an unsupported value.' );
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
