<?php
/**
 * Editor preview REST controller.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Editorial\PostControlAuthorization;
use PressQueue\Editorial\PostControlMetadata;
use PressQueue\Editorial\PostControlPreviewService;
use PressQueue\Security\SafeOperatorError;
use PressQueue\Storage\ValidationException;

/**
 * Handles nondurable dirty-editor preview requests.
 */
final class PostPreviewController {
	/**
	 * Register hooks.
	 */
	public static function register_hooks(): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		}
	}

	/**
	 * Register the preview route.
	 */
	public static function register_routes(): void {
		if ( ! function_exists( 'register_rest_route' ) ) {
			return;
		}

		register_rest_route(
			PostControlsController::REST_NAMESPACE,
			'/posts/(?P<post_id>\d+)/preview',
			array(
				'callback'            => array( self::class, 'handle_preview' ),
				'methods'             => 'POST',
				'permission_callback' => array( self::class, 'permission_callback' ),
			)
		);
	}

	/**
	 * Permission callback.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public static function permission_callback( \WP_REST_Request $request ) {
		$post_id = self::post_id_from_rest_request( $request );

		if ( function_exists( 'is_user_logged_in' ) && ! is_user_logged_in() ) {
			return self::rest_error( 'unauthenticated', 'Authentication is required.', 401 );
		}

		if ( ! function_exists( 'get_post' ) || ! ( get_post( $post_id ) instanceof \WP_Post ) ) {
			return self::rest_error( 'not_found', 'Post does not exist.', 404 );
		}

		if ( ! PostControlAuthorization::is_supported_post( $post_id ) ) {
			return self::rest_error( 'invalid_post_type', 'PressQueue controls are not supported for this post type.', 400 );
		}

		if ( ! PostControlAuthorization::current_user_can_access( $post_id ) ) {
			return self::rest_error( 'forbidden', 'You are not allowed to preview PressQueue controls for this post.', 403 );
		}

		return true;
	}

	/**
	 * Handle one preview request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function handle_preview( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = self::post_id_from_rest_request( $request );

		try {
			PostControlAuthorization::require_current_user_access( $post_id );

			$params = self::request_json_object( $request );
			if ( ! isset( $params['editor_snapshot'] ) || ! is_array( $params['editor_snapshot'] ) || array_is_list( $params['editor_snapshot'] ) ) {
				throw new ValidationException( 'A complete editor snapshot is required.' );
			}

			if ( ! isset( $params['controls'] ) || ! is_array( $params['controls'] ) || array_is_list( $params['controls'] ) ) {
				throw new ValidationException( 'A complete draft control bundle is required.' );
			}

			$trigger = isset( $params['trigger'] ) && is_string( $params['trigger'] ) ? $params['trigger'] : null;
			$service = new PostControlPreviewService();
			$result  = $service->preview(
				$post_id,
				$params['editor_snapshot'],
				PostControlMetadata::normalize_bundle( $params['controls'] ),
				$trigger
			);

			return self::rest_response( $service->response( $result ), 200 );
		} catch ( ValidationException $exception ) {
			return self::error_response( 'invalid_preview', SafeOperatorError::message( $exception ), 400, SafeOperatorError::field_errors( $exception, 'preview' ) );
		} catch ( \Throwable $throwable ) {
			return self::error_response( 'forbidden', SafeOperatorError::message( $throwable, __( 'PressQueue preview could not be created.', 'pressqueue' ) ), 403 );
		}
	}

	/**
	 * Return a post ID from a REST request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return int
	 */
	private static function post_id_from_rest_request( \WP_REST_Request $request ): int {
		return max( 0, (int) $request['post_id'] );
	}

	/**
	 * Return a JSON object from a REST request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	private static function request_json_object( \WP_REST_Request $request ): array {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) || array_is_list( $params ) ) {
			throw new ValidationException( 'REST request body must be a JSON object.' );
		}

		return $params;
	}

	/**
	 * Build a REST response.
	 *
	 * @param array<string,mixed> $body Body.
	 * @param int                 $status Status.
	 * @return \WP_REST_Response
	 */
	private static function rest_response( array $body, int $status ): \WP_REST_Response {
		return new \WP_REST_Response( $body, $status );
	}

	/**
	 * Build a REST error response.
	 *
	 * @param string              $code Code.
	 * @param string              $message Message.
	 * @param int                 $status Status.
	 * @param array<string,mixed> $field_errors Field errors.
	 * @return \WP_REST_Response
	 */
	private static function error_response( string $code, string $message, int $status, array $field_errors = array() ): \WP_REST_Response {
		return self::rest_response(
			array(
				'code'         => $code,
				'field_errors' => $field_errors,
				'message'      => $message,
			),
			$status
		);
	}

	/**
	 * Build a REST permission error.
	 *
	 * @param string $code Code.
	 * @param string $message Message.
	 * @param int    $status Status.
	 * @return \WP_Error
	 */
	private static function rest_error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error(
			$code,
			$message,
			array(
				'field_errors' => array(),
				'status'       => $status,
			)
		);
	}
}
