<?php
/**
 * Nondurable per-post editor preview service.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Editorial;

use PressQueue\Planning\EventNormalizer;
use PressQueue\Planning\Planner;
use PressQueue\Planning\PlanningResult;
use PressQueue\Storage\Repository\RuleRepository;
use PressQueue\Storage\StorageEnums;
use PressQueue\Storage\ValidationException;

/**
 * Builds exact dirty-editor previews without writing decisions or deliveries.
 */
final class PostControlPreviewService {
	/**
	 * Rule repository.
	 *
	 * @var RuleRepository
	 */
	private RuleRepository $rules;

	/**
	 * Event normalizer.
	 *
	 * @var EventNormalizer
	 */
	private EventNormalizer $normalizer;

	/**
	 * Planner.
	 *
	 * @var Planner
	 */
	private Planner $planner;

	/**
	 * Constructor.
	 *
	 * @param RuleRepository|null  $rules Rule repository.
	 * @param EventNormalizer|null $normalizer Event normalizer.
	 * @param Planner|null         $planner Planner.
	 */
	public function __construct( ?RuleRepository $rules = null, ?EventNormalizer $normalizer = null, ?Planner $planner = null ) {
		$this->rules      = $rules ?? new RuleRepository();
		$this->normalizer = $normalizer ?? new EventNormalizer();
		$this->planner    = $planner ?? new Planner();
	}

	/**
	 * Preview a dirty editor snapshot.
	 *
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $snapshot Dirty editor snapshot.
	 * @param array<string,mixed> $control_bundle Draft control bundle.
	 * @param string|null         $requested_trigger Optional explicit simulated trigger.
	 * @return PlanningResult
	 */
	public function preview( int $post_id, array $snapshot, array $control_bundle, ?string $requested_trigger = null ): PlanningResult {
		PostControlAuthorization::require_supported_post( $post_id );

		$bundle  = PostControlMetadata::normalize_bundle( $control_bundle );
		$trigger = $this->select_trigger( $post_id, $snapshot, $requested_trigger );
		$rules   = $this->rules->find_enabled_for_trigger( $trigger );
		$event   = $this->normalizer->from_dirty_snapshot(
			$post_id,
			'editor_preview',
			$trigger,
			$snapshot,
			$bundle,
			$this->normalizer->metadata_allowlist_for_rules( $rules )
		);

		if ( null === $event ) {
			throw new ValidationException( 'Editor snapshot cannot be previewed for this trigger.' );
		}

		return $this->planner->preview( $event, $rules );
	}

	/**
	 * Build the route response shape for a preview.
	 *
	 * @param PlanningResult $result Preview result.
	 * @return array<string,mixed>
	 */
	public function response( PlanningResult $result ): array {
		$event = $result->event->to_array();

		return array(
			'control_bundle_hash'           => $event['control_bundle_hash'],
			'decisions'                     => $result->decisions,
			'deliveries'                    => $result->deliveries,
			'delivery_revision_fingerprint' => $event['revision_fingerprint'],
			'durable'                       => false,
			'event_mode'                    => $result->event->mode,
			'normalized_draft_facts'        => $event,
			'selected_trigger'              => $result->event->trigger,
			'trigger_fingerprint'           => $event['trigger_fingerprint'],
		);
	}

	/**
	 * Select the simulated runtime trigger for the dirty snapshot.
	 *
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $snapshot Dirty editor snapshot.
	 * @param string|null         $requested_trigger Requested trigger.
	 * @return string
	 */
	private function select_trigger( int $post_id, array $snapshot, ?string $requested_trigger ): string {
		if ( null !== $requested_trigger && '' !== $requested_trigger ) {
			StorageEnums::assert_rule_trigger( $requested_trigger );
			return $requested_trigger;
		}

		$status = isset( $snapshot['post_status'] ) && is_string( $snapshot['post_status'] )
			? $snapshot['post_status']
			: '';

		if ( 'future' === $status ) {
			return 'scheduled_publication';
		}

		$current_status = '';
		if ( function_exists( 'get_post' ) ) {
			$post = get_post( $post_id );
			if ( $post instanceof \WP_Post ) {
				$current_status = (string) $post->post_status;
			}
		}

		if ( 'publish' === $current_status && 'publish' === $status ) {
			return 'update';
		}

		return 'first_publish';
	}
}
