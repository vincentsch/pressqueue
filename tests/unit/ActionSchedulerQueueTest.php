<?php
/**
 * Action Scheduler queue tests.
 *
 * @package PressQueue\Tests\Unit
 */

declare(strict_types=1);

namespace PressQueue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PressQueue\Scheduler\ActionSchedulerQueue;

/**
 * @covers \PressQueue\Scheduler\ActionSchedulerQueue
 */
final class ActionSchedulerQueueTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		pressqueue_test_reset_wordpress_stubs();
	}

	public function test_scheduler_init_ensures_recurring_sweep_even_when_repair_hook_is_supported(): void {
		$GLOBALS['pressqueue_test_as_supports']['ensure_recurring_actions_hook'] = true;

		ActionSchedulerQueue::after_scheduler_init();
		ActionSchedulerQueue::after_scheduler_init();

		self::assertCount( 1, $GLOBALS['pressqueue_test_as_actions'] );
		self::assertSame( ActionSchedulerQueue::SWEEP_HOOK, $GLOBALS['pressqueue_test_as_actions'][0]['hook'] );
		self::assertSame( ActionSchedulerQueue::GROUP, $GLOBALS['pressqueue_test_as_actions'][0]['group'] );
		self::assertSame( ActionSchedulerQueue::SWEEP_INTERVAL_SECONDS, $GLOBALS['pressqueue_test_as_actions'][0]['interval'] );
	}

	public function test_batch_queue_action_uses_expected_hook_group_args_and_uniqueness(): void {
		do_action( 'action_scheduler_init' );

		$queue           = new ActionSchedulerQueue();
		$batch_public_id = '01K8Z8D0PKH2N8J7S3K5A9C6DE';
		$snapshot_hash   = str_repeat( 'a', 64 );

		$first  = $queue->schedule_batch_queue( $batch_public_id, $snapshot_hash );
		$second = $queue->schedule_batch_queue( $batch_public_id, $snapshot_hash );

		self::assertSame( $first, $second );
		self::assertTrue( $queue->has_batch_queue_action( $batch_public_id, $snapshot_hash ) );
		self::assertCount( 1, $GLOBALS['pressqueue_test_as_actions'] );
		self::assertSame( ActionSchedulerQueue::BATCH_QUEUE_HOOK, $GLOBALS['pressqueue_test_as_actions'][0]['hook'] );
		self::assertSame( ActionSchedulerQueue::GROUP, $GLOBALS['pressqueue_test_as_actions'][0]['group'] );
		self::assertSame( array( $batch_public_id, $snapshot_hash ), $GLOBALS['pressqueue_test_as_actions'][0]['args'] );
	}

	public function test_batch_queue_continuation_schedules_even_when_same_action_exists(): void {
		do_action( 'action_scheduler_init' );

		$queue           = new ActionSchedulerQueue();
		$batch_public_id = '01K8Z8D0PKH2N8J7S3K5A9C6DE';
		$snapshot_hash   = str_repeat( 'b', 64 );

		$first  = $queue->schedule_batch_queue( $batch_public_id, $snapshot_hash );
		$second = $queue->schedule_batch_queue_continuation( $batch_public_id, $snapshot_hash );

		self::assertNotSame( $first, $second );
		self::assertCount( 2, $GLOBALS['pressqueue_test_as_actions'] );
		self::assertSame( ActionSchedulerQueue::BATCH_QUEUE_HOOK, $GLOBALS['pressqueue_test_as_actions'][1]['hook'] );
		self::assertSame( array( $batch_public_id, $snapshot_hash ), $GLOBALS['pressqueue_test_as_actions'][1]['args'] );
	}
}
