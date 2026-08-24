<?php
/**
 * Delivery worker credential cleanup tests.
 *
 * @package PressQueue\Tests\Unit
 */

declare(strict_types=1);

namespace PressQueue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PressQueue\Delivery\DeliveryWorker;
use ReflectionClass;

/**
 * @covers \PressQueue\Delivery\DeliveryWorker
 */
final class DeliveryWorkerCredentialCleanupTest extends TestCase {
	public function test_preflight_credential_is_erased_and_removed(): void {
		$preflight = array(
			'credential' => 'fixture credential',
			'ok'         => true,
		);

		$this->clearPreflightCredential( $preflight );

		self::assertArrayNotHasKey( 'credential', $preflight );
		self::assertSame( array( 'ok' => true ), $preflight );
	}

	public function test_preflight_cleanup_is_safe_without_credential(): void {
		$preflight = array(
			'hold' => true,
			'ok'   => false,
		);

		$this->clearPreflightCredential( $preflight );

		self::assertSame(
			array(
				'hold' => true,
				'ok'   => false,
			),
			$preflight
		);
	}

	/**
	 * Invoke the worker cleanup helper.
	 *
	 * @param array<string,mixed> $preflight Preflight state.
	 */
	private function clearPreflightCredential( array &$preflight ): void {
		$reflection = new ReflectionClass( DeliveryWorker::class );
		$worker     = $reflection->newInstanceWithoutConstructor();
		$method     = $reflection->getMethod( 'clear_preflight_credential' );

		$method->setAccessible( true );
		$method->invokeArgs( $worker, array( &$preflight ) );
	}
}
