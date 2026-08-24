<?php
/**
 * WordPress event collector.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Planning;

use PressQueue\Storage\Database;
use PressQueue\Storage\Repository\RuleRepository;
use PressQueue\Storage\SchemaStatus;

/**
 * Coalesces WordPress publishing hooks into one shutdown planning pass.
 */
final class EventCollector {
	/**
	 * Captured post intents.
	 *
	 * @var array<int,array{sources:array<string,bool>,triggers:array<string,bool>}>
	 */
	private static array $captured = array();

	/**
	 * Register WordPress hooks.
	 */
	public static function register_hooks(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( 'transition_post_status', array( self::class, 'capture_status_transition' ), 10, 3 );
		add_action( 'save_post', array( self::class, 'capture_save' ), 20, 3 );
		add_action( 'rest_after_insert_post', array( self::class, 'capture_rest_insert' ), 10, 3 );
		add_action( 'xmlrpc_call_success_wp_newPost', array( self::class, 'capture_xmlrpc_publish' ), 10, 2 );
		add_action( 'xmlrpc_call_success_wp_editPost', array( self::class, 'capture_xmlrpc_publish' ), 10, 2 );
		add_action( 'publish_future_post', array( self::class, 'capture_scheduled_callback' ), 10, 1 );
		add_action( 'shutdown', array( self::class, 'finalize' ), 0, 0 );
	}

	/**
	 * Capture a status transition.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post Post.
	 */
	public static function capture_status_transition( string $new_status, string $old_status, $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if ( 'publish' !== $new_status ) {
			return;
		}

		if ( 'future' === $old_status ) {
			self::capture( (int) $post->ID, 'scheduled_publication', 'status_transition' );
			return;
		}

		if ( 'publish' === $old_status ) {
			self::capture( (int) $post->ID, 'update', 'status_transition' );
			return;
		}

		if ( ! self::first_publish_seen( (int) $post->ID ) ) {
			self::capture( (int) $post->ID, 'first_publish', 'status_transition' );
		}
	}

	/**
	 * Capture direct save and editor flows.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post.
	 * @param bool     $update Whether this is an update.
	 */
	public static function capture_save( int $post_id, $post, bool $update ): void {
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return;
		}

		if ( $update ) {
			self::capture( $post_id, 'update', 'wp_insert_post' );
		}
	}

	/**
	 * Capture REST-created or updated posts.
	 *
	 * @param \WP_Post         $post Post.
	 * @param \WP_REST_Request $request Request.
	 * @param bool             $creating Whether created.
	 */
	public static function capture_rest_insert( $post, $request, bool $creating ): void {
		unset( $request, $creating );

		if ( $post instanceof \WP_Post && 'publish' === $post->post_status ) {
			self::capture( (int) $post->ID, 'update', 'rest' );
		}
	}

	/**
	 * Capture XML-RPC publication hooks.
	 *
	 * @param string           $method XML-RPC method.
	 * @param array<int,mixed> $args Arguments.
	 */
	public static function capture_xmlrpc_publish( string $method, array $args ): void {
		unset( $method );

		$post_id = isset( $args[0] ) ? (int) $args[0] : 0;

		if ( $post_id > 0 ) {
			self::capture( $post_id, 'update', 'xmlrpc' );
		}
	}

	/**
	 * Capture scheduled publication callback.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function capture_scheduled_callback( int $post_id ): void {
		self::capture( $post_id, 'scheduled_publication', 'scheduled_callback' );
	}

	/**
	 * Finalize captured events after WordPress state has settled.
	 */
	public static function finalize(): void {
		if ( array() === self::$captured ) {
			return;
		}

		$captured       = self::$captured;
		self::$captured = array();

		if ( ! Database::has_global_database() ) {
			return;
		}

		$status = new SchemaStatus();
		if ( ! $status->is_ready_for_planning() ) {
			return;
		}

		$rules      = new RuleRepository();
		$normalizer = new EventNormalizer();
		$planner    = new Planner();

		foreach ( $captured as $post_id => $intent ) {
			$trigger = self::select_trigger( (int) $post_id, $intent['triggers'] );

			if ( null === $trigger ) {
				continue;
			}

			try {
				$rules_for_trigger = $rules->find_enabled_for_trigger( $trigger );
				$allowlist         = $normalizer->metadata_allowlist_for_rules( $rules_for_trigger );
				$source            = self::source_label( $intent['sources'] );
				$event             = $normalizer->from_post_id( (int) $post_id, 'runtime', $source, $trigger, $allowlist );

				if ( null === $event ) {
					continue;
				}

				if ( 'update' === $trigger && self::update_matches_recorded_facts( $event ) ) {
					self::record_runtime_metadata( $event );
					continue;
				}

				$planner->plan_runtime( $event );
				self::record_runtime_metadata( $event );
			} catch ( \Throwable $exception ) {
				unset( $exception );
				continue;
			}
		}
	}

	/**
	 * Capture an event intent.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $trigger Trigger.
	 * @param string $source Source.
	 */
	private static function capture( int $post_id, string $trigger, string $source ): void {
		if ( $post_id <= 0 ) {
			return;
		}

		self::$captured[ $post_id ]['triggers'][ $trigger ] = true;
		self::$captured[ $post_id ]['sources'][ $source ]   = true;
	}

	/**
	 * Select the highest-precedence trigger for a post.
	 *
	 * @param int                $post_id Post ID.
	 * @param array<string,bool> $triggers Triggers.
	 * @return string|null
	 */
	private static function select_trigger( int $post_id, array $triggers ): ?string {
		if ( ! empty( $triggers['scheduled_publication'] ) ) {
			return 'scheduled_publication';
		}

		if ( ! empty( $triggers['first_publish'] ) && ! self::first_publish_seen( $post_id ) ) {
			return 'first_publish';
		}

		if ( ! empty( $triggers['update'] ) ) {
			return 'update';
		}

		return null;
	}

	/**
	 * Check whether first publish has been recorded.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function first_publish_seen( int $post_id ): bool {
		$value = function_exists( 'get_post_meta' ) ? get_post_meta( $post_id, EventNormalizer::META_FIRST_PUBLISH_SEEN_AT, true ) : '';

		return is_string( $value ) && '' !== $value;
	}

	/**
	 * Check whether an update already matches recorded trigger facts.
	 *
	 * @param NormalizedEvent $event Event.
	 * @return bool
	 */
	private static function update_matches_recorded_facts( NormalizedEvent $event ): bool {
		if ( $event->previous_trigger_fingerprint === $event->trigger_fingerprint ) {
			return true;
		}

		return null === $event->previous_trigger_fingerprint
			&& $event->previous_runtime_fingerprint === $event->legacy_runtime_fingerprint;
	}

	/**
	 * Record runtime fingerprints and first-publish metadata.
	 *
	 * @param NormalizedEvent $event Event.
	 */
	private static function record_runtime_metadata( NormalizedEvent $event ): void {
		if ( ! function_exists( 'update_post_meta' ) ) {
			return;
		}

		if ( in_array( $event->trigger, array( 'first_publish', 'scheduled_publication' ), true ) && ! self::first_publish_seen( $event->post_id ) ) {
			update_post_meta( $event->post_id, EventNormalizer::META_FIRST_PUBLISH_SEEN_AT, gmdate( 'Y-m-d H:i:s' ) );
		}

		update_post_meta( $event->post_id, EventNormalizer::META_LAST_TRIGGER_FINGERPRINT, $event->trigger_fingerprint );
		update_post_meta( $event->post_id, EventNormalizer::META_LAST_DELIVERY_REVISION_FINGERPRINT, $event->revision_fingerprint );
	}

	/**
	 * Build source label.
	 *
	 * @param array<string,bool> $sources Sources.
	 * @return string
	 */
	private static function source_label( array $sources ): string {
		$labels = array_keys( $sources );
		sort( $labels, SORT_STRING );

		return implode( ',', $labels );
	}
}
