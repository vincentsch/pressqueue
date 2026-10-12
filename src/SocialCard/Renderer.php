<?php
/**
 * Draws social card images with GD.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\SocialCard;

use RuntimeException;

/**
 * Renders a 1200 x 630 JPEG with the post title on a background.
 */
final class Renderer {
	/**
	 * Bump when the drawing changes, so stored cards are redrawn.
	 */
	public const VERSION = '1';

	public const WIDTH  = 1200;
	public const HEIGHT = 630;

	private const PAD_X         = 88;
	private const PAD_TOP       = 84;
	private const FOOTER_HEIGHT = 112;
	private const MAX_LINES     = 4;
	private const MAX_SIZE      = 66;
	private const MIN_SIZE      = 30;
	private const LINE_HEIGHT   = 1.16;
	private const PX_PER_POINT  = 96 / 72;

	/**
	 * Check whether this server can draw cards.
	 *
	 * @return bool
	 */
	public static function is_supported(): bool {
		return function_exists( 'imagecreatetruecolor' )
			&& function_exists( 'imagettftext' )
			&& function_exists( 'imagettfbbox' )
			&& function_exists( 'imagejpeg' )
			&& function_exists( 'imagecreatefromstring' );
	}

	/**
	 * Render a card.
	 *
	 * @param string              $title Post title.
	 * @param array<string,mixed> $style Style from Settings::style().
	 * @return string JPEG bytes.
	 */
	public function render( string $title, array $style ): string {
		if ( ! self::is_supported() ) {
			throw new RuntimeException( 'This server cannot draw images. PHP needs the GD extension with FreeType.' );
		}

		$canvas = imagecreatetruecolor( self::WIDTH, self::HEIGHT );
		if ( false === $canvas ) {
			throw new RuntimeException( 'Could not create the image canvas.' );
		}

		imagealphablending( $canvas, true );
		$this->paint_background( $canvas, $style );

		$light = $this->uses_light_text( $canvas, $style );
		$this->paint_scrim( $canvas, $light, (string) $style['align'] );

		$font  = Catalog::font_path( (string) $style['font'] );
		$scale = Catalog::font_scale( (string) $style['font'] );
		$text  = self::clean_title( $title );
		$label = (string) ( $style['site_label'] ?? '' );
		$this->paint_title( $canvas, $text, $font, $scale, $light, (string) $style['align'], '' !== $label );

		if ( '' !== $label ) {
			$this->paint_label( $canvas, $label, $light, (string) $style['align'] );
		}

		ob_start();
		imagejpeg( $canvas, null, 88 );
		$bytes = (string) ob_get_clean();
		imagedestroy( $canvas );

		if ( '' === $bytes ) {
			throw new RuntimeException( 'Could not encode the image.' );
		}

		return $bytes;
	}

	/**
	 * Remove markup, entities and characters the bundled fonts cannot draw.
	 *
	 * @param string $title Raw title.
	 * @return string
	 */
	public static function clean_title( string $title ): string {
		$title = html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// Emoji and pictographs have no glyphs in the bundled fonts; drop them instead of drawing boxes.
		$title = (string) preg_replace( '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u', '', $title );
		$title = trim( (string) preg_replace( '/\s+/u', ' ', $title ) );

		return '' === $title ? '…' : $title;
	}

	/**
	 * Paint the background, scaled to cover the canvas.
	 *
	 * @param \GdImage            $canvas Canvas.
	 * @param array<string,mixed> $style Style.
	 */
	private function paint_background( \GdImage $canvas, array $style ): void {
		$path = $this->background_file( $style );
		$data = '' !== $path && is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		$src  = is_string( $data ) && '' !== $data ? @imagecreatefromstring( $data ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid images are handled below.

		if ( false === $src ) {
			$fill = imagecolorallocate( $canvas, 22, 27, 45 );
			imagefilledrectangle( $canvas, 0, 0, self::WIDTH, self::HEIGHT, (int) $fill );
			return;
		}

		$width  = imagesx( $src );
		$height = imagesy( $src );
		$ratio  = max( self::WIDTH / $width, self::HEIGHT / $height );
		$crop_w = (int) round( self::WIDTH / $ratio );
		$crop_h = (int) round( self::HEIGHT / $ratio );
		$src_x  = (int) max( 0, round( ( $width - $crop_w ) / 2 ) );
		$src_y  = (int) max( 0, round( ( $height - $crop_h ) / 2 ) );

		imagecopyresampled( $canvas, $src, 0, 0, $src_x, $src_y, self::WIDTH, self::HEIGHT, $crop_w, $crop_h );
		imagedestroy( $src );
	}

	/**
	 * Return the background file for a style.
	 *
	 * @param array<string,mixed> $style Style.
	 * @return string
	 */
	private function background_file( array $style ): string {
		if ( 'custom' === ( $style['background'] ?? '' ) && (int) ( $style['custom_image'] ?? 0 ) > 0 && function_exists( 'get_attached_file' ) ) {
			$file = get_attached_file( (int) $style['custom_image'] );
			if ( is_string( $file ) && is_readable( $file ) ) {
				return $file;
			}
		}

		$id = (string) ( $style['background'] ?? Catalog::DEFAULT_BACKGROUND );

		return Catalog::background_path( null === Catalog::background( $id ) ? Catalog::DEFAULT_BACKGROUND : $id );
	}

	/**
	 * Decide whether the title is white or near-black.
	 *
	 * @param \GdImage            $canvas Canvas with the background.
	 * @param array<string,mixed> $style Style.
	 * @return bool True for light text.
	 */
	private function uses_light_text( \GdImage $canvas, array $style ): bool {
		if ( 'light' === $style['text'] ) {
			return true;
		}

		if ( 'dark' === $style['text'] ) {
			return false;
		}

		$background = Catalog::background( (string) $style['background'] );
		if ( null !== $background ) {
			return 'light' === $background['text'];
		}

		// Own image: measure the brightness where the title goes.
		$sum   = 0;
		$count = 0;
		$right = 'center' === $style['align'] ? self::WIDTH - self::PAD_X : (int) ( self::WIDTH * 0.7 );
		for ( $x = self::PAD_X; $x < $right; $x += 24 ) {
			for ( $y = self::PAD_TOP; $y < self::HEIGHT - self::FOOTER_HEIGHT; $y += 24 ) {
				$rgb  = imagecolorat( $canvas, $x, $y );
				$sum += 0.2126 * ( ( $rgb >> 16 ) & 0xFF ) + 0.7152 * ( ( $rgb >> 8 ) & 0xFF ) + 0.0722 * ( $rgb & 0xFF );
				++$count;
			}
		}

		return ( $sum / $count ) < 150;
	}

	/**
	 * Darken or lighten the title area so the text stays readable on any background.
	 *
	 * @param \GdImage $canvas Canvas.
	 * @param bool     $light Whether the text is light.
	 * @param string   $align Alignment.
	 */
	private function paint_scrim( \GdImage $canvas, bool $light, string $align ): void {
		$base     = $light ? array( 6, 9, 20 ) : array( 255, 255, 255 );
		$strength = $light ? 0.42 : 0.38;

		if ( 'center' === $align ) {
			$alpha = (int) round( 127 - 127 * $strength * 0.7 );
			$color = imagecolorallocatealpha( $canvas, $base[0], $base[1], $base[2], $alpha );
			imagefilledrectangle( $canvas, 0, 0, self::WIDTH, self::HEIGHT, (int) $color );
			return;
		}

		$fade = (int) ( self::WIDTH * 0.78 );
		for ( $x = 0; $x < $fade; $x += 4 ) {
			$t     = $x / $fade;
			$alpha = (int) round( 127 - 127 * $strength * ( 1 - $t * $t ) );
			$color = imagecolorallocatealpha( $canvas, $base[0], $base[1], $base[2], max( 0, min( 127, $alpha ) ) );
			imagefilledrectangle( $canvas, $x, 0, $x + 3, self::HEIGHT, (int) $color );
		}
	}

	/**
	 * Draw the title, as large as fits in four lines.
	 *
	 * @param \GdImage $canvas Canvas.
	 * @param string   $title Clean title.
	 * @param string   $font Font file.
	 * @param float    $scale Font size multiplier.
	 * @param bool     $light Whether the text is light.
	 * @param string   $align Alignment.
	 * @param bool     $has_label Whether a site label sits below.
	 */
	private function paint_title( \GdImage $canvas, string $title, string $font, float $scale, bool $light, string $align, bool $has_label ): void {
		$max_width = 'center' === $align ? self::WIDTH - 2 * self::PAD_X : 860;
		$area_top  = self::PAD_TOP + 40;
		$area_end  = self::HEIGHT - ( $has_label ? self::FOOTER_HEIGHT : self::PAD_TOP );

		$size  = self::MIN_SIZE;
		$lines = array();
		for ( $candidate = self::MAX_SIZE; $candidate >= self::MIN_SIZE; $candidate -= 2 ) {
			$size  = (int) round( $candidate * $scale );
			$lines = $this->wrap( $title, $font, $size, $max_width );
			if ( count( $lines ) <= self::MAX_LINES && count( $lines ) * self::line_height( $size ) <= $area_end - $area_top ) {
				break;
			}
		}

		if ( count( $lines ) > self::MAX_LINES ) {
			$lines = array_slice( $lines, 0, self::MAX_LINES );
			$last  = (string) end( $lines );
			while ( '' !== $last && $this->text_width( $last . '…', $font, $size ) > $max_width ) {
				$last = (string) preg_replace( '/\s*\S+$/u', '', $last );
			}
			$lines[ self::MAX_LINES - 1 ] = rtrim( $last, ' ,.;:-' ) . '…';
		}

		$line_height = self::line_height( $size );
		$block       = count( $lines ) * $line_height;
		$top         = (int) round( $area_top + max( 0, ( $area_end - $area_top - $block ) / 2 ) - $line_height * 0.1 );
		$ascent      = $this->ascent( $font, $size );
		$color       = $light ? imagecolorallocate( $canvas, 255, 255, 255 ) : imagecolorallocate( $canvas, 17, 20, 28 );
		$accent      = $light ? imagecolorallocatealpha( $canvas, 255, 255, 255, 40 ) : imagecolorallocatealpha( $canvas, 17, 20, 28, 30 );

		$bar_x = 'center' === $align ? (int) ( ( self::WIDTH - 72 ) / 2 ) : self::PAD_X;
		imagefilledrectangle( $canvas, $bar_x, $top - 34, $bar_x + 72, $top - 28, (int) $accent );

		foreach ( $lines as $index => $line ) {
			$x = self::PAD_X;
			if ( 'center' === $align ) {
				$x = (int) round( ( self::WIDTH - $this->text_width( $line, $font, $size ) ) / 2 );
			}
			imagettftext( $canvas, $size, 0, $x, $top + $index * $line_height + $ascent, (int) $color, $font, $line );
		}
	}

	/**
	 * Return the line height in pixels for a font size in points.
	 *
	 * @param int $size Font size in points.
	 * @return int
	 */
	private static function line_height( int $size ): int {
		return (int) round( $size * self::PX_PER_POINT * self::LINE_HEIGHT );
	}

	/**
	 * Draw the site label at the bottom.
	 *
	 * @param \GdImage $canvas Canvas.
	 * @param string   $label Label.
	 * @param bool     $light Whether the text is light.
	 * @param string   $align Alignment.
	 */
	private function paint_label( \GdImage $canvas, string $label, bool $light, string $align ): void {
		$font  = Catalog::small_font_path();
		$size  = 18;
		$color = $light ? imagecolorallocatealpha( $canvas, 255, 255, 255, 25 ) : imagecolorallocatealpha( $canvas, 17, 20, 28, 20 );
		$x     = self::PAD_X;
		if ( 'center' === $align ) {
			$x = (int) round( ( self::WIDTH - $this->text_width( $label, $font, $size ) ) / 2 );
		}

		imagettftext( $canvas, $size, 0, $x, self::HEIGHT - 62, (int) $color, $font, $label );
	}

	/**
	 * Split text into lines that fit a width.
	 *
	 * @param string $text Text.
	 * @param string $font Font file.
	 * @param int    $size Font size.
	 * @param int    $max_width Maximum width in pixels.
	 * @return array<int,string>
	 */
	public function wrap( string $text, string $font, int $size, int $max_width ): array {
		$words = preg_split( '/\s+/u', $text );
		$words = is_array( $words ) ? $words : array( $text );
		$lines = array();
		$line  = '';

		foreach ( $words as $word ) {
			// Break a single word that is wider than the line on its own.
			while ( $this->text_width( $word, $font, $size ) > $max_width && mb_strlen( $word ) > 1 ) {
				$cut = mb_strlen( $word ) - 1;
				while ( $cut > 1 && $this->text_width( mb_substr( $word, 0, $cut ) . '-', $font, $size ) > $max_width ) {
					--$cut;
				}
				if ( '' !== $line ) {
					$lines[] = $line;
					$line    = '';
				}
				$lines[] = mb_substr( $word, 0, $cut ) . '-';
				$word    = mb_substr( $word, $cut );
			}

			$candidate = '' === $line ? $word : $line . ' ' . $word;
			if ( '' !== $line && $this->text_width( $candidate, $font, $size ) > $max_width ) {
				$lines[] = $line;
				$line    = $word;
			} else {
				$line = $candidate;
			}
		}

		if ( '' !== $line ) {
			$lines[] = $line;
		}

		return $this->balance( $lines, $font, $size, $max_width );
	}

	/**
	 * Avoid a lonely last word by pulling one word down when it fits.
	 *
	 * @param array<int,string> $lines Lines.
	 * @param string            $font Font file.
	 * @param int               $size Font size.
	 * @param int               $max_width Maximum width.
	 * @return array<int,string>
	 */
	private function balance( array $lines, string $font, int $size, int $max_width ): array {
		$count = count( $lines );
		if ( $count < 2 || str_contains( $lines[ $count - 1 ], ' ' ) ) {
			return $lines;
		}

		$previous = $lines[ $count - 2 ];
		$split    = strrpos( $previous, ' ' );
		if ( false === $split ) {
			return $lines;
		}

		$moved = substr( $previous, $split + 1 ) . ' ' . $lines[ $count - 1 ];
		if ( $this->text_width( $moved, $font, $size ) <= $max_width ) {
			$lines[ $count - 2 ] = substr( $previous, 0, $split );
			$lines[ $count - 1 ] = $moved;
		}

		return $lines;
	}

	/**
	 * Measure text width in pixels.
	 *
	 * @param string $text Text.
	 * @param string $font Font file.
	 * @param int    $size Font size.
	 * @return int
	 */
	private function text_width( string $text, string $font, int $size ): int {
		$box = imagettfbbox( $size, 0, $font, $text );

		return false === $box ? 0 : (int) abs( $box[2] - $box[0] );
	}

	/**
	 * Return the distance from the top of a line to its baseline.
	 *
	 * @param string $font Font file.
	 * @param int    $size Font size.
	 * @return int
	 */
	private function ascent( string $font, int $size ): int {
		$box = imagettfbbox( $size, 0, $font, 'ÄHgfy' );

		return false === $box ? $size : (int) abs( $box[7] );
	}
}
