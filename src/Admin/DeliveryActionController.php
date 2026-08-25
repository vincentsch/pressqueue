<?php
/**
 * Delivery operator action controller.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Delivery\OperatorActionService;
use PressQueue\Delivery\ReconciliationService;
use PressQueue\Security\Capabilities;
use PressQueue\Security\SafeOperatorError;
use PressQueue\Storage\PublicIds;
use PressQueue\Storage\ValidationException;

/**
 * Handles state-changing delivery operator actions.
 */
final class DeliveryActionController {
	/**
	 * Test override for operator actions.
	 *
	 * @var OperatorActionService|null
	 */
	private static ?OperatorActionService $operator_service = null;

	/**
	 * Test override for reconciliation.
	 *
	 * @var ReconciliationService|null
	 */
	private static ?ReconciliationService $reconciliation_service = null;

	/**
	 * Register hooks.
	 */
	public static function register_hooks(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( 'admin_post_pressqueue_cancel_delivery', array( self::class, 'handle_cancel_delivery' ) );
		add_action( 'admin_post_pressqueue_resume_delivery', array( self::class, 'handle_resume_delivery' ) );
		add_action( 'admin_post_pressqueue_retry_delivery', array( self::class, 'handle_retry_delivery' ) );
		add_action( 'admin_post_pressqueue_reconcile_delivery', array( self::class, 'handle_reconcile_delivery' ) );
	}

	/**
	 * Set service overrides for tests.
	 *
	 * @param OperatorActionService|null $operator_service Operator service.
	 * @param ReconciliationService|null $reconciliation_service Reconciliation service.
	 */
	public static function set_services( ?OperatorActionService $operator_service, ?ReconciliationService $reconciliation_service ): void {
		self::$operator_service       = $operator_service;
		self::$reconciliation_service = $reconciliation_service;
	}

	/**
	 * Handle cancellation.
	 */
	public static function handle_cancel_delivery(): void {
		self::mutation_boundary( 'pressqueue_cancel_delivery' );
		self::require_confirmation();

		$delivery_public_id = '';
		try {
			$delivery_public_id = self::post_delivery_public_id();
			self::operator_service()->cancel( $delivery_public_id, self::actor_user_id() );
			self::redirect_detail( $delivery_public_id, 'delivery_cancelled' );
		} catch ( \Throwable $throwable ) {
			self::redirect_detail( $delivery_public_id, 'delivery_error', self::safe_error_detail( $throwable ) );
		}
	}

	/**
	 * Handle resume.
	 */
	public static function handle_resume_delivery(): void {
		self::mutation_boundary( 'pressqueue_resume_delivery' );

		$delivery_public_id = '';
		try {
			$delivery_public_id = self::post_delivery_public_id();
			self::operator_service()->resume( $delivery_public_id, self::actor_user_id() );
			self::redirect_detail( $delivery_public_id, 'delivery_resumed' );
		} catch ( \Throwable $throwable ) {
			self::redirect_detail( $delivery_public_id, 'delivery_error', self::safe_error_detail( $throwable ) );
		}
	}

	/**
	 * Handle manual retry.
	 */
	public static function handle_retry_delivery(): void {
		self::mutation_boundary( 'pressqueue_retry_delivery' );

		$delivery_public_id = '';
		try {
			$delivery_public_id = self::post_delivery_public_id();
			self::operator_service()->retry( $delivery_public_id, self::actor_user_id() );
			self::redirect_detail( $delivery_public_id, 'delivery_retried' );
		} catch ( \Throwable $throwable ) {
			self::redirect_detail( $delivery_public_id, 'delivery_error', self::safe_error_detail( $throwable ) );
		}
	}

	/**
	 * Handle ambiguity reconciliation.
	 */
	public static function handle_reconcile_delivery(): void {
		self::require_capability();
		self::require_post_request();

		$outcome = self::post_action_text( 'outcome' );
		if ( ! in_array( $outcome, array( 'confirmed_remote_id', 'confirmed_not_accepted', 'cancelled' ), true ) ) {
			wp_die( esc_html__( 'Delivery reconciliation outcome is not supported.', 'pressqueue' ) );
		}

		AdminPostBoundary::check( 'pressqueue_reconcile_delivery_' . $outcome, Capabilities::OPERATE_DELIVERIES, __( 'You are not allowed to operate PressQueue deliveries.', 'pressqueue' ) );
		self::require_confirmation();

		$delivery_public_id = '';
		try {
			$delivery_public_id = self::post_delivery_public_id();
			self::reconciliation_service()->reconcile(
				$delivery_public_id,
				$outcome,
				self::post_optional_raw_text( 'remote_id' ),
				self::post_optional_raw_text( 'note' ),
				self::actor_user_id()
			);
			self::redirect_detail( $delivery_public_id, 'delivery_reconciled' );
		} catch ( \Throwable $throwable ) {
			self::redirect_detail( $delivery_public_id, 'delivery_error', self::safe_error_detail( $throwable ) );
		}
	}

	/**
	 * Assert mutation request requirements.
	 *
	 * @param string $nonce_action Nonce action.
	 */
	private static function mutation_boundary( string $nonce_action ): void {
		AdminPostBoundary::check( $nonce_action, Capabilities::OPERATE_DELIVERIES, __( 'You are not allowed to operate PressQueue deliveries.', 'pressqueue' ) );
	}

	/**
	 * Require operator capability.
	 */
	private static function require_capability(): void {
		if ( ! current_user_can( Capabilities::OPERATE_DELIVERIES ) ) {
			wp_die( esc_html__( 'You are not allowed to operate PressQueue deliveries.', 'pressqueue' ) );
		}
	}

	/**
	 * Require POST.
	 */
	private static function require_post_request(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Request method is normalized on the next line.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';

		if ( 'POST' !== $method ) {
			wp_die( esc_html__( 'PressQueue changes require POST.', 'pressqueue' ) );
		}
	}

	/**
	 * Require explicit local-effect confirmation.
	 */
	private static function require_confirmation(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is checked before this method is called.
		$confirmed = isset( $_POST['confirm_local_effect'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm_local_effect'] ) ) : '';

		if ( '1' !== $confirmed ) {
			wp_die( esc_html__( 'Delivery action confirmation is required.', 'pressqueue' ) );
		}
	}

	/**
	 * Return a validated delivery public ID.
	 *
	 * @return string
	 */
	private static function post_delivery_public_id(): string {
		$value = self::post_action_text( 'delivery_public_id' );
		PublicIds::assert( $value );

		return $value;
	}

	/**
	 * Return one unmodified POST string.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private static function post_action_text( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Callers check nonces before reading mutations.
		$value = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';

		if ( ! is_string( $value ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Return an optional opaque POST string without text-field rewriting.
	 *
	 * @param string $key Key.
	 * @return string|null
	 */
	private static function post_optional_raw_text( string $key ): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Opaque IDs and notes are validated or sanitized by the service.
		$value = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';

		if ( ! is_string( $value ) ) {
			return null;
		}

		if ( '' === $value ) {
			return null;
		}

		return $value;
	}

	/**
	 * Return the current actor user ID.
	 *
	 * @return int|null
	 */
	private static function actor_user_id(): ?int {
		return function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : null;
	}

	/**
	 * Redirect back to the delivery detail screen.
	 *
	 * @param string      $delivery_public_id Delivery public ID.
	 * @param string      $message Message key.
	 * @param string|null $detail Optional detail.
	 */
	private static function redirect_detail( string $delivery_public_id, string $message, ?string $detail = null ): void {
		$args = array(
			'delivery_public_id' => $delivery_public_id,
			'page'               => DeliveryDetailController::SLUG,
			'pressqueue_message' => $message,
		);

		if ( '' === $delivery_public_id ) {
			$args['page'] = DeliveryListController::SLUG;
			unset( $args['delivery_public_id'] );
		}

		if ( null !== $detail && '' !== $detail ) {
			$args['pressqueue_detail'] = $detail;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Return the operator service.
	 *
	 * @return OperatorActionService
	 */
	private static function operator_service(): OperatorActionService {
		return self::$operator_service ?? new OperatorActionService();
	}

	/**
	 * Return the reconciliation service.
	 *
	 * @return ReconciliationService
	 */
	private static function reconciliation_service(): ReconciliationService {
		return self::$reconciliation_service ?? new ReconciliationService();
	}

	/**
	 * Return a redacted exception detail.
	 *
	 * @param \Throwable $throwable Throwable.
	 * @return string
	 */
	private static function safe_error_detail( \Throwable $throwable ): string {
		return SafeOperatorError::redirect_detail( $throwable );
	}
}
