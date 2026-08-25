<?php
/**
 * Batch contract tests.
 *
 * @package PressQueue\Tests\Unit
 */

declare(strict_types=1);

namespace PressQueue\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use PressQueue\Batch\BatchLimitService;
use PressQueue\Batch\BatchPreviewService;
use PressQueue\Batch\RepostCriteriaService;
use PressQueue\Planning\EventNormalizer;
use PressQueue\Planning\NormalizedEvent;
use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\HashKeys;
use PressQueue\Storage\Record\BatchRecord;
use PressQueue\Storage\StorageEnums;
use PressQueue\Storage\ValidationException;
use WP_Post;

/**
 * @covers \PressQueue\Batch\BatchLimitService
 * @covers \PressQueue\Batch\BatchPreviewService
 * @covers \PressQueue\Batch\RepostCriteriaService
 * @covers \PressQueue\Planning\NormalizedEvent
 * @covers \PressQueue\Storage\HashKeys
 * @covers \PressQueue\Storage\StorageEnums
 */
final class BatchContractsTest extends TestCase {
	public function test_batch_enums_accept_supported_types_and_states(): void {
		StorageEnums::assert_rule_trigger( 'bulk' );
		StorageEnums::assert_rule_trigger( 'repost' );
		StorageEnums::assert_batch_type( 'bulk' );
		StorageEnums::assert_batch_type( 'repost' );
		StorageEnums::assert_batch_status( 'queueing' );
		StorageEnums::assert_batch_item_state( 'reserved' );

		self::assertContains( 'bulk', StorageEnums::RULE_TRIGGERS );
		self::assertContains( 'repost', StorageEnums::RULE_TRIGGERS );
	}

	public function test_occurrence_hash_uses_canonical_batch_item_document(): void {
		$batch_public_id      = '01K8Z8D0PKH2N8J7S3K5A9C6DE';
		$batch_item_public_id = '01K8Z8D0PKH2N8J7S3K5A9C6DF';
		$channel_public_id    = '01K8Z8D0PKH2N8J7S3K5A9C6DG';
		$semantic_hash        = str_repeat( 'a', 64 );

		self::assertSame(
			CanonicalJson::hash(
				'intent_occurrence',
				array(
					'batch_item_public_id'  => $batch_item_public_id,
					'batch_public_id'       => $batch_public_id,
					'channel_public_id'     => $channel_public_id,
					'mode'                  => 'bulk',
					'post_id'               => 42,
					'semantic_preview_hash' => $semantic_hash,
				)
			),
			HashKeys::intent_occurrence_key( 'bulk', $batch_public_id, $batch_item_public_id, 42, $channel_public_id, $semantic_hash )
		);
		self::assertNotSame(
			HashKeys::intent_occurrence_key( 'bulk', $batch_public_id, $batch_item_public_id, 42, $channel_public_id, $semantic_hash ),
			HashKeys::intent_occurrence_key( 'bulk', $batch_public_id, '01K8Z8D0PKH2N8J7S3K5A9C6DH', 42, $channel_public_id, $semantic_hash )
		);
	}

	public function test_repost_occurrence_uses_criteria_hash_slot(): void {
		$criteria_hash = str_repeat( 'b', 64 );
		$hash          = HashKeys::intent_occurrence_key( 'repost', '01K8Z8D0PKH2N8J7S3K5A9C6DE', '01K8Z8D0PKH2N8J7S3K5A9C6DF', 42, '01K8Z8D0PKH2N8J7S3K5A9C6DG', $criteria_hash );

		self::assertSame(
			CanonicalJson::hash(
				'intent_occurrence',
				array(
					'batch_item_public_id' => '01K8Z8D0PKH2N8J7S3K5A9C6DF',
					'batch_public_id'      => '01K8Z8D0PKH2N8J7S3K5A9C6DE',
					'channel_public_id'    => '01K8Z8D0PKH2N8J7S3K5A9C6DG',
					'mode'                 => 'repost',
					'post_id'              => 42,
					'repost_criteria_hash' => $criteria_hash,
				)
			),
			$hash
		);
	}

	public function test_ordinary_normalization_adds_occurrence_to_event_fields(): void {
		pressqueue_test_reset_wordpress_stubs();
		$GLOBALS['pressqueue_test_posts'][42] = new WP_Post(
			array(
				'ID'          => 42,
				'post_author' => 7,
				'post_status' => 'publish',
				'post_title'  => 'Hello',
			)
		);

		$event = ( new EventNormalizer() )->from_post_id( 42, 'runtime', 'unit', 'first_publish' );

		self::assertInstanceOf( NormalizedEvent::class, $event );
		self::assertArrayHasKey( 'intent_occurrence_key', $event->event_fields() );
		self::assertSame(
			HashKeys::ordinary_intent_occurrence_key( 'single-site', 42, 'first_publish', $event->trigger_fingerprint ),
			$event->intent_occurrence_key
		);
	}

	public function test_occurrence_changes_event_and_delivery_keys(): void {
		$occurrence = str_repeat( 'c', 64 );
		$base       = $this->event();
		$batch      = $this->event( $occurrence );

		self::assertNotSame( $base->event_key, $batch->event_key );
		self::assertArrayNotHasKey( 'intent_occurrence_key', $base->event_fields() );
		self::assertSame( $occurrence, $batch->event_fields()['intent_occurrence_key'] );
		self::assertSame( $occurrence, $batch->to_array()['intent_occurrence_key'] );

		self::assertNotSame(
			HashKeys::delivery_key( 'dry_run', 'buffer', '01K8Z8D0PKH2N8J7S3K5A9C6DE', '01K8Z8D0PKH2N8J7S3K5A9C6DF', 'remote-1', 42, str_repeat( 'a', 64 ), 'bulk', '01K8Z8D0PKH2N8J7S3K5A9C6DG', 1 ),
			HashKeys::delivery_key( 'dry_run', 'buffer', '01K8Z8D0PKH2N8J7S3K5A9C6DE', '01K8Z8D0PKH2N8J7S3K5A9C6DF', 'remote-1', 42, str_repeat( 'a', 64 ), 'bulk', '01K8Z8D0PKH2N8J7S3K5A9C6DG', 1, 'immediate', null, null, $occurrence )
		);
	}

	public function test_batch_limits_refuse_invalid_staggering_and_multi_immediate_override(): void {
		$service = new BatchLimitService();

		$this->expectException( ValidationException::class );
		$service->normalize(
			array(
				'schema_version' => 1,
				'schedule'       => array(
					'first_due_at_utc' => '2026-08-25 12:00:00',
					'interval_minutes' => 4,
					'mode'             => 'staggered_explicit_time',
					'schema_version'   => 1,
					'timezone'         => 'UTC',
				),
			)
		);
	}

	public function test_multi_item_immediate_override_returns_policy_error_signal(): void {
		$service = new BatchLimitService();
		$limits  = $service->normalize(
			array(
				'schema_version' => 1,
				'schedule'       => array(
					'mode'           => 'immediate',
					'schema_version' => 1,
				),
			)
		);

		self::assertSame( 'batch_multi_item_schedule_not_allowed', $service->schedule_override_for_item( $limits, 0, 2 )['error'] );
	}

	public function test_staggered_schedule_requires_valid_timezone(): void {
		$this->expectException( ValidationException::class );

		( new BatchLimitService() )->normalize(
			array(
				'schema_version' => 1,
				'schedule'       => array(
					'first_due_at_utc' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
					'interval_minutes' => 15,
					'mode'             => 'staggered_explicit_time',
					'schema_version'   => 1,
					'timezone'         => 'Mars/Base',
				),
			)
		);
	}

	public function test_staggered_schedule_rejects_later_item_beyond_horizon(): void {
		$service = new BatchLimitService();
		$limits  = $service->normalize(
			array(
				'schema_version' => 1,
				'schedule'       => array(
					'first_due_at_utc' => gmdate( 'Y-m-d H:i:s', time() + ( 365 * DAY_IN_SECONDS ) - MINUTE_IN_SECONDS ),
					'interval_minutes' => 15,
					'mode'             => 'staggered_explicit_time',
					'schema_version'   => 1,
					'timezone'         => 'UTC',
				),
			)
		);

		$this->expectException( ValidationException::class );
		$service->schedule_override_for_item( $limits, 1, 2 );
	}

	public function test_repost_criteria_accept_only_prior_accepted_state(): void {
		$this->expectException( ValidationException::class );

		( new RepostCriteriaService() )->normalize(
			array(
				'prior_delivery_states' => array( 'failed' ),
			)
		);
	}

	public function test_exact_post_filter_rejects_missing_post_before_storage(): void {
		pressqueue_test_reset_wordpress_stubs();

		$service = ( new \ReflectionClass( BatchPreviewService::class ) )->newInstanceWithoutConstructor();
		$method  = new \ReflectionMethod( BatchPreviewService::class, 'validate_filters_before_storage' );
		$method->setAccessible( true );

		$this->expectException( ValidationException::class );
		$method->invoke(
			$service,
			array(
				'post_ids'      => array( 404 ),
				'post_statuses' => array( 'publish' ),
				'post_types'    => array( 'post' ),
			)
		);
	}

	public function test_batch_preview_identity_recomputes_reviewed_delivery_hashes_with_occurrence(): void {
		$batch   = new BatchRecord(
			1,
			'01K8Z8D0PKH2N8J7S3K5A9C6DE',
			'bulk',
			'previewed',
			1,
			null,
			array( 'schema_version' => 1 ),
			array( 'schema_version' => 1 ),
			str_repeat( 'd', 64 ),
			array( 'schema_version' => 1 ),
			new DateTimeImmutable( '2026-08-25 12:00:00' ),
			null,
			null,
			null,
			1,
			new DateTimeImmutable( '2026-08-25 11:30:00' ),
			new DateTimeImmutable( '2026-08-25 11:30:00' )
		);
		$item    = $this->preview_item();
		$service = ( new \ReflectionClass( BatchPreviewService::class ) )->newInstanceWithoutConstructor();
		$method  = new \ReflectionMethod( BatchPreviewService::class, 'apply_item_identity' );
		$method->setAccessible( true );

		$prepared   = $method->invoke( $service, $batch, $item, '01K8Z8D0PKH2N8J7S3K5A9C6DF' );
		$occurrence = $prepared['intent_occurrence_key'];

		self::assertSame( $occurrence, $prepared['preview_item']['delivery']['payload_preview']['intent_occurrence_key'] );
		self::assertSame(
			HashKeys::delivery_key( 'dry_run', 'buffer', '01K8Z8D0PKH2N8J7S3K5A9C6DG', '01K8Z8D0PKH2N8J7S3K5A9C6DH', 'remote-1', 42, str_repeat( 'a', 64 ), 'bulk', '01K8Z8D0PKH2N8J7S3K5A9C6DJ', 1, 'immediate', null, null, $occurrence ),
			$prepared['preview_item']['delivery']['delivery_key']
		);
		self::assertSame(
			HashKeys::payload_hash(
				array(
					'canonical_url'          => 'https://example.test/post',
					'channel_public_id'      => '01K8Z8D0PKH2N8J7S3K5A9C6DH',
					'connection_public_id'   => '01K8Z8D0PKH2N8J7S3K5A9C6DG',
					'final_encoded_text'     => 'Hello',
					'intent_occurrence_key'  => $occurrence,
					'media_identity_hash'    => null,
					'message'                => 'Hello',
					'payload_schema_version' => 2,
					'provider'               => 'buffer',
					'remote_channel_id'      => 'remote-1',
					'revision_fingerprint'   => str_repeat( 'a', 64 ),
					'rule_public_id'         => '01K8Z8D0PKH2N8J7S3K5A9C6DJ',
					'rule_version'           => 1,
					'scheduled_for_utc'      => null,
					'schedule_slot'          => 'immediate',
					'trigger'                => 'bulk',
				)
			),
			$prepared['preview_item']['delivery']['payload_hash']
		);
	}

	private function event( ?string $intent_occurrence_key = null ): NormalizedEvent {
		return new NormalizedEvent(
			'preview',
			'test',
			'bulk',
			42,
			'post',
			'publish',
			7,
			'https://example.test/post',
			'2026-08-24 12:00:00',
			'2026-08-24 12:05:00',
			array(),
			array(),
			array(
				'title' => 'Hello',
			),
			null,
			str_repeat( 'a', 64 ),
			null,
			null,
			null,
			null,
			null,
			null,
			$intent_occurrence_key
		);
	}

	/**
	 * Return a minimal queueable preview item.
	 *
	 * @return array<string,mixed>
	 */
	private function preview_item(): array {
		$connection = '01K8Z8D0PKH2N8J7S3K5A9C6DG';
		$channel    = '01K8Z8D0PKH2N8J7S3K5A9C6DH';
		$rule       = '01K8Z8D0PKH2N8J7S3K5A9C6DJ';
		$revision   = str_repeat( 'a', 64 );
		$semantic   = str_repeat( 'e', 64 );

		return array(
			'channel_public_id'     => $channel,
			'position'              => 0,
			'post_id'               => 42,
			'preview_item'          => array(
				'decision'       => array(
					'event_key'       => str_repeat( '1', 64 ),
					'result'          => 'included',
					'rule_public_id'  => $rule,
					'rule_version'    => 1,
					'scope_public_id' => $channel,
					'scope_type'      => 'channel',
				),
				'delivery'       => array(
					'delivery_key'    => str_repeat( '2', 64 ),
					'delivery_mode'   => 'dry_run',
					'payload_hash'    => str_repeat( '3', 64 ),
					'payload_preview' => array(
						'canonical_url'          => 'https://example.test/post',
						'channel_public_id'      => $channel,
						'connection_public_id'   => $connection,
						'final_encoded_text'     => 'Hello',
						'message'                => 'Hello',
						'payload_schema_version' => 2,
						'post_id'                => 42,
						'provider'               => 'buffer',
						'remote_channel_id'      => 'remote-1',
						'revision_fingerprint'   => $revision,
						'rule_public_id'         => $rule,
						'rule_version'           => 1,
						'schedule_slot'          => 'immediate',
						'trigger'                => 'bulk',
					),
				),
				'event'          => array(
					'mode'                 => 'preview',
					'post_id'              => 42,
					'post_status'          => 'publish',
					'post_type'            => 'post',
					'revision_fingerprint' => $revision,
					'trigger'              => 'bulk',
					'user_id'              => 7,
				),
				'schema_version' => 1,
			),
			'reason'                => array(
				'code'           => 'queueable_destination',
				'schema_version' => 1,
			),
			'semantic_preview_hash' => $semantic,
			'state'                 => 'previewed',
		);
	}
}
