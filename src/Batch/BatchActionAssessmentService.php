<?php
/**
 * Zero-write batch action assessments.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Batch;

use DateTimeImmutable;
use DateTimeZone;
use PressQueue\Delivery\OperatorActionAssessmentService;
use PressQueue\Storage\Database;
use PressQueue\Storage\Record\BatchItemRecord;
use PressQueue\Storage\Record\BatchRecord;
use PressQueue\Storage\Repository\BatchItemRepository;
use PressQueue\Storage\Repository\BatchRepository;
use PressQueue\Storage\StorageValidator;
use PressQueue\Storage\ValidationException;

/**
 * Evaluates batch actions without side effects.
 */
final class BatchActionAssessmentService {
	/**
	 * Batch repository.
	 *
	 * @var BatchRepository
	 */
	private BatchRepository $batches;

	/**
	 * Batch item repository.
	 *
	 * @var BatchItemRepository
	 */
	private BatchItemRepository $items;

	/**
	 * Batch preview service.
	 *
	 * @var BatchPreviewService
	 */
	private BatchPreviewService $preview;

	/**
	 * Batch limit service.
	 *
	 * @var BatchLimitService
	 */
	private BatchLimitService $limits;

	/**
	 * Delivery operator assessment service.
	 *
	 * @var OperatorActionAssessmentService
	 */
	private OperatorActionAssessmentService $operators;

	/**
	 * Constructor.
	 *
	 * @param Database|null $database Database.
	 */
	public function __construct( ?Database $database = null ) {
		$database        = $database ?? Database::from_globals();
		$this->batches   = new BatchRepository( $database );
		$this->items     = new BatchItemRepository( $database );
		$this->preview   = new BatchPreviewService( $database );
		$this->limits    = new BatchLimitService();
		$this->operators = new OperatorActionAssessmentService( $database );
	}

	/**
	 * Assess nondurable batch preview candidates.
	 *
	 * @param string              $batch_type Batch type.
	 * @param array<string,mixed> $filters Filters.
	 * @param array<string,mixed> $batch_limits Limits.
	 * @param int                 $created_by Creator user ID.
	 * @param int                 $per_page Item output limit.
	 * @return BatchActionAssessment
	 */
	public function assess_preview( string $batch_type, array $filters, array $batch_limits, int $created_by, int $per_page ): BatchActionAssessment {
		$assessment            = $this->preview->assess( $batch_type, $filters, $batch_limits, $created_by );
		$counts                = BatchActionAssessment::empty_counts();
		$counts['batches']     = 1;
		$counts['batch_items'] = count( $assessment['items'] );

		return new BatchActionAssessment(
			false,
			true,
			null,
			null,
			$counts,
			$this->bounded_item_arrays( $assessment['items'], $per_page ),
			array(),
			array(),
			$assessment['summary']
		);
	}

	/**
	 * Assess batch confirmation.
	 *
	 * @param string $batch_public_id Batch public ID.
	 * @param string $preview_snapshot_hash Reviewed snapshot hash.
	 * @param int    $per_page Item output limit.
	 * @return BatchActionAssessment
	 */
	public function assess_confirm( string $batch_public_id, string $preview_snapshot_hash, int $per_page ): BatchActionAssessment {
		StorageValidator::assert_hash( $preview_snapshot_hash );

		$batch = $this->batches->find_by_public_id( $batch_public_id );
		if ( null === $batch ) {
			return $this->blocked_batch( array( 'not_found' ), null );
		}

		if ( 'previewed' !== $batch->status ) {
			return $this->blocked_batch( array( 'batch_not_confirmable' ), $batch );
		}

		if ( $batch->preview_expires_at->getTimestamp() <= time() ) {
			return $this->blocked_batch( array( 'batch_preview_expired' ), $batch );
		}

		if ( $batch->preview_snapshot_hash !== $preview_snapshot_hash ) {
			return $this->blocked_batch( array( 'preview_snapshot_mismatch' ), $batch );
		}

		try {
			$this->limits->assert_confirmable_summary( $batch->preview_summary );
			$stored     = $this->items->find_by_batch( $batch->public_id );
			$evaluation = $this->preview->evaluate( $batch, null, null, $stored );
			if ( $evaluation['snapshot_hash'] !== $batch->preview_snapshot_hash ) {
				return $this->blocked_batch( array( 'batch_preview_changed' ), $batch, $stored, $per_page );
			}

			$this->assert_items_unchanged( $stored, $evaluation['items'] );
		} catch ( ValidationException $exception ) {
			unset( $exception );

			return $this->blocked_batch( array( 'batch_preview_not_confirmable' ), $batch, $this->items->find_by_batch( $batch->public_id ), $per_page );
		}

		$counts                      = BatchActionAssessment::empty_counts();
		$counts['batches']           = 1;
		$counts['batch_items']       = count(
			array_filter(
				$stored,
				static function ( BatchItemRecord $item ): bool {
					return 'previewed' === $item->state;
				}
			)
		);
		$counts['scheduled_actions'] = 1;

		return new BatchActionAssessment( false, true, $batch->public_id, $batch->preview_snapshot_hash, $counts, $this->bounded_item_records( $stored, $per_page ), array(), array(), $batch->preview_summary );
	}

	/**
	 * Assess batch cancellation.
	 *
	 * @param string $batch_public_id Batch public ID.
	 * @param int    $per_page Item output limit.
	 * @return BatchActionAssessment
	 */
	public function assess_cancel( string $batch_public_id, int $per_page ): BatchActionAssessment {
		$batch = $this->batches->find_by_public_id( $batch_public_id );
		if ( null === $batch ) {
			return $this->blocked_batch( array( 'not_found' ), null );
		}

		$stored = $this->items->find_by_batch( $batch->public_id );
		if ( ! in_array( $batch->status, array( 'previewed', 'confirming', 'queueing' ), true ) ) {
			return new BatchActionAssessment( false, false, $batch->public_id, $batch->preview_snapshot_hash, BatchActionAssessment::empty_counts(), $this->bounded_item_records( $stored, $per_page ), array(), array(), $batch->preview_summary );
		}

		$delivery_count = 0;
		foreach ( $stored as $item ) {
			if ( null === $item->delivery_public_id ) {
				continue;
			}

			if ( $this->operators->assess_cancel( $item->delivery_public_id )->would_mutate ) {
				++$delivery_count;
			}
		}

		$counts                    = BatchActionAssessment::empty_counts();
		$counts['batches']         = 1;
		$counts['batch_items']     = count(
			array_filter(
				$stored,
				static function ( BatchItemRecord $item ): bool {
					return in_array( $item->state, array( 'previewed', 'reserved' ), true );
				}
			)
		);
		$counts['deliveries']      = $delivery_count;
		$counts['delivery_events'] = $delivery_count;

		return new BatchActionAssessment( false, true, $batch->public_id, $batch->preview_snapshot_hash, $counts, $this->bounded_item_records( $stored, $per_page ), array(), array(), $batch->preview_summary );
	}

	/**
	 * Return a blocked batch assessment.
	 *
	 * @param array<int,string>          $reasons Reasons.
	 * @param BatchRecord|null           $batch Batch.
	 * @param array<int,BatchItemRecord> $items Items.
	 * @param int                        $per_page Item output limit.
	 * @return BatchActionAssessment
	 */
	private function blocked_batch( array $reasons, ?BatchRecord $batch, array $items = array(), int $per_page = 50 ): BatchActionAssessment {
		return new BatchActionAssessment(
			false,
			false,
			null === $batch ? null : $batch->public_id,
			null === $batch ? null : $batch->preview_snapshot_hash,
			BatchActionAssessment::empty_counts(),
			$this->bounded_item_records( $items, $per_page ),
			array(),
			array_values( $reasons ),
			null === $batch ? null : $batch->preview_summary
		);
	}

	/**
	 * Compare stored rows with a fresh nondurable evaluation.
	 *
	 * @param array<int,BatchItemRecord>     $stored Stored items.
	 * @param array<int,array<string,mixed>> $fresh Fresh item inputs.
	 */
	private function assert_items_unchanged( array $stored, array $fresh ): void {
		if ( count( $stored ) !== count( $fresh ) ) {
			throw new ValidationException( 'Batch item set changed.' );
		}

		foreach ( $stored as $index => $item ) {
			$candidate = $fresh[ $index ] ?? null;
			if (
				null === $candidate
				|| $item->position !== (int) $candidate['position']
				|| $item->post_id !== (int) $candidate['post_id']
				|| ( $candidate['channel_public_id'] ?? null ) !== $item->channel_public_id
				|| ( $candidate['intent_occurrence_key'] ?? null ) !== $item->intent_occurrence_key
				|| $item->state !== (string) $candidate['state']
				|| $item->semantic_preview_hash !== (string) $candidate['semantic_preview_hash']
				|| ( $item->preview_item['delivery']['delivery_key'] ?? null ) !== ( $candidate['preview_item']['delivery']['delivery_key'] ?? null )
				|| ( $item->preview_item['delivery']['payload_hash'] ?? null ) !== ( $candidate['preview_item']['delivery']['payload_hash'] ?? null )
			) {
				throw new ValidationException( 'Batch item changed.' );
			}
		}
	}

	/**
	 * Bound raw item arrays.
	 *
	 * @param array<int,array<string,mixed>> $items Items.
	 * @param int                            $per_page Limit.
	 * @return array<int,array<string,mixed>>
	 */
	private function bounded_item_arrays( array $items, int $per_page ): array {
		return array_map(
			array( $this, 'format_item_array' ),
			array_slice( $items, 0, $this->bounded_limit( $per_page ) )
		);
	}

	/**
	 * Bound item records.
	 *
	 * @param array<int,BatchItemRecord> $items Items.
	 * @param int                        $per_page Limit.
	 * @return array<int,array<string,mixed>>
	 */
	private function bounded_item_records( array $items, int $per_page ): array {
		return array_map(
			array( $this, 'format_item_record' ),
			array_slice( $items, 0, $this->bounded_limit( $per_page ) )
		);
	}

	/**
	 * Format an evaluated item without full preview payloads.
	 *
	 * @param array<string,mixed> $item Item.
	 * @return array<string,mixed>
	 */
	private function format_item_array( array $item ): array {
		return array(
			'channel_public_id'               => isset( $item['channel_public_id'] ) && is_string( $item['channel_public_id'] ) ? $item['channel_public_id'] : null,
			'duplicate_of_delivery_public_id' => isset( $item['duplicate_of_delivery_public_id'] ) && is_string( $item['duplicate_of_delivery_public_id'] ) ? $item['duplicate_of_delivery_public_id'] : null,
			'position'                        => (int) $item['position'],
			'post_id'                         => (int) $item['post_id'],
			'public_id'                       => isset( $item['public_id'] ) && is_string( $item['public_id'] ) ? $item['public_id'] : null,
			'reason'                          => isset( $item['reason'] ) && is_array( $item['reason'] ) ? $item['reason'] : null,
			'scheduled_for_utc'               => isset( $item['scheduled_for_utc'] ) && is_string( $item['scheduled_for_utc'] ) ? $this->rfc3339_string( $item['scheduled_for_utc'] ) : null,
			'semantic_preview_hash'           => (string) $item['semantic_preview_hash'],
			'state'                           => (string) $item['state'],
		);
	}

	/**
	 * Format a stored item without full preview payloads.
	 *
	 * @param BatchItemRecord $item Item.
	 * @return array<string,mixed>
	 */
	private function format_item_record( BatchItemRecord $item ): array {
		return array(
			'channel_public_id'               => $item->channel_public_id,
			'duplicate_of_delivery_public_id' => $item->duplicate_of_delivery_public_id,
			'position'                        => $item->position,
			'post_id'                         => $item->post_id,
			'public_id'                       => $item->public_id,
			'reason'                          => $item->reason,
			'scheduled_for_utc'               => null === $item->scheduled_for_utc ? null : $this->rfc3339( $item->scheduled_for_utc ),
			'semantic_preview_hash'           => $item->semantic_preview_hash,
			'state'                           => $item->state,
		);
	}

	/**
	 * Bound a list limit.
	 *
	 * @param int $limit Requested limit.
	 * @return int
	 */
	private function bounded_limit( int $limit ): int {
		return max( 1, min( 100, $limit ) );
	}

	/**
	 * Format a storage timestamp.
	 *
	 * @param string $value UTC datetime.
	 * @return string
	 */
	private function rfc3339_string( string $value ): string {
		return $this->rfc3339( StorageValidator::parse_required_date_time( $value ) );
	}

	/**
	 * Format a timestamp as UTC RFC 3339.
	 *
	 * @param DateTimeImmutable $date_time Date time.
	 * @return string
	 */
	private function rfc3339( DateTimeImmutable $date_time ): string {
		return $date_time->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
	}
}
