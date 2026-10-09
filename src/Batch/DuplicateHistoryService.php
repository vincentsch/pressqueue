<?php
/**
 * Batch duplicate history queries.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Batch;

// phpcs:disable Squiz.Commenting.VariableComment.Missing -- Constructor wires service dependencies.

use PressQueue\Storage\Database;
use PressQueue\Storage\PublicIds;
use PressQueue\Storage\Repository\BatchItemRepository;
use PressQueue\Storage\Schema;
use PressQueue\Storage\StorageEnums;
use PressQueue\Storage\StorageException;
use PressQueue\Storage\StorageValidator;

/**
 * Reads duplicate and repost history without mutating provider state.
 */
final class DuplicateHistoryService {
	private Database $database;
	private BatchItemRepository $batch_items;

	/**
	 * Constructor.
	 *
	 * @param Database|null $database Database wrapper.
	 */
	public function __construct( ?Database $database = null ) {
		$this->database    = $database ?? Database::from_globals();
		$this->batch_items = new BatchItemRepository( $this->database );
	}

	/**
	 * Return a bulk duplicate blocker for a post and channel.
	 *
	 * @param int         $post_id Post ID.
	 * @param string      $channel_public_id Channel public ID.
	 * @param string|null $exclude_batch_public_id Current batch to ignore.
	 * @param string|null $delivery_mode Optional reservation and history mode.
	 * @param bool        $for_update Whether to read current rows under the destination lock.
	 * @return array<string,mixed>|null
	 */
	public function blocking_bulk_duplicate( int $post_id, string $channel_public_id, ?string $exclude_batch_public_id = null, ?string $delivery_mode = null, bool $for_update = false ): ?array {
		foreach ( $this->delivery_history( $post_id, $channel_public_id, $delivery_mode, $for_update ) as $history ) {
			if ( $this->blocks_bulk( $history ) ) {
				return $history;
			}
		}

		$item = $this->batch_items->find_reserved_item( $post_id, $channel_public_id, $exclude_batch_public_id, $delivery_mode, $for_update );
		return null === $item ? null : array(
			'batch_item_public_id' => $item->public_id,
			'batch_public_id'      => $item->batch_public_id,
			'classification'       => 'reserved_batch_item',
			'state'                => $item->state,
		);
	}

	/**
	 * Return a repost blocker for a post and channel.
	 *
	 * @param int         $post_id Post ID.
	 * @param string      $channel_public_id Channel public ID.
	 * @param string|null $exclude_batch_public_id Batch to ignore.
	 * @return array<string,mixed>|null
	 */
	public function blocking_repost_duplicate( int $post_id, string $channel_public_id, ?string $exclude_batch_public_id = null ): ?array {
		foreach ( $this->delivery_history( $post_id, $channel_public_id ) as $history ) {
			if ( $this->blocks_repost( $history ) ) {
				return $history;
			}
		}

		$batch_item = $this->batch_items->find_unresolved_repost_item( $post_id, $channel_public_id, $exclude_batch_public_id );
		if ( null !== $batch_item ) {
			return array(
				'batch_item_public_id' => $batch_item->public_id,
				'batch_public_id'      => $batch_item->batch_public_id,
				'channel_public_id'    => $batch_item->channel_public_id,
				'classification'       => 'unresolved_repost_batch_item',
				'state'                => $batch_item->state,
			);
		}

		return null;
	}

	/**
	 * Return the most recent accepted history entry satisfying repost criteria.
	 *
	 * @param int                 $post_id Post ID.
	 * @param string              $channel_public_id Channel public ID.
	 * @param array<string,mixed> $criteria Repost criteria.
	 * @return array<string,mixed>|null
	 */
	public function prior_accepted_delivery( int $post_id, string $channel_public_id, array $criteria ): ?array {
		foreach ( $this->delivery_history( $post_id, $channel_public_id ) as $history ) {
			if ( 'accepted' !== ( $history['state'] ?? null ) ) {
				continue;
			}

			$accepted_at = (string) ( $history['state_changed_at'] ?? '' );
			if ( isset( $criteria['prior_accepted_from_utc'] ) && $accepted_at < (string) $criteria['prior_accepted_from_utc'] ) {
				return null;
			}

			if ( isset( $criteria['prior_accepted_to_utc'] ) && $accepted_at > (string) $criteria['prior_accepted_to_utc'] ) {
				return null;
			}

			if ( isset( $criteria['minimum_days_since_prior_acceptance'] ) ) {
				$latest_allowed = gmdate( 'Y-m-d H:i:s', time() - ( (int) $criteria['minimum_days_since_prior_acceptance'] * DAY_IN_SECONDS ) );
				if ( $accepted_at > $latest_allowed ) {
					return null;
				}
			}

			return $history;
		}

		return null;
	}

	/**
	 * Return visible delivery history for display and policy.
	 *
	 * @param int         $post_id Post ID.
	 * @param string      $channel_public_id Channel public ID.
	 * @param string|null $delivery_mode Optional mode filter.
	 * @param bool        $for_update Whether to read current rows under the destination lock.
	 * @return array<int,array<string,mixed>>
	 */
	public function delivery_history( int $post_id, string $channel_public_id, ?string $delivery_mode = null, bool $for_update = false ): array {
		StorageValidator::assert_unsigned_id( 'Post ID', $post_id );
		PublicIds::assert( $channel_public_id );
		$where = 'decisions.post_id = %d AND deliveries.channel_public_id = %s';
		$args  = array( $post_id, $channel_public_id );
		if ( null !== $delivery_mode ) {
			StorageEnums::assert_delivery_mode( $delivery_mode );
			$where .= ' AND deliveries.delivery_mode = %s';
			$args[] = $delivery_mode;
		}

		$sql = 'SELECT deliveries.public_id, deliveries.state, deliveries.channel_public_id, deliveries.schedule_slot, deliveries.scheduled_for_utc, deliveries.payload_hash, deliveries.remote_id, deliveries.attempt_count, deliveries.retry_safety, deliveries.reconciliation_status, deliveries.state_changed_at
				FROM `' . $this->database->table( Schema::DELIVERIES ) . '` deliveries
				INNER JOIN `' . $this->database->table( Schema::DECISIONS ) . '` decisions ON decisions.public_id = deliveries.decision_public_id
				WHERE ' . $where . '
				ORDER BY deliveries.state_changed_at DESC, deliveries.id DESC' . ( $for_update ? ' FOR UPDATE' : '' );
		// query() throws on lock failure; fetch helpers otherwise return empty results.
		$count = $for_update ? $this->database->query( $sql, $args ) : null;
		$rows  = 0 === $count ? array() : $this->database->get_results_array( $sql, $args );
		if ( null !== $count && count( $rows ) !== $count ) {
			throw new StorageException( 'Locked delivery history could not be loaded.' );
		}

		return array_map(
			static function ( array $row ): array {
				return array(
					'attempt_count'         => (int) ( $row['attempt_count'] ?? 0 ),
					'channel_public_id'     => (string) ( $row['channel_public_id'] ?? '' ),
					'delivery_public_id'    => (string) ( $row['public_id'] ?? '' ),
					'payload_hash'          => (string) ( $row['payload_hash'] ?? '' ),
					'reconciliation_status' => (string) ( $row['reconciliation_status'] ?? '' ),
					'remote_id'             => null === ( $row['remote_id'] ?? null ) ? null : (string) $row['remote_id'],
					'retry_safety'          => (string) ( $row['retry_safety'] ?? '' ),
					'schedule_slot'         => (string) ( $row['schedule_slot'] ?? '' ),
					'scheduled_for_utc'     => null === ( $row['scheduled_for_utc'] ?? null ) ? null : (string) $row['scheduled_for_utc'],
					'state'                 => (string) ( $row['state'] ?? '' ),
					'state_changed_at'      => (string) ( $row['state_changed_at'] ?? '' ),
				);
			},
			$rows
		);
	}

	/**
	 * Whether a history entry blocks bulk delivery.
	 *
	 * @param array<string,mixed> $history History.
	 * @return bool
	 */
	private function blocks_bulk( array $history ): bool {
		$state = (string) ( $history['state'] ?? '' );
		if ( in_array( $state, array( 'proposed', 'held', 'queued', 'leased', 'retrying', 'accepted' ), true ) ) {
			return true;
		}

		return $this->blocks_unresolved_followup( $history );
	}

	/**
	 * Whether a history entry blocks repost delivery.
	 *
	 * @param array<string,mixed> $history History.
	 * @return bool
	 */
	private function blocks_repost( array $history ): bool {
		$state = (string) ( $history['state'] ?? '' );
		if ( in_array( $state, array( 'proposed', 'held', 'queued', 'leased', 'retrying' ), true ) ) {
			return true;
		}

		return $this->blocks_unresolved_followup( $history );
	}

	/**
	 * Whether unresolved ambiguity or manual follow-up owns the next action.
	 *
	 * @param array<string,mixed> $history History.
	 * @return bool
	 */
	private function blocks_unresolved_followup( array $history ): bool {
		$state                 = (string) ( $history['state'] ?? '' );
		$retry_safety          = (string) ( $history['retry_safety'] ?? '' );
		$reconciliation_status = (string) ( $history['reconciliation_status'] ?? '' );

		if ( 'ambiguous' === $state && 'confirmed_not_accepted' !== $reconciliation_status && 'cancelled' !== $reconciliation_status ) {
			return true;
		}

		return 'failed' === $state && 'manual_allowed' === $retry_safety;
	}
}
