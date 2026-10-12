<?php
/**
 * Bundled social card backgrounds and fonts.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\SocialCard;

/**
 * Lists the styles that ship with the plugin.
 */
final class Catalog {
	/**
	 * Fonts that ship with the plugin, keyed by ID.
	 *
	 * @var array<string,array{name:string,file:string,scale:float}>
	 */
	private const FONTS = array(
		'inter'         => array(
			'name'  => 'Inter',
			'file'  => 'Inter-Bold.ttf',
			'scale' => 1.0,
		),
		'grotesk'       => array(
			'name'  => 'Space Grotesk',
			'file'  => 'SpaceGrotesk-Bold.ttf',
			'scale' => 1.0,
		),
		'bricolage'     => array(
			'name'  => 'Bricolage Grotesque',
			'file'  => 'BricolageGrotesque-ExtraBold.ttf',
			'scale' => 1.02,
		),
		// The two serif faces are trimmed copies of fonts with reserved names, so they
		// ship under their own names. See THIRD_PARTY_NOTICES.md.
		'elegant-serif' => array(
			'name'  => 'Elegant Serif',
			'file'  => 'ElegantSerif-Bold.ttf',
			'scale' => 1.04,
		),
		'sturdy-serif'  => array(
			'name'  => 'Sturdy Serif',
			'file'  => 'SturdySerif-ExtraBold.ttf',
			'scale' => 0.94,
		),
		'mono'          => array(
			'name'  => 'JetBrains Mono',
			'file'  => 'JetBrainsMono-Bold.ttf',
			'scale' => 0.9,
		),
	);

	public const DEFAULT_BACKGROUND = 'midnight-navy';
	public const DEFAULT_FONT       = 'inter';
	public const SMALL_FONT_FILE    = 'Inter-Medium.ttf';

	/**
	 * Cached background list.
	 *
	 * @var array<string,array<string,string>>|null
	 */
	private static ?array $backgrounds = null;

	/**
	 * Return bundled backgrounds keyed by ID.
	 *
	 * @return array<string,array{id:string,name:string,group:string,text:string,calm:string}>
	 */
	public static function backgrounds(): array {
		if ( null !== self::$backgrounds ) {
			return self::$backgrounds;
		}

		self::$backgrounds = array();
		$manifest          = self::asset_dir() . 'backgrounds/manifest.json';
		$raw               = is_readable( $manifest ) ? file_get_contents( $manifest ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local bundled file.
		$items             = is_string( $raw ) ? json_decode( $raw, true ) : null;

		if ( is_array( $items ) ) {
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) || ! isset( $item['id'] ) || ! is_string( $item['id'] ) || 1 !== preg_match( '/^[a-z0-9-]+$/', $item['id'] ) ) {
					continue;
				}

				self::$backgrounds[ $item['id'] ] = array(
					'id'    => $item['id'],
					'name'  => (string) ( $item['name'] ?? $item['id'] ),
					'group' => (string) ( $item['group'] ?? '' ),
					'text'  => 'dark' === ( $item['text'] ?? '' ) ? 'dark' : 'light',
					'calm'  => in_array( $item['calm'] ?? '', array( 'left', 'center', 'right' ), true ) ? (string) $item['calm'] : 'left',
				);
			}
		}

		return self::$backgrounds;
	}

	/**
	 * Return one bundled background.
	 *
	 * @param string $id Background ID.
	 * @return array{id:string,name:string,group:string,text:string,calm:string}|null
	 */
	public static function background( string $id ): ?array {
		return self::backgrounds()[ $id ] ?? null;
	}

	/**
	 * Return the file path of a bundled background.
	 *
	 * @param string $id Background ID.
	 * @return string
	 */
	public static function background_path( string $id ): string {
		return self::asset_dir() . 'backgrounds/' . $id . '.jpg';
	}

	/**
	 * Return the public URL of a bundled background or its thumbnail.
	 *
	 * @param string $id Background ID.
	 * @param bool   $thumbnail Whether to return the small preview.
	 * @return string
	 */
	public static function background_url( string $id, bool $thumbnail = false ): string {
		if ( ! defined( 'PRESSQUEUE_FILE' ) || ! function_exists( 'plugins_url' ) ) {
			return '';
		}

		return plugins_url( 'assets/social/backgrounds/' . ( $thumbnail ? 'thumbs/' : '' ) . $id . '.jpg', PRESSQUEUE_FILE );
	}

	/**
	 * Return fonts keyed by ID.
	 *
	 * @return array<string,array{name:string,file:string,scale:float}>
	 */
	public static function fonts(): array {
		return self::FONTS;
	}

	/**
	 * Return the path of a font, falling back to the default font.
	 *
	 * @param string $id Font ID.
	 * @return string
	 */
	public static function font_path( string $id ): string {
		$font = self::FONTS[ $id ] ?? self::FONTS[ self::DEFAULT_FONT ];

		return self::asset_dir() . 'fonts/' . $font['file'];
	}

	/**
	 * Return the size multiplier of a font so different faces look equally large.
	 *
	 * @param string $id Font ID.
	 * @return float
	 */
	public static function font_scale( string $id ): float {
		return ( self::FONTS[ $id ] ?? self::FONTS[ self::DEFAULT_FONT ] )['scale'];
	}

	/**
	 * Return the path of the small font used for the site name.
	 *
	 * @return string
	 */
	public static function small_font_path(): string {
		return self::asset_dir() . 'fonts/' . self::SMALL_FONT_FILE;
	}

	/**
	 * Return the bundled asset directory.
	 *
	 * @return string
	 */
	private static function asset_dir(): string {
		return dirname( __DIR__, 2 ) . '/assets/social/';
	}
}
