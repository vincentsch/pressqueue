<?php
/**
 * Structured planning reasons.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Planning;

/**
 * Builds stable reason documents for decisions and previews.
 */
final class ReasonFactory {
	/**
	 * Build a reason.
	 *
	 * @param string              $code Stable reason code.
	 * @param string              $label Human label.
	 * @param string|null         $condition_result Optional condition result.
	 * @param string|null         $field Field name.
	 * @param string|null         $operator Operator.
	 * @param mixed               $expected Expected value.
	 * @param mixed               $observed Observed value.
	 * @param array<string,mixed> $extra Additional data.
	 * @return array<string,mixed>
	 */
	public static function make(
		string $code,
		string $label,
		?string $condition_result = null,
		?string $field = null,
		?string $operator = null,
		$expected = null,
		$observed = null,
		array $extra = array()
	): array {
		$reason = array(
			'code'     => $code,
			'label'    => $label,
			'field'    => $field,
			'operator' => $operator,
			'expected' => $expected,
			'observed' => $observed,
		);

		if ( null !== $condition_result ) {
			$reason['condition_result'] = $condition_result;
		}

		foreach ( $extra as $key => $value ) {
			$reason[ $key ] = $value;
		}

		return $reason;
	}
}
