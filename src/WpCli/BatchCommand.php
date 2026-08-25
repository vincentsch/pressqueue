<?php
/**
 * WP-CLI batch command.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\WpCli;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Command exceptions are serialized by JSON envelopes.

use PressQueue\Batch\BatchActionAssessment;
use PressQueue\Batch\BatchActionAssessmentService;
use PressQueue\Batch\BatchCancellationService;
use PressQueue\Batch\BatchConfirmationService;
use PressQueue\Batch\BatchPreviewService;
use PressQueue\Security\Capabilities;
use PressQueue\Storage\Repository\BatchItemRepository;
use PressQueue\Storage\Repository\BatchRepository;
use PressQueue\Storage\ValidationException;
use PressQueue\WpCli\Query\BatchQueryService;
use PressQueue\WpCli\Query\RecordFormatter;

/**
 * Handles batch preview, confirmation, cancellation, and reads.
 */
final class BatchCommand extends AbstractCommand {
	private const FILTER_JSON_KEYS = array(
		'author_ids',
		'metadata',
		'minimum_days_since_prior_acceptance',
		'modified_from_utc',
		'modified_to_utc',
		'post_statuses',
		'post_types',
		'prior_accepted_from_utc',
		'prior_accepted_to_utc',
		'prior_delivery_states',
		'published_from_utc',
		'published_to_utc',
		'required_destination_channel_public_ids',
		'schema_version',
		'taxonomies',
	);

	private const REPOST_ONLY_FILTER_JSON_KEYS = array(
		'minimum_days_since_prior_acceptance',
		'prior_accepted_from_utc',
		'prior_accepted_to_utc',
		'prior_delivery_states',
		'required_destination_channel_public_ids',
	);

	private const LIMIT_JSON_KEYS = array(
		'candidate_limit',
		'destination_item_limit',
		'schedule',
		'schema_version',
	);

	/**
	 * List batches.
	 *
	 * @subcommand list
	 *
	 * @param array<int,mixed>    $args Args.
	 * @param array<string,mixed> $assoc_args Assoc args.
	 * @return int
	 */
	public function list_( array $args, array $assoc_args ): int {
		unset( $args );

		return $this->run_json(
			'batches.list',
			$assoc_args,
			function () use ( $assoc_args ): array {
				$this->require_capability( Capabilities::OPERATE_BATCHES );
				list( $page, $per_page ) = $this->pagination_args( $assoc_args );

				return ( new BatchQueryService() )->list_batches( $assoc_args, $page, $per_page );
			}
		);
	}

	/**
	 * Show one batch.
	 *
	 * @param array<int,mixed>    $args Args.
	 * @param array<string,mixed> $assoc_args Assoc args.
	 * @return int
	 */
	public function show( array $args, array $assoc_args ): int {
		return $this->run_json(
			'batches.show',
			$assoc_args,
			function () use ( $args, $assoc_args ): array {
				$this->require_capability( Capabilities::OPERATE_BATCHES );
				list( , $per_page ) = $this->pagination_args( $assoc_args );

				return ( new BatchQueryService() )->show( $this->positional( $args, 0, 'batch_public_id' ), $per_page );
			}
		);
	}

	/**
	 * Preview batch candidates.
	 *
	 * @param array<int,mixed>    $args Args.
	 * @param array<string,mixed> $assoc_args Assoc args.
	 * @return int
	 */
	public function preview( array $args, array $assoc_args ): int {
		unset( $args );

		return $this->run_json(
			'batches.preview',
			$assoc_args,
			function () use ( $assoc_args ): array {
				$this->require_capability( Capabilities::OPERATE_BATCHES );
				list( , $per_page ) = $this->pagination_args( $assoc_args );
				$batch_type         = isset( $assoc_args['type'] ) ? (string) $assoc_args['type'] : '';
				$filters            = $this->batch_filters( $assoc_args, $batch_type );
				$limits             = $this->batch_limits( $assoc_args );

				if ( ! $this->apply_requested( $assoc_args ) ) {
					return ( new BatchActionAssessmentService() )->assess_preview( $batch_type, $filters, $limits, $this->current_user_id(), $per_page )->to_array();
				}

				try {
					$batch = ( new BatchPreviewService() )->preview( $batch_type, $filters, $limits, $this->current_user_id() );
				} catch ( ValidationException $exception ) {
					throw new CommandException( 'schema_validation_failed', $exception->getMessage(), ExitCodes::INVALID );
				}

				$stored_items          = ( new BatchItemRepository() )->find_by_batch( $batch->public_id );
				$items                 = array_slice( $stored_items, 0, $per_page );
				$counts                = BatchActionAssessment::empty_counts();
				$counts['batches']     = 1;
				$counts['batch_items'] = count( $stored_items );

				return ( new BatchActionAssessment( true, true, $batch->public_id, $batch->preview_snapshot_hash, $counts, array_map( array( RecordFormatter::class, 'batch_item_record' ), $items ), array(), array(), $batch->preview_summary ) )->to_array();
			}
		);
	}

	/**
	 * Confirm a reviewed batch preview.
	 *
	 * @param array<int,mixed>    $args Args.
	 * @param array<string,mixed> $assoc_args Assoc args.
	 * @return int
	 */
	public function confirm( array $args, array $assoc_args ): int {
		return $this->run_json(
			'batches.confirm',
			$assoc_args,
			function () use ( $args, $assoc_args ): array {
				$this->require_capability( Capabilities::OPERATE_BATCHES );
				list( , $per_page ) = $this->pagination_args( $assoc_args );
				if ( ! isset( $assoc_args['preview-snapshot-hash'] ) ) {
					throw new CommandException( 'invalid_arguments', '--preview-snapshot-hash is required.', ExitCodes::INVALID );
				}

				$batch_public_id = $this->positional( $args, 0, 'batch_public_id' );
				$snapshot_hash   = (string) $assoc_args['preview-snapshot-hash'];
				$assessment      = ( new BatchActionAssessmentService() )->assess_confirm( $batch_public_id, $snapshot_hash, $per_page );
				if ( ! $this->apply_requested( $assoc_args ) ) {
					return $assessment->to_array();
				}

				if ( ! $assessment->would_mutate ) {
					throw $this->refused_from_reasons( $assessment->blocked_reasons );
				}

				$scheduler = new RecordingActionSchedulerQueue();
				try {
					$batch = ( new BatchConfirmationService( null, $scheduler ) )->confirm( $batch_public_id, $snapshot_hash, $this->current_user_id() );
				} catch ( ValidationException $exception ) {
					unset( $exception );
					throw new CommandException( 'policy_refused', 'The requested PressQueue batch action is not currently allowed.', ExitCodes::REFUSED );
				}

				$items                       = array_slice( ( new BatchItemRepository() )->find_by_batch( $batch->public_id ), 0, $per_page );
				$counts                      = $assessment->affected_counts;
				$counts['scheduled_actions'] = count( $scheduler->scheduled_action_ids() );

				return ( new BatchActionAssessment( true, true, $batch->public_id, $batch->preview_snapshot_hash, $counts, array_map( array( RecordFormatter::class, 'batch_item_record' ), $items ), $scheduler->scheduled_action_ids(), array(), $batch->preview_summary ) )->to_array();
			}
		);
	}

	/**
	 * Cancel a batch.
	 *
	 * @param array<int,mixed>    $args Args.
	 * @param array<string,mixed> $assoc_args Assoc args.
	 * @return int
	 */
	public function cancel( array $args, array $assoc_args ): int {
		return $this->run_json(
			'batches.cancel',
			$assoc_args,
			function () use ( $args, $assoc_args ): array {
				$this->require_capability( Capabilities::OPERATE_BATCHES );
				list( , $per_page ) = $this->pagination_args( $assoc_args );
				$batch_public_id    = $this->positional( $args, 0, 'batch_public_id' );
				$assessment         = ( new BatchActionAssessmentService() )->assess_cancel( $batch_public_id, $per_page );

				if ( ! $this->apply_requested( $assoc_args ) ) {
					return $assessment->to_array();
				}

				if ( ! $assessment->would_mutate ) {
					throw $this->refused_from_reasons( $assessment->blocked_reasons );
				}

				try {
					( new BatchCancellationService() )->cancel( $batch_public_id, $this->current_user_id() );
				} catch ( ValidationException $exception ) {
					unset( $exception );
					throw new CommandException( 'policy_refused', 'The requested PressQueue batch action is not currently allowed.', ExitCodes::REFUSED );
				}

				$batch = ( new BatchRepository() )->find_by_public_id( $batch_public_id );
				$items = null === $batch ? array() : array_slice( ( new BatchItemRepository() )->find_by_batch( $batch->public_id ), 0, $per_page );

				return ( new BatchActionAssessment( true, true, $batch_public_id, null === $batch ? null : $batch->preview_snapshot_hash, $assessment->affected_counts, array_map( array( RecordFormatter::class, 'batch_item_record' ), $items ), array(), array(), null === $batch ? null : $batch->preview_summary ) )->to_array();
			}
		);
	}

	/**
	 * Build and validate batch filters from CLI args.
	 *
	 * @param array<string,mixed> $assoc_args Args.
	 * @param string              $batch_type Batch type.
	 * @return array<string,mixed>
	 */
	private function batch_filters( array $assoc_args, string $batch_type ): array {
		$has_post_ids = isset( $assoc_args['post-ids'] ) && '' !== trim( (string) $assoc_args['post-ids'] );
		$has_json     = isset( $assoc_args['filters-json'] ) && '' !== trim( (string) $assoc_args['filters-json'] );

		if ( $has_post_ids === $has_json ) {
			throw new CommandException( 'invalid_arguments', 'Provide exactly one of --post-ids or --filters-json.', ExitCodes::INVALID );
		}

		if ( $has_post_ids ) {
			return array(
				'post_ids'       => $this->post_ids_arg( (string) $assoc_args['post-ids'] ),
				'post_statuses'  => array( 'publish' ),
				'post_types'     => array( 'post' ),
				'schema_version' => 1,
			);
		}

		$filters = $this->json_object_arg( $assoc_args, 'filters-json' );
		$this->assert_known_keys( $filters, self::FILTER_JSON_KEYS, '--filters-json' );
		$this->assert_required_schema_version( $filters, '--filters-json' );
		if ( array_key_exists( 'post_ids', $filters ) ) {
			throw new CommandException( 'schema_validation_failed', '--filters-json cannot include post_ids. Use --post-ids.', ExitCodes::INVALID );
		}

		if ( 'bulk' === $batch_type ) {
			foreach ( self::REPOST_ONLY_FILTER_JSON_KEYS as $key ) {
				if ( array_key_exists( $key, $filters ) ) {
					throw new CommandException( 'schema_validation_failed', '--filters-json contains a repost-only key for a bulk preview.', ExitCodes::INVALID );
				}
			}
		}

		return $filters;
	}

	/**
	 * Build and validate batch limits from CLI args.
	 *
	 * @param array<string,mixed> $assoc_args Args.
	 * @return array<string,mixed>
	 */
	private function batch_limits( array $assoc_args ): array {
		$limits = $this->json_object_arg( $assoc_args, 'limits-json' );
		if ( array() === $limits ) {
			return array(
				'candidate_limit'        => 20,
				'destination_item_limit' => 100,
				'schedule'               => array(
					'mode'           => 'inherit',
					'schema_version' => 1,
				),
				'schema_version'         => 1,
			);
		}

		$this->assert_known_keys( $limits, self::LIMIT_JSON_KEYS, '--limits-json' );
		$this->assert_required_schema_version( $limits, '--limits-json' );

		return $limits;
	}

	/**
	 * Parse comma-separated post IDs.
	 *
	 * @param string $value Raw value.
	 * @return array<int,int>
	 */
	private function post_ids_arg( string $value ): array {
		$ids = array();
		foreach ( explode( ',', $value ) as $part ) {
			$part = trim( $part );
			if ( '' === $part || ! ctype_digit( $part ) || (int) $part <= 0 ) {
				throw new CommandException( 'invalid_arguments', '--post-ids must be a comma-separated list of positive integers.', ExitCodes::INVALID );
			}

			$ids[] = (int) $part;
		}

		if ( count( $ids ) > 100 || count( array_unique( $ids ) ) !== count( $ids ) ) {
			throw new CommandException( 'invalid_arguments', '--post-ids must contain 1 through 100 unique IDs.', ExitCodes::INVALID );
		}

		return $ids;
	}

	/**
	 * Reject unknown JSON keys.
	 *
	 * @param array<string,mixed> $document Document.
	 * @param array<int,string>   $allowed Allowed keys.
	 * @param string              $option Option name.
	 */
	private function assert_known_keys( array $document, array $allowed, string $option ): void {
		if ( array() === $document ) {
			throw new CommandException( 'schema_validation_failed', $option . ' must not be empty.', ExitCodes::INVALID );
		}

		foreach ( array_keys( $document ) as $key ) {
			if ( ! is_string( $key ) || strlen( $key ) > 191 || ! in_array( $key, $allowed, true ) ) {
				throw new CommandException( 'schema_validation_failed', $option . ' contains an unsupported key.', ExitCodes::INVALID );
			}
		}

		if ( isset( $document['schema_version'] ) && 1 !== $document['schema_version'] ) {
			throw new CommandException( 'schema_validation_failed', $option . ' schema_version must be 1.', ExitCodes::INVALID );
		}
	}

	/**
	 * Require schema_version: 1 on provided JSON documents.
	 *
	 * @param array<string,mixed> $document Document.
	 * @param string              $option Option name.
	 */
	private function assert_required_schema_version( array $document, string $option ): void {
		if ( ! array_key_exists( 'schema_version', $document ) || 1 !== $document['schema_version'] ) {
			throw new CommandException( 'schema_validation_failed', $option . ' schema_version must be 1.', ExitCodes::INVALID );
		}
	}
}
