<?php
/**
 * Settings repository tests.
 *
 * @package PressQueue\Tests\Unit
 */

declare(strict_types=1);

namespace PressQueue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PressQueue\Storage\Schema;
use PressQueue\Storage\SchemaStatus;
use PressQueue\Storage\SettingsRepository;
use PressQueue\Storage\StorageUnavailableException;

use function pressqueue_test_reset_wordpress_stubs;

/**
 * @covers \PressQueue\Storage\PolicySettings
 * @covers \PressQueue\Storage\SchemaStatus
 * @covers \PressQueue\Storage\SettingsRepository
 */
final class SettingsRepositoryTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		pressqueue_test_reset_wordpress_stubs();
	}

	public function test_default_policy_is_dry_run_with_kill_switch_off(): void {
		$repository = new SettingsRepository();

		$repository->ensure_defaults();
		$settings = $repository->read();

		self::assertFalse( $settings->kill_switch_enabled );
		self::assertTrue( $settings->dry_run_enabled );
		self::assertSame( 'dry_run', $settings->delivery_mode );
		self::assertSame( 'dry_run', $settings->delivery_state );
		self::assertFalse( $GLOBALS['pressqueue_test_option_autoloads'][ SettingsRepository::KILL_SWITCH_OPTION ] );
		self::assertFalse( $GLOBALS['pressqueue_test_option_autoloads'][ SettingsRepository::DRY_RUN_OPTION ] );
	}

	public function test_policy_state_order_prefers_dry_run_before_kill_switch(): void {
		$repository = new SettingsRepository();

		$repository->ensure_defaults();
		$repository->set_kill_switch_enabled( true );

		$settings = $repository->read();

		self::assertSame( 'dry_run', $settings->delivery_mode );
		self::assertSame( 'dry_run', $settings->delivery_state );

		$repository->set_dry_run_enabled( false );
		$settings = $repository->read();

		self::assertSame( 'live', $settings->delivery_mode );
		self::assertSame( 'held', $settings->delivery_state );

		$repository->set_kill_switch_enabled( false );
		$settings = $repository->read();

		self::assertSame( 'live', $settings->delivery_mode );
		self::assertSame( 'proposed', $settings->delivery_state );
	}

	public function test_schema_status_fails_closed_when_schema_is_behind_locked_or_failed(): void {
		$status = new SchemaStatus();

		$this->expectException( StorageUnavailableException::class );
		$status->assert_ready_for_planning();
	}

	public function test_schema_status_is_not_ready_without_verified_storage(): void {
		add_option( Schema::VERSION_OPTION, Schema::TARGET_VERSION, '', false );

		$status = new SchemaStatus();

		self::assertFalse( $status->is_ready_for_planning() );

		add_option(
			Schema::MIGRATION_LOCK_OPTION,
			array(
				'owner'      => 'test',
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			'',
			false
		);

		self::assertFalse( $status->is_ready_for_planning() );

		delete_option( Schema::MIGRATION_LOCK_OPTION );
		add_option( Schema::MIGRATION_FAILED_OPTION, array( 'message' => 'failed' ), '', false );

		self::assertFalse( $status->is_ready_for_planning() );
	}
}
