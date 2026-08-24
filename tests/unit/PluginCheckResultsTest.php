<?php
/**
 * Plugin Check parser tests.
 *
 * @package PressQueue\Tests\Unit
 */

declare(strict_types=1);

namespace PressQueue\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/tools/plugin-check-results.php';

use function pressqueue_collect_plugin_check_failures;
use function pressqueue_plugin_check_output_is_success;

/**
 * @coversNothing
 */
final class PluginCheckResultsTest extends TestCase {
	public function test_parser_collects_type_based_errors_and_warnings(): void {
		$results = array(
			'files' => array(
				array(
					'type'     => 'ERROR',
					'severity' => 5,
					'message'  => 'Error finding.',
				),
				array(
					'type'     => 'WARNING',
					'severity' => 3,
					'message'  => 'Warning finding.',
				),
				array(
					'type'     => 'INFO',
					'severity' => 1,
					'message'  => 'Informational finding.',
				),
			),
		);

		self::assertCount( 2, pressqueue_collect_plugin_check_failures( $results ) );
	}

	public function test_parser_accepts_clean_success_output(): void {
		self::assertTrue( pressqueue_plugin_check_output_is_success( 'Success: Plugin check passed.' ) );
		self::assertFalse( pressqueue_plugin_check_output_is_success( 'FILE: pressqueue.php' ) );
	}
}
