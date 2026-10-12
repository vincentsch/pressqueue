<?php
/**
 * Uses social cards as the link preview image of posts.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\SocialCard;

/**
 * Hands the card to the SEO plugin, or prints the tags when no SEO plugin does.
 *
 * Social networks read the og:image tag of the shared link. When a post is shared
 * with its link, that tag decides the picture people see, so the card only shows
 * up everywhere when it is also the link preview image.
 */
final class LinkPreview {
	/**
	 * Register hooks.
	 */
	public static function register_hooks(): void {
		add_filter( 'the_seo_framework_image_generation_params', array( self::class, 'seo_framework_params' ), 20, 3 );
		add_action( 'wpseo_add_opengraph_images', array( self::class, 'yoast_first_image' ) );
		add_action( 'wpseo_add_opengraph_additional_images', array( self::class, 'yoast_missing_image' ) );
		add_filter( 'wpseo_twitter_image', array( self::class, 'replace_url' ), 20 );
		add_filter( 'rank_math/opengraph/facebook/image', array( self::class, 'fill_url' ), 20 );
		add_filter( 'rank_math/opengraph/twitter/image', array( self::class, 'fill_url' ), 20 );
		add_filter( 'jetpack_open_graph_tags', array( self::class, 'jetpack_tags' ), 20 );
		add_action( 'wp_head', array( self::class, 'print_tags' ), 5 );
	}

	/**
	 * Return the SEO plugin that prints link preview tags, if any.
	 *
	 * @return string Plugin key: seo_framework, yoast, rank_math, other or none.
	 */
	public static function seo_plugin(): string {
		if ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) ) {
			return 'seo_framework';
		}
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		if ( class_exists( '\RankMath' ) || defined( 'RANK_MATH_VERSION' ) ) {
			return 'rank_math';
		}
		if ( defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' ) || defined( 'SLIM_SEO_VER' ) || defined( 'WPSSO_VERSION' ) ) {
			return 'other';
		}
		if ( function_exists( 'jetpack_og_tags' ) && false !== has_action( 'wp_head', 'jetpack_og_tags' ) ) {
			return 'jetpack';
		}

		return 'none';
	}

	/**
	 * Return a readable name for an SEO plugin key.
	 *
	 * @param string $key Plugin key.
	 * @return string
	 */
	public static function seo_plugin_name( string $key ): string {
		switch ( $key ) {
			case 'seo_framework':
				return 'The SEO Framework';
			case 'yoast':
				return 'Yoast SEO';
			case 'rank_math':
				return 'Rank Math';
			case 'jetpack':
				return 'Jetpack';
			default:
				return '';
		}
	}

	/**
	 * Return the card for the post being viewed when link previews should use it.
	 *
	 * @param int  $post_id Post ID, or 0 for the current query.
	 * @param bool $has_own_image Whether the page already has its own preview image.
	 * @return array{attachment_id:int,url:string,width:int,height:int,hash:string}|null
	 */
	public static function card( int $post_id = 0, bool $has_own_image = false ): ?array {
		$mode = (string) Settings::read()['link_preview'];
		if ( 'off' === $mode || ( 'missing' === $mode && $has_own_image ) ) {
			return null;
		}

		if ( 0 === $post_id ) {
			if ( ! is_singular() || is_front_page() ) {
				return null;
			}
			$post_id = (int) get_queried_object_id();
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || ! in_array( $post->post_type, self::post_types(), true ) ) {
			return null;
		}

		if ( self::has_own_seo_image( $post_id ) ) {
			return null;
		}

		return CardStore::for_post( $post_id );
	}

	/**
	 * Check whether someone set a social image for this post in an SEO plugin.
	 *
	 * That choice was made for this one post, so it wins over the card.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function has_own_seo_image( int $post_id ): bool {
		$keys = array(
			'_social_image_url',
			'_social_image_id',
			'_yoast_wpseo_opengraph-image',
			'_yoast_wpseo_opengraph-image-id',
			'rank_math_facebook_image',
			'rank_math_facebook_image_id',
		);
		foreach ( $keys as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( ! empty( $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return the post types that get card link previews.
	 *
	 * @return array<int,string>
	 */
	public static function post_types(): array {
		$types = array( 'post' );
		if ( function_exists( 'get_post_types' ) ) {
			$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment', 'page' ) ) );
		}

		/**
		 * Filters which post types use social images as their link preview.
		 *
		 * @param array<int,string> $types Post type names.
		 */
		$types = apply_filters( 'pressqueue_social_card_post_types', $types );

		return is_array( $types ) ? array_values( array_filter( $types, 'is_string' ) ) : array();
	}

	/**
	 * Add the card to The SEO Framework's image search.
	 *
	 * The card goes first when previews always use it, or in place of the site-wide
	 * fallback when they use it only for posts without their own image.
	 *
	 * @param array<string,mixed>      $params Generation parameters.
	 * @param array<string,mixed>|null $args Query arguments, or null for the current query.
	 * @param string                   $context Caller context.
	 * @return array<string,mixed>
	 */
	public static function seo_framework_params( $params, $args = null, $context = 'social' ) {
		if ( ! is_array( $params ) || 'social' !== $context ) {
			return $params;
		}

		$mode = (string) Settings::read()['link_preview'];
		if ( 'off' === $mode ) {
			return $params;
		}

		if ( is_array( $args ) ) {
			if ( empty( $args['id'] ) || ! empty( $args['tax'] ) || ! empty( $args['pta'] ) || ! empty( $args['uid'] ) ) {
				return $params;
			}
		} elseif ( ! is_singular() || is_front_page() ) {
			return $params;
		}

		$callback = array( self::class, 'seo_framework_image' );
		if ( 'always' === $mode ) {
			$params['cbs']   = array( 'pressqueue' => $callback );
			$params['multi'] = false;
		} else {
			$params['fallback'] = array_merge( array( 'pressqueue' => $callback ), is_array( $params['fallback'] ?? null ) ? $params['fallback'] : array() );
		}

		return $params;
	}

	/**
	 * Yield the card to The SEO Framework.
	 *
	 * @param array<string,mixed>|null $args Query arguments.
	 * @param string                   $size Image size.
	 * @return \Generator<int,array<string,mixed>>
	 */
	public static function seo_framework_image( $args = null, $size = 'full' ): \Generator {
		unset( $size );
		$post_id = is_array( $args ) && ! empty( $args['id'] ) ? (int) $args['id'] : 0;
		$card    = self::card( $post_id );

		if ( null !== $card ) {
			yield array(
				'alt'    => (string) get_post_meta( $card['attachment_id'], '_wp_attachment_image_alt', true ),
				'height' => $card['height'],
				'id'     => $card['attachment_id'],
				'url'    => $card['url'],
				'width'  => $card['width'],
			);
		}
	}

	/**
	 * Put the card first in Yoast SEO's images when previews always use it.
	 *
	 * Yoast still adds the post's own image after it. Social networks use the first one.
	 *
	 * @param object $images Yoast image container.
	 */
	public static function yoast_first_image( $images ): void {
		if ( 'always' !== Settings::read()['link_preview'] || ! is_object( $images ) || ! method_exists( $images, 'add_image_by_id' ) ) {
			return;
		}

		$card = self::card();
		if ( null !== $card ) {
			$images->add_image_by_id( $card['attachment_id'] );
		}
	}

	/**
	 * Give Yoast SEO the card when the post has no image of its own.
	 *
	 * @param object $images Yoast image container.
	 */
	public static function yoast_missing_image( $images ): void {
		if ( 'missing' !== Settings::read()['link_preview'] || ! is_object( $images ) || ! method_exists( $images, 'add_image_by_id' ) ) {
			return;
		}

		if ( method_exists( $images, 'has_images' ) && $images->has_images() ) {
			return;
		}

		$card = self::card();
		if ( null !== $card ) {
			$images->add_image_by_id( $card['attachment_id'] );
		}
	}

	/**
	 * Replace an SEO plugin's image URL when previews always use the card.
	 *
	 * @param mixed $url Image URL.
	 * @return mixed
	 */
	public static function replace_url( $url ) {
		if ( 'always' !== Settings::read()['link_preview'] ) {
			return $url;
		}

		$card = self::card();

		return null === $card ? $url : $card['url'];
	}

	/**
	 * Fill an empty SEO plugin image URL, or replace it when previews always use the card.
	 *
	 * @param mixed $url Image URL.
	 * @return mixed
	 */
	public static function fill_url( $url ) {
		$card = self::card( 0, is_string( $url ) && '' !== $url );

		return null === $card ? $url : $card['url'];
	}

	/**
	 * Give Jetpack the card for its Open Graph tags.
	 *
	 * @param mixed $tags Tags keyed by property.
	 * @return mixed
	 */
	public static function jetpack_tags( $tags ) {
		if ( ! is_array( $tags ) ) {
			return $tags;
		}

		$card = self::card( 0, ! empty( $tags['og:image'] ) && ! str_contains( (string) ( is_array( $tags['og:image'] ) ? reset( $tags['og:image'] ) : $tags['og:image'] ), 'blank.jpg' ) );
		if ( null === $card ) {
			return $tags;
		}

		$tags['og:image']        = $card['url'];
		$tags['og:image:width']  = $card['width'];
		$tags['og:image:height'] = $card['height'];
		$tags['twitter:image']   = $card['url'];
		$tags['twitter:card']    = 'summary_large_image';

		return $tags;
	}

	/**
	 * Print link preview tags when no SEO plugin prints them.
	 */
	public static function print_tags(): void {
		if ( 'none' !== self::seo_plugin() ) {
			return;
		}

		$card = self::card( 0, has_post_thumbnail() );
		if ( null === $card ) {
			return;
		}

		// Nothing else prints link preview tags on this site, so print a complete set.
		$post_id     = (int) get_queried_object_id();
		$description = wp_trim_words( wp_strip_all_tags( (string) get_the_excerpt( $post_id ) ), 30, '...' );
		printf( '<meta property="og:type" content="article" />' . "\n" );
		printf( '<meta property="og:title" content="%s" />' . "\n", esc_attr( wp_strip_all_tags( get_the_title( $post_id ) ) ) );
		printf( '<meta property="og:url" content="%s" />' . "\n", esc_url( (string) get_permalink( $post_id ) ) );
		printf( '<meta property="og:site_name" content="%s" />' . "\n", esc_attr( get_bloginfo( 'name' ) ) );
		if ( '' !== $description ) {
			printf( '<meta property="og:description" content="%s" />' . "\n", esc_attr( $description ) );
		}
		printf( '<meta property="og:image" content="%s" />' . "\n", esc_url( $card['url'] ) );
		printf( '<meta property="og:image:width" content="%d" />' . "\n", (int) $card['width'] );
		printf( '<meta property="og:image:height" content="%d" />' . "\n", (int) $card['height'] );
		echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
		printf( '<meta name="twitter:image" content="%s" />' . "\n", esc_url( $card['url'] ) );
	}
}
