<?php
/**
 * Disposable database support for real schema and transactional integration tests.
 *
 * @package PressQueue\Tests\Support
 */

declare(strict_types=1);

namespace PressQueue\Tests\Support;

use RuntimeException;
use WP_UnitTestCase;

/**
 * Uses real plugin tables because temporary tables are invisible to SHOW TABLES.
 */
abstract class PressQueueIntegrationTestCase extends WP_UnitTestCase {
	/**
	 * Start a core transaction without rewriting plugin DDL into temporary tables.
	 */
	public function start_transaction() {
		// Parallel local runs may use suffixed disposable databases such as pressqueue_tests_editor.
		if ( ! defined( 'DB_NAME' ) || ( 'pressqueue_tests' !== DB_NAME && ! str_starts_with( (string) DB_NAME, 'pressqueue_tests_' ) ) ) {
			throw new RuntimeException( 'PressQueue integration tests require the disposable pressqueue_tests database.' );
		}
		parent::start_transaction();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		// Each test simulates a fresh request; shutdown capture is request-local.
		$captured = new \ReflectionProperty( \PressQueue\Planning\EventCollector::class, 'captured' );
		$captured->setAccessible( true );
		$captured->setValue( null, array() );
	}
}
