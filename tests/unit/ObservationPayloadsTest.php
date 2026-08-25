<?php
/**
 * Observation payload tests.
 *
 * @package PressQueue\Tests\Unit
 */

declare(strict_types=1);

namespace PressQueue\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use PressQueue\Storage\ObservationPayloads;
use PressQueue\Storage\Record\DecisionRecord;
use PressQueue\Storage\Record\DeliveryEventRecord;

/**
 * @covers \PressQueue\Storage\ObservationPayloads
 */
final class ObservationPayloadsTest extends TestCase {
	public function test_decision_payload_exposes_stable_metadata_without_snapshot_body(): void {
		$payload = ObservationPayloads::decision(
			new DecisionRecord(
				1,
				'01K3KGXB71P2MR4K9A7V6Q5C8D',
				str_repeat( 'a', 64 ),
				str_repeat( 'b', 64 ),
				null,
				'channel',
				'01K3KGXB71P2MR4K9A7V6Q5C8E',
				42,
				'post',
				'publish',
				7,
				str_repeat( 'c', 64 ),
				'update',
				'runtime',
				10,
				'01K3KGXB71P2MR4K9A7V6Q5C8F',
				2,
				'included',
				array( 'rule_matched' ),
				array( 'body' => 'Full post body' ),
				str_repeat( 'd', 64 ),
				new DateTimeImmutable( '2026-08-25 12:00:00', new DateTimeZone( 'UTC' ) )
			)
		);

		self::assertSame( 1, $payload['schema_version'] );
		self::assertSame( '2026-08-25T12:00:00Z', $payload['created_at_utc'] );
		self::assertSame( str_repeat( 'd', 64 ), $payload['snapshot_hash'] );
		self::assertArrayNotHasKey( 'snapshot', $payload );
		self::assertArrayNotHasKey( 'reasons', $payload );
		self::assertNotContains( 'Full post body', $payload );
	}

	public function test_delivery_event_payload_exposes_redacted_state_without_actor_user_or_detail(): void {
		$payload = ObservationPayloads::delivery_event(
			new DeliveryEventRecord(
				1,
				'01K3KGXB71P2MR4K9A7V6Q5C8D',
				2,
				'01K3KGXB71P2MR4K9A7V6Q5C8E',
				'provider_result',
				'queued',
				'failed',
				1,
				123,
				'system',
				99,
				'permanent_failure',
				'not_retryable',
				array(
					'body'           => 'Full provider response',
					'classification' => 'fixture_error',
				),
				new DateTimeImmutable( '2026-08-25 12:01:00', new DateTimeZone( 'UTC' ) )
			),
			str_repeat( 'e', 64 )
		);

		self::assertSame( 1, $payload['schema_version'] );
		self::assertSame( 'fixture_error', $payload['classification'] );
		self::assertSame( 'system', $payload['actor_type'] );
		self::assertArrayNotHasKey( 'actor_user_id', $payload );
		self::assertArrayNotHasKey( 'detail', $payload );
		self::assertNotContains( 'Full provider response', $payload );
	}
}
