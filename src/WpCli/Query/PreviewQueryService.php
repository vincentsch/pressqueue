<?php
/**
 * WP-CLI preview query service.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\WpCli\Query;

use PressQueue\Planning\EventNormalizer;
use PressQueue\Planning\Planner;
use PressQueue\Planning\PlanningResult;
use PressQueue\Storage\Database;
use PressQueue\Storage\PublicIds;
use PressQueue\Storage\Repository\RuleRepository;
use PressQueue\Storage\StorageEnums;
use PressQueue\Storage\ValidationException;

/**
 * Runs nondurable planner previews.
 */
final class PreviewQueryService {
	/**
	 * Database wrapper.
	 *
	 * @var Database
	 */
	private Database $database;

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
	 * @param Database|null $database Database.
	 */
	public function __construct( ?Database $database = null ) {
		$this->database   = $database ?? Database::from_globals();
		$this->rules      = new RuleRepository( $this->database );
		$this->normalizer = new EventNormalizer();
		$this->planner    = new Planner( $this->database );
	}

	/**
	 * Preview enabled rules for one stored post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $trigger Trigger.
	 * @return array<string,mixed>
	 */
	public function post_preview( int $post_id, string $trigger ): array {
		$this->assert_post_readable( $post_id );
		StorageEnums::assert_rule_trigger( $trigger );
		$rules     = $this->rules->find_enabled_for_trigger( $trigger );
		$allowlist = $this->normalizer->metadata_allowlist_for_rules( $rules );
		$event     = $this->normalizer->from_post_id( $post_id, 'preview', 'wp_cli', $trigger, $allowlist );
		if ( null === $event ) {
			throw new ValidationException( 'Post cannot be previewed.' );
		}

		return $this->format_result( $this->planner->preview( $event, $rules ) );
	}

	/**
	 * Preview one rule against a stored post.
	 *
	 * @param string $rule_public_id Rule public ID.
	 * @param int    $post_id Post ID.
	 * @param string $trigger Trigger.
	 * @return array<string,mixed>
	 */
	public function rule_preview( string $rule_public_id, int $post_id, string $trigger ): array {
		PublicIds::assert( $rule_public_id );
		$this->assert_post_readable( $post_id );
		StorageEnums::assert_rule_trigger( $trigger );
		$rule = $this->rules->find_by_public_id( $rule_public_id );
		if ( null === $rule ) {
			throw new ValidationException( 'Rule does not exist.' );
		}

		$event = $this->normalizer->from_post_id( $post_id, 'preview', 'wp_cli', $trigger, $rule->custom_field_allowlist );
		if ( null === $event ) {
			throw new ValidationException( 'Post cannot be previewed.' );
		}

		return $this->format_result( $this->planner->preview( $event, array( $rule ) ) );
	}

	/**
	 * Assert the target post exists and can be read by the current WP-CLI user.
	 *
	 * @param int $post_id Post ID.
	 */
	private function assert_post_readable( int $post_id ): void {
		if ( $post_id <= 0 || ! function_exists( 'get_post' ) || ! get_post( $post_id ) instanceof \WP_Post ) {
			throw new ValidationException( 'Post does not exist.' );
		}

		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'read_post', $post_id ) ) {
			throw new ValidationException( 'Post is not readable.' );
		}
	}

	/**
	 * Format a planning result.
	 *
	 * @param PlanningResult $result Result.
	 * @return array<string,mixed>
	 */
	private function format_result( PlanningResult $result ): array {
		return array(
			'decisions'      => array_map( array( $this, 'format_decision' ), $result->decisions ),
			'deliveries'     => array_map( array( $this, 'format_delivery' ), $result->deliveries ),
			'durable'        => $result->durable,
			'event'          => $result->event->to_array(),
			'schema_version' => 1,
		);
	}

	/**
	 * Format a decision preview.
	 *
	 * @param array<string,mixed> $decision Decision.
	 * @return array<string,mixed>
	 */
	private function format_decision( array $decision ): array {
		unset( $decision['stored_record'] );

		return $decision;
	}

	/**
	 * Format a delivery preview without rendered content or media URLs.
	 *
	 * @param array<string,mixed> $delivery Delivery.
	 * @return array<string,mixed>
	 */
	private function format_delivery( array $delivery ): array {
		$payload = isset( $delivery['payload_preview'] ) && is_array( $delivery['payload_preview'] ) ? $delivery['payload_preview'] : array();
		unset( $delivery['payload_preview'] );
		$delivery['payload_summary'] = array(
			'adapter_encoding_version'  => $payload['adapter_encoding_version'] ?? null,
			'bound_capability_hash'     => $payload['bound_capability_hash'] ?? null,
			'final_encoded_text_length' => $payload['final_encoded_text_length'] ?? null,
			'media_identity_hash'       => $payload['media_identity_hash'] ?? null,
			'payload_hash'              => $delivery['payload_hash'] ?? null,
			'payload_schema_version'    => $payload['payload_schema_version'] ?? null,
			'schedule_slot'             => $payload['schedule_slot'] ?? null,
			'scheduled_for_utc'         => isset( $payload['scheduled_for_utc'] ) && is_string( $payload['scheduled_for_utc'] ) ? RecordFormatter::rfc3339_value( $payload['scheduled_for_utc'] ) : null,
		);

		return $delivery;
	}
}
