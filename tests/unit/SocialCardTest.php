<?php
/**
 * Social card tests.
 *
 * @package PressQueue\Tests\Unit
 */

declare(strict_types=1);

namespace PressQueue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PressQueue\Planning\MediaSnapshot;
use PressQueue\SocialCard\Catalog;
use PressQueue\SocialCard\Renderer;
use PressQueue\SocialCard\Settings;
use PressQueue\Storage\StorageEnums;
use PressQueue\Storage\ValidationException;

/**
 * @covers \PressQueue\SocialCard\Catalog
 * @covers \PressQueue\SocialCard\Renderer
 * @covers \PressQueue\SocialCard\Settings
 */
final class SocialCardTest extends TestCase {
	protected function setUp(): void {
		pressqueue_test_reset_wordpress_stubs();
	}

	public function test_every_bundled_background_has_an_image_and_a_thumbnail(): void {
		$backgrounds = Catalog::backgrounds();

		self::assertGreaterThanOrEqual( 20, count( $backgrounds ) );
		self::assertArrayHasKey( Catalog::DEFAULT_BACKGROUND, $backgrounds );

		foreach ( $backgrounds as $id => $background ) {
			$path = Catalog::background_path( $id );
			self::assertFileExists( $path, $id );
			self::assertFileExists( dirname( $path ) . '/thumbs/' . $id . '.jpg', $id );
			$size = getimagesize( $path );
			self::assertIsArray( $size );
			self::assertSame( array( 1200, 630 ), array( $size[0], $size[1] ), $id );
			self::assertContains( $background['text'], array( 'light', 'dark' ) );
			self::assertNotSame( '', $background['name'] );
		}
	}

	public function test_every_font_ships_with_its_license(): void {
		$dir = dirname( Catalog::small_font_path() );
		self::assertFileExists( Catalog::small_font_path() );

		foreach ( Catalog::fonts() as $id => $font ) {
			self::assertFileExists( Catalog::font_path( $id ) );
		}

		self::assertNotEmpty( glob( $dir . '/OFL-*.txt' ) );
	}

	public function test_settings_repair_unknown_values_and_reject_them_when_saving(): void {
		$settings = Settings::normalize(
			array(
				'align'        => 'diagonal',
				'background'   => 'no-such-background',
				'font'         => 'comic-sans',
				'link_preview' => 'sometimes',
				'site_label'   => '<b>Example</b>',
				'text'         => 'purple',
			)
		);

		self::assertSame( Catalog::DEFAULT_BACKGROUND, $settings['background'] );
		self::assertSame( Catalog::DEFAULT_FONT, $settings['font'] );
		self::assertSame( 'left', $settings['align'] );
		self::assertSame( 'auto', $settings['text'] );
		self::assertSame( 'off', $settings['link_preview'] );
		self::assertSame( 'Example', $settings['site_label'] );

		$this->expectException( ValidationException::class );
		Settings::normalize( array( 'background' => 'no-such-background' ), true );
	}

	public function test_own_background_needs_an_image(): void {
		self::assertSame( Catalog::DEFAULT_BACKGROUND, Settings::normalize( array( 'background' => 'custom' ) )['background'] );
		self::assertSame( 'custom', Settings::normalize( array( 'background' => 'custom', 'custom_image' => '12' ) )['background'] );

		$this->expectException( ValidationException::class );
		Settings::normalize( array( 'background' => 'custom' ), true );
	}

	public function test_site_label_defaults_to_the_domain_and_can_be_hidden(): void {
		$GLOBALS['pressqueue_test_options']['home'] = 'https://www.example.org';

		$label = Settings::site_label( Settings::normalize( array() ) );
		self::assertStringNotContainsString( 'www.', $label );
		self::assertSame( 'Custom', Settings::site_label( Settings::normalize( array( 'site_label' => 'Custom' ) ) ) );
		self::assertSame( '', Settings::site_label( Settings::normalize( array( 'show_site' => false ) ) ) );
	}

	public function test_style_hash_changes_when_the_look_changes(): void {
		$base  = Settings::style( Settings::normalize( array() ) );
		$other = Settings::style( Settings::normalize( array( 'font' => 'elegant-serif' ) ) );

		self::assertSame( Settings::style_hash( $base ), Settings::style_hash( $base ) );
		self::assertNotSame( Settings::style_hash( $base ), Settings::style_hash( $other ) );
		self::assertSame(
			Settings::style_hash( Settings::style( Settings::normalize( array( 'link_preview' => 'off' ) ) ) ),
			Settings::style_hash( Settings::style( Settings::normalize( array( 'link_preview' => 'always' ) ) ) ),
			'Link preview settings do not change the image.'
		);
	}

	public function test_titles_are_cleaned_before_drawing(): void {
		self::assertSame( 'Fish & Chips', Renderer::clean_title( '<em>Fish</em> &amp; Chips' ) );
		self::assertSame( 'Launch day', Renderer::clean_title( "Launch \u{1F680}  day" ) );
		self::assertSame( '…', Renderer::clean_title( '   ' ) );
	}

	/**
	 * @requires extension gd
	 */
	public function test_cards_are_1200_by_630_jpegs_in_every_font_and_layout(): void {
		if ( ! Renderer::is_supported() ) {
			self::markTestSkipped( 'GD with FreeType is not available.' );
		}

		$renderer = new Renderer();
		foreach ( array_keys( Catalog::fonts() ) as $font ) {
			foreach ( array( 'left', 'center' ) as $align ) {
				$style = Settings::style(
					Settings::normalize(
						array(
							'align' => $align,
							'font'  => $font,
						)
					)
				);
				$bytes = $renderer->render( 'Why a very long headline still fits on the image without running off the edge of the card at all', $style );
				$size  = getimagesizefromstring( $bytes );

				self::assertIsArray( $size );
				self::assertSame( array( Renderer::WIDTH, Renderer::HEIGHT, IMAGETYPE_JPEG ), array( $size[0], $size[1], $size[2] ), $font . ' ' . $align );
			}
		}
	}

	/**
	 * @requires extension gd
	 */
	public function test_wrapped_lines_fit_the_width(): void {
		if ( ! Renderer::is_supported() ) {
			self::markTestSkipped( 'GD with FreeType is not available.' );
		}

		$font  = Catalog::font_path( 'inter' );
		$lines = ( new Renderer() )->wrap( 'Supercalifragilisticexpialidocious words keep going and going on one line', $font, 60, 600 );

		self::assertGreaterThan( 1, count( $lines ) );
		foreach ( $lines as $line ) {
			$box = imagettfbbox( 60, 0, $font, $line );
			self::assertLessThanOrEqual( 600, abs( $box[2] - $box[0] ), $line );
		}
	}

	public function test_social_card_is_a_known_media_mode(): void {
		StorageEnums::assert_media_mode( 'social_card' );

		$snapshot = new MediaSnapshot(
			array(
				'items'          => array(
					array(
						'attachment_id'          => 7,
						'attachment_modified_at' => '2026-10-12 08:00:00',
						'byte_size'              => 48000,
						'capability_hash'        => str_repeat( 'a', 64 ),
						'height'                 => 630,
						'image_kind'             => 'image',
						'mime_type'              => 'image/jpeg',
						'position'               => 0,
						'probe_result_hash'      => str_repeat( 'b', 64 ),
						'url'                    => 'https://example.org/wp-content/uploads/pressqueue/social/card.jpg',
						'width'                  => 1200,
					),
				),
				'mode'           => 'social_card',
				'schema_version' => 1,
			)
		);

		self::assertSame( 'social_card', $snapshot->document()['mode'] );
	}
}
