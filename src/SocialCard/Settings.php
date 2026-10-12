<?php
/**
 * Social card style and link preview settings.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\SocialCard;

use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\ValidationException;

/**
 * Reads and writes the site-wide social card style.
 */
final class Settings {
	public const OPTION = 'pressqueue_social_cards';

	public const LINK_PREVIEW_MODES = array( 'off', 'missing', 'always' );
	public const TEXT_MODES         = array( 'auto', 'light', 'dark' );
	public const ALIGNMENTS         = array( 'left', 'center' );

	/**
	 * Return the default settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'align'          => 'left',
			'background'     => Catalog::DEFAULT_BACKGROUND,
			'custom_image'   => 0,
			'font'           => Catalog::DEFAULT_FONT,
			'link_preview'   => 'off',
			'schema_version' => 1,
			'show_site'      => true,
			'site_label'     => '',
			'text'           => 'auto',
		);
	}

	/**
	 * Read the stored settings, repairing anything unknown with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function read(): array {
		$stored = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();

		return self::normalize( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Validate and store settings.
	 *
	 * @param array<string,mixed> $input Raw settings.
	 * @return array<string,mixed> Stored settings.
	 */
	public static function save( array $input ): array {
		$settings = self::normalize( array_merge( self::read(), $input ), true );
		update_option( self::OPTION, $settings, true );

		return $settings;
	}

	/**
	 * Normalize settings.
	 *
	 * @param array<string,mixed> $input Raw settings.
	 * @param bool                $strict Whether to reject invalid values instead of repairing them.
	 * @return array<string,mixed>
	 */
	public static function normalize( array $input, bool $strict = false ): array {
		$settings = self::defaults();

		$background = isset( $input['background'] ) && is_string( $input['background'] ) ? $input['background'] : $settings['background'];
		if ( 'custom' !== $background && null === Catalog::background( $background ) ) {
			if ( $strict ) {
				throw new ValidationException( 'Pick one of the listed backgrounds.' );
			}
			$background = $settings['background'];
		}
		$settings['background'] = $background;

		$settings['custom_image'] = isset( $input['custom_image'] ) && is_numeric( $input['custom_image'] ) ? max( 0, (int) $input['custom_image'] ) : 0;
		if ( 'custom' === $settings['background'] && $settings['custom_image'] <= 0 ) {
			if ( $strict ) {
				throw new ValidationException( 'Choose an image for your own background.' );
			}
			$settings['background'] = Catalog::DEFAULT_BACKGROUND;
		}

		$font                  = isset( $input['font'] ) && is_string( $input['font'] ) ? $input['font'] : $settings['font'];
		$settings['font']      = array_key_exists( $font, Catalog::fonts() ) ? $font : Catalog::DEFAULT_FONT;
		$settings['text']      = self::choice( $input, 'text', self::TEXT_MODES, $settings['text'] );
		$settings['align']     = self::choice( $input, 'align', self::ALIGNMENTS, $settings['align'] );
		$settings['show_site'] = ! isset( $input['show_site'] ) || (bool) $input['show_site'];

		$label = isset( $input['site_label'] ) && is_string( $input['site_label'] ) ? trim( wp_strip_all_tags( $input['site_label'] ) ) : '';
		if ( function_exists( 'mb_substr' ) ) {
			$label = mb_substr( $label, 0, 60 );
		}
		$settings['site_label'] = $label;

		$settings['link_preview'] = self::choice( $input, 'link_preview', self::LINK_PREVIEW_MODES, $settings['link_preview'] );

		return $settings;
	}

	/**
	 * Return only the parts that change how a card looks.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return array<string,mixed>
	 */
	public static function style( array $settings ): array {
		return array(
			'align'        => $settings['align'],
			'background'   => $settings['background'],
			'custom_image' => 'custom' === $settings['background'] ? (int) $settings['custom_image'] : 0,
			'font'         => $settings['font'],
			'show_site'    => (bool) $settings['show_site'],
			'site_label'   => self::site_label( $settings ),
			'text'         => $settings['text'],
		);
	}

	/**
	 * Return a short, stable hash of a style.
	 *
	 * @param array<string,mixed> $style Style.
	 * @return string
	 */
	public static function style_hash( array $style ): string {
		return substr( hash( 'sha256', CanonicalJson::encode( $style ) . '|' . Renderer::VERSION ), 0, 16 );
	}

	/**
	 * Return the label printed under the title, such as the site's domain.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return string
	 */
	public static function site_label( array $settings ): string {
		if ( empty( $settings['show_site'] ) ) {
			return '';
		}

		if ( isset( $settings['site_label'] ) && is_string( $settings['site_label'] ) && '' !== $settings['site_label'] ) {
			return $settings['site_label'];
		}

		$host = function_exists( 'home_url' ) ? (string) wp_parse_url( home_url(), PHP_URL_HOST ) : '';

		return (string) preg_replace( '/^www\./', '', $host );
	}

	/**
	 * Read one choice.
	 *
	 * @param array<string,mixed> $input Input.
	 * @param string              $key Key.
	 * @param array<int,string>   $allowed Allowed values.
	 * @param string              $fallback Fallback.
	 * @return string
	 */
	private static function choice( array $input, string $key, array $allowed, string $fallback ): string {
		$value = isset( $input[ $key ] ) && is_string( $input[ $key ] ) ? $input[ $key ] : $fallback;

		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}
}
