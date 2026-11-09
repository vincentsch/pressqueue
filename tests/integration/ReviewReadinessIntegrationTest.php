<?php
/**
 * Output boundaries checked during directory review.
 *
 * @package PressQueue\Tests\Integration
 */

declare(strict_types=1);

namespace PressQueue\Tests\Integration;

use PressQueue\Admin\AdminScreens;
use PressQueue\Admin\ConnectionController;
use PressQueue\Admin\ChannelsPage;
use PressQueue\Admin\DeliveryActionController;
use PressQueue\Admin\PostControlsController;
use PressQueue\Admin\RulesController;
use PressQueue\Admin\SocialCardsController;
use PressQueue\Admin\Ui;
use PressQueue\Security\Capabilities;
use PressQueue\Storage\Database;
use PressQueue\Storage\Schema;
use PressQueue\Storage\Record\ConnectionRecord;
use PressQueue\SocialCard\Catalog;
use PressQueue\Tests\Support\PressQueueIntegrationTestCase;

/**
 * @covers \PressQueue\Admin\Ui
 * @covers \PressQueue\Admin\SocialCardsController
 */
final class ReviewReadinessIntegrationTest extends PressQueueIntegrationTestCase {
	public function test_icon_allowlist_preserves_the_graphics_without_allowing_active_content(): void {
		foreach ( array( array( Ui::class, 'logo_svg' ), array( RulesController::class, 'shield_icon' ), array( RulesController::class, 'share_icon' ) ) as $icon ) {
			$method = new \ReflectionMethod( $icon[0], $icon[1] );
			$svg = $method->invoke( null );
			$clean = wp_kses( $svg, Ui::svg_allowed_html() );
			self::assertStringContainsString( '<svg ', $clean );
			self::assertMatchesRegularExpression( '/viewbox="0 0 (?:14 14|16 16)"/i', $clean );
			self::assertStringContainsString( '<path d="', $clean );
			if ( 'logo_svg' === $icon[1] ) {
				self::assertStringContainsString( '<circle cx="12.5" cy="12.5" r="2" fill="#8ea2ff"', $clean );
			} else {
				self::assertStringContainsString( 'stroke="currentColor"', $clean );
			}
		}
		$hostile = '<svg onload="alert(1)"><script>alert(2)</script><image href="javascript:alert(3)" /><path d="M0 0" onclick="alert(4)" /></svg>';
		$clean = wp_kses( $hostile, Ui::svg_allowed_html() );
		foreach ( array( 'onload=', 'onclick=', '<script', '<image', 'href=' ) as $unsafe ) {
			self::assertStringNotContainsString( $unsafe, $clean );
		}
	}

	public function test_account_verification_time_keeps_its_datetime_and_escapes_the_name(): void {
		$connection = ( new \ReflectionClass( ConnectionRecord::class ) )->newInstanceWithoutConstructor();
		$connection->account = array( 'display_label' => '<script>Account</script>' );
		$connection->credential_verified_at = new \DateTimeImmutable( '2026-10-01T12:34:56+00:00' );
		$connection->connection_status = 'verified';
		ob_start();
		( new \ReflectionMethod( ChannelsPage::class, 'render_account_meta' ) )->invoke( null, $connection );
		$html = (string) ob_get_clean();
		self::assertStringContainsString( '<time datetime="2026-10-01T12:34:56+00:00" title="', $html );
		self::assertStringContainsString( '</time>', $html );
		self::assertStringContainsString( '&lt;script&gt;Account&lt;/script&gt;', $html );
		self::assertStringNotContainsString( '<script', $html );
	}

	public function test_post_thumbnail_keeps_responsive_attributes_and_removes_active_markup(): void {
		$id = self::factory()->attachment->create_object( 'image.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );
		$downsize = static fn (): array => array( 'https://example.test/image.jpg', 150, 150, true );
		$image = static fn (): string => '<img src="https://example.test/image.jpg" width="150" height="150" srcset="https://example.test/image.jpg 150w, https://example.test/image-large.jpg 300w" sizes="150px" loading="lazy" decoding="async" onerror="alert(1)" /><script>alert(2)</script>';
		add_filter( 'image_downsize', $downsize );
		add_filter( 'wp_get_attachment_image', $image );
		ob_start();
		try {
			( new \ReflectionMethod( PostControlsController::class, 'render_classic_customize' ) )->invoke(
				null,
				array( 'media' => array( 'mode' => 'post_media', 'post_media' => array( 'attachment_ids' => array( $id ) ) ) ),
				null,
				array( 'channels' => array(), 'timezone' => 'UTC', 'timezone_label' => 'UTC' )
			);
			$html = (string) ob_get_contents();
		} finally {
			ob_end_clean();
			remove_filter( 'image_downsize', $downsize );
			remove_filter( 'wp_get_attachment_image', $image );
		}
		self::assertStringContainsString( 'srcset="https://example.test/image.jpg 150w, https://example.test/image-large.jpg 300w"', $html );
		self::assertStringContainsString( 'sizes="150px"', $html );
		self::assertStringContainsString( 'loading="lazy"', $html );
		self::assertStringContainsString( 'decoding="async"', $html );
		self::assertStringNotContainsString( 'onerror=', $html );
		self::assertStringNotContainsString( '<script', $html );
	}

	/** @dataProvider empty_state_renderers */
	public function test_empty_state_sanitizes_links_without_losing_the_action( string $renderer ): void {
		ob_start();
		$renderer::empty_state(
			'Nothing <script>alert(1)</script>',
			'Try <img src=x onerror=alert(2)>',
			'<a class="button" href="https://example.test/rules" onclick="alert(3)">Add rule</a><a href="javascript:alert(4)">Unsafe</a><script>alert(5)</script>'
		);
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '<a class="button" href="https://example.test/rules">Add rule</a>', $html );
		self::assertStringContainsString( 'Nothing &lt;script&gt;', $html );
		self::assertStringNotContainsString( '<script', $html );
		self::assertStringNotContainsString( '<img', $html );
		self::assertStringNotContainsString( 'onclick=', $html );
		self::assertStringNotContainsString( 'href="javascript:', $html );
	}

	public static function empty_state_renderers(): array {
		return array( array( Ui::class ), array( RulesController::class ) );
	}

	/** @dataProvider rejected_mutation_requests */
	public function test_reviewed_handlers_reject_requests_before_mutation( string $controller, string $handler, string $nonce_action, string $case ): void {
		$user = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		if ( 'unauthorized' !== $case ) {
			foreach ( Capabilities::names() as $capability ) {
				$user->add_cap( $capability );
			}
		}
		wp_set_current_user( $user->ID );
		$before                    = array( $_POST, $_GET, $_REQUEST, $_SERVER['REQUEST_METHOD'] ?? null );
		$_SERVER['REQUEST_METHOD'] = 'wrong_method' === $case ? 'GET' : 'POST';
		$_GET                      = array();
		$_POST                     = array(
			'outcome'              => 'confirmed_remote_id',
			'confirm_local_effect' => '1',
			'api_key'              => 'fixture-key-that-must-not-be-used',
			'delivery_public_id'   => 'invalid-before-validation',
			'remote_id'            => '<script>invalid</script>',
		);
		if ( 'missing_nonce' !== $case ) {
			$_POST['_wpnonce'] = 'invalid_nonce' === $case ? 'invalid' : wp_create_nonce( $nonce_action );
		}
		$_REQUEST = $_POST;
		$rows_before = $this->reviewed_table_counts();
		$reject_redirect = static function (): void {
			throw new \RuntimeException( 'The handler reached a redirect before rejecting the request.' );
		};
		add_filter( 'wp_redirect', $reject_redirect );

		try {
			$controller::$handler();
			self::fail( 'The handler accepted a request without all security gates.' );
		} catch ( \WPDieException $exception ) {
			if ( 'unauthorized' === $case ) {
				self::assertStringContainsString( 'not allowed', $exception->getMessage() );
			} elseif ( 'wrong_method' === $case ) {
				self::assertStringContainsString( 'require POST', $exception->getMessage() );
			} else {
				self::assertStringContainsString( 'The link you followed has expired.', $exception->getMessage() );
			}
			self::assertSame( $rows_before, $this->reviewed_table_counts() );
		} finally {
			remove_filter( 'wp_redirect', $reject_redirect );
			list( $_POST, $_GET, $_REQUEST, $method ) = $before;
			if ( null === $method ) {
				unset( $_SERVER['REQUEST_METHOD'] );
			} else {
				$_SERVER['REQUEST_METHOD'] = $method;
			}
		}
	}

	private function reviewed_table_counts(): array {
		global $wpdb;
		$counts = array();
		foreach ( array( Schema::CONNECTIONS, Schema::DELIVERIES ) as $name ) {
			$table = Database::from_globals()->table( $name );
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			$counts[ $name ] = null === $exists ? null : (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $table . '`' );
		}
		return $counts;
	}

	public static function rejected_mutation_requests(): array {
		$handlers = array();
		foreach ( array( 'connect_buffer', 'finish_connection', 'create_connection', 'test_connection', 'sync_connection', 'rotate_connection', 'enable_connection', 'disable_connection', 'disconnect_connection' ) as $action ) {
			$handlers[] = array( ConnectionController::class, 'handle_' . $action, 'pressqueue_' . $action );
		}
		foreach ( array( 'cancel_delivery', 'resume_delivery', 'retry_delivery', 'resume_held_deliveries', 'cancel_held_deliveries' ) as $action ) {
			$handlers[] = array( DeliveryActionController::class, 'handle_' . $action, 'pressqueue_' . $action );
		}
		$handlers[] = array( DeliveryActionController::class, 'handle_reconcile_delivery', 'pressqueue_reconcile_delivery_confirmed_remote_id' );
		$handlers[] = array( AdminScreens::class, 'handle_post_preview', 'pressqueue_preview_post' );

		$cases = array();
		foreach ( $handlers as $handler ) {
			foreach ( array( 'missing_nonce', 'invalid_nonce', 'unauthorized', 'wrong_method' ) as $case ) {
				$cases[ $handler[1] . ':' . $case ] = array_merge( $handler, array( $case ) );
			}
		}
		return $cases;
	}

	public function test_page_header_keeps_links_and_badges_but_strips_active_markup(): void {
		ob_start();
		Ui::page_start(
			'Example',
			array(
				'actions' => '<div class="pq-row"><a href="https://example.test/rules" onclick="alert(1)">Edit rule</a><script>alert(2)</script><img src="x" onerror="alert(3)"></div>',
				'badge'   => '<span class="pq-pill" onmouseover="alert(4)">Ready</span>',
			)
		);
		Ui::page_end();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'href="https://example.test/rules"', $html );
		self::assertStringContainsString( 'class="pq-row"', $html );
		self::assertStringContainsString( '<span class="pq-pill">Ready</span>', $html );
		self::assertStringNotContainsString( '<script', $html );
		foreach ( array( 'onclick=', 'onerror=', 'onmouseover=' ) as $attribute ) {
			self::assertStringNotContainsString( $attribute, $html );
		}
	}

	public function test_font_styles_keep_bundled_urls_and_escape_hostile_url_markup(): void {
		$previous = $_GET['page'] ?? null;
		$_GET['page'] = SocialCardsController::SLUG;
		$filter = static fn ( string $url ): string => str_contains( $url, '/assets/social/fonts/' ) ? $url . '"></style><script>alert(1)</script>' : $url;
		add_filter( 'plugins_url', $filter );
		try {
			SocialCardsController::enqueue_assets();
			$styles = wp_styles()->get_data( 'pressqueue-social', 'after' );
			self::assertIsArray( $styles );
			$css = implode( "\n", $styles );
			self::assertSame( count( Catalog::fonts() ), substr_count( $css, '@font-face' ) );
			self::assertStringNotContainsString( '</style>', $css );
			self::assertStringNotContainsString( '<script>', $css );
			foreach ( Catalog::fonts() as $font ) {
				self::assertStringContainsString( $font['file'], $css );
			}
		} finally {
			remove_filter( 'plugins_url', $filter );
			if ( null === $previous ) {
				unset( $_GET['page'] );
			} else {
				$_GET['page'] = $previous;
			}
		}
	}
}
