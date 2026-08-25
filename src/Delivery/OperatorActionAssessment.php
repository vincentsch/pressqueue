<?php
/**
 * Delivery operator action assessment.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Delivery;

/**
 * Immutable zero-write delivery action assessment.
 */
final class OperatorActionAssessment {
	/**
	 * Constructor.
	 *
	 * @param bool              $applied Whether the mutation has been applied.
	 * @param bool              $would_mutate Whether the action would mutate storage if applied.
	 * @param array<int,string> $blocked_reasons Redacted refusal reasons.
	 * @param string|null       $record_public_id Delivery public ID.
	 * @param string|null       $old_state Source state.
	 * @param string|null       $new_state Expected target state.
	 * @param string|null       $event_public_id Delivery event public ID when known.
	 * @param int|null          $scheduled_action_id Action Scheduler ID when created.
	 */
	public function __construct(
		public readonly bool $applied,
		public readonly bool $would_mutate,
		public readonly array $blocked_reasons,
		public readonly ?string $record_public_id,
		public readonly ?string $old_state,
		public readonly ?string $new_state,
		public readonly ?string $event_public_id,
		public readonly ?int $scheduled_action_id
	) {}

	/**
	 * Build a blocked assessment.
	 *
	 * @param array<int,string> $reasons Reasons.
	 * @param string|null       $record_public_id Record public ID.
	 * @param string|null       $old_state Old state.
	 * @return self
	 */
	public static function blocked( array $reasons, ?string $record_public_id = null, ?string $old_state = null ): self {
		return new self( false, false, array_values( $reasons ), $record_public_id, $old_state, null, null, null );
	}

	/**
	 * Build a successful zero-write assessment.
	 *
	 * @param string $record_public_id Record public ID.
	 * @param string $old_state Old state.
	 * @param string $new_state New state.
	 * @return self
	 */
	public static function would_mutate( string $record_public_id, string $old_state, string $new_state ): self {
		return new self( false, true, array(), $record_public_id, $old_state, $new_state, null, null );
	}

	/**
	 * Build an applied assessment.
	 *
	 * @param string      $record_public_id Record public ID.
	 * @param string|null $old_state Old state.
	 * @param string      $new_state New state.
	 * @param string|null $event_public_id Event public ID.
	 * @param int|null    $scheduled_action_id Action Scheduler ID.
	 * @return self
	 */
	public static function applied( string $record_public_id, ?string $old_state, string $new_state, ?string $event_public_id = null, ?int $scheduled_action_id = null ): self {
		return new self( true, true, array(), $record_public_id, $old_state, $new_state, $event_public_id, $scheduled_action_id );
	}

	/**
	 * Return the stable CLI document.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'applied'             => $this->applied,
			'blocked_reasons'     => $this->blocked_reasons,
			'event_public_id'     => $this->event_public_id,
			'new_state'           => $this->new_state,
			'old_state'           => $this->old_state,
			'record_public_id'    => $this->record_public_id,
			'scheduled_action_id' => $this->scheduled_action_id,
			'would_mutate'        => $this->would_mutate,
		);
	}
}
