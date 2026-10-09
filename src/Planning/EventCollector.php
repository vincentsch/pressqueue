<?php
/**
 * WordPress event collector.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Planning;

use PressQueue\Runtime\RuntimeDisabledLatch;
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
	 * @var array<string,array{post_id:int,blog_id:int,sources:array<string,bool>,triggers:array<string,bool>}>
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
		add_action( 'pressqueue_planning_retry', array( self::class, 'retry_planning' ), 10, 4 );
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
		if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) {
			update_post_meta( (int) $post->ID, EventNormalizer::META_FIRST_PUBLISH_SEEN_AT, gmdate( 'Y-m-d H:i:s' ) );
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
		if ( RuntimeDisabledLatch::is_closed() ) {
			self::$captured = array();
			return;
		}

		if ( array() === self::$captured ) {
			return;
		}

		$captured       = self::$captured;
		self::$captured = array();

		if ( ! Database::has_global_database() ) {
			return;
		}

		foreach ( $captured as $intent ) {
			$post_id  = $intent['post_id'];
			$switched = function_exists( 'get_current_blog_id' ) && get_current_blog_id() !== $intent['blog_id'];
			if ( $switched ) {
				switch_to_blog( $intent['blog_id'] );
			}
			try {
				$trigger = self::select_trigger( $post_id, $intent['triggers'] );
				if ( null !== $trigger ) {
					self::process_intent( $post_id, self::source_label( $intent['sources'] ), $trigger, 0 );
				}
			} finally {
				if ( $switched ) {
					restore_current_blog();
				}
			}
		}
	}

	/**
	 * Retry a failed local planning pass against current policy.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $source Original hook source.
	 * @param string $trigger Event trigger.
	 * @param int    $attempt Retry count.
	 */
	public static function retry_planning( int $post_id, string $source, string $trigger, int $attempt ): void {
		self::process_intent( $post_id, $source, $trigger, $attempt );
	}

	/**
	 * Plan one captured intent or leave a redacted failure notice and retry.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $source Hook source.
	 * @param string $trigger Event trigger.
	 * @param int    $attempt Retry count.
	 */
	private static function process_intent( int $post_id, string $source, string $trigger, int $attempt ): void {
		try {
			if ( RuntimeDisabledLatch::is_closed() || ! ( new SchemaStatus() )->is_ready_for_planning() ) {
				throw new \PressQueue\Storage\StorageUnavailableException( 'Planning is temporarily unavailable.' );
			}
			$rules      = ( new RuleRepository() )->find_enabled_for_trigger( $trigger );
			$normalizer = new EventNormalizer();
			$event      = $normalizer->from_post_id( $post_id, 'runtime', $source, $trigger, $normalizer->metadata_allowlist_for_rules( $rules ) );
			if ( null === $event ) {
				$failure = get_option( 'pressqueue_last_planning_failure', array() );
				if ( is_array( $failure ) && ( $failure['post_id'] ?? null ) === $post_id ) {
					delete_option( 'pressqueue_last_planning_failure' );
				}
				return;
			}
			if ( $event->is_backdated_automatic_publication() ) {
				self::record_event_notice( $event, 'skipped', 'backdated_publication', 'Automatic first publication is skipped for posts dated more than one day ago. Use a reviewed manual batch.' );
				self::record_runtime_metadata( $event );
				return;
			}
			if ( 'update' !== $trigger || ! self::update_matches_recorded_facts( $event ) ) {
				( new Planner() )->plan_runtime( $event );
			}
			self::record_runtime_metadata( $event );
			$failure = get_option( 'pressqueue_last_planning_failure', array() );
			if ( is_array( $failure ) && ( $failure['post_id'] ?? null ) === $post_id ) {
				delete_option( 'pressqueue_last_planning_failure' );
			}
		} catch ( \Throwable $exception ) {
			unset( $exception );
			if ( $attempt >= 5 && isset( $event ) ) {
				try {
					self::record_event_notice( $event, 'policy_error', 'planning_retry_exhausted', 'Post planning failed after five retries. Preview the post before creating a manual batch.' );
				} catch ( \Throwable $storage_error ) {
					unset( $storage_error );
				}
			}
			update_option(
				'pressqueue_last_planning_failure',
				array(
					'post_id'     => $post_id,
					'retry_count' => $attempt,
					'at_utc'      => gmdate( 'Y-m-d H:i:s' ),
				),
				false
			);
			try {
				if ( $attempt < 5 && function_exists( 'as_schedule_single_action' ) && ( new \PressQueue\Scheduler\ActionSchedulerQueue() )->is_ready() ) {
					as_schedule_single_action( time() + min( 3600, 60 * ( 2 ** $attempt ) ), 'pressqueue_planning_retry', array( $post_id, $source, $trigger, $attempt + 1 ), \PressQueue\Scheduler\ActionSchedulerQueue::GROUP, false );
				}
			} catch ( \Throwable $scheduler_error ) {
				unset( $scheduler_error );
			}
		}
	}

	/**
	 * Keep a durable explanation for a skipped or failed publishing hook.
	 *
	 * @param NormalizedEvent $event Event facts.
	 * @param string          $result Decision result.
	 * @param string          $code Reason code.
	 * @param string          $message Plain explanation.
	 */
	private static function record_event_notice( NormalizedEvent $event, string $result, string $code, string $message ): void {
		( new \PressQueue\Storage\Repository\DecisionRepository() )->create(
			array_merge(
				$event->event_fields(),
				array(
					'author_id'       => $event->author_id,
					'event_key'       => $event->event_key,
					'result'          => $result,
					'scope_type'      => 'global',
					'scope_public_id' => null,
					'reasons'         => array( ReasonFactory::make( $code, $message, 'error', 'post', 'planning', null, null ) ),
					'snapshot'        => array(
						'schema_version' => 1,
						'post_id'        => $event->post_id,
						'classification' => $code,
					),
				)
			)
		);
	}

	/**
	 * Capture an event intent.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $trigger Trigger.
	 * @param string $source Source.
	 */
	private static function capture( int $post_id, string $trigger, string $source ): void {
		if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) {
			update_post_meta( $post_id, EventNormalizer::META_FIRST_PUBLISH_SEEN_AT, gmdate( 'Y-m-d H:i:s' ) );
			return;
		}
		if ( RuntimeDisabledLatch::is_closed() ) {
			return;
		}

		if ( $post_id <= 0 ) {
			return;
		}

		$blog_id                           = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1;
		$key                               = $blog_id . ':' . $post_id;
		self::$captured[ $key ]['post_id'] = $post_id;
		self::$captured[ $key ]['blog_id'] = $blog_id;
		self::$captured[ $key ]['triggers'][ $trigger ] = true;
		self::$captured[ $key ]['sources'][ $source ]   = true;
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
			return self::first_publish_seen( $post_id ) ? 'update' : 'scheduled_publication';
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
		if ( $event->previous_trigger_fingerprint === $event->trigger_fingerprint || $event->previous_trigger_fingerprint === $event->legacy_trigger_fingerprint ) {
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
