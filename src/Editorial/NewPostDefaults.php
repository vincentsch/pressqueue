<?php
/**
 * Default sharing choice for newly created posts.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Editorial;

use PressQueue\Storage\SettingsRepository;

/**
 * Marks new posts as "do not share" when the site uses opt-in sharing.
 *
 * The marker is the legacy `_pressqueue_policy = exclude` value. PostControlMetadata
 * reads it as an exclusion only while no full control record exists, so it can stop
 * delivery but never authorize it. Any writer may set it, including WP-CLI or REST
 * writers without PressQueue access. A full control record stored later by the editor
 * wins, so switching "Share this post" on still works.
 */
final class NewPostDefaults {
	/**
	 * Hook priority. Runs long before EventCollector captures the transition at 10.
	 */
	public const PRIORITY = -1000;

	/**
	 * Register hooks.
	 */
	public static function register_hooks(): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'transition_post_status', array( self::class, 'handle_transition' ), self::PRIORITY, 3 );
		}
	}

	/**
	 * Apply the opt-in default when a post is created.
	 *
	 * @param string $new_status New status.
	 * @param string $old_status Old status.
	 * @param mixed  $post Post.
	 */
	public static function handle_transition( $new_status, $old_status, $post ): void {
		unset( $new_status );

		if ( 'new' !== $old_status || ! $post instanceof \WP_Post ) {
			return;
		}

		self::apply_to_new_post( $post );
	}

	/**
	 * Write the exclusion marker for one new post when the site is in opt-in mode.
	 *
	 * @param \WP_Post $post Newly created post.
	 * @return bool Whether the marker was written.
	 */
	public static function apply_to_new_post( \WP_Post $post ): bool {
		try {
			if ( 'opt_in' !== ( new SettingsRepository() )->new_post_sharing() ) {
				return false;
			}
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return false;
		}

		$post_id = (int) $post->ID;
		if ( $post_id <= 0 || ! PostControlAuthorization::is_supported_post_type( (string) $post->post_type ) ) {
			return false;
		}

		if ( self::has_stored_choice( $post_id ) ) {
			return false;
		}

		return (bool) update_post_meta( $post_id, PostControlMetadata::LEGACY_META_KEY, 'exclude' );
	}

	/**
	 * Check whether a post already carries a sharing choice.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function has_stored_choice( int $post_id ): bool {
		if ( ! function_exists( 'metadata_exists' ) || ! function_exists( 'update_post_meta' ) ) {
			return true;
		}

		return metadata_exists( 'post', $post_id, PostControlMetadata::META_KEY )
			|| metadata_exists( 'post', $post_id, PostControlMetadata::LEGACY_META_KEY );
	}
}
