<?php
/**
 * Batch preview planning.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Batch;

// phpcs:disable Squiz.Commenting.VariableComment.Missing -- Constructor wires service dependencies.

use PressQueue\Planning\EventNormalizer;
use PressQueue\Planning\NormalizedEvent;
use PressQueue\Planning\Planner;
use PressQueue\Planning\PlanningResult;
use PressQueue\Runtime\RuntimeDisabledLatch;
use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\Database;
use PressQueue\Storage\HashKeys;
use PressQueue\Storage\PublicIds;
use PressQueue\Storage\Record\BatchItemRecord;
use PressQueue\Storage\Record\BatchRecord;
use PressQueue\Storage\Repository\BatchItemRepository;
use PressQueue\Storage\Repository\BatchRepository;
use PressQueue\Storage\Repository\RuleRepository;
use PressQueue\Storage\Schema;
use PressQueue\Storage\StorageEnums;
use PressQueue\Storage\StorageValidator;
use PressQueue\Storage\ValidationException;

/**
 * Builds durable, non-queueable batch preview evidence.
 */
final class BatchPreviewService {
	private const FILTER_KEYS = array(
		'schema_version',
		'post_types',
		'author_ids',
		'taxonomies',
		'post_statuses',
		'published_from_utc',
		'published_to_utc',
		'modified_from_utc',
		'modified_to_utc',
		'post_ids',
		'metadata',
		'required_destination_channel_public_ids',
		'prior_delivery_states',
		'prior_accepted_from_utc',
		'prior_accepted_to_utc',
		'minimum_days_since_prior_acceptance',
	);

	private Database $database;
	private RuleRepository $rules;
	private EventNormalizer $normalizer;
	private Planner $planner;
	private BatchRepository $batches;
	private BatchItemRepository $items;
	private DuplicateHistoryService $duplicates;
	private BatchLimitService $limits;
	private RepostCriteriaService $repost_criteria;

	/**
	 * Constructor.
	 *
	 * @param Database|null $database Database wrapper.
	 */
	public function __construct( ?Database $database = null ) {
		$this->database        = $database ?? Database::from_globals();
		$this->rules           = new RuleRepository( $this->database );
		$this->normalizer      = new EventNormalizer();
		$this->planner         = new Planner( $this->database );
		$this->batches         = new BatchRepository( $this->database );
		$this->items           = new BatchItemRepository( $this->database );
		$this->duplicates      = new DuplicateHistoryService( $this->database );
		$this->limits          = new BatchLimitService();
		$this->repost_criteria = new RepostCriteriaService();
	}

	/**
	 * Create a durable batch preview.
	 *
	 * @param string              $batch_type Batch type.
	 * @param array<string,mixed> $filters Raw filters.
	 * @param array<string,mixed> $batch_limits Raw limits.
	 * @param int                 $created_by Creator user ID.
	 * @return BatchRecord
	 */
	public function preview( string $batch_type, array $filters, array $batch_limits, int $created_by ): BatchRecord {
		RuntimeDisabledLatch::assert_open_for( 'batch preview' );

		StorageEnums::assert_batch_type( $batch_type );
		$this->assert_json_size( $filters );
		$this->assert_json_size( $batch_limits );

		$normalized_limits  = $this->limits->normalize( $batch_limits );
		$normalized_filters = $this->normalize_filters( $batch_type, $filters );
		$this->validate_filters_before_storage( $normalized_filters );

		$batch        = $this->batches->create_preview( $batch_type, $created_by, $normalized_filters, $normalized_limits, $this->limits->preview_expires_at() );
		$evaluation   = $this->evaluate( $batch, $normalized_filters, $normalized_limits );
		$final_limits = $normalized_limits;

		if ( 'staggered_explicit_time' === ( $final_limits['schedule']['mode'] ?? null ) ) {
			$final_limits['schedule']['item_order_hash'] = $this->limits->item_order_hash( $evaluation['queueable_items'] );
			$evaluation['summary']['item_order_hash']    = $final_limits['schedule']['item_order_hash'];
		}

		$evaluation['items']         = $this->apply_new_item_identities( $batch, $evaluation['items'] );
		$evaluation['snapshot_hash'] = $this->snapshot_hash( $batch, $normalized_filters, $final_limits, $evaluation['summary'], $evaluation['items'] );

		foreach ( $evaluation['items'] as $item_input ) {
			$this->items->create( $batch, $item_input );
		}

		return $this->batches->finalize_preview( $batch->public_id, $batch->lock_version, $evaluation['summary'], $evaluation['snapshot_hash'], $final_limits );
	}

	/**
	 * Evaluate a batch preview without creating storage rows.
	 *
	 * @param string              $batch_type Batch type.
	 * @param array<string,mixed> $filters Raw filters.
	 * @param array<string,mixed> $batch_limits Raw limits.
	 * @param int                 $created_by Creator user ID.
	 * @return array{filters:array<string,mixed>,batch_limits:array<string,mixed>,items:array<int,array<string,mixed>>,queueable_items:array<int,array<string,mixed>>,summary:array<string,mixed>,snapshot_hash:null}
	 */
	public function assess( string $batch_type, array $filters, array $batch_limits, int $created_by ): array {
		StorageEnums::assert_batch_type( $batch_type );
		StorageValidator::assert_unsigned_id( 'Creator user ID', $created_by, true );
		$this->assert_json_size( $filters );
		$this->assert_json_size( $batch_limits );

		$normalized_limits  = $this->limits->normalize( $batch_limits );
		$normalized_filters = $this->normalize_filters( $batch_type, $filters );
		$this->validate_filters_before_storage( $normalized_filters );

		$now        = StorageValidator::parse_required_date_time( StorageValidator::now() );
		$evaluation = $this->evaluate(
			new BatchRecord(
				0,
				'01K3KGXB71P2MR4K9A7V6Q5C8D',
				$batch_type,
				'previewed',
				$created_by,
				null,
				$normalized_filters,
				array( 'schema_version' => 1 ),
				str_repeat( '0', 64 ),
				$normalized_limits,
				StorageValidator::parse_required_date_time( $this->limits->preview_expires_at() ),
				null,
				null,
				null,
				1,
				$now,
				$now
			),
			$normalized_filters,
			$normalized_limits
		);

		if ( 'staggered_explicit_time' === ( $normalized_limits['schedule']['mode'] ?? null ) ) {
			$normalized_limits['schedule']['item_order_hash'] = $this->limits->item_order_hash( $evaluation['queueable_items'] );
			$evaluation['summary']['item_order_hash']         = $normalized_limits['schedule']['item_order_hash'];
		}

		return array(
			'batch_limits'    => $normalized_limits,
			'filters'         => $normalized_filters,
			'items'           => $evaluation['items'],
			'queueable_items' => $evaluation['queueable_items'],
			'snapshot_hash'   => null,
			'summary'         => $evaluation['summary'],
		);
	}

	/**
	 * Evaluate a batch without creating decision or delivery rows.
	 *
	 * @param BatchRecord                     $batch Batch.
	 * @param array<string,mixed>|null        $filters Optional filters.
	 * @param array<string,mixed>|null        $batch_limits Optional limits.
	 * @param array<int,BatchItemRecord>|null $stored_items Optional stored item identities.
	 * @return array{items:array<int,array<string,mixed>>,queueable_items:array<int,array<string,mixed>>,summary:array<string,mixed>,snapshot_hash:string}
	 */
	public function evaluate( BatchRecord $batch, ?array $filters = null, ?array $batch_limits = null, ?array $stored_items = null ): array {
		$filters      = $filters ?? $batch->filters;
		$batch_limits = $batch_limits ?? $batch->batch_limits;
		$trigger      = $batch->batch_type;
		$rules        = $this->rules->find_enabled_for_trigger( $trigger );
		$allowlist    = $this->normalizer->metadata_allowlist_for_rules( $rules );
		$candidates   = $this->candidate_post_ids( $filters, (int) $batch_limits['candidate_limit'] );
		$items        = array();
		$position     = 0;
		$counts       = array(
			'duplicate'    => 0,
			'excluded'     => 0,
			'policy_error' => 0,
			'queueable'    => 0,
			'truncated'    => 0,
			'unauthorized' => 0,
		);
		$repost       = 'repost' === $batch->batch_type ? $this->repost_criteria->normalize( $filters ) : null;
		$repost_hash  = null === $repost ? null : $this->repost_criteria->hash( $repost );

		foreach ( $candidates as $post_id ) {
			if ( ! $this->can_preview_post( $post_id ) ) {
				++$counts['unauthorized'];
				continue;
			}

			$event = $this->normalizer->from_post_id( $post_id, 'preview', 'batch_preview', $trigger, $allowlist );
			if ( null === $event ) {
				$items[] = $this->global_item( $position++, $post_id, 'policy_error', 'post_not_previewable', 'Post cannot be previewed for this batch trigger.' );
				++$counts['policy_error'];
				continue;
			}

			$result     = $this->planner->preview( $event, $rules );
			$post_items = $this->items_for_result( $batch, $result, $position, $repost, $repost_hash );

			foreach ( $post_items as $item ) {
				$items[] = $item;
				++$position;
			}
		}

		$items = $this->apply_batch_schedule( $items, $batch_limits );

		foreach ( $items as $item ) {
			$state = (string) $item['state'];
			if ( 'previewed' === $state ) {
				++$counts['queueable'];
			} elseif ( isset( $counts[ $state ] ) ) {
				++$counts[ $state ];
			}
		}

		if ( $counts['queueable'] > (int) $batch_limits['destination_item_limit'] ) {
			$counts['truncated'] = $counts['queueable'] - (int) $batch_limits['destination_item_limit'];
		}

		$invalid_multi_item_schedule = $this->has_invalid_multi_item_schedule( $items );
		$queueable_items             = array_values(
			array_filter(
				$items,
				static function ( array $item ): bool {
					return 'previewed' === (string) $item['state'];
				}
			)
		);
		$summary                     = array(
			'batch_type'                  => $batch->batch_type,
			'candidate_posts'             => count( $candidates ),
			'counts'                      => $counts,
			'invalid_multi_item_schedule' => $invalid_multi_item_schedule,
			'schema_version'              => 1,
			'total_items'                 => count( $items ),
			'truncated'                   => $counts['truncated'] > 0,
		);

		if ( 'staggered_explicit_time' === ( $batch_limits['schedule']['mode'] ?? null ) && isset( $batch_limits['schedule']['item_order_hash'] ) ) {
			$summary['item_order_hash'] = $batch_limits['schedule']['item_order_hash'];
		}

		if ( null !== $stored_items ) {
			$items = $this->apply_stored_item_identities( $batch, $items, $stored_items );
		}

		$snapshot_hash = $this->snapshot_hash( $batch, $filters, $batch_limits, $summary, $items );

		return array(
			'items'           => $items,
			'queueable_items' => $queueable_items,
			'snapshot_hash'   => $snapshot_hash,
			'summary'         => $summary,
		);
	}

	/**
	 * Build destination items from a planning result.
	 *
	 * @param BatchRecord              $batch Batch.
	 * @param PlanningResult           $result Planning result.
	 * @param int                      $start_position Starting position.
	 * @param array<string,mixed>|null $repost Repost criteria.
	 * @param string|null              $repost_hash Repost criteria hash.
	 * @return array<int,array<string,mixed>>
	 */
	private function items_for_result( BatchRecord $batch, PlanningResult $result, int $start_position, ?array $repost, ?string $repost_hash ): array {
		$items      = array();
		$decisions  = $this->included_decisions_by_channel( $result->decisions );
		$deliveries = $result->deliveries;

		usort(
			$deliveries,
			static function ( array $left, array $right ): int {
				return strcmp( (string) ( $left['channel_public_id'] ?? '' ), (string) ( $right['channel_public_id'] ?? '' ) );
			}
		);

		if ( null !== $repost && isset( $repost['required_destination_channel_public_ids'] ) ) {
			$required   = array_fill_keys( $repost['required_destination_channel_public_ids'], true );
			$deliveries = array_values(
				array_filter(
					$deliveries,
					static function ( array $delivery ) use ( $required ): bool {
						return isset( $required[ (string) ( $delivery['channel_public_id'] ?? '' ) ] );
					}
				)
			);
		}

		if ( array() === $deliveries ) {
			return array( $this->global_item_from_result( $result, $start_position ) );
		}

		foreach ( $deliveries as $offset => $delivery ) {
			$channel_public_id = (string) $delivery['channel_public_id'];
			$decision          = $decisions[ $channel_public_id ] ?? null;
			if ( null === $decision ) {
				$items[] = $this->global_item( $start_position + $offset, $result->event->post_id, 'policy_error', 'missing_included_decision', 'Delivery preview has no included decision.' );
				continue;
			}

			$state             = 'previewed';
			$duplicate_history = $this->duplicates->delivery_history( $result->event->post_id, $channel_public_id );
			$blocking          = null;
			$prior_accepted    = null;
			$reason_code       = 'queueable_destination';
			$reason_message    = 'Destination item is queueable after preview.';

			if ( 'bulk' === $batch->batch_type ) {
				$blocking = $this->duplicates->blocking_bulk_duplicate( $result->event->post_id, $channel_public_id );
				if ( null !== $blocking ) {
					$state          = 'duplicate';
					$reason_code    = 'duplicate_delivery';
					$reason_message = 'Existing local delivery blocks this bulk destination item.';
				}
			} else {
				$blocking       = $this->duplicates->blocking_repost_duplicate( $result->event->post_id, $channel_public_id, $batch->public_id );
				$prior_accepted = null === $repost ? null : $this->duplicates->prior_accepted_delivery( $result->event->post_id, $channel_public_id, $repost );
				if ( null !== $blocking ) {
					$state          = 'duplicate';
					$reason_code    = 'duplicate_repost';
					$reason_message = 'Unresolved local delivery or repost work blocks this repost item.';
				} elseif ( null === $prior_accepted ) {
					$state          = 'policy_error';
					$reason_code    = 'repost_without_prior_acceptance';
					$reason_message = 'Repost requires a prior accepted delivery for the same post and destination.';
				}
			}

			$preview_item = array(
				'batch_type'        => $batch->batch_type,
				'decision'          => $decision,
				'delivery'          => $delivery,
				'duplicate_history' => $duplicate_history,
				'event'             => $result->event->to_array(),
				'post'              => $this->post_snapshot( $result->event ),
				'prior_accepted'    => $prior_accepted,
				'schema_version'    => 1,
			);
			$reason       = $this->reason_document( $reason_code, $reason_message, $state, $decision['reasons'] ?? array(), $blocking );
			$semantic     = $this->semantic_hash( $state, $result->event, $decision, $delivery, $reason, $blocking );

			$items[] = array(
				'channel_public_id'               => $channel_public_id,
				'duplicate_of_delivery_public_id' => isset( $blocking['delivery_public_id'] ) ? $blocking['delivery_public_id'] : null,
				'inclusion_rule_public_id'        => $decision['rule_public_id'],
				'inclusion_rule_version'          => $decision['rule_version'],
				'occurrence_semantic_hash'        => $repost_hash,
				'position'                        => $start_position + $offset,
				'post_id'                         => $result->event->post_id,
				'preview_item'                    => $preview_item,
				'reason'                          => $reason,
				'scheduled_for_utc'               => $delivery['payload_preview']['scheduled_for_utc'] ?? null,
				'semantic_preview_hash'           => $semantic,
				'state'                           => $state,
			);
		}

		return $items;
	}

	/**
	 * Apply batch-level schedule overrides.
	 *
	 * @param array<int,array<string,mixed>> $items Items.
	 * @param array<string,mixed>            $limits Limits.
	 * @return array<int,array<string,mixed>>
	 */
	private function apply_batch_schedule( array $items, array $limits ): array {
		$queueable_count = count(
			array_filter(
				$items,
				static function ( array $item ): bool {
					return 'previewed' === (string) $item['state'];
				}
			)
		);
		$queueable_index = 0;

		foreach ( $items as $index => $item ) {
			if ( 'previewed' !== (string) $item['state'] ) {
				continue;
			}

			$override = $this->limits->schedule_override_for_item( $limits, $queueable_index++, $queueable_count );
			if ( null === $override ) {
				continue;
			}

			if ( isset( $override['error'] ) ) {
				$items[ $index ]['state']                 = 'policy_error';
				$items[ $index ]['reason']                = $this->reason_document( (string) $override['error'], 'Multi-item batches cannot use immediate or queue-next scheduling.', 'policy_error', array(), null );
				$items[ $index ]['semantic_preview_hash'] = $this->semantic_hash_for_item( $items[ $index ] );
				continue;
			}

			$payload =& $items[ $index ]['preview_item']['delivery']['payload_preview'];
			if ( ! is_array( $payload ) ) {
				unset( $payload );
				continue;
			}

			$payload['schedule_slot']                                       = $override['schedule_slot'];
			$payload['scheduled_for_utc']                                   = $override['scheduled_for_utc'];
			$payload['schedule_timezone']                                   = $override['schedule_timezone'];
			$items[ $index ]['preview_item']['delivery']['payload_preview'] = $payload;
			unset( $payload );

			$payload_hash = $this->payload_hash_from_preview( $items[ $index ]['preview_item']['delivery'] );
			$delivery_key = $this->delivery_key_from_preview( $items[ $index ]['preview_item']['delivery'] );
			$items[ $index ]['preview_item']['delivery']['payload_hash']                    = $payload_hash;
			$items[ $index ]['preview_item']['delivery']['delivery_key']                    = $delivery_key;
			$items[ $index ]['preview_item']['delivery']['payload_preview']['payload_hash'] = $payload_hash;
			$items[ $index ]['preview_item']['delivery']['payload_preview']['delivery_key'] = $delivery_key;
			$items[ $index ]['scheduled_for_utc']     = $override['scheduled_for_utc'];
			$items[ $index ]['semantic_preview_hash'] = $this->semantic_hash_for_item( $items[ $index ] );
		}

		return $items;
	}

	/**
	 * Return whether queueable multi-item schedules are invalid.
	 *
	 * @param array<int,array<string,mixed>> $items Items.
	 * @return bool
	 */
	private function has_invalid_multi_item_schedule( array $items ): bool {
		$queueable = array_values(
			array_filter(
				$items,
				static function ( array $item ): bool {
					return 'previewed' === (string) $item['state'];
				}
			)
		);

		if ( count( $queueable ) < 2 ) {
			return false;
		}

		foreach ( $queueable as $item ) {
			$slot = $item['preview_item']['delivery']['payload_preview']['schedule_slot'] ?? null;
			if ( in_array( $slot, array( 'immediate', 'queue_next' ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Add final public IDs and occurrence-aware reviewed identities.
	 *
	 * @param BatchRecord                    $batch Batch.
	 * @param array<int,array<string,mixed>> $items Items.
	 * @return array<int,array<string,mixed>>
	 */
	private function apply_new_item_identities( BatchRecord $batch, array $items ): array {
		foreach ( $items as $index => $item ) {
			$items[ $index ] = $this->apply_item_identity( $batch, $item, $this->items->new_public_id() );
		}

		return $items;
	}

	/**
	 * Rebuild final identities from existing item public IDs.
	 *
	 * @param BatchRecord                    $batch Batch.
	 * @param array<int,array<string,mixed>> $items Fresh item inputs.
	 * @param array<int,BatchItemRecord>     $stored_items Stored items.
	 * @return array<int,array<string,mixed>>
	 */
	private function apply_stored_item_identities( BatchRecord $batch, array $items, array $stored_items ): array {
		if ( count( $items ) !== count( $stored_items ) ) {
			throw new ValidationException( 'Batch item set changed. Create a fresh preview before confirming.' );
		}

		foreach ( $items as $index => $item ) {
			$stored = $stored_items[ $index ];
			if (
				$stored->position !== (int) $item['position']
				|| $stored->post_id !== (int) $item['post_id']
				|| ( $item['channel_public_id'] ?? null ) !== $stored->channel_public_id
			) {
				throw new ValidationException( 'Batch item order changed. Create a fresh preview before confirming.' );
			}

			$items[ $index ] = $this->apply_item_identity( $batch, $item, $stored->public_id );
		}

		return $items;
	}

	/**
	 * Apply one item's public ID, occurrence key, and reviewed delivery hashes.
	 *
	 * @param BatchRecord         $batch Batch.
	 * @param array<string,mixed> $item Item.
	 * @param string              $public_id Batch item public ID.
	 * @return array<string,mixed>
	 */
	private function apply_item_identity( BatchRecord $batch, array $item, string $public_id ): array {
		PublicIds::assert( $public_id );

		$post_id               = (int) $item['post_id'];
		$channel_public_id     = isset( $item['channel_public_id'] ) && is_string( $item['channel_public_id'] ) ? $item['channel_public_id'] : null;
		$semantic_preview_hash = (string) $item['semantic_preview_hash'];
		$occurrence_hash_input = isset( $item['occurrence_semantic_hash'] ) && is_string( $item['occurrence_semantic_hash'] ) ? $item['occurrence_semantic_hash'] : $semantic_preview_hash;
		$item_key              = HashKeys::batch_item_key( $batch->public_id, $post_id, $channel_public_id, $semantic_preview_hash );
		$occurrence_key        = HashKeys::intent_occurrence_key( $batch->batch_type, $batch->public_id, $public_id, $post_id, $channel_public_id, $occurrence_hash_input );

		$item['public_id']             = $public_id;
		$item['item_key']              = $item_key;
		$item['intent_occurrence_key'] = $occurrence_key;

		if ( ! isset( $item['preview_item'] ) || ! is_array( $item['preview_item'] ) ) {
			return $item;
		}

		$item['preview_item']['batch_item_public_id']  = $public_id;
		$item['preview_item']['item_key']              = $item_key;
		$item['preview_item']['intent_occurrence_key'] = $occurrence_key;

		$event_fields = null;
		if ( isset( $item['preview_item']['event'] ) && is_array( $item['preview_item']['event'] ) ) {
			$item['preview_item']['event']['intent_occurrence_key'] = $occurrence_key;
			$event_fields                               = $this->event_fields_from_preview( $item['preview_item']['event'] );
			$item['preview_item']['event']['event_key'] = HashKeys::event_key( $event_fields );
		}

		if ( isset( $item['preview_item']['decision'] ) && is_array( $item['preview_item']['decision'] ) && null !== $event_fields ) {
			$decision                          =& $item['preview_item']['decision'];
			$decision['event_key']             = $item['preview_item']['event']['event_key'];
			$decision['intent_occurrence_key'] = $occurrence_key;
			$decision['decision_key']          = HashKeys::decision_key(
				$event_fields,
				(string) $decision['event_key'],
				(string) ( $decision['scope_type'] ?? 'channel' ),
				isset( $decision['scope_public_id'] ) && is_string( $decision['scope_public_id'] ) ? $decision['scope_public_id'] : null,
				isset( $decision['rule_public_id'] ) && is_string( $decision['rule_public_id'] ) ? $decision['rule_public_id'] : null,
				isset( $decision['rule_version'] ) ? (int) $decision['rule_version'] : null,
				(string) ( $decision['result'] ?? 'included' )
			);
			unset( $decision );
		}

		if ( isset( $item['preview_item']['delivery'] ) && is_array( $item['preview_item']['delivery'] ) ) {
			$delivery                                    =& $item['preview_item']['delivery'];
			$delivery['intent_occurrence_key']           = $occurrence_key;
			$payload                                     = isset( $delivery['payload_preview'] ) && is_array( $delivery['payload_preview'] ) ? $delivery['payload_preview'] : array();
			$payload['intent_occurrence_key']            = $occurrence_key;
			$payload['event_key']                        = $item['preview_item']['event']['event_key'] ?? ( $payload['event_key'] ?? null );
			$delivery['payload_preview']                 = $payload;
			$delivery['payload_hash']                    = $this->payload_hash_from_preview( $delivery );
			$delivery['delivery_key']                    = $this->delivery_key_from_preview( $delivery );
			$delivery['payload_preview']['payload_hash'] = $delivery['payload_hash'];
			$delivery['payload_preview']['delivery_key'] = $delivery['delivery_key'];
			unset( $delivery );
		}

		return $item;
	}

	/**
	 * Return event key fields from a preview event array.
	 *
	 * @param array<string,mixed> $event Event facts.
	 * @return array<string,mixed>
	 */
	private function event_fields_from_preview( array $event ): array {
		$fields = array(
			'author_id'            => (int) ( $event['user_id'] ?? $event['author_id'] ?? 0 ),
			'event_mode'           => (string) ( $event['mode'] ?? $event['event_mode'] ?? 'preview' ),
			'post_id'              => (int) $event['post_id'],
			'post_status'          => (string) $event['post_status'],
			'post_type'            => (string) $event['post_type'],
			'revision_fingerprint' => (string) $event['revision_fingerprint'],
			'trigger'              => (string) $event['trigger'],
		);

		if ( isset( $event['intent_occurrence_key'] ) && is_string( $event['intent_occurrence_key'] ) ) {
			$fields['intent_occurrence_key'] = $event['intent_occurrence_key'];
		}

		return $fields;
	}

	/**
	 * Normalize and validate filters.
	 *
	 * @param string              $batch_type Batch type.
	 * @param array<string,mixed> $filters Raw filters.
	 * @return array<string,mixed>
	 */
	private function normalize_filters( string $batch_type, array $filters ): array {
		$this->assert_known_keys( $filters, self::FILTER_KEYS );

		if ( 'bulk' === $batch_type ) {
			foreach ( array( 'required_destination_channel_public_ids', 'prior_delivery_states', 'prior_accepted_from_utc', 'prior_accepted_to_utc', 'minimum_days_since_prior_acceptance' ) as $key ) {
				if ( isset( $filters[ $key ] ) ) {
					throw new ValidationException( 'Bulk filters cannot include repost criteria.' );
				}
			}
		}

		$schema_version = (int) ( $filters['schema_version'] ?? 1 );
		if ( 1 !== $schema_version ) {
			throw new ValidationException( 'Batch filters schema version is unsupported.' );
		}

		$normalized = array( 'schema_version' => 1 );

		if ( isset( $filters['post_ids'] ) ) {
			$normalized['post_ids'] = $this->positive_int_list( $filters['post_ids'], 'Post IDs' );
		}

		$post_types = $filters['post_types'] ?? array( 'post' );
		if ( ! is_array( $post_types ) || ! array_is_list( $post_types ) ) {
			throw new ValidationException( 'Post types must be a list.' );
		}
		$normalized['post_types'] = array_values( array_unique( array_map( array( $this, 'wordpress_slug' ), $post_types ) ) );
		sort( $normalized['post_types'], SORT_STRING );

		$post_statuses = $filters['post_statuses'] ?? array( 'publish' );
		if ( ! is_array( $post_statuses ) || ! array_is_list( $post_statuses ) ) {
			throw new ValidationException( 'Post statuses must be a list.' );
		}
		$statuses = array();
		foreach ( $post_statuses as $status ) {
			if ( ! is_string( $status ) || ! in_array( $status, array( 'publish', 'future' ), true ) ) {
				throw new ValidationException( 'Batch post status is unsupported.' );
			}
			$statuses[] = $status;
		}
		$normalized['post_statuses'] = array_values( array_unique( $statuses ) );
		sort( $normalized['post_statuses'], SORT_STRING );

		if ( isset( $filters['author_ids'] ) ) {
			$normalized['author_ids'] = $this->positive_int_list( $filters['author_ids'], 'Author IDs' );
			sort( $normalized['author_ids'], SORT_NUMERIC );
		}

		foreach ( array( 'published_from_utc', 'published_to_utc', 'modified_from_utc', 'modified_to_utc' ) as $date_key ) {
			if ( isset( $filters[ $date_key ] ) && '' !== $filters[ $date_key ] ) {
				if ( ! is_string( $filters[ $date_key ] ) ) {
					throw new ValidationException( 'Date filters must be UTC timestamps.' );
				}
				StorageValidator::assert_utc_timestamp( 'Batch date filter', $filters[ $date_key ] );
				$normalized[ $date_key ] = $filters[ $date_key ];
			}
		}

		if ( isset( $filters['taxonomies'] ) ) {
			$normalized['taxonomies'] = $this->taxonomy_filters( $filters['taxonomies'] );
		}

		if ( isset( $filters['metadata'] ) ) {
			$normalized['metadata'] = $this->metadata_filters( $batch_type, $filters['metadata'] );
		}

		if ( 'repost' === $batch_type ) {
			$normalized = array_merge( $normalized, $this->repost_criteria->normalize( $filters ) );
		}

		return $normalized;
	}

	/**
	 * Reject filter inputs that require current WordPress facts before storage.
	 *
	 * @param array<string,mixed> $filters Normalized filters.
	 */
	private function validate_filters_before_storage( array $filters ): void {
		if ( isset( $filters['post_ids'] ) ) {
			foreach ( $filters['post_ids'] as $post_id ) {
				$post = function_exists( 'get_post' ) ? get_post( (int) $post_id ) : null;
				if ( ! $post instanceof \WP_Post ) {
					throw new ValidationException( 'Exact post ID does not exist.' );
				}

				if ( ! in_array( (string) $post->post_type, $filters['post_types'], true ) || ! in_array( (string) $post->post_status, $filters['post_statuses'], true ) ) {
					throw new ValidationException( 'Exact post ID does not match the selected post filters.' );
				}

				if ( ! $this->can_preview_post( (int) $post_id ) ) {
					throw new ValidationException( 'Exact post ID is not authorized for preview.' );
				}
			}
		}
	}

	/**
	 * Return candidate post IDs.
	 *
	 * @param array<string,mixed> $filters Filters.
	 * @param int                 $candidate_limit Limit.
	 * @return array<int,int>
	 */
	private function candidate_post_ids( array $filters, int $candidate_limit ): array {
		if ( isset( $filters['post_ids'] ) ) {
			return array_slice(
				array_values(
					array_filter(
						$filters['post_ids'],
						static function ( int $post_id ) use ( $filters ): bool {
							$post = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
							return $post instanceof \WP_Post
								&& in_array( (string) $post->post_type, $filters['post_types'], true )
								&& in_array( (string) $post->post_status, $filters['post_statuses'], true );
						}
					)
				),
				0,
				$candidate_limit
			);
		}

		if ( ! class_exists( 'WP_Query' ) ) {
			throw new ValidationException( 'WordPress query support is not available.' );
		}

		$args = array(
			'fields'                 => 'ids',
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'orderby'                => array(
				'modified' => 'DESC',
				'ID'       => 'DESC',
			),
			'post_status'            => $filters['post_statuses'],
			'post_type'              => $filters['post_types'],
			'posts_per_page'         => $candidate_limit,
			'suppress_filters'       => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		if ( isset( $filters['author_ids'] ) ) {
			$args['author__in'] = $filters['author_ids'];
		}

		$date_query = $this->date_query( $filters );
		if ( array() !== $date_query ) {
			$args['date_query'] = $date_query;
		}

		if ( isset( $filters['taxonomies'] ) ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Batch filters are bounded and operator-requested.
			$args['tax_query'] = $this->tax_query( $filters['taxonomies'] );
		}

		if ( isset( $filters['metadata'] ) ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Batch filters are bounded and allowlist-authorized.
			$args['meta_query'] = $this->meta_query( $filters['metadata'] );
		}

		$query = new \WP_Query( $args );

		return array_map( 'intval', is_array( $query->posts ) ? $query->posts : array() );
	}

	/**
	 * Build a global item from a planning result without delivery rows.
	 *
	 * @param PlanningResult $result Result.
	 * @param int            $position Position.
	 * @return array<string,mixed>
	 */
	private function global_item_from_result( PlanningResult $result, int $position ): array {
		$decision = $result->decisions[0] ?? array();
		$state    = 'excluded' === ( $decision['result'] ?? null ) ? 'excluded' : 'policy_error';
		$code     = 'excluded' === $state ? 'global_exclusion' : 'no_queueable_destination';
		$message  = 'excluded' === $state ? 'Global exclusion blocks delivery.' : 'No destination item can be queued.';
		$reason   = $this->reason_document( $code, $message, $state, isset( $decision['reasons'] ) && is_array( $decision['reasons'] ) ? $decision['reasons'] : array(), null );
		$preview  = array(
			'batch_type'     => $result->event->trigger,
			'decision'       => $decision,
			'delivery'       => null,
			'event'          => $result->event->to_array(),
			'post'           => $this->post_snapshot( $result->event ),
			'schema_version' => 1,
		);

		return array(
			'channel_public_id'     => null,
			'position'              => $position,
			'post_id'               => $result->event->post_id,
			'preview_item'          => $preview,
			'reason'                => $reason,
			'semantic_preview_hash' => CanonicalJson::hash(
				'batch_semantic_preview',
				array(
					'decision' => $decision,
					'event'    => $result->event->event_fields(),
					'reason'   => $reason,
					'state'    => $state,
				)
			),
			'state'                 => $state,
		);
	}

	/**
	 * Build a simple global item.
	 *
	 * @param int    $position Position.
	 * @param int    $post_id Post ID.
	 * @param string $state State.
	 * @param string $code Reason code.
	 * @param string $message Reason message.
	 * @return array<string,mixed>
	 */
	private function global_item( int $position, int $post_id, string $state, string $code, string $message ): array {
		$reason = $this->reason_document( $code, $message, $state, array(), null );

		return array(
			'channel_public_id'     => null,
			'position'              => $position,
			'post_id'               => $post_id,
			'preview_item'          => array(
				'batch_type'     => null,
				'post'           => array( 'post_id' => $post_id ),
				'schema_version' => 1,
			),
			'reason'                => $reason,
			'semantic_preview_hash' => CanonicalJson::hash(
				'batch_semantic_preview',
				array(
					'post_id' => $post_id,
					'reason'  => $reason,
					'state'   => $state,
				)
			),
			'state'                 => $state,
		);
	}

	/**
	 * Return included decisions keyed by channel.
	 *
	 * @param array<int,array<string,mixed>> $decisions Decisions.
	 * @return array<string,array<string,mixed>>
	 */
	private function included_decisions_by_channel( array $decisions ): array {
		$indexed = array();
		foreach ( $decisions as $decision ) {
			if ( 'included' === ( $decision['result'] ?? null ) && 'channel' === ( $decision['scope_type'] ?? null ) && isset( $decision['scope_public_id'] ) ) {
				$indexed[ (string) $decision['scope_public_id'] ] = $decision;
			}
		}

		return $indexed;
	}

	/**
	 * Build a post snapshot from immutable event facts.
	 *
	 * @param NormalizedEvent $event Event.
	 * @return array<string,mixed>
	 */
	private function post_snapshot( NormalizedEvent $event ): array {
		return array(
			'canonical_url'    => $event->canonical_url,
			'excerpt'          => (string) ( $event->template_values['excerpt'] ?? '' ),
			'modified_at_gmt'  => $event->modified_at_gmt,
			'post_id'          => $event->post_id,
			'post_status'      => $event->post_status,
			'post_type'        => $event->post_type,
			'published_at_gmt' => $event->published_at_gmt,
			'title'            => (string) ( $event->template_values['title'] ?? '' ),
		);
	}

	/**
	 * Build a reason document.
	 *
	 * @param string                   $code Code.
	 * @param string                   $message Message.
	 * @param string                   $state Item state.
	 * @param array<int,mixed>         $planner_reasons Planner reasons.
	 * @param array<string,mixed>|null $blocking Blocking history.
	 * @return array<string,mixed>
	 */
	private function reason_document( string $code, string $message, string $state, array $planner_reasons, ?array $blocking ): array {
		return array(
			'blocking_history' => $blocking,
			'code'             => $code,
			'message'          => $message,
			'planner_reasons'  => $planner_reasons,
			'schema_version'   => 1,
			'state'            => $state,
		);
	}

	/**
	 * Return a semantic item hash.
	 *
	 * @param string                   $state Item state.
	 * @param NormalizedEvent          $event Event.
	 * @param array<string,mixed>      $decision Decision.
	 * @param array<string,mixed>      $delivery Delivery.
	 * @param array<string,mixed>      $reason Reason.
	 * @param array<string,mixed>|null $blocking Blocking history.
	 * @return string
	 */
	private function semantic_hash( string $state, NormalizedEvent $event, array $decision, array $delivery, array $reason, ?array $blocking ): string {
		return CanonicalJson::hash(
			'batch_semantic_preview',
			array(
				'blocking'          => $blocking,
				'channel_public_id' => $delivery['channel_public_id'] ?? null,
				'decision_key'      => $decision['decision_key'] ?? null,
				'delivery_key'      => $delivery['delivery_key'] ?? null,
				'event_fields'      => $event->event_fields(),
				'payload_hash'      => $delivery['payload_hash'] ?? null,
				'post_id'           => $event->post_id,
				'reason'            => $reason,
				'schedule_slot'     => $delivery['payload_preview']['schedule_slot'] ?? null,
				'scheduled_for_utc' => $delivery['payload_preview']['scheduled_for_utc'] ?? null,
				'state'             => $state,
			)
		);
	}

	/**
	 * Recompute semantic hash from a mutable item array.
	 *
	 * @param array<string,mixed> $item Item.
	 * @return string
	 */
	private function semantic_hash_for_item( array $item ): string {
		return CanonicalJson::hash(
			'batch_semantic_preview',
			array(
				'channel_public_id' => $item['channel_public_id'] ?? null,
				'decision_key'      => $item['preview_item']['decision']['decision_key'] ?? null,
				'delivery_key'      => $item['preview_item']['delivery']['delivery_key'] ?? null,
				'payload_hash'      => $item['preview_item']['delivery']['payload_hash'] ?? null,
				'post_id'           => $item['post_id'],
				'reason'            => $item['reason'],
				'schedule_slot'     => $item['preview_item']['delivery']['payload_preview']['schedule_slot'] ?? null,
				'scheduled_for_utc' => $item['preview_item']['delivery']['payload_preview']['scheduled_for_utc'] ?? null,
				'state'             => $item['state'],
			)
		);
	}

	/**
	 * Recompute payload hash after a schedule override.
	 *
	 * @param array<string,mixed> $delivery Delivery summary.
	 * @return string
	 */
	private function payload_hash_from_preview( array $delivery ): string {
		$payload = $delivery['payload_preview'];
		$input   = array(
			'canonical_url'          => $payload['canonical_url'],
			'channel_public_id'      => $payload['channel_public_id'],
			'connection_public_id'   => $payload['connection_public_id'],
			'final_encoded_text'     => $payload['final_encoded_text'] ?? null,
			'media_identity_hash'    => $payload['media_identity_hash'] ?? null,
			'message'                => $payload['message'],
			'payload_schema_version' => $payload['payload_schema_version'] ?? 1,
			'provider'               => $payload['provider'],
			'remote_channel_id'      => $payload['remote_channel_id'],
			'revision_fingerprint'   => $payload['revision_fingerprint'],
			'rule_public_id'         => $payload['rule_public_id'],
			'rule_version'           => $payload['rule_version'],
			'scheduled_for_utc'      => $payload['scheduled_for_utc'] ?? null,
			'schedule_slot'          => $payload['schedule_slot'],
			'trigger'                => $payload['trigger'],
		);

		if ( isset( $payload['intent_occurrence_key'] ) && is_string( $payload['intent_occurrence_key'] ) ) {
			$input['intent_occurrence_key'] = $payload['intent_occurrence_key'];
		}

		return HashKeys::payload_hash( $input );
	}

	/**
	 * Recompute delivery key after a schedule override.
	 *
	 * @param array<string,mixed> $delivery Delivery summary.
	 * @return string
	 */
	private function delivery_key_from_preview( array $delivery ): string {
		$payload = $delivery['payload_preview'];

		return HashKeys::delivery_key(
			(string) $delivery['delivery_mode'],
			(string) $payload['provider'],
			(string) $payload['connection_public_id'],
			(string) $payload['channel_public_id'],
			(string) $payload['remote_channel_id'],
			(int) $payload['post_id'],
			(string) $payload['revision_fingerprint'],
			(string) $payload['trigger'],
			(string) $payload['rule_public_id'],
			(int) $payload['rule_version'],
			(string) $payload['schedule_slot'],
			$payload['scheduled_for_utc'] ?? null,
			$payload['media_identity_hash'] ?? null,
			isset( $payload['intent_occurrence_key'] ) && is_string( $payload['intent_occurrence_key'] ) ? $payload['intent_occurrence_key'] : null
		);
	}

	/**
	 * Refuse oversized JSON documents before storing preview rows.
	 *
	 * @param array<string,mixed> $document Document.
	 */
	private function assert_json_size( array $document ): void {
		CanonicalJson::assert_maximum_size( CanonicalJson::encode_object( $document ), Schema::BATCH_JSON_MAX_BYTES );
	}

	/**
	 * Hash the immutable review snapshot.
	 *
	 * @param BatchRecord                    $batch Batch.
	 * @param array<string,mixed>            $filters Filters.
	 * @param array<string,mixed>            $limits Limits.
	 * @param array<string,mixed>            $summary Summary.
	 * @param array<int,array<string,mixed>> $items Items.
	 * @return string
	 */
	private function snapshot_hash( BatchRecord $batch, array $filters, array $limits, array $summary, array $items ): string {
		$snapshot_items = array_map(
			static function ( array $item ): array {
				return array(
					'batch_item_public_id'  => $item['public_id'] ?? null,
					'channel_public_id'     => $item['channel_public_id'],
					'delivery_key'          => $item['preview_item']['delivery']['delivery_key'] ?? null,
					'intent_occurrence_key' => $item['intent_occurrence_key'] ?? null,
					'item_key'              => $item['item_key'] ?? null,
					'payload_hash'          => $item['preview_item']['delivery']['payload_hash'] ?? null,
					'post_id'               => $item['post_id'],
					'position'              => $item['position'],
					'semantic_preview_hash' => $item['semantic_preview_hash'],
					'state'                 => $item['state'],
				);
			},
			$items
		);

		return CanonicalJson::hash(
			'batch_preview_snapshot',
			array(
				'batch_public_id' => $batch->public_id,
				'batch_type'      => $batch->batch_type,
				'filters'         => $filters,
				'items'           => $snapshot_items,
				'limits'          => $limits,
				'summary'         => $summary,
			)
		);
	}

	/**
	 * Return sanitized metadata filters.
	 *
	 * @param string $trigger Trigger.
	 * @param mixed  $metadata Metadata filters.
	 * @return array<int,array<string,mixed>>
	 */
	private function metadata_filters( string $trigger, $metadata ): array {
		if ( ! is_array( $metadata ) || ! array_is_list( $metadata ) ) {
			throw new ValidationException( 'Metadata filters must be a list.' );
		}

		$authority = $this->metadata_key_authority( $trigger );
		$filters   = array();
		foreach ( $metadata as $filter ) {
			if ( ! is_array( $filter ) || array_is_list( $filter ) ) {
				throw new ValidationException( 'Metadata filter must be an object.' );
			}
			$this->assert_known_keys( $filter, array( 'key', 'operator', 'value' ) );
			$key = isset( $filter['key'] ) && is_string( $filter['key'] ) ? $filter['key'] : '';
			StorageValidator::assert_field_key( $key );
			if ( ! isset( $authority[ $key ] ) ) {
				throw new ValidationException( 'Metadata filter key is not authorized by an enabled inclusion rule.' );
			}

			$operator = isset( $filter['operator'] ) && is_string( $filter['operator'] ) ? $filter['operator'] : 'equals';
			if ( ! in_array( $operator, array( 'equals', 'not_equals', 'in', 'not_in', 'exists', 'not_exists' ), true ) ) {
				throw new ValidationException( 'Metadata filter operator is unsupported.' );
			}

			$value = $filter['value'] ?? null;
			if ( in_array( $operator, array( 'in', 'not_in' ), true ) ) {
				if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
					throw new ValidationException( 'Metadata list filters need a list value.' );
				}
			} elseif ( in_array( $operator, array( 'exists', 'not_exists' ), true ) ) {
				$value = null;
			} elseif ( is_array( $value ) ) {
				throw new ValidationException( 'Metadata scalar filters need a scalar value.' );
			}

			$filters[] = array(
				'authorized_by_rule_public_id' => $authority[ $key ],
				'key'                          => $key,
				'operator'                     => $operator,
				'value'                        => $value,
			);
		}

		return $filters;
	}

	/**
	 * Return metadata-key authorities for enabled inclusion rules.
	 *
	 * @param string $trigger Trigger.
	 * @return array<string,string>
	 */
	private function metadata_key_authority( string $trigger ): array {
		$authority = array();

		foreach ( $this->rules->find_enabled_for_trigger( $trigger ) as $rule ) {
			if ( 'include' !== $rule->effect ) {
				continue;
			}

			foreach ( $rule->custom_field_allowlist as $key ) {
				if ( ! isset( $authority[ $key ] ) ) {
					$authority[ $key ] = $rule->public_id;
				}
			}
		}

		return $authority;
	}

	/**
	 * Return normalized taxonomy filters.
	 *
	 * @param mixed $taxonomies Taxonomy filters.
	 * @return array<int,array<string,mixed>>
	 */
	private function taxonomy_filters( $taxonomies ): array {
		if ( ! is_array( $taxonomies ) || ! array_is_list( $taxonomies ) ) {
			throw new ValidationException( 'Taxonomy filters must be a list.' );
		}

		$filters = array();
		foreach ( $taxonomies as $filter ) {
			if ( ! is_array( $filter ) || array_is_list( $filter ) ) {
				throw new ValidationException( 'Taxonomy filter must be an object.' );
			}

			$this->assert_known_keys( $filter, array( 'taxonomy', 'operator', 'term_ids' ) );
			$taxonomy = isset( $filter['taxonomy'] ) && is_string( $filter['taxonomy'] ) ? $this->wordpress_slug( $filter['taxonomy'] ) : '';
			if ( function_exists( 'taxonomy_exists' ) && ! taxonomy_exists( $taxonomy ) ) {
				throw new ValidationException( 'Taxonomy filter does not exist.' );
			}

			$operator = isset( $filter['operator'] ) && is_string( $filter['operator'] ) ? $filter['operator'] : '';
			if ( ! in_array( $operator, array( 'has_any', 'has_all', 'has_none' ), true ) ) {
				throw new ValidationException( 'Taxonomy filter operator is unsupported.' );
			}

			$term_ids = $this->positive_int_list( $filter['term_ids'] ?? null, 'Term IDs' );
			foreach ( $term_ids as $term_id ) {
				if ( function_exists( 'get_term' ) && function_exists( 'is_wp_error' ) ) {
					$term = get_term( $term_id, $taxonomy );
					if ( null === $term || false === $term || is_wp_error( $term ) ) {
						throw new ValidationException( 'Taxonomy term filter does not exist.' );
					}
				}
			}
			sort( $term_ids, SORT_NUMERIC );
			$filters[] = array(
				'operator' => $operator,
				'taxonomy' => $taxonomy,
				'term_ids' => $term_ids,
			);
		}

		return $filters;
	}

	/**
	 * Build a WP_Query date_query.
	 *
	 * @param array<string,mixed> $filters Filters.
	 * @return array<int|string,mixed>
	 */
	private function date_query( array $filters ): array {
		$date_query = array( 'relation' => 'AND' );
		foreach ( array(
			'published' => 'post_date_gmt',
			'modified'  => 'post_modified_gmt',
		) as $prefix => $column ) {
			$from = $filters[ $prefix . '_from_utc' ] ?? null;
			$to   = $filters[ $prefix . '_to_utc' ] ?? null;
			if ( null === $from && null === $to ) {
				continue;
			}

			$clause = array(
				'column'    => $column,
				'inclusive' => true,
			);
			if ( null !== $from ) {
				$clause['after'] = $from;
			}
			if ( null !== $to ) {
				$clause['before'] = $to;
			}
			$date_query[] = $clause;
		}

		return count( $date_query ) > 1 ? $date_query : array();
	}

	/**
	 * Build a WP_Query tax_query.
	 *
	 * @param array<int,array<string,mixed>> $filters Tax filters.
	 * @return array<int|string,mixed>
	 */
	private function tax_query( array $filters ): array {
		$query = array( 'relation' => 'AND' );
		foreach ( $filters as $filter ) {
			$query[] = array(
				'field'            => 'term_id',
				'operator'         => array(
					'has_any'  => 'IN',
					'has_all'  => 'AND',
					'has_none' => 'NOT IN',
				)[ $filter['operator'] ],
				'taxonomy'         => $filter['taxonomy'],
				'terms'            => $filter['term_ids'],
				'include_children' => false,
			);
		}

		return $query;
	}

	/**
	 * Build a WP_Query meta_query.
	 *
	 * @param array<int,array<string,mixed>> $filters Metadata filters.
	 * @return array<int|string,mixed>
	 */
	private function meta_query( array $filters ): array {
		$query = array( 'relation' => 'AND' );
		foreach ( $filters as $filter ) {
			$operator = (string) $filter['operator'];
			$compare  = array(
				'equals'     => '=',
				'exists'     => 'EXISTS',
				'in'         => 'IN',
				'not_equals' => '!=',
				'not_exists' => 'NOT EXISTS',
				'not_in'     => 'NOT IN',
			)[ $operator ];
			$clause   = array(
				'compare' => $compare,
				'key'     => $filter['key'],
			);
			if ( ! in_array( $operator, array( 'exists', 'not_exists' ), true ) ) {
				$clause['value'] = $filter['value'];
			}
			$query[] = $clause;
		}

		return $query;
	}

	/**
	 * Return a positive integer list.
	 *
	 * @param mixed  $values Values.
	 * @param string $label Label.
	 * @return array<int,int>
	 */
	private function positive_int_list( $values, string $label ): array {
		unset( $label );
		if ( ! is_array( $values ) || ! array_is_list( $values ) || array() === $values ) {
			throw new ValidationException( 'ID filters must be non-empty lists.' );
		}

		$ids = array();
		foreach ( $values as $value ) {
			$id = (int) $value;
			StorageValidator::assert_unsigned_id( 'ID', $id );
			$ids[] = $id;
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Validate and return a WordPress slug.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function wordpress_slug( $value ): string {
		if ( ! is_string( $value ) ) {
			throw new ValidationException( 'WordPress slug filters must contain strings.' );
		}
		StorageValidator::assert_wordpress_slug( 'WordPress slug', $value );

		return $value;
	}

	/**
	 * Check current-user post preview authority.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function can_preview_post( int $post_id ): bool {
		$post = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		if ( 'publish' === $post->post_status ) {
			return true;
		}

		return function_exists( 'current_user_can' ) ? current_user_can( 'read_post', $post_id ) : true;
	}

	/**
	 * Refuse unknown object keys.
	 *
	 * @param array<string,mixed> $document Document.
	 * @param array<int,string>   $known Known keys.
	 */
	private function assert_known_keys( array $document, array $known ): void {
		$allowed = array_fill_keys( $known, true );
		foreach ( $document as $key => $_value ) {
			if ( ! isset( $allowed[ (string) $key ] ) ) {
				throw new ValidationException( 'Batch JSON contains an unsupported key.' );
			}
		}
	}
}
