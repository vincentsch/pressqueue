<?php
/**
 * Current editorial policy checks for frozen deliveries.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Delivery;

use PressQueue\Planning\ConditionEvaluator;
use PressQueue\Planning\EventNormalizer;
use PressQueue\Storage\Database;
use PressQueue\Storage\Record\DeliveryRecord;
use PressQueue\Storage\Repository\DecisionRepository;
use PressQueue\Storage\Repository\RuleRepository;

/**
 * Rechecks authority without changing the reviewed message or making HTTP calls.
 */
final class DeliveryPolicyGate {
	/**
	 * Database wrapper.
	 *
	 * @var Database
	 */
	private Database $database;

	/**
	 * Constructor.
	 *
	 * @param Database $database Database wrapper.
	 */
	public function __construct( Database $database ) {
		$this->database = $database;
	}

	/**
	 * Return a reason to hold delivery, or null if current policy permits it.
	 *
	 * @param DeliveryRecord $delivery Frozen delivery.
	 * @return string|null
	 */
	public function hold_reason( DeliveryRecord $delivery ): ?string {
		$decision = ( new DecisionRepository( $this->database ) )->find_by_public_id( $delivery->decision_public_id );
		if ( null === $decision || null === $decision->rule_public_id ) {
			return 'delivery_policy_missing';
		}
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $decision->post_id, 'posts' );
			wp_cache_delete( $decision->post_id, 'post_meta' );
		}
		$post = get_post( $decision->post_id );
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || '' !== $post->post_password ) {
			return 'post_not_public';
		}
		if ( function_exists( 'clean_object_term_cache' ) ) {
			clean_object_term_cache( $decision->post_id, $post->post_type );
		}
		$repository = new RuleRepository( $this->database );
		$rule       = $repository->find_by_public_id( $decision->rule_public_id );
		if ( null === $rule || ! $rule->enabled || null !== $rule->invalid_reason || $rule->version !== $decision->rule_version || ! $rule->applies_to_trigger( $decision->trigger ) ) {
			return 'rule_changed_or_disabled';
		}
		$rules      = $repository->find_enabled_for_trigger( $decision->trigger );
		$normalizer = new EventNormalizer();
		$event      = $normalizer->from_post_id( $decision->post_id, 'runtime', 'delivery_preflight', $decision->trigger, $normalizer->metadata_allowlist_for_rules( $rules ) );
		if ( null === $event ) {
			return 'post_not_public';
		}
		if ( 'exclude' === ( $event->control_bundle['policy'] ?? null ) ) {
			return 'post_excluded';
		}
		$channels = $event->control_bundle['channels'] ?? array();
		if ( 'override' === ( $channels['mode'] ?? null ) && ! in_array( $delivery->channel_public_id, $channels['channel_public_ids'] ?? array(), true ) ) {
			return 'post_destination_removed';
		}
		$evaluator = new ConditionEvaluator();
		if ( 'matched' !== $evaluator->evaluate( $rule->condition_tree, $event )->condition_result ) {
			return 'rule_conditions_changed';
		}
		foreach ( $rules as $candidate ) {
			if ( 'exclude' !== $candidate->effect ) {
				continue;
			}
			if ( null !== $candidate->invalid_reason || 'not_matched' !== $evaluator->evaluate( $candidate->condition_tree, $event )->condition_result ) {
				return 'current_exclusion_policy';
			}
		}
		return null;
	}
}
