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
	private const SCHEMA_VERSION           = 1;
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
		return self::normalize_condition_node( $tree, $custom_field_allowlist, 1, $count, true );
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
	 * Normalize and validate a condition tree node.
	 *
	 * @param array<string,mixed> $node Condition node.
	 * @param array<int,string>   $custom_field_allowlist Custom field allowlist.
	 * @param int                 $depth Current depth.
	 * @param int                 $count Current node count.
	 * @param bool                $root Whether this node is the root.
	 * @return array<string,mixed>
	 */
	private static function normalize_condition_node( array $node, array $custom_field_allowlist, int $depth, int &$count, bool $root = false ): array {
		++$count;

		if ( $depth > self::MAX_TREE_DEPTH || $count > self::MAX_TREE_NODES ) {
			throw new ValidationException( 'Condition tree exceeds the allowed complexity.' );
		}

		$type = $node['type'] ?? null;

		if ( 'group' === $type ) {
			return self::normalize_group_node( $node, $custom_field_allowlist, $depth, $count, $root );
		}

		if ( 'condition' === $type ) {
			return self::normalize_leaf_node( $node, $custom_field_allowlist );
		}

		throw new ValidationException( 'Condition tree node type is unsupported.' );
	}

	/**
	 * Normalize and validate a group node.
	 *
	 * @param array<string,mixed> $node Group node.
	 * @param array<int,string>   $custom_field_allowlist Custom field allowlist.
	 * @param int                 $depth Current depth.
	 * @param int                 $count Current node count.
	 * @param bool                $root Whether this node is the root.
	 * @return array<string,mixed>
	 */
	private static function normalize_group_node( array $node, array $custom_field_allowlist, int $depth, int &$count, bool $root ): array {
		$allowed = $root ? array( 'schema_version', 'type', 'mode', 'operator', 'children' ) : array( 'type', 'mode', 'operator', 'children' );
		self::assert_no_unknown_keys( $node, $allowed, 'Condition group' );

		$mode = $node['mode'] ?? ( $node['operator'] ?? null );
		if ( ! in_array( $mode, array( 'ALL', 'ANY' ), true ) ) {
			throw new ValidationException( 'Condition group operator is unsupported.' );
		}

		if ( ! isset( $node['children'] ) || ! is_array( $node['children'] ) || ! array_is_list( $node['children'] ) || array() === $node['children'] ) {
			throw new ValidationException( 'Condition groups need children.' );
		}

		$children = array();
		foreach ( $node['children'] as $child ) {
			if ( ! is_array( $child ) ) {
				throw new ValidationException( 'Condition group child is invalid.' );
			}

			$children[] = self::normalize_condition_node( $child, $custom_field_allowlist, $depth + 1, $count );
		}

		$normalized = array(
			'children' => $children,
			'mode'     => $mode,
			'type'     => 'group',
		);

		if ( $root ) {
			$normalized['schema_version'] = self::SCHEMA_VERSION;
		}

		return $normalized;
	}

	/**
	 * Normalize and validate a leaf condition node.
	 *
	 * @param array<string,mixed> $node Condition node.
	 * @param array<int,string>   $custom_field_allowlist Custom field allowlist.
	 * @return array<string,mixed>
	 */
	private static function normalize_leaf_node( array $node, array $custom_field_allowlist ): array {
		self::assert_no_unknown_keys( $node, array( 'type', 'condition', 'operator', 'value' ), 'Condition node' );

		$condition = $node['condition'] ?? null;
		$operator  = $node['operator'] ?? null;

		if ( ! is_string( $condition ) || ! is_string( $operator ) ) {
			throw new ValidationException( 'Condition nodes need condition and operator values.' );
		}

		$value = $node['value'] ?? null;
		switch ( $condition ) {
			case 'post_type':
				$value = self::normalize_slug_comparison( 'Post type', $operator, $value, 'post_type_exists' );
				break;
			case 'post_status':
				$value = self::normalize_slug_comparison( 'Post status', $operator, $value, 'post_status_exists' );
				break;
			case 'author':
				$value = self::normalize_author_comparison( $operator, $value );
				break;
			case 'taxonomy':
				$value = self::normalize_taxonomy_comparison( $operator, $value );
				break;
			case 'metadata':
				$value = self::normalize_metadata_comparison( $operator, $value, $custom_field_allowlist );
				break;
			case 'post_policy':
				$value = self::normalize_post_policy_comparison( $operator, $value );
				break;
			default:
				throw new ValidationException( 'Condition type is unsupported.' );
		}

		return array(
			'condition' => $condition,
			'operator'  => $operator,
			'type'      => 'condition',
			'value'     => $value,
		);
	}

	/**
	 * Normalize post type and status comparisons.
	 *
	 * @param string $label Value label.
	 * @param string $operator Operator.
	 * @param mixed  $value Value.
	 * @param string $existence_check Existence check.
	 * @return string|array<int,string>
	 */
	private static function normalize_slug_comparison( string $label, string $operator, $value, string $existence_check ) {
		if ( 'equals' === $operator && is_string( $value ) && '' !== $value ) {
			return self::normalize_condition_slug_value( $label, $value, $existence_check );
		}

		if ( 'in' === $operator && is_array( $value ) && array_is_list( $value ) && array() !== $value ) {
			$values = array();
			foreach ( $value as $item ) {
				if ( ! is_string( $item ) || '' === $item ) {
					throw new ValidationException( 'String condition list contains an invalid value.' );
				}

				$values[] = self::normalize_condition_slug_value( $label, $item, $existence_check );
			}

			$values = array_values( array_unique( $values ) );
			sort( $values, SORT_STRING );

			return $values;
		}

		throw new ValidationException( 'String condition comparison is invalid.' );
	}

	/**
	 * Normalize a condition slug value.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 * @param string $existence_check Existence check.
	 * @return string
	 */
	private static function normalize_condition_slug_value( string $label, string $value, string $existence_check ): string {
		$value = strtolower( $value );
		StorageValidator::assert_wordpress_slug( $label, $value );

		if ( 'post_type_exists' === $existence_check && function_exists( 'post_type_exists' ) && ! post_type_exists( $value ) ) {
			throw new ValidationException( 'Condition post type does not exist.' );
		}

		if ( 'post_status_exists' === $existence_check && function_exists( 'get_post_stati' ) ) {
			$statuses = get_post_stati();
			if ( is_array( $statuses ) && ! in_array( $value, $statuses, true ) ) {
				throw new ValidationException( 'Condition post status does not exist.' );
			}
		}

		return $value;
	}

	/**
	 * Normalize author comparisons.
	 *
	 * @param string $operator Operator.
	 * @param mixed  $value Value.
	 * @return int|array<int,int>
	 */
	private static function normalize_author_comparison( string $operator, $value ) {
		if ( 'equals' === $operator ) {
			return self::normalize_author_value( $value );
		}

		if ( 'in' === $operator && is_array( $value ) && array_is_list( $value ) && array() !== $value ) {
			$values = array();
			foreach ( $value as $item ) {
				$values[] = self::normalize_author_value( $item );
			}

			$values = array_values( array_unique( $values ) );
			sort( $values, SORT_NUMERIC );

			return $values;
		}

		throw new ValidationException( 'Author condition comparison is invalid.' );
	}

	/**
	 * Normalize one author value.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private static function normalize_author_value( $value ): int {
		if ( is_string( $value ) && ctype_digit( $value ) ) {
			$value = (int) $value;
		}

		if ( ! is_int( $value ) || $value <= 0 ) {
			throw new ValidationException( 'Author condition value is invalid.' );
		}

		if ( function_exists( 'get_user_by' ) && false === get_user_by( 'id', $value ) ) {
			throw new ValidationException( 'Author condition user does not exist.' );
		}

		return $value;
	}

	/**
	 * Normalize taxonomy comparisons.
	 *
	 * @param string $operator Operator.
	 * @param mixed  $value Value.
	 * @return array<string,mixed>
	 */
	private static function normalize_taxonomy_comparison( string $operator, $value ): array {
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

		$taxonomy = strtolower( $value['taxonomy'] );
		StorageValidator::assert_wordpress_slug( 'Taxonomy', $taxonomy );

		if ( function_exists( 'taxonomy_exists' ) && ! taxonomy_exists( $taxonomy ) ) {
			throw new ValidationException( 'Taxonomy condition taxonomy does not exist.' );
		}

		$terms = array();
		foreach ( $value['terms'] as $term ) {
			if ( is_string( $term ) && ctype_digit( $term ) ) {
				$term = (int) $term;
			}

			if ( ! is_int( $term ) || $term <= 0 ) {
				throw new ValidationException( 'Taxonomy condition term is invalid.' );
			}

			if ( function_exists( 'get_term' ) && function_exists( 'is_wp_error' ) ) {
				$found = get_term( $term, $taxonomy );
				if ( null === $found || is_wp_error( $found ) ) {
					throw new ValidationException( 'Taxonomy condition term does not exist.' );
				}
			}

			$terms[] = $term;
		}

		$terms = array_values( array_unique( $terms ) );
		sort( $terms, SORT_NUMERIC );

		return array(
			'include_ancestors' => $value['include_ancestors'],
			'taxonomy'          => $taxonomy,
			'terms'             => $terms,
		);
	}

	/**
	 * Normalize metadata comparisons.
	 *
	 * @param string            $operator Operator.
	 * @param mixed             $value Value.
	 * @param array<int,string> $custom_field_allowlist Custom field allowlist.
	 * @return array<string,mixed>
	 */
	private static function normalize_metadata_comparison( string $operator, $value, array $custom_field_allowlist ): array {
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
			self::assert_no_unknown_keys( $value, array( 'key' ), 'Metadata condition value' );
			return array( 'key' => $value['key'] );
		}

		self::assert_no_unknown_keys( $value, array( 'key', 'value' ), 'Metadata condition value' );

		if ( ! array_key_exists( 'value', $value ) ) {
			throw new ValidationException( 'Metadata condition comparison value is invalid.' );
		}

		return array(
			'key'   => $value['key'],
			'value' => self::normalize_metadata_value_document( $value['value'] ),
		);
	}

	/**
	 * Normalize a metadata typed scalar value.
	 *
	 * @param mixed $value Candidate value.
	 * @return array<string,mixed>
	 */
	private static function normalize_metadata_value_document( $value ): array {
		if ( is_array( $value ) && ! array_is_list( $value ) && isset( $value['type'] ) && array_key_exists( 'value', $value ) ) {
			self::assert_no_unknown_keys( $value, array( 'type', 'value' ), 'Metadata scalar value' );

			if ( ! is_string( $value['type'] ) ) {
				throw new ValidationException( 'Metadata scalar value type is invalid.' );
			}

			$type = $value['type'];
			$raw  = $value['value'];
		} else {
			$type = self::metadata_scalar_type( $value );
			$raw  = $value;
		}

		switch ( $type ) {
			case 'string':
				if ( ! is_string( $raw ) ) {
					throw new ValidationException( 'Metadata string value is invalid.' );
				}
				break;
			case 'number':
				if ( ! is_int( $raw ) && ! is_float( $raw ) ) {
					throw new ValidationException( 'Metadata number value is invalid.' );
				}
				break;
			case 'boolean':
				if ( ! is_bool( $raw ) ) {
					throw new ValidationException( 'Metadata boolean value is invalid.' );
				}
				break;
			case 'null':
				if ( null !== $raw ) {
					throw new ValidationException( 'Metadata null value is invalid.' );
				}
				break;
			default:
				throw new ValidationException( 'Metadata scalar value type is unsupported.' );
		}

		return array(
			'type'  => $type,
			'value' => $raw,
		);
	}

	/**
	 * Infer a metadata scalar type from a legacy scalar.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function metadata_scalar_type( $value ): string {
		if ( is_string( $value ) ) {
			return 'string';
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return 'number';
		}

		if ( is_bool( $value ) ) {
			return 'boolean';
		}

		if ( null === $value ) {
			return 'null';
		}

		throw new ValidationException( 'Metadata condition comparison value is invalid.' );
	}

	/**
	 * Normalize post policy comparisons.
	 *
	 * @param string $operator Operator.
	 * @param mixed  $value Value.
	 * @return string
	 */
	private static function normalize_post_policy_comparison( string $operator, $value ): string {
		if ( ! is_string( $value ) ) {
			throw new ValidationException( 'Post policy condition comparison is invalid.' );
		}

		$value = strtolower( $value );
		if ( 'equals' !== $operator || ! in_array( $value, array( 'inherit', 'include', 'exclude' ), true ) ) {
			throw new ValidationException( 'Post policy condition comparison is invalid.' );
		}

		return $value;
	}

	/**
	 * Assert exact object keys.
	 *
	 * @param array<string,mixed> $value Object value.
	 * @param array<int,string>   $allowed Allowed keys.
	 * @param string              $label Label.
	 */
	private static function assert_no_unknown_keys( array $value, array $allowed, string $label ): void {
		unset( $label );

		foreach ( array_keys( $value ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				throw new ValidationException( 'Condition document contains an unsupported field.' );
			}
		}
	}
}
