<?php
/**
 * Stores generated social cards as attachments of their post.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\SocialCard;

use Throwable;

/**
 * Creates, finds and removes social card files.
 *
 * A card is saved as an attachment whose parent is the post, so the normal media
 * pipeline can send it and SEO plugins can use it as the link preview image.
 * Cards are keyed by a hash of the title and style, so a new title or style makes
 * a new card and an unchanged post reuses its card.
 */
final class CardStore {
	public const META_KEY = '_pressqueue_social_card';

	/**
	 * Superseded cards are kept this long, because Buffer fetches the image by its URL
	 * when a queued post goes out, which can be days after it was queued.
	 */
	private const KEEP_SUPERSEDED_SECONDS = 30 * 86400;

	private const UPLOAD_SUBDIR = 'pressqueue/social';

	/**
	 * Cards found or made in this request, keyed by post ID.
	 *
	 * @var array<int,array<string,mixed>|null>
	 */
	private static array $memo = array();

	/**
	 * Return the card for a post, drawing it first when needed.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $create Whether to draw a missing card.
	 * @return array{attachment_id:int,url:string,width:int,height:int,hash:string}|null
	 */
	public static function for_post( int $post_id, bool $create = true ): ?array {
		if ( array_key_exists( $post_id, self::$memo ) && ( null !== self::$memo[ $post_id ] || ! $create ) ) {
			return self::$memo[ $post_id ];
		}

		$post = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post || 'attachment' === $post->post_type ) {
			return null;
		}

		// A stored card is a public file, so only published posts get one.
		// Drafts, scheduled and private posts keep their titles to themselves.
		if ( ! self::may_store( $post ) ) {
			$create = false;
		}

		$title = self::title( $post );
		if ( '' === $title ) {
			return null;
		}

		$style = Settings::style( Settings::read() );
		$hash  = self::card_hash( $title, $style );
		$card  = self::find( $post_id, $hash );

		if ( null === $card && $create ) {
			$card = self::create( $post, $title, $style, $hash );
		}

		self::$memo[ $post_id ] = $card;

		return $card;
	}

	/**
	 * Whether a post may get a stored card.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	public static function may_store( \WP_Post $post ): bool {
		return 'publish' === $post->post_status;
	}

	/**
	 * Whether a post that has no stored card yet will get one when it is published.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function will_draw_when_published( int $post_id ): bool {
		$post = function_exists( 'get_post' ) ? get_post( $post_id ) : null;

		return $post instanceof \WP_Post
			&& 'attachment' !== $post->post_type
			&& ! self::may_store( $post )
			&& '' !== self::title( $post )
			&& Renderer::is_supported();
	}

	/**
	 * Forget cards remembered in this request, for example after the style changed.
	 */
	public static function flush_memo(): void {
		self::$memo = array();
	}

	/**
	 * Return the title drawn on a post's card.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public static function title( \WP_Post $post ): string {
		$title = (string) $post->post_title;

		/**
		 * Filters the text drawn on a post's social image.
		 *
		 * @param string   $title Post title.
		 * @param \WP_Post $post  Post.
		 */
		$title = function_exists( 'apply_filters' ) ? (string) apply_filters( 'pressqueue_social_card_title', $title, $post ) : $title;

		return trim( wp_strip_all_tags( $title ) );
	}

	/**
	 * Return the hash that identifies one card drawing.
	 *
	 * @param string              $title Title.
	 * @param array<string,mixed> $style Style.
	 * @return string
	 */
	public static function card_hash( string $title, array $style ): string {
		return substr( hash( 'sha256', $title . '|' . Settings::style_hash( $style ) ), 0, 16 );
	}

	/**
	 * Check whether an attachment is a PressQueue social card.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function is_card( int $attachment_id ): bool {
		return $attachment_id > 0 && function_exists( 'get_post_meta' ) && '' !== (string) get_post_meta( $attachment_id, self::META_KEY, true );
	}

	/**
	 * Delete every card of a post.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function delete_for_post( int $post_id ): void {
		foreach ( self::card_ids( $post_id ) as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}
		unset( self::$memo[ $post_id ] );
	}

	/**
	 * Delete every card on the site. Used on uninstall.
	 */
	public static function delete_all(): void {
		if ( ! function_exists( 'get_posts' ) ) {
			return;
		}

		do {
			$ids = get_posts(
				array(
					'fields'                 => 'ids',
					'meta_key'               => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Uninstall cleanup.
					'no_found_rows'          => true,
					'post_status'            => 'any',
					'post_type'              => 'attachment',
					'posts_per_page'         => 100,
					// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- PressQueue's own image attachments; other plugins' query filters must not hide them from lookups and cleanup.
					'suppress_filters'       => true,
					'update_post_term_cache' => false,
				)
			);

			foreach ( $ids as $id ) {
				wp_delete_attachment( (int) $id, true );
			}
			$full_page = 100 === count( $ids );
		} while ( $full_page );

		$uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir( null, false ) : array();
		$dir     = isset( $uploads['basedir'] ) && is_string( $uploads['basedir'] ) ? trailingslashit( $uploads['basedir'] ) . self::UPLOAD_SUBDIR : '';
		if ( '' !== $dir && is_dir( $dir ) ) {
			foreach ( (array) glob( $dir . '/*.jpg' ) as $file ) {
				if ( is_string( $file ) ) {
					wp_delete_file( $file );
				}
			}
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Best effort; the directory may hold other files.
			@rmdir( dirname( $dir ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Best effort.
		}
	}

	/**
	 * Find a stored card with this hash.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $hash Card hash.
	 * @return array{attachment_id:int,url:string,width:int,height:int,hash:string}|null
	 */
	private static function find( int $post_id, string $hash ): ?array {
		$ids = get_posts(
			array(
				'fields'                 => 'ids',
				'meta_key'               => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Small lookup on one post's children.
				'meta_value'             => $hash, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Small lookup on one post's children.
				'no_found_rows'          => true,
				'post_parent'            => $post_id,
				'post_status'            => 'inherit',
				'post_type'              => 'attachment',
				'posts_per_page'         => 1,
				// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- PressQueue's own image attachments; other plugins' query filters must not hide them from lookups and cleanup.
				'suppress_filters'       => true,
				'update_post_term_cache' => false,
			)
		);

		if ( array() === $ids ) {
			return null;
		}

		$attachment_id = (int) $ids[0];
		$file          = get_attached_file( $attachment_id );
		if ( ! is_string( $file ) || ! is_file( $file ) ) {
			// The file was removed outside WordPress. Drop the record and draw it again.
			wp_delete_attachment( $attachment_id, true );
			return null;
		}

		return self::describe( $attachment_id, $hash );
	}

	/**
	 * Draw and store a new card.
	 *
	 * @param \WP_Post            $post Post.
	 * @param string              $title Title.
	 * @param array<string,mixed> $style Style.
	 * @param string              $hash Card hash.
	 * @return array{attachment_id:int,url:string,width:int,height:int,hash:string}|null
	 */
	private static function create( \WP_Post $post, string $title, array $style, string $hash ): ?array {
		if ( ! Renderer::is_supported() || ! function_exists( 'wp_insert_attachment' ) ) {
			return null;
		}

		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || ! isset( $uploads['basedir'] ) || ! is_string( $uploads['basedir'] ) ) {
			return null;
		}

		$dir = trailingslashit( $uploads['basedir'] ) . self::UPLOAD_SUBDIR;
		if ( ! wp_mkdir_p( $dir ) ) {
			return null;
		}

		try {
			$bytes = ( new Renderer() )->render( $title, $style );
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			return null;
		}

		$slug     = sanitize_title( $post->post_name ? $post->post_name : $title );
		$slug     = '' === $slug ? 'post' : substr( $slug, 0, 60 );
		$filename = $slug . '-' . $post->ID . '-' . substr( $hash, 0, 8 ) . '.jpg';
		$file     = $dir . '/' . $filename;

		if ( false === file_put_contents( $file, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing a generated image into uploads.
			return null;
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_author'    => (int) $post->post_author,
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
				/* translators: %s: post title. */
				'post_title'     => sprintf( __( 'Social image: %s', 'pressqueue' ), $title ),
			),
			$file,
			$post->ID,
			true
		);

		if ( is_wp_error( $attachment_id ) || (int) $attachment_id <= 0 ) {
			wp_delete_file( $file );
			return null;
		}

		$attachment_id = (int) $attachment_id;
		$relative      = self::UPLOAD_SUBDIR . '/' . $filename;
		update_post_meta( $attachment_id, self::META_KEY, $hash );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', Renderer::clean_title( $title ) );
		wp_update_attachment_metadata(
			$attachment_id,
			array(
				'file'     => $relative,
				'filesize' => strlen( $bytes ),
				'height'   => Renderer::HEIGHT,
				'sizes'    => array(),
				'width'    => Renderer::WIDTH,
			)
		);

		self::remove_superseded( (int) $post->ID, $hash );

		return self::describe( $attachment_id, $hash );
	}

	/**
	 * Remove older cards of a post once nothing queued is likely to need them.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $keep_hash Hash of the current card.
	 */
	private static function remove_superseded( int $post_id, string $keep_hash ): void {
		$cutoff = time() - self::KEEP_SUPERSEDED_SECONDS;

		foreach ( self::card_ids( $post_id ) as $attachment_id ) {
			if ( (string) get_post_meta( $attachment_id, self::META_KEY, true ) === $keep_hash ) {
				continue;
			}

			$created = strtotime( (string) get_post_field( 'post_date_gmt', $attachment_id ) . ' UTC' );
			if ( false !== $created && $created < $cutoff ) {
				wp_delete_attachment( $attachment_id, true );
			}
		}
	}

	/**
	 * Return the IDs of every card of a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int,int>
	 */
	private static function card_ids( int $post_id ): array {
		if ( $post_id <= 0 || ! function_exists( 'get_posts' ) ) {
			return array();
		}

		return array_map(
			'intval',
			get_posts(
				array(
					'fields'                 => 'ids',
					'meta_key'               => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Small lookup on one post's children.
					'no_found_rows'          => true,
					'post_parent'            => $post_id,
					'post_status'            => 'any',
					'post_type'              => 'attachment',
					'posts_per_page'         => 50,
					// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- PressQueue's own image attachments; other plugins' query filters must not hide them from lookups and cleanup.
					'suppress_filters'       => true,
					'update_post_term_cache' => false,
				)
			)
		);
	}

	/**
	 * Describe a stored card.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $hash Card hash.
	 * @return array{attachment_id:int,url:string,width:int,height:int,hash:string}|null
	 */
	private static function describe( int $attachment_id, string $hash ): ?array {
		$url = wp_get_attachment_url( $attachment_id );
		if ( ! is_string( $url ) || '' === $url ) {
			return null;
		}

		return array(
			'attachment_id' => $attachment_id,
			'hash'          => $hash,
			'height'        => Renderer::HEIGHT,
			'url'           => $url,
			'width'         => Renderer::WIDTH,
		);
	}
}
