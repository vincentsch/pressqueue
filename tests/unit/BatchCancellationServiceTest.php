<?php
/**
 * Batch cancellation service tests.
 *
 * @package PressQueue\Tests\Unit
 */

declare(strict_types=1);

namespace PressQueue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PressQueue\Batch\BatchCancellationService;
use PressQueue\Security\Capabilities;
use PressQueue\Storage\Database;
use PressQueue\Storage\StaleRecordException;

use function pressqueue_test_reset_wordpress_stubs;

/**
 * @covers \PressQueue\Batch\BatchCancellationService
 */
final class BatchCancellationServiceTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		pressqueue_test_reset_wordpress_stubs();
		$GLOBALS['pressqueue_test_current_user_caps'][ Capabilities::OPERATE_BATCHES ] = true;
	}

	public function test_cancel_stops_when_batch_transition_loses_compare_and_set(): void {
		$wpdb    = new StaleBatchCancellationWpdb();
		$service = new BatchCancellationService( new Database( $wpdb ) );

		try {
			$service->cancel( '01K3KGXB71P2MR4K9A7V6Q5C8D', 123 );
			self::fail( 'Cancellation must fail before item or delivery changes.' );
		} catch ( StaleRecordException $exception ) {
			self::assertSame( 'Batch changed before cancellation completed.', $exception->getMessage() );
		}

		self::assertSame( 1, $wpdb->batch_updates );
		self::assertSame( 0, $wpdb->batch_item_updates );
	}
}

/**
 * Database double for a stale batch cancellation transition.
 */
final class StaleBatchCancellationWpdb extends \wpdb {
	/**
	 * Batch update attempts.
	 *
	 * @var int
	 */
	public int $batch_updates = 0;

	/**
	 * Batch item update attempts.
	 *
	 * @var int
	 */
	public int $batch_item_updates = 0;

	/**
	 * Prepare SQL.
	 *
	 * @param string           $query Query.
	 * @param array<int,mixed> $args Args.
	 * @return string
	 */
	public function prepare( $query, $args = array() ): string {
		unset( $args );

		return (string) $query;
	}

	/**
	 * Execute SQL.
	 *
	 * @param string $query Query.
	 * @return int
	 */
	public function query( $query ): int {
		if ( false !== strpos( (string) $query, 'wp_pressqueue_batch_items' ) ) {
			++$this->batch_item_updates;
			return 1;
		}

		if ( false !== strpos( (string) $query, 'wp_pressqueue_batches' ) ) {
			++$this->batch_updates;
			return 0;
		}

		return 1;
	}

	/**
	 * Return one batch row.
	 *
	 * @param string $query Query.
	 * @return object|null
	 */
	public function get_row( $query ): ?object {
		if ( false === strpos( (string) $query, 'wp_pressqueue_batches' ) ) {
			return null;
		}

		return (object) array(
			'batch_limits_json'     => '{"candidate_limit":20,"destination_item_limit":100,"schedule":{"mode":"inherit","schema_version":1},"schema_version":1}',
			'batch_type'            => 'bulk',
			'completed_at'          => null,
			'confirmed_at'          => null,
			'confirmed_by'          => null,
			'created_at'            => '2026-08-25 12:00:00',
			'created_by'            => 123,
			'filters_json'          => '{"post_ids":[42],"post_statuses":["publish"],"post_types":["post"],"schema_version":1}',
			'id'                    => 1,
			'lock_version'          => 2,
			'preview_expires_at'    => '2026-08-26 12:00:00',
			'preview_snapshot_hash' => str_repeat( 'a', 64 ),
			'preview_summary_json'  => '{"counts":{"queueable":1},"schema_version":1}',
			'public_id'             => '01K3KGXB71P2MR4K9A7V6Q5C8D',
			'started_at'            => null,
			'status'                => 'queueing',
			'updated_at'            => '2026-08-25 12:00:00',
		);
	}

	/**
	 * Return no scalar results.
	 *
	 * @param string $query Query.
	 * @return null
	 */
	public function get_var( $query ) {
		unset( $query );

		return null;
	}

	/**
	 * Return no rows.
	 *
	 * @param string $query Query.
	 * @param mixed  $output Output mode.
	 * @return array<int,object>
	 */
	public function get_results( $query, $output = null ): array {
		unset( $query, $output );

		return array();
	}
}
