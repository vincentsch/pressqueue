<?php
/**
 * Output boundaries checked during directory review.
 *
 * @package PressQueue\Tests\Integration
 */

declare(strict_types=1);

namespace PressQueue\Tests\Integration;

use PressQueue\Admin\SocialCardsController;
use PressQueue\Admin\Ui;
use PressQueue\SocialCard\Catalog;
use PressQueue\Tests\Support\PressQueueIntegrationTestCase;

/**
 * @covers \PressQueue\Admin\Ui
 * @covers \PressQueue\Admin\SocialCardsController
 */
final class ReviewReadinessIntegrationTest extends PressQueueIntegrationTestCase {
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
