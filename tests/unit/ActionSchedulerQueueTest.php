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
}
