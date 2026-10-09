<?php
/**
 * Plain-language descriptions of stored rules.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Storage\Record\ChannelRecord;
use PressQueue\Storage\Record\RuleRecord;

/**
 * Turns the stored rule documents into one sentence that says what the rule does.
 */
final class RuleSummary {
	/**
	 * Condition types the simple condition editor can show.
	 */
	private const SIMPLE_CONDITIONS = array( 'post_type', 'author', 'taxonomy', 'metadata' );

	/**
	 * Channels keyed by public ID.
	 *
	 * @var array<string,ChannelRecord>
	 */
	private array $channels = array();

	/**
	 * Constructor.
	 *
	 * @param array<int,ChannelRecord> $channels Known channels.
	 */
	public function __construct( array $channels = array() ) {
		foreach ( $channels as $channel ) {
			$this->channels[ $channel->public_id ] = $channel;
		}
	}

	/**
	 * Return the condition rows when the tree is one flat group the simple editor understands.
	 *
	 * @param array<string,mixed> $tree Condition tree.
	 * @return array{mode:string,rows:array<int,array<string,mixed>>}|null
	 */
	public static function simple_conditions( array $tree ): ?array {
		if ( 'group' !== ( $tree['type'] ?? null ) ) {
			return null;
		}

		$mode     = $tree['mode'] ?? ( $tree['operator'] ?? null );
		$children = $tree['children'] ?? null;

		if ( ! in_array( $mode, array( 'ALL', 'ANY' ), true ) || ! is_array( $children ) || ! array_is_list( $children ) || array() === $children ) {
			return null;
		}

		$rows = array();
		foreach ( $children as $child ) {
			if ( ! is_array( $child ) || 'condition' !== ( $child['type'] ?? null ) || ! self::is_simple_row( $child ) ) {
				return null;
			}

			$rows[] = $child;
		}

		return array(
			'mode' => (string) $mode,
			'rows' => $rows,
		);
	}

	/**
	 * Return the one-sentence description as escaped HTML.
	 *
	 * @param RuleRecord $rule Rule.
	 * @return string
	 */
	public function sentence( RuleRecord $rule ): string {
		$subject = $this->subject( $rule->condition_tree );

		if ( 'exclude' === $rule->effect ) {
			/* translators: %s: the kind of post, for example "a Post in Private Journal". */
			return sprintf( esc_html__( 'Never share %s.', 'pressqueue' ), $subject ) . ' ' . esc_html__( 'This beats every sharing rule.', 'pressqueue' );
		}

		$triggers = $this->trigger_phrase( $rule->triggers['triggers'] ?? array() );
		$channels = $this->channel_chips( $rule );
		$timing   = $this->timing_phrase( $rule->schedule_policy, $channels );
		$media    = $this->media_phrase( (string) ( $rule->media_policy['mode'] ?? 'none' ) );

		return sprintf(
			/* translators: 1: the kind of post, 2: what happens to the post such as "published", 3: where and when it is sent, 4: the image choice. */
			esc_html__( 'When %1$s is %2$s, %3$s %4$s.', 'pressqueue' ),
			$subject,
			$triggers,
			$timing,
			$media
		);
	}

	/**
	 * Return plain text lines that describe each condition, for screens without JavaScript.
	 *
	 * @param array<string,mixed> $tree Condition tree.
	 * @return array<int,string>
	 */
	public function condition_lines( array $tree ): array {
		$simple = self::simple_conditions( $tree );

		if ( null === $simple ) {
			return array( __( 'Advanced conditions. Edit them as JSON under Advanced.', 'pressqueue' ) );
		}

		$lines = array();
		foreach ( $simple['rows'] as $row ) {
			$lines[] = wp_strip_all_tags( $this->row_phrase( $row, true ) );
		}

		return $lines;
	}

	/**
	 * Return the post type labels a rule targets, or an empty list when it covers every type.
	 *
	 * @param array<string,mixed> $tree Condition tree.
	 * @return array<int,string>
	 */
	public static function post_types( array $tree ): array {
		$simple = self::simple_conditions( $tree );
		$types  = array();

		if ( null === $simple ) {
			return $types;
		}

		foreach ( $simple['rows'] as $row ) {
			if ( 'post_type' === $row['condition'] ) {
				$types = array_merge( $types, self::string_values( $row['value'] ) );
			}
		}

		return array_values( array_unique( $types ) );
	}

	/**
	 * Return the short label for a post type.
	 *
	 * @param string $post_type Post type slug.
	 * @param bool   $plural Whether to return the plural label.
	 * @return string
	 */
	public static function post_type_label( string $post_type, bool $plural = false ): string {
		$object = function_exists( 'get_post_type_object' ) ? get_post_type_object( $post_type ) : null;

		if ( $object instanceof \WP_Post_Type ) {
			$label = $plural ? $object->labels->name : $object->labels->singular_name;
			if ( is_string( $label ) && '' !== $label ) {
				return $label;
			}
		}

		return $post_type;
	}

	/**
	 * Return a term name or a placeholder when the term is gone.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $taxonomy Taxonomy.
	 * @return string
	 */
	public static function term_name( int $term_id, string $taxonomy ): string {
		$term = get_term( $term_id, $taxonomy );

		if ( $term instanceof \WP_Term ) {
			return html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		if ( ! taxonomy_exists( $taxonomy ) ) {
			/* translators: %d: term ID. */
			return sprintf( __( 'Term #%d', 'pressqueue' ), $term_id );
		}

		/* translators: %d: term ID. */
		return sprintf( __( 'Deleted term #%d', 'pressqueue' ), $term_id );
	}

	/**
	 * Return an author display name or a placeholder.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function author_name( int $user_id ): string {
		$user = get_userdata( $user_id );

		if ( $user instanceof \WP_User && '' !== $user->display_name ) {
			return $user->display_name;
		}

		/* translators: %d: user ID. */
		return sprintf( __( 'Deleted user #%d', 'pressqueue' ), $user_id );
	}

	/**
	 * Return the taxonomy's singular label.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return string
	 */
	public static function taxonomy_label( string $taxonomy ): string {
		$object = get_taxonomy( $taxonomy );

		if ( $object instanceof \WP_Taxonomy && is_string( $object->labels->singular_name ) && '' !== $object->labels->singular_name ) {
			return $object->labels->singular_name;
		}

		return $taxonomy;
	}

	/**
	 * Return a one-line description of a rule's message.
	 *
	 * @param RuleRecord $rule Rule.
	 * @return array{message:string,variants:array<int,string>}
	 */
	public static function message_summary( RuleRecord $rule ): array {
		$set      = is_array( $rule->template_set ) ? $rule->template_set : array();
		$message  = (string) ( $set['default_message'] ?? ( $rule->inline_template['message'] ?? '' ) );
		$variants = array();

		if ( isset( $set['service_messages'] ) && is_array( $set['service_messages'] ) ) {
			foreach ( array_keys( $set['service_messages'] ) as $service ) {
				$variants[] = Labels::service( (string) $service );
			}
		}

		return array(
			'message'  => $message,
			'variants' => $variants,
		);
	}

	/**
	 * Return the escaped noun phrase that names the posts a rule covers.
	 *
	 * @param array<string,mixed> $tree Condition tree.
	 * @return string
	 */
	private function subject( array $tree ): string {
		$simple = self::simple_conditions( $tree );

		if ( null === $simple ) {
			return esc_html__( 'a post matching advanced conditions', 'pressqueue' );
		}

		$rows = $simple['rows'];

		if ( 'ANY' === $simple['mode'] && count( $rows ) > 1 ) {
			$phrases = array();
			foreach ( $rows as $row ) {
				$phrases[] = $this->row_phrase( $row, true );
			}

			/* translators: %s: conditions joined with "or", for example "is in Recipes or is by Mara". */
			return sprintf( esc_html__( 'a post that %s', 'pressqueue' ), implode( ' ' . esc_html__( 'or', 'pressqueue' ) . ' ', $phrases ) );
		}

		$types      = array();
		$qualifiers = array();
		foreach ( $rows as $row ) {
			if ( 'post_type' === $row['condition'] ) {
				$types = array_merge( $types, self::string_values( $row['value'] ) );
				continue;
			}

			$qualifiers[] = $this->row_phrase( $row, false );
		}

		$types = array_values( array_unique( $types ) );

		if ( array() === $types ) {
			$noun = esc_html__( 'a post', 'pressqueue' );
		} else {
			$chips = array();
			foreach ( $types as $type ) {
				$chips[] = self::chip( self::post_type_label( $type ) );
			}

			$label = self::post_type_label( $types[0] );
			$noun  = 1 === preg_match( '/^[aeiou]/i', $label )
				/* translators: %s: post type names such as "Event". Used before a word that starts with a vowel. */
				? sprintf( esc_html__( 'an %s', 'pressqueue' ), self::join( $chips, __( 'or', 'pressqueue' ) ) )
				/* translators: %s: post type names such as "Post". */
				: sprintf( esc_html__( 'a %s', 'pressqueue' ), self::join( $chips, __( 'or', 'pressqueue' ) ) );
		}

		if ( array() === $qualifiers ) {
			return $noun;
		}

		return $noun . ' ' . implode( ' ' . esc_html__( 'and', 'pressqueue' ) . ' ', $qualifiers );
	}

	/**
	 * Describe one simple condition row as escaped HTML.
	 *
	 * @param array<string,mixed> $row Condition row.
	 * @param bool                $predicate Whether to use the "is in ..." form instead of "in ...".
	 * @return string
	 */
	private function row_phrase( array $row, bool $predicate ): string {
		$operator = (string) $row['operator'];
		$value    = $row['value'];

		switch ( $row['condition'] ) {
			case 'post_type':
				$chips = array();
				foreach ( self::string_values( $value ) as $type ) {
					$chips[] = self::chip( self::post_type_label( $type ) );
				}
				/* translators: %s: post type names. */
				return sprintf( esc_html__( 'is a %s', 'pressqueue' ), self::join( $chips, __( 'or', 'pressqueue' ) ) );

			case 'author':
				$chips = array();
				foreach ( self::int_values( $value ) as $user_id ) {
					$chips[] = self::chip( self::author_name( $user_id ) );
				}
				$names = self::join( $chips, __( 'or', 'pressqueue' ) );
				/* translators: %s: author names. */
				return $predicate ? sprintf( esc_html__( 'is by %s', 'pressqueue' ), $names ) : sprintf( esc_html__( 'by %s', 'pressqueue' ), $names );

			case 'taxonomy':
				return $this->taxonomy_phrase( $operator, is_array( $value ) ? $value : array(), $predicate );

			case 'metadata':
				return self::metadata_phrase( $operator, is_array( $value ) ? $value : array(), $predicate );
		}

		return esc_html__( 'matching advanced conditions', 'pressqueue' );
	}

	/**
	 * Describe a taxonomy condition.
	 *
	 * @param string              $operator Operator.
	 * @param array<string,mixed> $value Condition value.
	 * @param bool                $predicate Whether to use the predicate form.
	 * @return string
	 */
	private function taxonomy_phrase( string $operator, array $value, bool $predicate ): string {
		$taxonomy = (string) ( $value['taxonomy'] ?? '' );
		$terms    = self::int_values( $value['terms'] ?? array() );
		$chips    = array();

		foreach ( $terms as $term_id ) {
			$chips[] = self::chip( self::term_name( $term_id, $taxonomy ) );
		}

		$names = array() === $chips
			? '<span class="pq-tag pq-tag--warn">' . esc_html__( 'nothing picked yet', 'pressqueue' ) . '</span>'
			: self::join( $chips, 'has_all' === $operator ? __( 'and', 'pressqueue' ) : __( 'or', 'pressqueue' ) );

		if ( true === ( $value['include_ancestors'] ?? false ) && is_taxonomy_hierarchical( $taxonomy ) ) {
			if ( 'category' === $taxonomy ) {
				$names .= ' ' . ( count( $terms ) > 1 ? esc_html__( 'or any of their subcategories', 'pressqueue' ) : esc_html__( 'or any of its subcategories', 'pressqueue' ) );
			} else {
				$names .= ' ' . esc_html__( 'or anything nested under it', 'pressqueue' );
			}
		}

		if ( 'category' === $taxonomy ) {
			if ( 'has_none' === $operator ) {
				/* translators: %s: category names. */
				return $predicate ? sprintf( esc_html__( 'is not in %s', 'pressqueue' ), $names ) : sprintf( esc_html__( 'not in %s', 'pressqueue' ), $names );
			}

			/* translators: %s: category names. */
			return $predicate ? sprintf( esc_html__( 'is in %s', 'pressqueue' ), $names ) : sprintf( esc_html__( 'in %s', 'pressqueue' ), $names );
		}

		if ( 'post_tag' === $taxonomy ) {
			if ( 'has_none' === $operator ) {
				/* translators: %s: tag names. */
				return $predicate ? sprintf( esc_html__( 'is not tagged %s', 'pressqueue' ), $names ) : sprintf( esc_html__( 'not tagged %s', 'pressqueue' ), $names );
			}

			/* translators: %s: tag names. */
			return $predicate ? sprintf( esc_html__( 'is tagged %s', 'pressqueue' ), $names ) : sprintf( esc_html__( 'tagged %s', 'pressqueue' ), $names );
		}

		$label = esc_html( self::taxonomy_label( $taxonomy ) );

		if ( 'has_none' === $operator ) {
			/* translators: 1: taxonomy name such as "Genre", 2: term names. */
			return $predicate ? sprintf( esc_html__( 'does not have %1$s %2$s', 'pressqueue' ), $label, $names ) : sprintf( esc_html__( 'without %1$s %2$s', 'pressqueue' ), $label, $names );
		}

		/* translators: 1: taxonomy name such as "Genre", 2: term names. */
		return $predicate ? sprintf( esc_html__( 'has %1$s %2$s', 'pressqueue' ), $label, $names ) : sprintf( esc_html__( 'with %1$s %2$s', 'pressqueue' ), $label, $names );
	}

	/**
	 * Describe a custom field condition.
	 *
	 * @param string              $operator Operator.
	 * @param array<string,mixed> $value Condition value.
	 * @param bool                $predicate Whether to use the predicate form.
	 * @return string
	 */
	private static function metadata_phrase( string $operator, array $value, bool $predicate ): string {
		$key = self::chip( (string) ( $value['key'] ?? '' ) );

		if ( 'exists' === $operator ) {
			/* translators: %s: custom field name. */
			return $predicate ? sprintf( esc_html__( 'has the custom field %s', 'pressqueue' ), $key ) : sprintf( esc_html__( 'with the custom field %s', 'pressqueue' ), $key );
		}

		if ( 'not_exists' === $operator ) {
			/* translators: %s: custom field name. */
			return $predicate ? sprintf( esc_html__( 'does not have the custom field %s', 'pressqueue' ), $key ) : sprintf( esc_html__( 'without the custom field %s', 'pressqueue' ), $key );
		}

		$expected = self::chip( self::metadata_value_text( $value['value'] ?? null ) );

		if ( 'not_equals' === $operator ) {
			/* translators: 1: custom field name, 2: value. */
			return sprintf( esc_html__( 'where %1$s is not %2$s', 'pressqueue' ), $key, $expected );
		}

		/* translators: 1: custom field name, 2: value. */
		return sprintf( esc_html__( 'where %1$s is %2$s', 'pressqueue' ), $key, $expected );
	}

	/**
	 * Return a readable custom field comparison value.
	 *
	 * @param mixed $value Typed value document or scalar.
	 * @return string
	 */
	public static function metadata_value_text( $value ): string {
		if ( is_array( $value ) && array_key_exists( 'value', $value ) ) {
			$value = $value['value'];
		}

		if ( true === $value ) {
			return 'true';
		}

		if ( false === $value ) {
			return 'false';
		}

		if ( null === $value ) {
			return __( 'empty', 'pressqueue' );
		}

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Describe the triggers.
	 *
	 * @param array<int,string> $triggers Stored triggers.
	 * @return string
	 */
	private function trigger_phrase( array $triggers ): string {
		$chips = array();

		if ( in_array( 'first_publish', $triggers, true ) ) {
			$chips[] = self::chip( __( 'published', 'pressqueue' ) );
		} elseif ( in_array( 'scheduled_publication', $triggers, true ) ) {
			$chips[] = self::chip( __( 'published on schedule', 'pressqueue' ) );
		}

		if ( in_array( 'update', $triggers, true ) ) {
			$chips[] = self::chip( __( 'updated', 'pressqueue' ) );
		}

		if ( in_array( 'bulk', $triggers, true ) ) {
			$chips[] = self::chip( __( 'picked in Share old posts', 'pressqueue' ) );
		}

		if ( in_array( 'repost', $triggers, true ) ) {
			$chips[] = self::chip( __( 'picked to share again', 'pressqueue' ) );
		}

		return self::join( $chips, __( 'or', 'pressqueue' ) );
	}

	/**
	 * Return the destination chips for an inclusion rule.
	 *
	 * @param RuleRecord $rule Rule.
	 * @return string
	 */
	private function channel_chips( RuleRecord $rule ): string {
		$destinations = isset( $rule->destination_set['destinations'] ) && is_array( $rule->destination_set['destinations'] ) ? $rule->destination_set['destinations'] : array();
		$chips        = array();

		foreach ( $destinations as $destination ) {
			$public_id = is_array( $destination ) ? (string) ( $destination['channel_public_id'] ?? '' ) : '';
			$channel   = $this->channels[ $public_id ] ?? null;

			if ( null === $channel ) {
				$chips[] = '<span class="pq-tag pq-tag--warn">' . esc_html__( 'Missing channel', 'pressqueue' ) . '</span>';
				continue;
			}

			$chip = '<span class="pq-tag">' . Ui::service_badge( $channel->service, 'sm' ) . esc_html( ActivitySummary::channel_display_name( $channel ) ) . '<span class="screen-reader-text"> (' . esc_html( Labels::service( $channel->service ) ) . ')</span></span>';
			if ( ! $channel->enabled ) {
				$chip .= ' <span class="pq-muted">' . esc_html__( '(off in Channels)', 'pressqueue' ) . '</span>';
			}
			$chips[] = $chip;
		}

		if ( array() === $chips ) {
			return '<span class="pq-tag pq-tag--warn">' . esc_html__( 'no channels yet', 'pressqueue' ) . '</span>';
		}

		return implode( ' ', $chips );
	}

	/**
	 * Describe where and when the post is sent.
	 *
	 * @param string $mode Schedule mode.
	 * @param string $channels Escaped channel chips.
	 * @return string
	 */
	private function timing_phrase( string $mode, string $channels ): string {
		switch ( $mode ) {
			case 'queue_end':
				/* translators: 1: channel names, 2: highlighted phrase "end of the Buffer queue". */
				return sprintf( esc_html__( 'send it to %1$s, add it to the %2$s', 'pressqueue' ), $channels, self::chip( __( 'end of the Buffer queue', 'pressqueue' ) ) );
			case 'queue_next':
				/* translators: 1: channel names, 2: highlighted phrase "next free queue slot". */
				return sprintf( esc_html__( 'send it to %1$s, put it in the %2$s', 'pressqueue' ), $channels, self::chip( __( 'next free queue slot', 'pressqueue' ) ) );
			case 'draft':
				/* translators: 1: channel names, 2: highlighted phrase "Buffer draft for review". */
				return sprintf( esc_html__( 'send it to %1$s as a %2$s', 'pressqueue' ), $channels, self::chip( __( 'Buffer draft for review', 'pressqueue' ) ) );
			case 'immediate':
				/* translators: 1: channel names, 2: highlighted phrase "right away". */
				return sprintf( esc_html__( 'send it to %1$s and post it %2$s', 'pressqueue' ), $channels, self::chip( __( 'right away', 'pressqueue' ) ) );
		}

		/* translators: %s: channel names. */
		return sprintf( esc_html__( 'send it to %s', 'pressqueue' ), $channels );
	}

	/**
	 * Describe the image choice.
	 *
	 * @param string $mode Media mode.
	 * @return string
	 */
	private function media_phrase( string $mode ): string {
		switch ( $mode ) {
			case 'featured_image_optional':
				/* translators: %s: highlighted phrase "featured image when there is one". */
				return sprintf( esc_html__( 'with the %s', 'pressqueue' ), self::chip( __( 'featured image when there is one', 'pressqueue' ) ) );
			case 'featured_image':
				/* translators: %s: highlighted phrase "featured image". */
				return sprintf( esc_html__( 'with the %s, and hold back posts without one', 'pressqueue' ), self::chip( __( 'featured image', 'pressqueue' ) ) );
			case 'post_media':
				/* translators: %s: highlighted phrase "images chosen in the post". */
				return sprintf( esc_html__( 'with the %s', 'pressqueue' ), self::chip( __( 'images chosen in the post', 'pressqueue' ) ) );
		}

		return self::chip( __( 'without an image', 'pressqueue' ) );
	}

	/**
	 * Check whether a leaf condition fits the simple editor.
	 *
	 * @param array<string,mixed> $row Condition node.
	 * @return bool
	 */
	private static function is_simple_row( array $row ): bool {
		$condition = $row['condition'] ?? null;
		$operator  = $row['operator'] ?? null;
		$value     = $row['value'] ?? null;

		if ( ! in_array( $condition, self::SIMPLE_CONDITIONS, true ) || ! is_string( $operator ) ) {
			return false;
		}

		switch ( $condition ) {
			case 'post_type':
				return ( 'equals' === $operator && is_string( $value ) && '' !== $value )
					|| ( 'in' === $operator && is_array( $value ) && array_is_list( $value ) && array() !== $value && count( array_filter( $value, 'is_string' ) ) === count( $value ) );
			case 'author':
				return ( 'equals' === $operator && self::is_id( $value ) )
					|| ( 'in' === $operator && is_array( $value ) && array_is_list( $value ) && array() !== $value && count( array_filter( $value, array( self::class, 'is_id' ) ) ) === count( $value ) );
			case 'taxonomy':
				return in_array( $operator, array( 'has_any', 'has_all', 'has_none' ), true )
					&& is_array( $value )
					&& is_string( $value['taxonomy'] ?? null )
					&& is_bool( $value['include_ancestors'] ?? null )
					&& is_array( $value['terms'] ?? null )
					&& array_is_list( $value['terms'] )
					&& count( array_filter( $value['terms'], array( self::class, 'is_id' ) ) ) === count( $value['terms'] );
			default:
				if ( ! is_array( $value ) || ! is_string( $value['key'] ?? null ) ) {
					return false;
				}
				if ( in_array( $operator, array( 'exists', 'not_exists' ), true ) ) {
					return true;
				}
				return in_array( $operator, array( 'equals', 'not_equals' ), true ) && array_key_exists( 'value', $value );
		}
	}

	/**
	 * Check for a positive integer ID or digit string.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_id( $value ): bool {
		return ( is_int( $value ) && $value > 0 ) || ( is_string( $value ) && ctype_digit( $value ) && (int) $value > 0 );
	}

	/**
	 * Return string values from a scalar or list.
	 *
	 * @param mixed $value Value.
	 * @return array<int,string>
	 */
	private static function string_values( $value ): array {
		if ( is_string( $value ) ) {
			return array( $value );
		}

		return is_array( $value ) ? array_values( array_filter( $value, 'is_string' ) ) : array();
	}

	/**
	 * Return integer values from a scalar or list.
	 *
	 * @param mixed $value Value.
	 * @return array<int,int>
	 */
	private static function int_values( $value ): array {
		$values = is_array( $value ) ? $value : array( $value );
		$ids    = array();

		foreach ( $values as $item ) {
			if ( self::is_id( $item ) ) {
				$ids[] = (int) $item;
			}
		}

		return $ids;
	}

	/**
	 * Return an escaped chip.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function chip( string $text ): string {
		return '<span class="pq-tag">' . esc_html( $text ) . '</span>';
	}

	/**
	 * Join escaped items as "A, B or C".
	 *
	 * @param array<int,string> $items Escaped items.
	 * @param string            $conjunction Unescaped conjunction word.
	 * @return string
	 */
	public static function join( array $items, string $conjunction ): string {
		$items = array_values( $items );

		if ( count( $items ) <= 1 ) {
			return $items[0] ?? '';
		}

		$last = array_pop( $items );

		return implode( ', ', $items ) . ' ' . esc_html( $conjunction ) . ' ' . $last;
	}
}
