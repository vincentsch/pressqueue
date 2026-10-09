<?php
/**
 * Rule planner.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Planning;

use PressQueue\Batch\DuplicateHistoryService;
use PressQueue\Editorial\PostControlMetadata;
use PressQueue\Provider\Capability\DeliveryCapability;
use PressQueue\Runtime\RuntimeDisabledLatch;
use PressQueue\Security\SafeOperatorError;
use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\Database;
use PressQueue\Storage\HashKeys;
use PressQueue\Storage\PolicySettings;
use PressQueue\Storage\PublicIds;
use PressQueue\Storage\Record\ChannelRecord;
use PressQueue\Storage\Record\ConnectionRecord;
use PressQueue\Storage\Record\DecisionRecord;
use PressQueue\Storage\Record\DeliveryRecord;
use PressQueue\Storage\Record\RuleRecord;
use PressQueue\Storage\Repository\ChannelRepository;
use PressQueue\Storage\Repository\ConnectionRepository;
use PressQueue\Storage\Repository\DecisionRepository;
use PressQueue\Storage\Repository\DeliveryRepository;
use PressQueue\Storage\Repository\RuleRepository;
use PressQueue\Storage\RuleDocuments;
use PressQueue\Storage\Schema;
use PressQueue\Storage\SettingsRepository;
use PressQueue\Storage\StorageException;
use PressQueue\Storage\ValidationException;

/**
 * Evaluates rules and optionally ensures immutable storage rows.
 */
final class Planner {
	/**
	 * Shared transaction wrapper.
	 *
	 * @var Database
	 */
	private Database $database;

	/**
	 * Destination reservation and delivery history.
	 *
	 * @var DuplicateHistoryService
	 */
	private DuplicateHistoryService $duplicates;

	/**
	 * Rule repository.
	 *
	 * @var RuleRepository
	 */
	private RuleRepository $rules;

	/**
	 * Decision repository.
	 *
	 * @var DecisionRepository
	 */
	private DecisionRepository $decisions;

	/**
	 * Delivery repository.
	 *
	 * @var DeliveryRepository
	 */
	private DeliveryRepository $deliveries;

	/**
	 * Channel repository.
	 *
	 * @var ChannelRepository
	 */
	private ChannelRepository $channels;

	/**
	 * Connection repository.
	 *
	 * @var ConnectionRepository
	 */
	private ConnectionRepository $connections;

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Condition evaluator.
	 *
	 * @var ConditionEvaluator
	 */
	private ConditionEvaluator $conditions;

	/**
	 * Template renderer.
	 *
	 * @var TemplateRenderer
	 */
	private TemplateRenderer $templates;

	/**
	 * Template set resolver.
	 *
	 * @var TemplateSetResolver
	 */
	private TemplateSetResolver $template_sets;

	/**
	 * Schedule resolver.
	 *
	 * @var ScheduleResolver
	 */
	private ScheduleResolver $schedules;

	/**
	 * Media resolver.
	 *
	 * @var MediaResolver
	 */
	private MediaResolver $media;

	/**
	 * Constructor.
	 *
	 * @param Database|null $database Database wrapper.
	 */
	public function __construct( ?Database $database = null ) {
		$database            = $database ?? Database::from_globals();
		$this->database      = $database;
		$this->duplicates    = new DuplicateHistoryService( $database );
		$this->rules         = new RuleRepository( $database );
		$this->decisions     = new DecisionRepository( $database );
		$this->deliveries    = new DeliveryRepository( $database );
		$this->channels      = new ChannelRepository( $database );
		$this->connections   = new ConnectionRepository( $database );
		$this->settings      = new SettingsRepository();
		$this->conditions    = new ConditionEvaluator();
		$this->templates     = new TemplateRenderer();
		$this->template_sets = new TemplateSetResolver();
		$this->schedules     = new ScheduleResolver();
		$this->media         = new MediaResolver();
	}

	/**
	 * Run non-durable preview evaluation.
	 *
	 * @param NormalizedEvent            $event Event.
	 * @param array<int,RuleRecord>|null $rules Optional rules, including disabled rules.
	 * @param bool                       $allow_probe Whether new media URL probes may run.
	 * @return PlanningResult
	 */
	public function preview( NormalizedEvent $event, ?array $rules = null, bool $allow_probe = true ): PlanningResult {
		return $this->plan( $event, $rules ?? $this->rules->find_enabled_for_trigger( $event->trigger ), false, null, null, $allow_probe );
	}

	/**
	 * Run durable runtime planning.
	 *
	 * @param NormalizedEvent $event Event.
	 * @return PlanningResult
	 */
	public function plan_runtime( NormalizedEvent $event ): PlanningResult {
		RuntimeDisabledLatch::assert_open_for( 'runtime planning' );

		return $this->plan( $event, $this->rules->find_enabled_for_trigger( $event->trigger ), true );
	}

	/**
	 * Run non-durable runtime planning for one destination channel.
	 *
	 * @param NormalizedEvent     $event Event.
	 * @param string              $channel_public_id Destination channel public ID.
	 * @param SchedulePolicy|null $schedule_override Reviewed schedule override.
	 * @return PlanningResult
	 */
	public function preview_runtime_destination( NormalizedEvent $event, string $channel_public_id, ?SchedulePolicy $schedule_override = null ): PlanningResult {
		PublicIds::assert( $channel_public_id );

		return $this->plan( $event, $this->rules->find_enabled_for_trigger( $event->trigger ), false, $channel_public_id, $schedule_override );
	}

	/**
	 * Run durable runtime planning for one destination channel.
	 *
	 * @param NormalizedEvent     $event Event.
	 * @param string              $channel_public_id Destination channel public ID.
	 * @param SchedulePolicy|null $schedule_override Reviewed schedule override.
	 * @return PlanningResult
	 */
	public function plan_runtime_destination( NormalizedEvent $event, string $channel_public_id, ?SchedulePolicy $schedule_override = null ): PlanningResult {
		PublicIds::assert( $channel_public_id );
		RuntimeDisabledLatch::assert_open_for( 'runtime destination planning' );

		return $this->plan( $event, $this->rules->find_enabled_for_trigger( $event->trigger ), true, $channel_public_id, $schedule_override, false );
	}

	/**
	 * Run planning.
	 *
	 * @param NormalizedEvent       $event Event.
	 * @param array<int,RuleRecord> $rules Rules to evaluate.
	 * @param bool                  $durable Whether to write rows.
	 * @param string|null           $only_channel_public_id Optional destination filter.
	 * @param SchedulePolicy|null   $schedule_override Reviewed schedule override.
	 * @param bool                  $allow_probe Whether new media URL probes may run.
	 * @return PlanningResult
	 */
	private function plan( NormalizedEvent $event, array $rules, bool $durable, ?string $only_channel_public_id = null, ?SchedulePolicy $schedule_override = null, bool $allow_probe = true ): PlanningResult {
		if ( null !== $only_channel_public_id ) {
			PublicIds::assert( $only_channel_public_id );
		}

		$settings   = $this->settings->read();
		$decisions  = array();
		$deliveries = array();

		if ( 'exclude' === ( $event->control_bundle['policy'] ?? null ) ) {
			$decisions[] = $this->decision_summary(
				$event,
				$settings,
				'excluded',
				'global',
				null,
				null,
				array(
					ReasonFactory::make( 'explicit_post_exclusion', 'Post is explicitly excluded from social publishing.', 'matched', PostControlMetadata::LEGACY_META_KEY, 'equals', 'exclude', 'exclude' ),
				),
				null,
				$durable
			);

			return new PlanningResult( $event, $decisions, array(), $durable );
		}

		if ( $event->is_backdated_automatic_publication() ) {
			$decisions[] = $this->decision_summary(
				$event,
				$settings,
				'skipped',
				'global',
				null,
				null,
				array( ReasonFactory::make( 'backdated_publication', 'Automatic first publication is skipped for posts dated more than one day ago. Use a reviewed manual batch.', 'not_matched', 'published_at_gmt', 'backdated', $event->published_at_gmt, null ) ),
				null,
				$durable
			);
			return new PlanningResult( $event, $decisions, array(), $durable );
		}

		if ( array() === $rules ) {
			$decisions[] = $this->decision_summary(
				$event,
				$settings,
				'skipped',
				'global',
				null,
				null,
				array(
					ReasonFactory::make( 'no_enabled_rules', 'No enabled rules apply to this trigger.', 'not_matched', 'trigger', 'has_enabled_rule', $event->trigger, null ),
				),
				null,
				$durable
			);

			return new PlanningResult( $event, $decisions, array(), $durable );
		}

		$evaluated = $this->evaluate_rules( $event, $rules );

		$exclusion_errors  = array();
		$matched_excludes  = array();
		$matching_includes = array();
		$include_errors    = array();
		$evaluated_matches = 0;

		foreach ( $evaluated as $entry ) {
			$rule = $entry['rule'];

			if ( 'exclude' === $rule->effect ) {
				if ( 'error' === $entry['condition_result'] ) {
					$exclusion_errors[] = $entry;
				} elseif ( 'matched' === $entry['condition_result'] ) {
					$matched_excludes[] = $entry;
					++$evaluated_matches;
				}
				continue;
			}

			if ( 'error' === $entry['condition_result'] ) {
				$include_errors[] = $entry;
			} elseif ( 'matched' === $entry['condition_result'] ) {
				$matching_includes[] = $entry;
				++$evaluated_matches;
			}
		}

		$skipped_rule_decisions = $this->skipped_rule_decisions( $event, $settings, $evaluated, $durable );

		if ( array() !== $exclusion_errors ) {
			foreach ( $exclusion_errors as $entry ) {
				$decisions[] = $this->decision_summary(
					$event,
					$settings,
					'policy_error',
					'global',
					null,
					$entry['rule'],
					$entry['reasons'],
					null,
					$durable
				);
			}

			foreach ( $matched_excludes as $entry ) {
				$decisions[] = $this->decision_summary(
					$event,
					$settings,
					'skipped',
					'global',
					null,
					$entry['rule'],
					array_merge(
						$entry['reasons'],
						array(
							ReasonFactory::make( 'blocked_by_exclusion_error', 'Matched exclusion was blocked by another exclusion policy error.', 'matched', 'rule', 'blocked_by_exclusion_error', $entry['rule']->public_id, null ),
						)
					),
					null,
					$durable
				);
			}

			$decisions = array_merge( $decisions, $skipped_rule_decisions );

			return new PlanningResult( $event, $decisions, array(), $durable );
		}

		if ( array() !== $matched_excludes ) {
			foreach ( $matched_excludes as $entry ) {
				$decisions[] = $this->decision_summary(
					$event,
					$settings,
					'excluded',
					'global',
					null,
					$entry['rule'],
					$entry['reasons'],
					null,
					$durable
				);
			}

			$decisions = array_merge( $decisions, $skipped_rule_decisions );

			return new PlanningResult( $event, $decisions, array(), $durable );
		}

		$candidates               = array();
		$blocked_destinations     = array();
		$override_public_ids      = $this->channel_override_public_ids( $event );
		$matching_destination_ids = array();

		foreach ( $include_errors as $entry ) {
			$destination_public_ids = $this->destination_public_ids( $entry['rule'] );

			if ( array() === $destination_public_ids ) {
				$decisions[] = $this->decision_summary( $event, $settings, 'policy_error', 'global', null, $entry['rule'], $entry['reasons'], null, $durable );
				continue;
			}

			foreach ( $destination_public_ids as $channel_public_id ) {
				if ( null !== $only_channel_public_id && $channel_public_id !== $only_channel_public_id ) {
					continue;
				}

				$blocked_destinations[ $channel_public_id ] = true;

				$decisions[] = $this->decision_summary( $event, $settings, 'policy_error', 'channel', $channel_public_id, $entry['rule'], $entry['reasons'], null, $durable );
			}
		}

		foreach ( $matching_includes as $entry ) {
			$rule                   = $entry['rule'];
			$destination_public_ids = $this->destination_public_ids( $rule );

			if ( array() === $destination_public_ids ) {
				$decisions[] = $this->decision_summary(
					$event,
					$settings,
					'skipped',
					'global',
					null,
					$rule,
					array_merge(
						$entry['reasons'],
						array(
							ReasonFactory::make( 'empty_destination_set', 'Rule has no local destinations.', 'not_matched', 'destination_set', 'not_empty', null, array() ),
						)
					),
					null,
					$durable
				);
				continue;
			}

			foreach ( $destination_public_ids as $channel_public_id ) {
				if ( null !== $only_channel_public_id && $channel_public_id !== $only_channel_public_id ) {
					continue;
				}

				$matching_destination_ids[ $channel_public_id ] = true;

				if ( null !== $override_public_ids && ! in_array( $channel_public_id, $override_public_ids, true ) ) {
					$decisions[] = $this->decision_summary(
						$event,
						$settings,
						'skipped',
						'channel',
						$channel_public_id,
						$rule,
						array_merge(
							$entry['reasons'],
							array(
								ReasonFactory::make( 'omitted_by_channel_override', 'Destination was omitted by the post channel override.', 'not_matched', 'channel_override', 'contains', $override_public_ids, $channel_public_id ),
							)
						),
						$this->destination_snapshot_if_available( $channel_public_id ),
						$durable
					);
					continue;
				}

				$destination = $this->resolve_destination( $channel_public_id );

				if ( ! $destination['ok'] ) {
					$decisions[] = $this->decision_summary(
						$event,
						$settings,
						$destination['result'],
						'channel',
						$channel_public_id,
						$rule,
						array_merge( $entry['reasons'], array( $destination['reason'] ) ),
						$destination['destination'],
						$durable
					);
					continue;
				}

				try {
					$template_set     = $this->template_sets->effective_template_set( $rule );
					$message_template = $this->template_sets->message_for_service( $template_set, (string) $destination['destination']['service'] );
					if ( isset( $event->control_bundle['message'] ) && is_array( $event->control_bundle['message'] ) && ( $event->control_bundle['message']['mode'] ?? null ) === 'override' && isset( $event->control_bundle['message']['value'] ) && is_string( $event->control_bundle['message']['value'] ) ) {
						$message_template = $event->control_bundle['message']['value'];
					}

					$schedule = $schedule_override ?? $this->schedules->resolve( $rule, $event->control_bundle );
					if ( 'draft' === RuleDocuments::schedule_policy( $rule->schedule_policy_document )['mode'] && 'draft' !== $schedule->slot ) {
						throw new ValidationException( 'A draft-only rule cannot be overridden to publish or schedule a social post.' );
					}
					$media           = $this->media->resolve( $rule, $event, $destination['destination']['capability_document'], $allow_probe );
					$payload_preview = $this->templates->payload_preview_v2(
						$message_template,
						$template_set,
						$this->template_sets->provenance( $rule ),
						$rule->custom_field_allowlist,
						$event,
						$destination['destination'],
						$rule->public_id,
						$rule->version,
						$schedule,
						$media,
						$destination['destination']['capability_document']
					);
				} catch ( ValidationException $exception ) {
					$blocked_destinations[ $channel_public_id ] = true;

					$decisions[] = $this->decision_summary(
						$event,
						$settings,
						'policy_error',
						'channel',
						$channel_public_id,
						$rule,
						array_merge(
							$entry['reasons'],
							array(
								ReasonFactory::make( 'render_policy_error', SafeOperatorError::message( $exception ), 'error', 'payload', 'render', $rule->public_id, null ),
							)
						),
						$destination['destination'],
						$durable
					);
					continue;
				}

				$candidates[ $channel_public_id ][] = array(
					'destination'     => $destination['destination'],
					'payload_preview' => $payload_preview,
					'reasons'         => $entry['reasons'],
					'rule'            => $rule,
				);
			}
		}

		if ( null !== $override_public_ids ) {
			foreach ( $override_public_ids as $requested_public_id ) {
				if ( null !== $only_channel_public_id && $requested_public_id !== $only_channel_public_id ) {
					continue;
				}

				if ( isset( $matching_destination_ids[ $requested_public_id ] ) ) {
					continue;
				}

				$destination = $this->resolve_destination( $requested_public_id );
				$reasons     = array(
					ReasonFactory::make( 'requested_channel_not_selected_by_rules', 'Requested channel was not selected by any matching inclusion rule.', 'not_matched', 'channel_override', 'selected_by_rule', $requested_public_id, false ),
				);

				if ( ! $destination['ok'] ) {
					$reasons[] = $destination['reason'];
				}

				$decisions[] = $this->decision_summary(
					$event,
					$settings,
					'skipped',
					'channel',
					$requested_public_id,
					null,
					$reasons,
					$destination['destination'],
					$durable
				);
			}
		}

		foreach ( $candidates as $channel_public_id => $destination_candidates ) {
			if ( isset( $blocked_destinations[ $channel_public_id ] ) ) {
				foreach ( $destination_candidates as $candidate ) {
					$decisions[] = $this->decision_summary(
						$event,
						$settings,
						'skipped',
						'channel',
						$channel_public_id,
						$candidate['rule'],
						array_merge(
							$candidate['reasons'],
							array(
								ReasonFactory::make( 'blocked_by_destination_policy_error', 'Destination was blocked by another inclusion policy error.', 'matched', 'destination', 'blocked_by_policy_error', $channel_public_id, null ),
							)
						),
						$candidate['destination'],
						$durable
					);
				}

				continue;
			}

			usort(
				$destination_candidates,
				static function ( array $left, array $right ): int {
					$priority = $left['rule']->priority <=> $right['rule']->priority;

					if ( 0 !== $priority ) {
						return $priority;
					}

					return strcmp( $left['rule']->public_id, $right['rule']->public_id );
				}
			);

			$winner = array_shift( $destination_candidates );

			if ( null !== $winner ) {
				$planned     = $this->plan_winner( $event, $settings, $channel_public_id, $winner, $durable );
				$decisions[] = $planned['decision'];
				if ( null !== $planned['delivery'] ) {
					$deliveries[] = $planned['delivery'];
				}
			}

			foreach ( $destination_candidates as $candidate ) {
				$decisions[] = $this->decision_summary(
					$event,
					$settings,
					'skipped',
					'channel',
					$channel_public_id,
					$candidate['rule'],
					array_merge(
						$candidate['reasons'],
						array(
							ReasonFactory::make( 'superseded_by_rule', 'Rule was superseded by a higher-priority match.', 'matched', 'rule', 'lower_priority', $candidate['rule']->public_id, $winner['rule']->public_id ?? null ),
						)
					),
					$candidate['destination'],
					$durable
				);
			}
		}

		if ( array() === $decisions && 0 === $evaluated_matches ) {
			$decisions[] = $this->decision_summary(
				$event,
				$settings,
				'skipped',
				'global',
				null,
				null,
				array(
					ReasonFactory::make( 'no_matching_rules', 'Enabled rules exist but none matched this event.', 'not_matched', 'rules', 'any_match', true, false, array( 'evaluated_rules' => $this->rule_summaries( $evaluated ) ) ),
				),
				null,
				$durable
			);
		}

		$decisions = array_merge( $decisions, $skipped_rule_decisions );

		return new PlanningResult( $event, $decisions, $deliveries, $durable );
	}

	/**
	 * Store a winner while serializing live first publication with manual reservations.
	 *
	 * @param NormalizedEvent     $event Event.
	 * @param PolicySettings      $settings Settings.
	 * @param string              $channel_public_id Destination.
	 * @param array<string,mixed> $winner Winning candidate.
	 * @param bool                $durable Whether to write rows.
	 * @return array{decision:array<string,mixed>,delivery:array<string,mixed>|null}
	 */
	private function plan_winner( NormalizedEvent $event, PolicySettings $settings, string $channel_public_id, array $winner, bool $durable ): array {
		$reasons = array_merge(
			$winner['reasons'],
			array( ReasonFactory::make( 'included_destination', 'Destination is included by the winning rule.', 'matched', 'destination', 'selected', $channel_public_id, $winner['rule']->public_id ) )
		);
		$store   = function () use ( $event, $settings, $channel_public_id, $winner, $durable, $reasons ): array {
			$decision = $this->decision_summary( $event, $settings, 'included', 'channel', $channel_public_id, $winner['rule'], $reasons, $winner['destination'], $durable );
			return array(
				'decision' => $decision,
				'delivery' => $this->delivery_summary( $event, $settings, $decision, $winner['destination'], $winner['payload_preview'], $durable ),
			);
		};

		if ( ! $durable || 'live' !== $settings->delivery_mode || ! in_array( $event->trigger, array( 'first_publish', 'scheduled_publication' ), true ) ) {
			return $store();
		}

		return $this->database->transaction(
			function () use ( $event, $settings, $channel_public_id, $winner, $reasons, $store ): array {
				// This is the same destination lock acquired by manual confirmation.
				if ( 1 !== $this->database->query( 'SELECT public_id FROM `' . $this->database->table( Schema::CHANNELS ) . '` WHERE public_id = %s FOR UPDATE', array( $channel_public_id ) ) ) {
					throw new StorageException( 'Destination could not be locked for first publication.' );
				}
				$decision = $this->decision_summary( $event, $settings, 'included', 'channel', $channel_public_id, $winner['rule'], $reasons, $winner['destination'], false );
				$delivery = $this->delivery_summary( $event, $settings, $decision, $winner['destination'], $winner['payload_preview'], false );
				$existing = $this->database->query( 'SELECT public_id FROM `' . $this->database->table( Schema::DELIVERIES ) . '` WHERE delivery_key = %s FOR UPDATE', array( $delivery['delivery_key'] ) );

				// An exact replay retains the original identity even after acceptance.
				if ( 0 === $existing && null !== $this->duplicates->blocking_bulk_duplicate( $event->post_id, $channel_public_id, null, 'live', true ) ) {
					return array(
						'decision' => $this->decision_summary(
							$event,
							$settings,
							'skipped',
							'channel',
							$channel_public_id,
							$winner['rule'],
							array_merge( $winner['reasons'], array( ReasonFactory::make( 'first_publish_duplicate', 'Existing live delivery or confirmed manual reservation blocks first publication to this destination.', 'matched', 'destination', 'already_reserved', $channel_public_id, $channel_public_id ) ) ),
							$winner['destination'],
							true
						),
						'delivery' => null,
					);
				}

				return $store();
			},
			array( Schema::CHANNELS, Schema::BATCHES, Schema::BATCH_ITEMS, Schema::DECISIONS, Schema::DELIVERIES )
		);
	}

	/**
	 * Build rule-level skipped decisions for evaluated rules that did not match.
	 *
	 * @param NormalizedEvent                $event Event.
	 * @param PolicySettings                 $settings Settings.
	 * @param array<int,array<string,mixed>> $evaluated Evaluated rules.
	 * @param bool                           $durable Whether to write rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function skipped_rule_decisions( NormalizedEvent $event, PolicySettings $settings, array $evaluated, bool $durable ): array {
		$decisions = array();

		foreach ( $evaluated as $entry ) {
			if ( 'not_matched' !== $entry['condition_result'] ) {
				continue;
			}

			$decisions[] = $this->decision_summary(
				$event,
				$settings,
				'skipped',
				'global',
				null,
				$entry['rule'],
				$entry['reasons'],
				null,
				$durable
			);
		}

		return $decisions;
	}

	/**
	 * Evaluate rules for an event.
	 *
	 * @param NormalizedEvent       $event Event.
	 * @param array<int,RuleRecord> $rules Rules.
	 * @return array<int,array{rule:RuleRecord,condition_result:string,reasons:array<int,array<string,mixed>>}>
	 */
	private function evaluate_rules( NormalizedEvent $event, array $rules ): array {
		$evaluated = array();

		foreach ( $rules as $rule ) {
			try {
				$this->validate_rule_documents( $rule );

				if ( ! $rule->applies_to_trigger( $event->trigger ) ) {
					$evaluated[] = array(
						'condition_result' => 'not_matched',
						'reasons'          => array(
							ReasonFactory::make( 'trigger_not_selected', 'Rule does not include this trigger.', 'not_matched', 'trigger', 'in', $rule->triggers['triggers'] ?? array(), $event->trigger ),
						),
						'rule'             => $rule,
					);
					continue;
				}

				$evaluation  = $this->conditions->evaluate( $rule->condition_tree, $event );
				$evaluated[] = array(
					'condition_result' => $evaluation->condition_result,
					'reasons'          => $evaluation->reasons,
					'rule'             => $rule,
				);
			} catch ( ValidationException $exception ) {
				$evaluated[] = array(
					'condition_result' => 'error',
					'reasons'          => array(
						ReasonFactory::make( 'malformed_rule', SafeOperatorError::message( $exception ), 'error', 'rule', 'valid_document', $rule->public_id, null ),
					),
					'rule'             => $rule,
				);
			}
		}

		return $evaluated;
	}

	/**
	 * Validate rule document shapes before evaluation.
	 *
	 * @param RuleRecord $rule Rule.
	 */
	private function validate_rule_documents( RuleRecord $rule ): void {
		if ( null !== $rule->invalid_reason ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Stored validation messages are not rendered directly.
			throw new ValidationException( $rule->invalid_reason );
		}

		RuleDocuments::triggers( $rule->triggers['triggers'] ?? array() );
		RuleDocuments::custom_field_allowlist( $rule->custom_field_allowlist );
		RuleDocuments::condition_tree( $rule->condition_tree, $rule->custom_field_allowlist );
		RuleDocuments::inline_template( $rule->inline_template );
		RuleDocuments::template_set( $this->template_sets->effective_template_set( $rule ), $rule->custom_field_allowlist );
		RuleDocuments::destination_set( $rule->effect, $rule->destination_set );
		RuleDocuments::schedule_policy( $rule->schedule_policy_document );
		RuleDocuments::media_policy( $rule->media_policy );
	}

	/**
	 * Return destination public IDs for an include rule.
	 *
	 * @param RuleRecord $rule Rule.
	 * @return array<int,string>
	 */
	private function destination_public_ids( RuleRecord $rule ): array {
		if ( 'include' !== $rule->effect || ! isset( $rule->destination_set['destinations'] ) || ! is_array( $rule->destination_set['destinations'] ) ) {
			return array();
		}

		$public_ids = array();
		foreach ( $rule->destination_set['destinations'] as $destination ) {
			if ( is_array( $destination ) && isset( $destination['channel_public_id'] ) && is_string( $destination['channel_public_id'] ) ) {
				$public_ids[] = $destination['channel_public_id'];
			}
		}

		return array_values( array_unique( $public_ids ) );
	}

	/**
	 * Return restrictive override channel IDs, or null when controls inherit.
	 *
	 * @param NormalizedEvent $event Event.
	 * @return array<int,string>|null
	 */
	private function channel_override_public_ids( NormalizedEvent $event ): ?array {
		$channels = $event->control_bundle['channels'] ?? array();

		if ( ! is_array( $channels ) || ( $channels['mode'] ?? null ) !== 'override' ) {
			return null;
		}

		$ids = $channels['channel_public_ids'] ?? array();

		if ( ! is_array( $ids ) || ! array_is_list( $ids ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$ids,
				static function ( $id ): bool {
					return is_string( $id );
				}
			)
		);
	}

	/**
	 * Return a destination snapshot when the channel exists.
	 *
	 * @param string $channel_public_id Channel public ID.
	 * @return array<string,mixed>|null
	 */
	private function destination_snapshot_if_available( string $channel_public_id ): ?array {
		$channel = $this->channels->find_by_public_id( $channel_public_id );

		if ( null === $channel ) {
			return null;
		}

		$connection = $this->connections->find_by_public_id( $channel->connection_public_id );

		return $this->destination_snapshot( $channel, $connection );
	}

	/**
	 * Resolve a destination and its labels.
	 *
	 * @param string $channel_public_id Channel public ID.
	 * @return array{ok:bool,result:string,reason:array<string,mixed>,destination:array<string,mixed>|null}
	 */
	private function resolve_destination( string $channel_public_id ): array {
		$channel = $this->channels->find_by_public_id( $channel_public_id );

		if ( null === $channel ) {
			return array(
				'destination' => null,
				'ok'          => false,
				'reason'      => ReasonFactory::make( 'missing_channel', 'Destination channel does not exist.', 'error', 'channel_public_id', 'exists', $channel_public_id, null ),
				'result'      => 'policy_error',
			);
		}

		if ( ! $channel->enabled ) {
			return array(
				'destination' => $this->destination_snapshot( $channel, null ),
				'ok'          => false,
				'reason'      => ReasonFactory::make( 'disabled_channel', 'Destination channel is disabled.', 'not_matched', 'channel_enabled', 'equals', true, false ),
				'result'      => 'skipped',
			);
		}

		if ( 'available' !== $channel->availability ) {
			return array(
				'destination' => $this->destination_snapshot( $channel, null ),
				'ok'          => false,
				'reason'      => ReasonFactory::make( 'unavailable_channel', 'Destination channel is not available for text delivery.', 'error', 'channel_availability', 'equals', 'available', $channel->availability ),
				'result'      => 'policy_error',
			);
		}

		$capability = $this->channel_capability_decision( $channel );
		if ( ! $capability['compatible'] ) {
			return array(
				'destination' => $this->destination_snapshot( $channel, null ),
				'ok'          => false,
				'reason'      => ReasonFactory::make( 'unsupported_channel_capability', 'Destination channel does not support text immediate delivery.', 'error', 'channel_capability', 'supports_text_immediate', true, $capability ),
				'result'      => 'policy_error',
			);
		}

		$connection = $this->connections->find_by_public_id( $channel->connection_public_id );

		if ( null === $connection ) {
			return array(
				'destination' => $this->destination_snapshot( $channel, null ),
				'ok'          => false,
				'reason'      => ReasonFactory::make( 'missing_connection', 'Destination connection does not exist.', 'error', 'connection_public_id', 'exists', $channel->connection_public_id, null ),
				'result'      => 'policy_error',
			);
		}

		if ( $connection->provider !== $channel->provider ) {
			return array(
				'destination' => $this->destination_snapshot( $channel, $connection ),
				'ok'          => false,
				'reason'      => ReasonFactory::make( 'destination_identity_mismatch', 'Destination identity is inconsistent.', 'error', 'provider', 'same_provider', $connection->provider, $channel->provider ),
				'result'      => 'policy_error',
			);
		}

		if ( ! $connection->enabled ) {
			return array(
				'destination' => $this->destination_snapshot( $channel, $connection ),
				'ok'          => false,
				'reason'      => ReasonFactory::make( 'disabled_connection', 'Destination connection is disabled.', 'error', 'connection_enabled', 'equals', true, false ),
				'result'      => 'policy_error',
			);
		}

		if ( 'verified' !== $connection->connection_status ) {
			return array(
				'destination' => $this->destination_snapshot( $channel, $connection ),
				'ok'          => false,
				'reason'      => ReasonFactory::make( 'unverified_connection', 'Destination connection is not verified.', 'error', 'connection_status', 'equals', 'verified', $connection->connection_status ),
				'result'      => 'policy_error',
			);
		}

		if ( ! $this->connection_has_delivery_identity( $connection ) ) {
			return array(
				'destination' => $this->destination_snapshot( $channel, $connection ),
				'ok'          => false,
				'reason'      => ReasonFactory::make( 'unverified_connection', 'Destination connection identity is not complete.', 'error', 'connection_identity', 'complete', true, false ),
				'result'      => 'policy_error',
			);
		}

		if ( $channel->remote_organization_id !== $connection->remote_organization_id ) {
			return array(
				'destination' => $this->destination_snapshot( $channel, $connection ),
				'ok'          => false,
				'reason'      => ReasonFactory::make( 'destination_identity_mismatch', 'Destination organization does not match the selected connection organization.', 'error', 'remote_organization_id', 'equals', $connection->remote_organization_id, $channel->remote_organization_id ),
				'result'      => 'policy_error',
			);
		}

		return array(
			'destination' => $this->destination_snapshot( $channel, $connection ),
			'ok'          => true,
			'reason'      => ReasonFactory::make( 'destination_available', 'Destination is available.', 'matched', 'destination', 'available', $channel_public_id, $channel_public_id ),
			'result'      => 'included',
		);
	}

	/**
	 * Build destination snapshot.
	 *
	 * @param ChannelRecord         $channel Channel.
	 * @param ConnectionRecord|null $connection Connection.
	 * @return array<string,mixed>
	 */
	private function destination_snapshot( ChannelRecord $channel, ?ConnectionRecord $connection ): array {
		return array(
			'channel_id'                        => $channel->id,
			'channel_label'                     => $this->channel_label( $channel ),
			'channel_public_id'                 => $channel->public_id,
			'channel_enabled'                   => $channel->enabled,
			'channel_availability'              => $channel->availability,
			'channel_capability_decision'       => $this->channel_capability_decision( $channel ),
			'capability_document'               => $channel->capabilities,
			'channel_capability_schema_version' => (int) ( $channel->capabilities['schema_version'] ?? 0 ),
			'connection_id'                     => null === $connection ? 0 : $connection->id,
			'connection_label'                  => null === $connection ? '' : $connection->label,
			'connection_public_id'              => $channel->connection_public_id,
			'connection_enabled'                => null !== $connection && $connection->enabled,
			'connection_status'                 => null === $connection ? '' : $connection->connection_status,
			'provider'                          => $channel->provider,
			'remote_organization_id'            => $channel->remote_organization_id,
			'remote_channel_id'                 => $channel->remote_channel_id,
			'service'                           => $channel->service,
		);
	}

	/**
	 * Return the text immediate delivery capability decision for a channel.
	 *
	 * @param ChannelRecord $channel Channel.
	 * @return array<string,mixed>
	 */
	private function channel_capability_decision( ChannelRecord $channel ): array {
		return DeliveryCapability::decision( $channel->capabilities );
	}

	/**
	 * Check whether a verified connection has the identity required for delivery.
	 *
	 * @param ConnectionRecord $connection Connection.
	 * @return bool
	 */
	private function connection_has_delivery_identity( ConnectionRecord $connection ): bool {
		return $connection->has_credential
			&& null !== $connection->credential_envelope_hash
			&& null !== $connection->remote_account_id
			&& null !== $connection->remote_organization_id
			&& null !== $connection->verified_identity_hash
			&& null !== $connection->last_sync_completed_at;
	}

	/**
	 * Return a channel display label.
	 *
	 * @param ChannelRecord $channel Channel.
	 * @return string
	 */
	private function channel_label( ChannelRecord $channel ): string {
		foreach ( array( 'label', 'name', 'title' ) as $key ) {
			if ( isset( $channel->display[ $key ] ) && is_string( $channel->display[ $key ] ) && '' !== trim( $channel->display[ $key ] ) ) {
				return $channel->display[ $key ];
			}
		}

		return $channel->public_id;
	}

	/**
	 * Build, store, and summarize one decision.
	 *
	 * @param NormalizedEvent                $event Event.
	 * @param PolicySettings                 $settings Settings.
	 * @param string                         $result Decision result.
	 * @param string                         $scope_type Scope type.
	 * @param string|null                    $scope_public_id Scope public ID.
	 * @param RuleRecord|null                $rule Rule.
	 * @param array<int,array<string,mixed>> $reasons Reasons.
	 * @param array<string,mixed>|null       $destination Destination snapshot.
	 * @param bool                           $durable Whether to write storage.
	 * @return array<string,mixed>
	 */
	private function decision_summary(
		NormalizedEvent $event,
		PolicySettings $settings,
		string $result,
		string $scope_type,
		?string $scope_public_id,
		?RuleRecord $rule,
		array $reasons,
		?array $destination,
		bool $durable
	): array {
		$snapshot = array(
			'destination' => $destination,
			'event'       => $event->to_array(),
			'reasons'     => $reasons,
			'rule'        => null === $rule ? null : $this->rule_snapshot( $rule ),
			'settings'    => $this->settings_snapshot( $settings ),
		);

		$input = array(
			'author_id'             => $event->author_id,
			'event_key'             => $event->event_key,
			'event_mode'            => $event->mode,
			'intent_occurrence_key' => $event->intent_occurrence_key,
			'post_id'               => $event->post_id,
			'post_status'           => $event->post_status,
			'post_type'             => $event->post_type,
			'reasons'               => $reasons,
			'result'                => $result,
			'revision_fingerprint'  => $event->revision_fingerprint,
			'rule_id'               => null === $rule ? null : $rule->id,
			'rule_public_id'        => null === $rule ? null : $rule->public_id,
			'rule_version'          => null === $rule ? null : $rule->version,
			'scope_public_id'       => $scope_public_id,
			'scope_type'            => $scope_type,
			'snapshot'              => $snapshot,
			'trigger'               => $event->trigger,
		);

		$decision_key  = HashKeys::decision_key( $event->event_fields(), $event->event_key, $scope_type, $scope_public_id, null === $rule ? null : $rule->public_id, null === $rule ? null : $rule->version, $result );
		$snapshot_hash = HashKeys::snapshot_hash( $snapshot );
		$record        = null;

		if ( $durable ) {
			$record        = $this->ensure_decision( $input, $decision_key, $snapshot_hash );
			$snapshot_hash = $record->snapshot_hash;
		}

		return array(
			'decision_key'         => $decision_key,
			'event_key'            => $event->event_key,
			'post_id'              => $event->post_id,
			'public_id'            => null === $record ? null : $record->public_id,
			'reasons'              => $reasons,
			'result'               => $result,
			'revision_fingerprint' => $event->revision_fingerprint,
			'rule_id'              => null === $rule ? null : $rule->id,
			'rule_public_id'       => null === $rule ? null : $rule->public_id,
			'rule_version'         => null === $rule ? null : $rule->version,
			'scope_public_id'      => $scope_public_id,
			'scope_type'           => $scope_type,
			'snapshot_hash'        => $snapshot_hash,
			'stored_record'        => $record,
			'trigger'              => $event->trigger,
		);
	}

	/**
	 * Build, store, and summarize a delivery preview.
	 *
	 * @param NormalizedEvent     $event Event.
	 * @param PolicySettings      $settings Settings.
	 * @param array<string,mixed> $decision Decision summary.
	 * @param array<string,mixed> $destination Destination snapshot.
	 * @param array<string,mixed> $payload_preview Payload preview.
	 * @param bool                $durable Whether to write storage.
	 * @return array<string,mixed>
	 */
	private function delivery_summary( NormalizedEvent $event, PolicySettings $settings, array $decision, array $destination, array $payload_preview, bool $durable ): array {
		$schedule_slot     = isset( $payload_preview['schedule_slot'] ) && is_string( $payload_preview['schedule_slot'] ) ? $payload_preview['schedule_slot'] : 'immediate';
		$scheduled_for_utc = isset( $payload_preview['scheduled_for_utc'] ) && is_string( $payload_preview['scheduled_for_utc'] ) ? $payload_preview['scheduled_for_utc'] : null;
		$media_hash        = isset( $payload_preview['media_identity_hash'] ) && is_string( $payload_preview['media_identity_hash'] ) ? $payload_preview['media_identity_hash'] : null;
		$key_occurrence    = $event->intent_occurrence_key;
		$delivery_key      = HashKeys::delivery_key(
			$settings->delivery_mode,
			(string) $destination['provider'],
			(string) $destination['connection_public_id'],
			(string) $destination['channel_public_id'],
			(string) $destination['remote_channel_id'],
			$event->post_id,
			$event->revision_fingerprint,
			$event->trigger,
			(string) $decision['rule_public_id'],
			(int) $decision['rule_version'],
			$schedule_slot,
			$scheduled_for_utc,
			$media_hash,
			$key_occurrence
		);

		$payload_hash_input = array(
			'canonical_url'          => $payload_preview['canonical_url'],
			'channel_public_id'      => $destination['channel_public_id'],
			'connection_public_id'   => $destination['connection_public_id'],
			'final_encoded_text'     => $payload_preview['final_encoded_text'] ?? null,
			'media_identity_hash'    => $media_hash,
			'message'                => $payload_preview['message'],
			'payload_schema_version' => $payload_preview['payload_schema_version'] ?? 1,
			'provider'               => $destination['provider'],
			'remote_channel_id'      => $destination['remote_channel_id'],
			'revision_fingerprint'   => $event->revision_fingerprint,
			'rule_public_id'         => $decision['rule_public_id'],
			'rule_version'           => $decision['rule_version'],
			'scheduled_for_utc'      => $scheduled_for_utc,
			'schedule_slot'          => $schedule_slot,
			'trigger'                => $event->trigger,
		);
		if ( null !== $key_occurrence ) {
			$payload_hash_input['intent_occurrence_key'] = $key_occurrence;
		}
		$intent_occurrence_key = CanonicalJson::hash(
			'intent_occurrence',
			array(
				'channel_public_id'    => $destination['channel_public_id'],
				'connection_public_id' => $destination['connection_public_id'],
				'media_identity_hash'  => $media_hash,
				'post_id'              => $event->post_id,
				'provider'             => $destination['provider'],
				'remote_channel_id'    => $destination['remote_channel_id'],
				'revision_fingerprint' => $event->revision_fingerprint,
				'rule_public_id'       => $decision['rule_public_id'],
				'rule_version'         => $decision['rule_version'],
				'scheduled_for_utc'    => $scheduled_for_utc,
				'schedule_slot'        => $schedule_slot,
				'trigger'              => $event->trigger,
			)
		);
		$payload_hash          = HashKeys::payload_hash( $payload_hash_input );

		$payload_preview['delivery_key']          = $delivery_key;
		$payload_preview['event_key']             = $event->event_key;
		$payload_preview['intent_occurrence_key'] = $key_occurrence ?? $intent_occurrence_key;
		$payload_preview['payload_hash']          = $payload_hash;
		$payload_preview['payload_hash_input']    = $payload_hash_input;
		$payload_preview['revision_fingerprint']  = $event->revision_fingerprint;

		$record = null;

		if ( $durable && $decision['stored_record'] instanceof DecisionRecord ) {
			$input = array(
				'channel_public_id'     => $destination['channel_public_id'],
				'connection_public_id'  => $destination['connection_public_id'],
				'decision_public_id'    => $decision['stored_record']->public_id,
				'delivery_mode'         => $settings->delivery_mode,
				'intent_occurrence_key' => $key_occurrence,
				'payload_preview'       => $payload_preview,
				'provider'              => $destination['provider'],
				'remote_channel_id'     => $destination['remote_channel_id'],
				'schedule_slot'         => $schedule_slot,
				'state'                 => $settings->delivery_state,
			);

			$record = $this->ensure_delivery( $input, $delivery_key, $payload_hash );
		}

		return array(
			'channel_public_id' => $destination['channel_public_id'],
			'delivery_key'      => $delivery_key,
			'delivery_mode'     => $settings->delivery_mode,
			'payload_hash'      => $payload_hash,
			'payload_preview'   => $payload_preview,
			'public_id'         => null === $record ? null : $record->public_id,
			'state'             => $settings->delivery_state,
			'stored_record'     => $record,
		);
	}

	/**
	 * Ensure one immutable decision row.
	 *
	 * @param array<string,mixed> $input Decision input.
	 * @param string              $decision_key Expected decision key.
	 * @param string              $snapshot_hash Expected snapshot hash.
	 * @return DecisionRecord
	 */
	private function ensure_decision( array $input, string $decision_key, string $snapshot_hash ): DecisionRecord {
		try {
			return $this->decisions->create( $input );
		} catch ( StorageException $exception ) {
			if ( false === stripos( $exception->getMessage(), 'duplicate' ) ) {
				throw $exception;
			}
		}

		$existing = $this->decisions->find_by_decision_key( $decision_key );

		if ( null === $existing ) {
			throw new StorageException( 'Duplicate decision could not be loaded.' );
		}

		if ( $existing->snapshot_hash !== $snapshot_hash && ! $this->snapshots_match_ignoring_settings( $existing->snapshot, $input['snapshot'] ) ) {
			throw new StorageException( 'Duplicate decision immutable snapshot does not match.' );
		}

		return $existing;
	}

	/**
	 * Compare snapshots while allowing delivery settings to change between dry-run and live planning.
	 *
	 * @param array<string,mixed> $existing Existing snapshot.
	 * @param mixed               $candidate Candidate snapshot.
	 * @return bool
	 */
	private function snapshots_match_ignoring_settings( array $existing, $candidate ): bool {
		if ( ! is_array( $candidate ) || array_is_list( $candidate ) ) {
			return false;
		}

		unset( $existing['settings'], $candidate['settings'] );

		return CanonicalJson::encode_object( $existing ) === CanonicalJson::encode_object( $candidate );
	}

	/**
	 * Ensure one immutable delivery row.
	 *
	 * @param array<string,mixed> $input Delivery input.
	 * @param string              $delivery_key Expected delivery key.
	 * @param string              $payload_hash Expected payload hash.
	 * @return DeliveryRecord
	 */
	private function ensure_delivery( array $input, string $delivery_key, string $payload_hash ): DeliveryRecord {
		try {
			return $this->deliveries->create( $input );
		} catch ( StorageException $exception ) {
			if ( false === stripos( $exception->getMessage(), 'duplicate' ) ) {
				throw $exception;
			}
		}

		$existing = $this->deliveries->find_by_delivery_key( $delivery_key );

		if ( null === $existing ) {
			throw new StorageException( 'Duplicate delivery could not be loaded.' );
		}

		if ( $existing->payload_hash !== $payload_hash ) {
			throw new StorageException( 'Duplicate delivery immutable payload does not match.' );
		}

		return $existing;
	}

	/**
	 * Build a rule snapshot.
	 *
	 * @param RuleRecord $rule Rule.
	 * @return array<string,mixed>
	 */
	private function rule_snapshot( RuleRecord $rule ): array {
		return array(
			'condition_tree'         => $rule->condition_tree,
			'custom_field_allowlist' => $rule->custom_field_allowlist,
			'destination_set'        => $rule->destination_set,
			'effect'                 => $rule->effect,
			'enabled'                => $rule->enabled,
			'id'                     => $rule->id,
			'inline_template'        => $rule->inline_template,
			'name'                   => $rule->name,
			'priority'               => $rule->priority,
			'public_id'              => $rule->public_id,
			'schedule_policy'        => $rule->schedule_policy,
			'triggers'               => $rule->triggers,
			'version'                => $rule->version,
		);
	}

	/**
	 * Build settings snapshot.
	 *
	 * @param PolicySettings $settings Settings.
	 * @return array<string,mixed>
	 */
	private function settings_snapshot( PolicySettings $settings ): array {
		return array(
			'delivery_mode'       => $settings->delivery_mode,
			'delivery_state'      => $settings->delivery_state,
			'dry_run_enabled'     => $settings->dry_run_enabled,
			'kill_switch_enabled' => $settings->kill_switch_enabled,
		);
	}

	/**
	 * Return compact evaluated rule summaries.
	 *
	 * @param array<int,array{rule:RuleRecord,condition_result:string,reasons:array<int,array<string,mixed>>}> $evaluated Evaluated rules.
	 * @return array<int,array<string,mixed>>
	 */
	private function rule_summaries( array $evaluated ): array {
		$summaries = array();

		foreach ( $evaluated as $entry ) {
			$summaries[] = array(
				'condition_result' => $entry['condition_result'],
				'effect'           => $entry['rule']->effect,
				'priority'         => $entry['rule']->priority,
				'public_id'        => $entry['rule']->public_id,
				'version'          => $entry['rule']->version,
			);
		}

		return $summaries;
	}
}
