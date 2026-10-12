<?php
/**
 * Social card hooks.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\SocialCard;

/**
 * Keeps generated cards out of the way and removes them with their post.
 */
final class Hooks {
	/**
	 * Register hooks.
	 */
	public static function register_hooks(): void {
		add_filter( 'ajax_query_attachments_args', array( self::class, 'hide_from_media_modal' ) );
		add_action( 'pre_get_posts', array( self::class, 'hide_from_media_library' ) );
		add_action( 'before_delete_post', array( self::class, 'delete_with_post' ), 10, 2 );
		add_action( 'update_option_' . Settings::OPTION, array( CardStore::class, 'flush_memo' ), 10, 0 );
		LinkPreview::register_hooks();
	}

	/**
	 * Hide cards from the media picker.
	 *
	 * @param array<string,mixed> $query Query arguments.
	 * @return array<string,mixed>
	 */
	public static function hide_from_media_modal( $query ) {
		if ( ! is_array( $query ) ) {
			return $query;
		}

		$query['meta_query'] = array_merge( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Hides generated images from the media picker.
			is_array( $query['meta_query'] ?? null ) ? $query['meta_query'] : array(),
			array(
				array(
					'compare' => 'NOT EXISTS',
					'key'     => CardStore::META_KEY,
				),
			)
		);

		return $query;
	}

	/**
	 * Hide cards from the Media Library list view.
	 *
	 * @param \WP_Query $query Query.
	 */
	public static function hide_from_media_library( $query ): void {
		if ( ! $query instanceof \WP_Query || ! is_admin() || ! $query->is_main_query() || 'attachment' !== $query->get( 'post_type' ) ) {
			return;
		}

		global $pagenow;
		if ( 'upload.php' !== $pagenow ) {
			return;
		}

		$meta_query   = $query->get( 'meta_query' );
		$meta_query   = is_array( $meta_query ) ? $meta_query : array();
		$meta_query[] = array(
			'compare' => 'NOT EXISTS',
			'key'     => CardStore::META_KEY,
		);
		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Delete a post's cards when the post is deleted for good.
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post Post.
	 */
	public static function delete_with_post( $post_id, $post = null ): void {
		if ( $post instanceof \WP_Post && 'attachment' === $post->post_type ) {
			return;
		}

		CardStore::delete_for_post( (int) $post_id );
	}
}
