<?php
/**
 * Observation hook integration tests.
 *
 * @package PressQueue\Tests\Integration
 */

declare(strict_types=1);

namespace PressQueue\Tests\Integration;

use PressQueue\Security\Capabilities;
use PressQueue\Storage\Database;
use PressQueue\Storage\Repository\DecisionRepository;
use PressQueue\Storage\Repository\DeliveryEventRepository;
use PressQueue\Storage\Schema;
use PressQueue\Storage\SchemaMigrator;
use PressQueue\Storage\SettingsRepository;
use RuntimeException;
use PressQueue\Tests\Support\PressQueueIntegrationTestCase as WP_UnitTestCase;

/**
 * @covers \PressQueue\Storage\ObservationPayloads
 * @covers \PressQueue\Storage\Repository\DecisionRepository
 * @covers \PressQueue\Storage\Repository\DeliveryEventRepository
 */
final class ObservationRepositoryIntegrationTest extends WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();

		remove_all_actions( 'pressqueue_decision_recorded' );
		remove_all_actions( 'pressqueue_delivery_event_recorded' );
		$this->resetStorage();
		( new SchemaMigrator( Database::from_globals() ) )->migrate();
	}

	protected function tearDown(): void {
		remove_all_actions( 'pressqueue_decision_recorded' );
		remove_all_actions( 'pressqueue_delivery_event_recorded' );
		$this->resetStorage();

		parent::tearDown();
	}

	public function test_decision_hook_dispatches_after_commit_and_not_for_dedup(): void {
		$database = Database::from_globals();
		$repo     = new DecisionRepository( $database );
		$calls    = array();

		add_action(
			'pressqueue_decision_recorded',
			static function ( array $payload ) use ( &$calls ): void {
				$calls[] = $payload;
			}
		);

		$first = $database->transaction(
			function () use ( $repo, &$calls ) {
				$record = $repo->create( $this->decisionInput() );
				self::assertSame( array(), $calls );

				return $record;
			}
		);

		self::assertCount( 1, $calls );
		self::assertSame( $first->public_id, $calls[0]['decision_public_id'] );
		self::assertArrayNotHasKey( 'snapshot', $calls[0] );

		$second = $database->transaction(
			function () use ( $repo ) {
				return $repo->create( $this->decisionInput() );
			}
		);

		self::assertSame( $first->public_id, $second->public_id );
		self::assertCount( 1, $calls );
	}

	public function test_delivery_event_hook_dispatches_once_per_commit_and_discards_rollback(): void {
		$database = Database::from_globals();
		$repo     = new DeliveryEventRepository( $database );
		$calls    = array();

		add_action(
			'pressqueue_delivery_event_recorded',
			static function ( array $payload ) use ( &$calls ): void {
				$calls[] = $payload;
			}
		);

		$database->transaction(
			function () use ( $repo, &$calls ): void {
				$repo->append( $this->eventInput( 'queued' ) );
				$repo->append( $this->eventInput( 'scheduler_action_scheduled' ) );
				self::assertSame( array(), $calls );
			}
		);

		self::assertCount( 2, $calls );
		self::assertSame( array( 'queued', 'scheduler_action_scheduled' ), array_column( $calls, 'event_type' ) );
		self::assertArrayNotHasKey( 'detail', $calls[0] );
		self::assertArrayNotHasKey( 'actor_user_id', $calls[0] );

		try {
			$database->transaction(
				function () use ( $repo ): void {
					$repo->append( $this->eventInput( 'refused_transition' ) );
					throw new RuntimeException( 'rollback' );
				}
			);
			self::fail( 'Rollback should throw.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'rollback', $exception->getMessage() );
		}

		self::assertCount( 2, $calls );
	}

	public function test_observer_exception_does_not_undo_committed_decision(): void {
		$database = Database::from_globals();
		$repo     = new DecisionRepository( $database );

		add_action(
			'pressqueue_decision_recorded',
			static function (): void {
				throw new RuntimeException( 'observer failed with token value' );
			}
		);

		$record = $database->transaction(
			function () use ( $repo ) {
				return $repo->create( $this->decisionInput() );
			}
		);

		self::assertNotNull( $repo->find_by_public_id( $record->public_id ) );
	}

	/**
	 * Return a valid decision input.
	 *
	 * @return array<string,mixed>
	 */
	private function decisionInput(): array {
		return array(
			'author_id'            => 7,
			'event_key'            => str_repeat( 'a', 64 ),
			'event_mode'           => 'runtime',
			'post_id'              => 42,
			'post_status'          => 'publish',
			'post_type'            => 'post',
			'reasons'              => array(
				array(
					'code'             => 'matched_rule',
					'condition_result' => 'matched',
				),
			),
			'result'               => 'included',
			'revision_fingerprint' => str_repeat( 'b', 64 ),
			'rule_id'              => 1,
			'rule_public_id'       => '01K3KGXB71P2MR4K9A7V6Q5C8D',
			'rule_version'         => 1,
			'scope_public_id'      => '01K3KGXB71P2MR4K9A7V6Q5C8E',
			'scope_type'           => 'channel',
			'snapshot'             => array(
				'post'     => array( 'id' => 42 ),
				'settings' => array( 'dry_run' => false ),
			),
			'trigger'              => 'first_publish',
		);
	}

	/**
	 * Return a valid delivery event input.
	 *
	 * @param string $event_type Event type.
	 * @return array<string,mixed>
	 */
	private function eventInput( string $event_type ): array {
		return array(
			'action_id'          => 'scheduler_action_scheduled' === $event_type ? 500 : null,
			'actor_type'         => 'system',
			'actor_user_id'      => 123,
			'attempt_number'     => null,
			'delivery_id'        => 1,
			'delivery_public_id' => '01K3KGXB71P2MR4K9A7V6Q5C8F',
			'detail'             => array(
				'classification' => $event_type,
				'raw_response'   => 'secret body',
				'schema_version' => 1,
			),
			'event_type'         => $event_type,
			'from_state'         => 'proposed',
			'provider_result'    => null,
			'retry_safety'       => null,
			'to_state'           => 'queued',
		);
	}

	/**
	 * Reset custom storage between tests.
	 */
	private function resetStorage(): void {
		global $wpdb;

		$database = Database::from_globals();

		foreach ( array_reverse( Schema::table_suffixes() ) as $suffix ) {
			$table = $database->table( $suffix );
			$wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' );
		}

		foreach ( array( Schema::VERSION_OPTION, Schema::MIGRATION_LOCK_OPTION, Schema::MIGRATION_FAILED_OPTION, Capabilities::VERSION_OPTION, SettingsRepository::KILL_SWITCH_OPTION, SettingsRepository::DRY_RUN_OPTION ) as $option ) {
			delete_option( $option );
		}
	}
}
