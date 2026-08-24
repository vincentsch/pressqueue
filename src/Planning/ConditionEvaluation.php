<?php
/**
 * Condition evaluation result.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Planning;

// phpcs:disable Squiz.Commenting.VariableComment.Missing -- Constructor docs cover this immutable DTO.

/**
 * Holds the outcome and collected reasons for a condition tree.
 */
final class ConditionEvaluation {
	public string $condition_result;
	/**
	 * Structured reasons.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $reasons;

	/**
	 * Constructor.
	 *
	 * @param string                         $condition_result matched, not_matched, or error.
	 * @param array<int,array<string,mixed>> $reasons Reasons.
	 */
	public function __construct( string $condition_result, array $reasons ) {
		$this->condition_result = $condition_result;
		$this->reasons          = $reasons;
	}

	/**
	 * Whether the condition matched.
	 *
	 * @return bool
	 */
	public function matched(): bool {
		return 'matched' === $this->condition_result;
	}

	/**
	 * Whether the condition had an evaluation error.
	 *
	 * @return bool
	 */
	public function errored(): bool {
		return 'error' === $this->condition_result;
	}
}
