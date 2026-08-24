<?php
/**
 * Storage enum validators.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage;

/**
 * Keeps storage states fail-closed.
 */
final class StorageEnums {
	public const RULE_EFFECTS            = array( 'include', 'exclude' );
	public const CONDITION_RESULTS       = array( 'matched', 'not_matched', 'error' );
	public const DECISION_RESULTS        = array( 'included', 'excluded', 'skipped', 'policy_error' );
	public const EVENT_MODES             = array( 'runtime', 'preview' );
	public const DELIVERY_MODES          = array( 'live', 'dry_run' );
	public const DELIVERY_STATES         = array( 'proposed', 'dry_run', 'held', 'queued', 'leased', 'retrying', 'accepted', 'failed', 'ambiguous', 'cancelled' );
	public const PROVIDER_RESULTS        = array( 'accepted', 'retryable_failure', 'rate_limited', 'permanent_failure', 'ambiguous' );
	public const RETRY_SAFETY_VALUES     = array( 'not_retryable', 'automatic_allowed', 'manual_allowed', 'reconcile_first' );
	public const RECONCILIATION_STATUSES = array( 'none', 'confirmed_remote_id', 'confirmed_not_accepted', 'cancelled' );
	public const DELIVERY_EVENT_TYPES    = array( 'destination_bound', 'queued', 'leased', 'submission_started', 'provider_result', 'retry_scheduled', 'held', 'failed', 'cancelled', 'resumed', 'manual_retry', 'reconciled', 'refused_transition', 'scheduler_enqueue_failed', 'scheduler_unavailable', 'scheduler_action_scheduled', 'scheduler_race_repaired', 'lease_recovered', 'lease_ambiguous' );
	public const DELIVERY_ACTOR_TYPES    = array( 'system', 'queue_sweep', 'worker', 'recovery', 'operator', 'scheduler' );
	public const CONNECTION_STATUSES     = array( 'disconnected', 'unverified', 'verified', 'credential_invalid' );
	public const CHANNEL_AVAILABILITIES  = array( 'unverified', 'available', 'missing', 'unsupported' );
	public const RULE_TRIGGERS           = array( 'first_publish', 'update', 'scheduled_publication' );
	public const SCHEDULE_POLICIES       = array( 'immediate' );
	public const SCOPE_TYPES             = array( 'global', 'channel' );

	/**
	 * Assert that a value belongs to an enum set.
	 *
	 * @param string            $name Allowed value name for errors.
	 * @param string            $value Candidate value.
	 * @param array<int,string> $allowed Allowed values.
	 */
	public static function assert( string $name, string $value, array $allowed ): void {
		unset( $name );

		if ( ! in_array( $value, $allowed, true ) ) {
			throw new ValidationException( 'Storage enum contains an unsupported value.' );
		}
	}

	/**
	 * Assert a rule effect.
	 *
	 * @param string $value Candidate value.
	 */
	public static function assert_rule_effect( string $value ): void {
		self::assert( 'Rule effect', $value, self::RULE_EFFECTS );
	}

	/**
	 * Assert a condition result.
	 *
	 * @param string $value Candidate value.
	 */
	public static function assert_condition_result( string $value ): void {
		self::assert( 'Condition result', $value, self::CONDITION_RESULTS );
	}

	/**
	 * Assert a decision result.
	 *
	 * @param string $value Candidate value.
	 */
	public static function assert_decision_result( string $value ): void {
		self::assert( 'Decision result', $value, self::DECISION_RESULTS );
	}

	/**
	 * Assert an event mode.
	 *
	 * @param string $value Candidate value.
	 */
	public static function assert_event_mode( string $value ): void {
		self::assert( 'Event mode', $value, self::EVENT_MODES );
	}

	/**
	 * Assert a delivery mode.
	 *
	 * @param string $value Candidate value.
	 */
	public static function assert_delivery_mode( string $value ): void {
		self::assert( 'Delivery mode', $value, self::DELIVERY_MODES );
	}

	/**
	 * Assert a delivery state.
	 *
	 * @param string $value Candidate value.
	 */
	public static function assert_delivery_state( string $value ): void {
		self::assert( 'Delivery state', $value, self::DELIVERY_STATES );
	}

	/**
	 * Assert a provider delivery result.
	 *
	 * @param string $value Candidate value.
	 */
	public static function assert_provider_result( string $value ): void {
		self::assert( 'Provider result', $value, self::PROVIDER_RESULTS );
	}

	/**
	 * Assert a retry safety value.
	 *
	 * @param string $value Candidate value.
	 */
	public static function assert_retry_safety( string $value ): void {
		self::assert( 'Retry safety', $value, self::RETRY_SAFETY_VALUES );
	}

	/**
	 * Assert a reconciliation status.
	 *
	 * @param string $value Candidate value.
	 */
	public static function assert_reconciliation_status( string $value ): void {
		self::assert( 'Reconciliation status', $value, self::RECONCILIATION_STATUSES );
	}

	/**
	 * Assert a delivery event type.
	 *
	 * @param string $value Candidate value.
	 */
	public static function assert_delivery_event_type( string $value ): void {
		self::assert( 'Delivery event type', $value, self::DELIVERY_EVENT_TYPES );
	}

	/**
	 * Assert a delivery actor type.
	 *
	 * @param string $value Candidate value.
	 */
	public static function assert_delivery_actor_type( string $value ): void {
		self::assert( 'Delivery actor type', $value, self::DELIVERY_ACTOR_TYPES );
	}

	/**
	 * Assert a connection status.
	 *
	 * @param string $value Candidate value.
	 */
	public static function assert_connection_status( string $value ): void {
		self::assert( 'Connection status', $value, self::CONNECTION_STATUSES );
	}

	/**
	 * Assert a channel availability.
	 *
	 * @param string $value Candidate value.
	 */
	public static function assert_channel_availability( string $value ): void {
		self::assert( 'Channel availability', $value, self::CHANNEL_AVAILABILITIES );
	}

	/**
	 * Assert a rule trigger.
	 *
	 * @param string $value Candidate value.
	 */
	public static function assert_rule_trigger( string $value ): void {
		self::assert( 'Rule trigger', $value, self::RULE_TRIGGERS );
	}

	/**
	 * Assert an event or decision scope type.
	 *
	 * @param string $value Candidate value.
	 */
	public static function assert_scope_type( string $value ): void {
		self::assert( 'Scope type', $value, self::SCOPE_TYPES );
	}
}
