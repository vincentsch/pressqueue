<?php
/**
 * Rule document validation.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage;

/**
 * Validates and canonicalizes rule JSON documents.
 */
final class RuleDocuments {
	private const MAX_CONDITION_TREE_BYTES = 65536;
	private const MAX_DESTINATION_BYTES    = 16384;
	private const MAX_TEMPLATE_BYTES       = 16384;
	private const MAX_TEMPLATE_LENGTH      = 2000;
	private const MAX_TREE_DEPTH           = 5;
	private const MAX_TREE_NODES           = 50;

	/**
	 * Build the canonical triggers document.
	 *
	 * @param array<int,string> $triggers Trigger names.
	 * @return array<string,mixed>
	 */
	public static function triggers( array $triggers ): array {
		$triggers = StorageValidator::normalize_string_list( $triggers );

		if ( array() === $triggers ) {
			throw new ValidationException( 'Rules need at least one trigger.' );
		}

		foreach ( $triggers as $trigger ) {
			StorageEnums::assert_rule_trigger( $trigger );
		}

		return array(
			'schema_version' => 1,
			'triggers'       => $triggers,
		);
	}

	/**
	 * Validate and return a condition tree document.
	 *
	 * @param array<string,mixed> $tree Condition tree.
	 * @param array<int,string>   $custom_field_allowlist Custom field allowlist.
	 * @return array<string,mixed>
	 */
	public static function condition_tree( array $tree, array $custom_field_allowlist ): array {
		self::assert_schema_version( $tree, 'condition tree' );

		if ( ( $tree['type'] ?? null ) !== 'group' ) {
			throw new ValidationException( 'Condition tree root must be a group.' );
		}

		$count = 0;
		self::assert_condition_node( $tree, $custom_field_allowlist, 1, $count );

		return $tree;
	}

	/**
	 * Validate and return an inline template document.
	 *
	 * @param array<string,mixed> $template Template document.
	 * @return array<string,mixed>
	 */
	public static function inline_template( array $template ): array {
		self::assert_schema_version( $template, 'inline template' );

		if ( ! isset( $template['message'] ) || ! is_string( $template['message'] ) ) {
			throw new ValidationException( 'Inline template message is required.' );
		}

		if ( strlen( $template['message'] ) > self::MAX_TEMPLATE_LENGTH ) {
			throw new ValidationException( 'Inline template message exceeds the allowed length.' );
		}

		$allowlist = array();
		if ( isset( $template['custom_field_allowlist'] ) ) {
			if ( ! is_array( $template['custom_field_allowlist'] ) || ! array_is_list( $template['custom_field_allowlist'] ) ) {
				throw new ValidationException( 'Inline template custom field allowlist must be a list.' );
			}

			$allowlist = self::custom_field_allowlist( $template['custom_field_allowlist'] );
		}

		$template['custom_field_allowlist'] = $allowlist;

		return $template;
	}

	/**
	 * Validate and return a destination set document.
	 *
	 * @param string              $effect Rule effect.
	 * @param array<string,mixed> $destination_set Destination set.
	 * @return array<string,mixed>
	 */
	public static function destination_set( string $effect, array $destination_set ): array {
		self::assert_schema_version( $destination_set, 'destination set' );

		if ( 'exclude' === $effect ) {
			if ( ( $destination_set['scope'] ?? null ) !== 'all' ) {
				throw new ValidationException( 'Exclusion rules must use the all destination scope.' );
			}

			return array(
				'schema_version' => 1,
				'scope'          => 'all',
			);
		}

		if ( ! isset( $destination_set['destinations'] ) || ! is_array( $destination_set['destinations'] ) || ! array_is_list( $destination_set['destinations'] ) ) {
			throw new ValidationException( 'Inclusion rules need a destination list.' );
		}

		$destinations = array();
		foreach ( $destination_set['destinations'] as $destination ) {
			if ( ! is_array( $destination ) || ! isset( $destination['channel_public_id'] ) || ! is_string( $destination['channel_public_id'] ) ) {
				throw new ValidationException( 'Destination entries need a channel public ID.' );
			}

			PublicIds::assert( $destination['channel_public_id'] );
			$destinations[] = array( 'channel_public_id' => $destination['channel_public_id'] );
		}

		usort(
			$destinations,
			static function ( array $left, array $right ): int {
				return strcmp( $left['channel_public_id'], $right['channel_public_id'] );
			}
		);

		return array(
			'destinations'   => $destinations,
			'schema_version' => 1,
		);
	}

	/**
	 * Validate and normalize the custom field allowlist.
	 *
	 * @param array<int,mixed> $allowlist Candidate allowlist.
	 * @return array<int,string>
	 */
	public static function custom_field_allowlist( array $allowlist ): array {
		$allowlist = StorageValidator::normalize_string_list( $allowlist );

		foreach ( $allowlist as $field ) {
			StorageValidator::assert_field_key( $field );
		}

		return $allowlist;
	}

	/**
	 * Encode a condition tree with size validation.
	 *
	 * @param array<string,mixed> $tree Condition tree.
	 * @return string
	 */
	public static function encode_condition_tree( array $tree ): string {
		$json = CanonicalJson::encode_object( $tree );
		CanonicalJson::assert_maximum_size( $json, self::MAX_CONDITION_TREE_BYTES );

		return $json;
	}

	/**
	 * Encode a destination set with size validation.
	 *
	 * @param array<string,mixed> $destination_set Destination set.
	 * @return string
	 */
	public static function encode_destination_set( array $destination_set ): string {
		$json = CanonicalJson::encode_object( $destination_set );
		CanonicalJson::assert_maximum_size( $json, self::MAX_DESTINATION_BYTES );

		return $json;
	}

	/**
	 * Encode an inline template with size validation.
	 *
	 * @param array<string,mixed> $template Template document.
	 * @return string
	 */
	public static function encode_inline_template( array $template ): string {
		$json = CanonicalJson::encode_object( $template );
		CanonicalJson::assert_maximum_size( $json, self::MAX_TEMPLATE_BYTES );

		return $json;
	}

	/**
	 * Assert a document schema version.
	 *
	 * @param array<string,mixed> $document Document.
	 * @param string              $name Document name.
	 */
	private static function assert_schema_version( array $document, string $name ): void {
		unset( $name );

		if ( ( $document['schema_version'] ?? null ) !== 1 ) {
			throw new ValidationException( 'Rule document must use schema version 1.' );
		}
	}

	/**
	 * Validate a condition tree node.
	 *
	 * @param array<string,mixed> $node Condition node.
	 * @param array<int,string>   $custom_field_allowlist Custom field allowlist.
	 * @param int                 $depth Current depth.
	 * @param int                 $count Current node count.
	 */
	private static function assert_condition_node( array $node, array $custom_field_allowlist, int $depth, int &$count ): void {
		++$count;

		if ( $depth > self::MAX_TREE_DEPTH || $count > self::MAX_TREE_NODES ) {
			throw new ValidationException( 'Condition tree exceeds the allowed complexity.' );
		}

		$type = $node['type'] ?? null;

		if ( 'group' === $type ) {
			self::assert_group_node( $node, $custom_field_allowlist, $depth, $count );
			return;
		}

		if ( 'condition' === $type ) {
			self::assert_leaf_node( $node, $custom_field_allowlist );
			return;
		}

		throw new ValidationException( 'Condition tree node type is unsupported.' );
	}

	/**
	 * Validate a group node.
	 *
	 * @param array<string,mixed> $node Group node.
	 * @param array<int,string>   $custom_field_allowlist Custom field allowlist.
	 * @param int                 $depth Current depth.
	 * @param int                 $count Current node count.
	 */
	private static function assert_group_node( array $node, array $custom_field_allowlist, int $depth, int &$count ): void {
		if ( ! in_array( $node['operator'] ?? null, array( 'ALL', 'ANY' ), true ) ) {
			throw new ValidationException( 'Condition group operator is unsupported.' );
		}

		if ( ! isset( $node['children'] ) || ! is_array( $node['children'] ) || ! array_is_list( $node['children'] ) || array() === $node['children'] ) {
			throw new ValidationException( 'Condition groups need children.' );
		}

		foreach ( $node['children'] as $child ) {
			if ( ! is_array( $child ) ) {
				throw new ValidationException( 'Condition group child is invalid.' );
			}

			self::assert_condition_node( $child, $custom_field_allowlist, $depth + 1, $count );
		}
	}

	/**
	 * Validate a leaf condition node.
	 *
	 * @param array<string,mixed> $node Condition node.
	 * @param array<int,string>   $custom_field_allowlist Custom field allowlist.
	 */
	private static function assert_leaf_node( array $node, array $custom_field_allowlist ): void {
		$condition = $node['condition'] ?? null;
		$operator  = $node['operator'] ?? null;

		if ( ! is_string( $condition ) || ! is_string( $operator ) ) {
			throw new ValidationException( 'Condition nodes need condition and operator values.' );
		}

		switch ( $condition ) {
			case 'post_type':
			case 'post_status':
				self::assert_string_comparison( $operator, $node['value'] ?? null );
				return;
			case 'author':
				self::assert_author_comparison( $operator, $node['value'] ?? null );
				return;
			case 'taxonomy':
				self::assert_taxonomy_comparison( $operator, $node['value'] ?? null );
				return;
			case 'metadata':
				self::assert_metadata_comparison( $operator, $node['value'] ?? null, $custom_field_allowlist );
				return;
			case 'post_policy':
				self::assert_post_policy_comparison( $operator, $node['value'] ?? null );
				return;
		}

		throw new ValidationException( 'Condition type is unsupported.' );
	}

	/**
	 * Validate post type and status comparisons.
	 *
	 * @param string $operator Operator.
	 * @param mixed  $value Value.
	 */
	private static function assert_string_comparison( string $operator, $value ): void {
		if ( 'equals' === $operator && is_string( $value ) && '' !== $value ) {
			return;
		}

		if ( 'in' === $operator && is_array( $value ) && array_is_list( $value ) && array() !== $value ) {
			foreach ( $value as $item ) {
				if ( ! is_string( $item ) || '' === $item ) {
					throw new ValidationException( 'String condition list contains an invalid value.' );
				}
			}

			return;
		}

		throw new ValidationException( 'String condition comparison is invalid.' );
	}

	/**
	 * Validate author comparisons.
	 *
	 * @param string $operator Operator.
	 * @param mixed  $value Value.
	 */
	private static function assert_author_comparison( string $operator, $value ): void {
		if ( 'equals' === $operator ) {
			self::assert_author_value( $value );
			return;
		}

		if ( 'in' === $operator && is_array( $value ) && array_is_list( $value ) && array() !== $value ) {
			foreach ( $value as $item ) {
				self::assert_author_value( $item );
			}

			return;
		}

		throw new ValidationException( 'Author condition comparison is invalid.' );
	}

	/**
	 * Validate one author value.
	 *
	 * @param mixed $value Value.
	 */
	private static function assert_author_value( $value ): void {
		if ( is_int( $value ) && $value > 0 ) {
			return;
		}

		if ( is_string( $value ) && ( PublicIds::is_valid( $value ) || ctype_digit( $value ) ) ) {
			return;
		}

		throw new ValidationException( 'Author condition value is invalid.' );
	}

	/**
	 * Validate taxonomy comparisons.
	 *
	 * @param string $operator Operator.
	 * @param mixed  $value Value.
	 */
	private static function assert_taxonomy_comparison( string $operator, $value ): void {
		if ( ! in_array( $operator, array( 'has_any', 'has_all', 'has_none' ), true ) ) {
			throw new ValidationException( 'Taxonomy condition operator is unsupported.' );
		}

		if ( ! is_array( $value ) || ! isset( $value['taxonomy'], $value['terms'], $value['include_ancestors'] ) ) {
			throw new ValidationException( 'Taxonomy condition value is invalid.' );
		}

		if ( ! is_string( $value['taxonomy'] ) || '' === $value['taxonomy'] ) {
			throw new ValidationException( 'Taxonomy condition taxonomy is invalid.' );
		}

		if ( ! is_bool( $value['include_ancestors'] ) || ! is_array( $value['terms'] ) || ! array_is_list( $value['terms'] ) || array() === $value['terms'] ) {
			throw new ValidationException( 'Taxonomy condition terms are invalid.' );
		}

		foreach ( $value['terms'] as $term ) {
			if ( ! is_string( $term ) && ! is_int( $term ) ) {
				throw new ValidationException( 'Taxonomy condition term is invalid.' );
			}
		}
	}

	/**
	 * Validate metadata comparisons.
	 *
	 * @param string            $operator Operator.
	 * @param mixed             $value Value.
	 * @param array<int,string> $custom_field_allowlist Custom field allowlist.
	 */
	private static function assert_metadata_comparison( string $operator, $value, array $custom_field_allowlist ): void {
		if ( ! in_array( $operator, array( 'equals', 'not_equals', 'exists', 'not_exists' ), true ) ) {
			throw new ValidationException( 'Metadata condition operator is unsupported.' );
		}

		if ( ! is_array( $value ) || ! isset( $value['key'] ) || ! is_string( $value['key'] ) ) {
			throw new ValidationException( 'Metadata condition value is invalid.' );
		}

		if ( ! in_array( $value['key'], $custom_field_allowlist, true ) ) {
			throw new ValidationException( 'Metadata condition key is not allowlisted.' );
		}

		if ( in_array( $operator, array( 'exists', 'not_exists' ), true ) ) {
			return;
		}

		if ( ! array_key_exists( 'value', $value ) || is_array( $value['value'] ) || is_object( $value['value'] ) ) {
			throw new ValidationException( 'Metadata condition comparison value is invalid.' );
		}
	}

	/**
	 * Validate post policy comparisons.
	 *
	 * @param string $operator Operator.
	 * @param mixed  $value Value.
	 */
	private static function assert_post_policy_comparison( string $operator, $value ): void {
		if ( 'equals' !== $operator || ! in_array( $value, array( 'inherit', 'include', 'exclude' ), true ) ) {
			throw new ValidationException( 'Post policy condition comparison is invalid.' );
		}
	}
}
