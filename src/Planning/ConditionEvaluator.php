<?php
/**
 * Rule condition evaluator.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Planning;

use PressQueue\Storage\ValidationException;

/**
 * Evaluates condition trees without short-circuiting reason collection.
 */
final class ConditionEvaluator {
	/**
	 * Evaluate a condition tree.
	 *
	 * @param array<string,mixed> $tree Condition tree.
	 * @param NormalizedEvent     $event Event.
	 * @return ConditionEvaluation
	 */
	public function evaluate( array $tree, NormalizedEvent $event ): ConditionEvaluation {
		return $this->evaluate_node( $tree, $event );
	}

	/**
	 * Evaluate one node.
	 *
	 * @param array<string,mixed> $node Node.
	 * @param NormalizedEvent     $event Event.
	 * @return ConditionEvaluation
	 */
	private function evaluate_node( array $node, NormalizedEvent $event ): ConditionEvaluation {
		$type = $node['type'] ?? null;

		if ( 'group' === $type ) {
			return $this->evaluate_group( $node, $event );
		}

		if ( 'condition' === $type ) {
			return $this->evaluate_condition( $node, $event );
		}

		return new ConditionEvaluation(
			'error',
			array(
				ReasonFactory::make( 'invalid_condition_node', 'Condition node is invalid.', 'error', 'type', 'supported_node', array( 'group', 'condition' ), $type ),
			)
		);
	}

	/**
	 * Evaluate a group node.
	 *
	 * @param array<string,mixed> $node Group node.
	 * @param NormalizedEvent     $event Event.
	 * @return ConditionEvaluation
	 */
	private function evaluate_group( array $node, NormalizedEvent $event ): ConditionEvaluation {
		$operator = (string) ( $node['mode'] ?? ( $node['operator'] ?? '' ) );
		$children = $node['children'] ?? null;

		if ( ! in_array( $operator, array( 'ALL', 'ANY' ), true ) || ! is_array( $children ) || ! array_is_list( $children ) || array() === $children ) {
			return new ConditionEvaluation(
				'error',
				array(
					ReasonFactory::make( 'invalid_condition_group', 'Condition group is invalid.', 'error', 'group', 'valid_group', null, $node ),
				)
			);
		}

		$results = array();
		$reasons = array();

		foreach ( $children as $child ) {
			if ( ! is_array( $child ) ) {
				$evaluation = new ConditionEvaluation(
					'error',
					array(
						ReasonFactory::make( 'invalid_condition_child', 'Condition child is invalid.', 'error', 'child', 'object', null, $child ),
					)
				);
			} else {
				$evaluation = $this->evaluate_node( $child, $event );
			}

			$results[] = $evaluation->condition_result;
			$reasons   = array_merge( $reasons, $evaluation->reasons );
		}

		if ( in_array( 'error', $results, true ) ) {
			$group_result = 'error';
		} elseif ( 'ALL' === $operator ) {
			$group_result = in_array( 'not_matched', $results, true ) ? 'not_matched' : 'matched';
		} else {
			$group_result = in_array( 'matched', $results, true ) ? 'matched' : 'not_matched';
		}

		$reasons[] = ReasonFactory::make(
			'condition_group_' . strtolower( $group_result ),
			'Condition group evaluated.',
			$group_result,
			'group',
			$operator,
			null,
			$results
		);

		return new ConditionEvaluation( $group_result, $reasons );
	}

	/**
	 * Evaluate a leaf condition.
	 *
	 * @param array<string,mixed> $node Condition node.
	 * @param NormalizedEvent     $event Event.
	 * @return ConditionEvaluation
	 */
	private function evaluate_condition( array $node, NormalizedEvent $event ): ConditionEvaluation {
		$condition = (string) ( $node['condition'] ?? '' );
		$operator  = (string) ( $node['operator'] ?? '' );
		$value     = $node['value'] ?? null;

		try {
			switch ( $condition ) {
				case 'post_type':
					return $this->compare_string_value( 'post_type', 'Post type condition evaluated.', $operator, $value, $event->post_type );
				case 'post_status':
					return $this->compare_string_value( 'post_status', 'Post status condition evaluated.', $operator, $value, $event->post_status );
				case 'author':
					return $this->compare_author( $operator, $value, $event );
				case 'taxonomy':
					return $this->compare_taxonomy( $operator, $value, $event );
				case 'metadata':
					return $this->compare_metadata( $operator, $value, $event );
				case 'post_policy':
					return $this->compare_string_value( 'post_policy', 'Post policy condition evaluated.', $operator, $value, $this->post_policy( $event ) );
			}
		} catch ( ValidationException $exception ) {
			unset( $exception );
		}

		return new ConditionEvaluation(
			'error',
			array(
				ReasonFactory::make( 'invalid_condition', 'Condition is invalid.', 'error', $condition, $operator, $value, null ),
			)
		);
	}

	/**
	 * Compare a string field.
	 *
	 * @param string $field Field name.
	 * @param string $label Human label.
	 * @param string $operator Operator.
	 * @param mixed  $expected Expected value.
	 * @param string $observed Observed value.
	 * @return ConditionEvaluation
	 */
	private function compare_string_value( string $field, string $label, string $operator, $expected, string $observed ): ConditionEvaluation {
		if ( 'equals' === $operator && is_string( $expected ) ) {
			$matched = $observed === $expected;
			return $this->single_reason( $matched, $field, $label, $operator, $expected, $observed );
		}

		if ( 'in' === $operator && is_array( $expected ) && array_is_list( $expected ) ) {
			$matched = in_array( $observed, $expected, true );
			return $this->single_reason( $matched, $field, $label, $operator, $expected, $observed );
		}

		return new ConditionEvaluation(
			'error',
			array(
				ReasonFactory::make( 'invalid_string_condition', 'String condition is invalid.', 'error', $field, $operator, $expected, $observed ),
			)
		);
	}

	/**
	 * Compare author values.
	 *
	 * @param string          $operator Operator.
	 * @param mixed           $expected Expected value.
	 * @param NormalizedEvent $event Event.
	 * @return ConditionEvaluation
	 */
	private function compare_author( string $operator, $expected, NormalizedEvent $event ): ConditionEvaluation {
		if ( 'equals' === $operator ) {
			$matched = $this->author_matches( $expected, $event );
			return $this->single_reason( $matched, 'author', 'Author condition evaluated.', $operator, $expected, $this->author_observed_value( $event ) );
		}

		if ( 'in' === $operator && is_array( $expected ) && array_is_list( $expected ) ) {
			foreach ( $expected as $item ) {
				if ( $this->author_matches( $item, $event ) ) {
					return $this->single_reason( true, 'author', 'Author condition evaluated.', $operator, $expected, $this->author_observed_value( $event ) );
				}
			}

			return $this->single_reason( false, 'author', 'Author condition evaluated.', $operator, $expected, $this->author_observed_value( $event ) );
		}

		return new ConditionEvaluation(
			'error',
			array(
				ReasonFactory::make( 'invalid_author_condition', 'Author condition is invalid.', 'error', 'author', $operator, $expected, $this->author_observed_value( $event ) ),
			)
		);
	}

	/**
	 * Check one author value.
	 *
	 * @param mixed           $expected Expected value.
	 * @param NormalizedEvent $event Event.
	 * @return bool
	 */
	private function author_matches( $expected, NormalizedEvent $event ): bool {
		if ( is_int( $expected ) ) {
			return $expected === $event->author_id;
		}

		if ( is_string( $expected ) && ctype_digit( $expected ) ) {
			return (int) $expected === $event->author_id;
		}

		if ( is_string( $expected ) && isset( $event->template_values['author_public_id'] ) && is_string( $event->template_values['author_public_id'] ) ) {
			return $expected === $event->template_values['author_public_id'];
		}

		return false;
	}

	/**
	 * Return observed author facts.
	 *
	 * @param NormalizedEvent $event Event.
	 * @return array<string,mixed>
	 */
	private function author_observed_value( NormalizedEvent $event ): array {
		return array(
			'id'        => $event->author_id,
			'public_id' => isset( $event->template_values['author_public_id'] ) && is_string( $event->template_values['author_public_id'] ) ? $event->template_values['author_public_id'] : '',
		);
	}

	/**
	 * Compare taxonomy terms.
	 *
	 * @param string          $operator Operator.
	 * @param mixed           $expected Expected value document.
	 * @param NormalizedEvent $event Event.
	 * @return ConditionEvaluation
	 */
	private function compare_taxonomy( string $operator, $expected, NormalizedEvent $event ): ConditionEvaluation {
		if ( ! is_array( $expected ) || ! isset( $expected['taxonomy'], $expected['terms'], $expected['include_ancestors'] ) || ! is_array( $expected['terms'] ) ) {
			return new ConditionEvaluation(
				'error',
				array(
					ReasonFactory::make( 'invalid_taxonomy_condition', 'Taxonomy condition is invalid.', 'error', 'taxonomy', $operator, $expected, null ),
				)
			);
		}

		if ( ! is_string( $expected['taxonomy'] ) || ! is_bool( $expected['include_ancestors'] ) || ! array_is_list( $expected['terms'] ) ) {
			return new ConditionEvaluation(
				'error',
				array(
					ReasonFactory::make( 'invalid_taxonomy_condition', 'Taxonomy condition is invalid.', 'error', 'taxonomy', $operator, $expected, null ),
				)
			);
		}

		$taxonomy          = $expected['taxonomy'];
		$expected_terms    = $this->expected_taxonomy_terms( $expected['terms'] );
		$include_ancestors = true === $expected['include_ancestors'];
		$observed_terms    = array();

		if ( '' === $taxonomy || array() === $expected_terms || $this->taxonomy_is_invalid( $taxonomy ) || $this->expected_terms_are_invalid( $taxonomy, $expected_terms ) ) {
			return new ConditionEvaluation(
				'error',
				array(
					ReasonFactory::make( 'invalid_taxonomy_configuration', 'Taxonomy condition configuration is invalid.', 'error', 'taxonomy:' . $taxonomy, $operator, $expected, null ),
				)
			);
		}

		foreach ( $event->terms as $term ) {
			if ( ( $term['taxonomy'] ?? null ) !== $taxonomy ) {
				continue;
			}

			$observed_terms[] = (string) ( $term['term_id'] ?? '' );
			$observed_terms[] = (string) ( $term['slug'] ?? '' );
			$observed_terms[] = (string) ( $term['name'] ?? '' );

			if ( $include_ancestors && isset( $term['ancestor_ids'] ) && is_array( $term['ancestor_ids'] ) ) {
				foreach ( $term['ancestor_ids'] as $ancestor_id ) {
					$observed_terms[] = (string) $ancestor_id;
				}
			}
		}

		$observed_terms = array_values(
			array_unique(
				array_filter(
					$observed_terms,
					static function ( string $term ): bool {
						return '' !== $term;
					}
				)
			)
		);
		$intersection   = array_intersect( $expected_terms, $observed_terms );

		if ( 'has_any' === $operator ) {
			$matched = array() !== $intersection;
		} elseif ( 'has_all' === $operator ) {
			$matched = count( $intersection ) === count( array_unique( $expected_terms ) );
		} elseif ( 'has_none' === $operator ) {
			$matched = array() === $intersection;
		} else {
			return new ConditionEvaluation(
				'error',
				array(
					ReasonFactory::make( 'invalid_taxonomy_operator', 'Taxonomy operator is invalid.', 'error', 'taxonomy', $operator, $expected, $observed_terms ),
				)
			);
		}

		return $this->single_reason( $matched, 'taxonomy:' . $taxonomy, 'Taxonomy condition evaluated.', $operator, $expected_terms, $observed_terms );
	}

	/**
	 * Normalize expected taxonomy term values.
	 *
	 * @param array<int,mixed> $terms Expected terms.
	 * @return array<int,string>
	 */
	private function expected_taxonomy_terms( array $terms ): array {
		$expected_terms = array();

		foreach ( $terms as $term ) {
			if ( ! is_int( $term ) && ! is_string( $term ) ) {
				return array();
			}

			$expected_terms[] = (string) $term;
		}

		return $expected_terms;
	}

	/**
	 * Check whether a taxonomy is invalid in the current WordPress runtime.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return bool
	 */
	private function taxonomy_is_invalid( string $taxonomy ): bool {
		return function_exists( 'taxonomy_exists' ) && ! taxonomy_exists( $taxonomy );
	}

	/**
	 * Check whether expected term references are invalid.
	 *
	 * @param string            $taxonomy Taxonomy.
	 * @param array<int,string> $terms Expected terms.
	 * @return bool
	 */
	private function expected_terms_are_invalid( string $taxonomy, array $terms ): bool {
		foreach ( $terms as $term ) {
			if ( '' === $term ) {
				return true;
			}

			if ( ctype_digit( $term ) && function_exists( 'get_term' ) && function_exists( 'is_wp_error' ) ) {
				$found = get_term( (int) $term, $taxonomy );

				if ( null === $found || is_wp_error( $found ) ) {
					return true;
				}
			} elseif ( ! ctype_digit( $term ) && function_exists( 'term_exists' ) ) {
				$found = term_exists( $term, $taxonomy );

				if ( null === $found || 0 === $found ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Compare allowlisted metadata.
	 *
	 * @param string          $operator Operator.
	 * @param mixed           $expected Expected value document.
	 * @param NormalizedEvent $event Event.
	 * @return ConditionEvaluation
	 */
	private function compare_metadata( string $operator, $expected, NormalizedEvent $event ): ConditionEvaluation {
		if ( ! is_array( $expected ) || ! isset( $expected['key'] ) || ! is_string( $expected['key'] ) ) {
			return new ConditionEvaluation(
				'error',
				array(
					ReasonFactory::make( 'invalid_metadata_condition', 'Metadata condition is invalid.', 'error', 'metadata', $operator, $expected, null ),
				)
			);
		}

		$key    = $expected['key'];
		$exists = array_key_exists( $key, $event->metadata ) && null !== $event->metadata[ $key ];
		$value  = $exists ? $event->metadata[ $key ] : null;

		if ( is_array( $value ) || is_object( $value ) ) {
			return new ConditionEvaluation(
				'error',
				array(
					ReasonFactory::make( 'metadata_non_scalar', 'Metadata value is not scalar.', 'error', 'metadata:' . $key, $operator, $expected, $value ),
				)
			);
		}

		if ( 'exists' === $operator ) {
			return $this->single_reason( $exists, 'metadata:' . $key, 'Metadata condition evaluated.', $operator, true, $exists );
		}

		if ( 'not_exists' === $operator ) {
			return $this->single_reason( ! $exists, 'metadata:' . $key, 'Metadata condition evaluated.', $operator, false, $exists );
		}

		if ( ! array_key_exists( 'value', $expected ) ) {
			return new ConditionEvaluation(
				'error',
				array(
					ReasonFactory::make( 'invalid_metadata_expected_value', 'Metadata condition expected value is invalid.', 'error', 'metadata:' . $key, $operator, $expected, $value ),
				)
			);
		}

		if ( ! $exists ) {
			return $this->single_reason( false, 'metadata:' . $key, 'Metadata condition evaluated.', $operator, $this->metadata_expected_scalar( $expected['value'] ), null );
		}

		$matched = $this->scalar_equals( $value, $this->metadata_expected_scalar( $expected['value'] ) );

		if ( 'not_equals' === $operator ) {
			$matched = ! $matched;
		} elseif ( 'equals' !== $operator ) {
			return new ConditionEvaluation(
				'error',
				array(
					ReasonFactory::make( 'invalid_metadata_operator', 'Metadata operator is invalid.', 'error', 'metadata:' . $key, $operator, $expected, $value ),
				)
			);
		}

		return $this->single_reason( $matched, 'metadata:' . $key, 'Metadata condition evaluated.', $operator, $this->metadata_expected_scalar( $expected['value'] ), $value );
	}

	/**
	 * Return explicit post policy.
	 *
	 * @param NormalizedEvent $event Event.
	 * @return string
	 */
	private function post_policy( NormalizedEvent $event ): string {
		$value = $event->control_bundle['policy'] ?? ( $event->metadata['_pressqueue_policy'] ?? null );

		if ( in_array( $value, array( 'include', 'exclude' ), true ) ) {
			return (string) $value;
		}

		return 'inherit';
	}

	/**
	 * Unwrap a typed metadata comparison value.
	 *
	 * @param mixed $value Expected value document or legacy scalar.
	 * @return mixed
	 */
	private function metadata_expected_scalar( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( ! array_key_exists( 'type', $value ) || ! array_key_exists( 'value', $value ) ) {
			throw new ValidationException( 'Metadata comparison value is invalid.' );
		}

		return $value['value'];
	}

	/**
	 * Compare scalar values using canonical string equivalence.
	 *
	 * @param mixed $left Left value.
	 * @param mixed $right Right value.
	 * @return bool
	 */
	private function scalar_equals( $left, $right ): bool {
		if ( is_bool( $right ) && is_string( $left ) ) {
			if ( ! in_array( $left, array( '0', '1', '' ), true ) ) {
				throw new ValidationException( 'Metadata value cannot be compared as a boolean.' );
			}
			$left = '1' === $left;
		}
		if ( is_bool( $left ) || is_bool( $right ) || null === $left || null === $right ) {
			return $left === $right;
		}
		if ( is_int( $right ) || is_float( $right ) ) {
			if ( ! is_numeric( $left ) ) {
				throw new ValidationException( 'Metadata value cannot be compared as a number.' );
			}
			return (float) $left === (float) $right;
		}
		return (string) $left === (string) $right;
	}

	/**
	 * Build a single matched or not matched reason.
	 *
	 * @param bool   $matched Whether matched.
	 * @param string $field Field.
	 * @param string $label Label.
	 * @param string $operator Operator.
	 * @param mixed  $expected Expected.
	 * @param mixed  $observed Observed.
	 * @return ConditionEvaluation
	 */
	private function single_reason( bool $matched, string $field, string $label, string $operator, $expected, $observed ): ConditionEvaluation {
		$result = $matched ? 'matched' : 'not_matched';
		$code   = strtolower( (string) preg_replace( '/[^a-zA-Z0-9_]+/', '_', str_replace( ':', '_', $field . '_' . $result ) ) );

		return new ConditionEvaluation(
			$result,
			array(
				ReasonFactory::make( $code, $label, $result, $field, $operator, $expected, $observed ),
			)
		);
	}
}
