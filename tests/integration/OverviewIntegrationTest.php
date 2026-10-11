<?php
/**
 * Overview screen integration tests.
 *
 * @package PressQueue\Tests\Integration
 */

declare(strict_types=1);

namespace PressQueue\Tests\Integration;

use PressQueue\Admin\OverviewController;
use PressQueue\Security\Capabilities;
use PressQueue\Storage\Database;
use PressQueue\Storage\SchemaMigrator;
use PressQueue\Tests\Support\PressQueueIntegrationTestCase;

/**
 * @covers \PressQueue\Admin\OverviewController
 */
final class OverviewIntegrationTest extends PressQueueIntegrationTestCase {
	protected function setUp(): void {
		parent::setUp();

		( new SchemaMigrator( Database::from_globals() ) )->migrate();
	}

	public function test_writers_with_only_post_controls_see_no_site_wide_activity(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$user    = get_userdata( $user_id );
		self::assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( Capabilities::EDIT_POST_CONTROLS );
		wp_set_current_user( 0 );
		wp_set_current_user( $user_id );

		self::assertTrue( current_user_can( Capabilities::ACCESS_ADMIN ) );

		ob_start();
		OverviewController::render_page();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Sharing your posts', $html );
		self::assertStringNotContainsString( 'Recent activity', $html );
		self::assertStringNotContainsString( 'Check a post', $html );
		self::assertStringNotContainsString( 'Not shared this week', $html );
		self::assertStringNotContainsString( 'Connect Buffer', $html );
	}

	public function test_administrators_see_the_checklist_activity_and_post_check(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		do_action( 'pressqueue_capability_registration', false );
		wp_set_current_user( 0 );
		wp_set_current_user( $user_id );

		ob_start();
		OverviewController::render_page();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Connect Buffer', $html );
		self::assertStringContainsString( 'Recent activity', $html );
		self::assertStringContainsString( 'Check a post', $html );
	}
}
