<?php
/**
 * Authorization and supported-post checks for editor controls.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Editorial;

use PressQueue\Security\Capabilities;
use PressQueue\Storage\ValidationException;

/**
 * Centralizes access checks for PressQueue post controls.
 */
final class PostControlAuthorization {
	private const EXCLUDED_POST_TYPES = array(
		'attachment',
		'revision',
		'nav_menu_item',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'custom_css',
		'customize_changeset',
	);

	/**
	 * Check whether a post can ever expose PressQueue controls.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_supported_post( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		if ( function_exists( 'wp_is_post_autosave' ) && wp_is_post_autosave( $post_id ) ) {
			return false;
		}

		if ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post_id ) ) {
			return false;
		}

		if ( ! function_exists( 'get_post' ) ) {
			return true;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		return self::is_supported_post_type( (string) $post->post_type );
	}

	/**
	 * Assert a supported post and return it.
	 *
	 * @param int $post_id Post ID.
	 * @return \WP_Post
	 */
	public static function require_supported_post( int $post_id ): \WP_Post {
		if ( ! function_exists( 'get_post' ) ) {
			throw new ValidationException( 'WordPress post storage is unavailable.' );
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			throw new ValidationException( 'Post does not exist.' );
		}

		if ( ! self::is_supported_post( $post_id ) ) {
			throw new ValidationException( 'PressQueue controls are not supported for this post type.' );
		}

		return $post;
	}

	/**
	 * Check whether controls may be shown or changed by the current user.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function current_user_can_access( int $post_id ): bool {
		if ( ! function_exists( 'current_user_can' ) ) {
			return false;
		}

		return current_user_can( Capabilities::EDIT_POST_CONTROLS )
			&& current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Assert current-user access to a supported post.
	 *
	 * @param int $post_id Post ID.
	 * @return \WP_Post
	 */
	public static function require_current_user_access( int $post_id ): \WP_Post {
		$post = self::require_supported_post( $post_id );

		if ( ! self::current_user_can_access( $post_id ) ) {
			throw new ValidationException( 'You are not allowed to edit PressQueue controls for this post.' );
		}

		return $post;
	}

	/**
	 * Check whether a post type supports ordinary editor controls.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public static function is_supported_post_type( string $post_type ): bool {
		if ( '' === $post_type || in_array( $post_type, self::EXCLUDED_POST_TYPES, true ) ) {
			return false;
		}

		if ( function_exists( 'get_post_type_object' ) ) {
			$object = get_post_type_object( $post_type );

			if ( ! is_object( $object ) ) {
				return false;
			}

			$show_ui = (bool) $object->show_ui;
			$public  = (bool) $object->public;

			return $show_ui && $public;
		}

		return true;
	}
}
