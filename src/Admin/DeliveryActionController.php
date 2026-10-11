<?php
/**
 * Delivery operator action controller.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Delivery\DeliveryQueryRepository;
use PressQueue\Delivery\OperatorActionAssessmentService;
use PressQueue\Delivery\OperatorActionService;
use PressQueue\Delivery\ReconciliationService;
use PressQueue\Security\Capabilities;
use PressQueue\Security\SafeOperatorError;
use PressQueue\Storage\PublicIds;
use PressQueue\Storage\SettingsRepository;
use PressQueue\Storage\ValidationException;

/**
 * Handles state-changing delivery operator actions.
 */
final class DeliveryActionController {
	/**
	 * Most held shares changed by one bulk request.
	 */
	public const BULK_LIMIT = 25;

	/**
	 * Most held shares checked by one bulk resume request.
	 */
	private const BULK_SCAN_LIMIT = 100;

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
	 * Test override for zero-write assessments.
	 *
	 * @var OperatorActionAssessmentService|null
	 */
	private static ?OperatorActionAssessmentService $assessment_service = null;

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
		add_action( 'admin_post_pressqueue_resume_held_deliveries', array( self::class, 'handle_resume_held_deliveries' ) );
		add_action( 'admin_post_pressqueue_cancel_held_deliveries', array( self::class, 'handle_cancel_held_deliveries' ) );
		add_action( 'admin_enqueue_scripts', array( DeliveryListController::class, 'enqueue_assets' ) );
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
	 * Set the assessment override for tests.
	 *
	 * @param OperatorActionAssessmentService|null $assessment_service Assessment service.
	 */
	public static function set_assessment_service( ?OperatorActionAssessmentService $assessment_service ): void {
		self::$assessment_service = $assessment_service;
	}

	/**
	 * Handle cancellation.
	 */
	public static function handle_cancel_delivery(): void {
		self::mutation_boundary( 'pressqueue_cancel_delivery' );
		self::require_confirmation( 'delivery_confirm_cancel' );

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
		self::require_confirmation( 'delivery_confirm_reconcile' );

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
			$messages = array(
				'cancelled'              => 'delivery_reconciled_cancelled',
				'confirmed_not_accepted' => 'delivery_reconciled_not_found',
				'confirmed_remote_id'    => 'delivery_reconciled_found',
			);
			self::redirect_detail( $delivery_public_id, $messages[ $outcome ] );
		} catch ( \Throwable $throwable ) {
			self::redirect_detail( $delivery_public_id, 'delivery_error', self::safe_error_detail( $throwable ) );
		}
	}

	/**
	 * Handle "Resume all on hold".
	 */
	public static function handle_resume_held_deliveries(): void {
		self::mutation_boundary( 'pressqueue_resume_held_deliveries' );

		self::redirect_activity( self::resume_held_batch( self::actor_user_id() ) );
	}

	/**
	 * Handle "Cancel all on hold".
	 */
	public static function handle_cancel_held_deliveries(): void {
		self::mutation_boundary( 'pressqueue_cancel_held_deliveries' );
		if ( ! self::has_confirmation() ) {
			self::redirect_activity( array( 'pressqueue_message' => 'held_confirm_cancel' ) );
		}

		self::redirect_activity( self::cancel_held_batch( self::actor_user_id() ) );
	}

	/**
	 * Resume up to BULK_LIMIT held shares through the operator service.
	 *
	 * Each share is assessed without writing first, so shares that would only be
	 * held again are skipped instead of collecting refusal events. Callers must
	 * check the nonce and capability first.
	 *
	 * @param int|null $actor_user_id Actor user ID.
	 * @return array<string,scalar> Redirect arguments for the Activity notice.
	 */
	public static function resume_held_batch( ?int $actor_user_id ): array {
		try {
			$mode = ( new SettingsRepository() )->sharing_mode();
			if ( 'live' !== $mode ) {
				return array(
					'pq_reason'          => 'paused' === $mode ? 'kill_switch_enabled' : 'dry_run_enabled',
					'pressqueue_message' => 'held_blocked',
				);
			}

			$done    = 0;
			$skipped = 0;
			$reasons = array();

			foreach ( self::held_deliveries( self::BULK_SCAN_LIMIT ) as $delivery_public_id ) {
				if ( $done >= self::BULK_LIMIT ) {
					break;
				}

				$assessment = self::assessment_service()->assess_resume( $delivery_public_id );
				if ( ! $assessment->would_mutate ) {
					++$skipped;
					$reason             = DeliveryExplainer::normalize_block_code( (string) ( $assessment->blocked_reasons[0] ?? 'unknown' ) );
					$reasons[ $reason ] = ( $reasons[ $reason ] ?? 0 ) + 1;
					continue;
				}

				try {
					self::operator_service()->resume( $delivery_public_id, $actor_user_id );
					++$done;
				} catch ( \Throwable $throwable ) {
					++$skipped;
					$reason             = self::refusal_code( $throwable );
					$reasons[ $reason ] = ( $reasons[ $reason ] ?? 0 ) + 1;
				}
			}

			return self::bulk_result( 'held_resumed', $done, $skipped, $reasons );
		} catch ( \Throwable $throwable ) {
			return array(
				'pressqueue_detail'  => self::safe_error_detail( $throwable ),
				'pressqueue_message' => 'delivery_error',
			);
		}
	}

	/**
	 * Cancel up to BULK_LIMIT held shares through the operator service.
	 *
	 * Callers must check the nonce, capability and confirmation first.
	 *
	 * @param int|null $actor_user_id Actor user ID.
	 * @return array<string,scalar> Redirect arguments for the Activity notice.
	 */
	public static function cancel_held_batch( ?int $actor_user_id ): array {
		try {
			$done    = 0;
			$skipped = 0;
			$reasons = array();

			foreach ( self::held_deliveries( self::BULK_LIMIT ) as $delivery_public_id ) {
				try {
					self::operator_service()->cancel( $delivery_public_id, $actor_user_id );
					++$done;
				} catch ( \Throwable $throwable ) {
					++$skipped;
					$reason             = self::refusal_code( $throwable );
					$reasons[ $reason ] = ( $reasons[ $reason ] ?? 0 ) + 1;
				}
			}

			return self::bulk_result( 'held_cancelled', $done, $skipped, $reasons );
		} catch ( \Throwable $throwable ) {
			return array(
				'pressqueue_detail'  => self::safe_error_detail( $throwable ),
				'pressqueue_message' => 'delivery_error',
			);
		}
	}

	/**
	 * Build the redirect arguments for a bulk result.
	 *
	 * @param string            $message Message key.
	 * @param int               $done Shares changed.
	 * @param int               $skipped Shares left unchanged.
	 * @param array<string,int> $reasons Skip reasons with counts.
	 * @return array<string,scalar>
	 */
	private static function bulk_result( string $message, int $done, int $skipped, array $reasons ): array {
		arsort( $reasons );

		return array(
			'pq_done'            => $done,
			'pq_left'            => max( 0, ( new ActivitySummary() )->count_in_states( array( 'held' ) ) - $skipped ),
			'pq_reason'          => (string) ( array_key_first( $reasons ) ?? '' ),
			'pq_skipped'         => $skipped,
			'pressqueue_message' => $message,
		);
	}

	/**
	 * Return public IDs of held shares, most recently held first.
	 *
	 * @param int $limit Maximum IDs.
	 * @return array<int,string>
	 */
	private static function held_deliveries( int $limit ): array {
		$result = ( new DeliveryQueryRepository() )->query( array( 'state' => 'held' ), 1, $limit );
		$ids    = array();

		foreach ( $result['rows'] as $delivery ) {
			$ids[] = $delivery->public_id;
		}

		return $ids;
	}

	/**
	 * Return a whitelisted refusal code from a failed operator action.
	 *
	 * @param \Throwable $throwable Failure.
	 * @return string
	 */
	private static function refusal_code( \Throwable $throwable ): string {
		$message = SafeOperatorError::message( $throwable );

		if ( 1 === preg_match( '/^Delivery (?:transition|reconciliation) refused: ([a-z_]{1,64})\.$/', $message, $matches ) ) {
			return DeliveryExplainer::normalize_block_code( $matches[1] );
		}

		return 'unknown';
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
	 * Send the person back to the share with a friendly notice when the box was not ticked.
	 *
	 * Nothing changes without the tick. Callers check the nonce and capability first.
	 *
	 * @param string $message Notice key shown after the redirect.
	 */
	private static function require_confirmation( string $message ): void {
		if ( self::has_confirmation() ) {
			return;
		}

		$delivery_public_id = self::post_action_text( 'delivery_public_id' );
		self::redirect_detail( PublicIds::is_valid( $delivery_public_id ) ? $delivery_public_id : '', $message );
	}

	/**
	 * Check whether the person ticked the confirmation box.
	 *
	 * @return bool
	 */
	private static function has_confirmation(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is checked before this method is called; the value is only compared with a fixed string.
		$confirmed = isset( $_POST['confirm_local_effect'] ) ? wp_unslash( $_POST['confirm_local_effect'] ) : '';

		return is_string( $confirmed ) && '1' === trim( $confirmed );
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

		if ( '' === $delivery_public_id || 'activity' === self::post_action_text( 'pq_return' ) ) {
			$args['page'] = DeliveryListController::SLUG;
			unset( $args['delivery_public_id'] );

			$group = self::post_action_text( 'pq_group' );
			if ( 'all' !== $group && isset( DeliveryListController::groups()[ $group ] ) ) {
				$args['group'] = $group;
			}
		}

		if ( null !== $detail && '' !== $detail ) {
			$args['pressqueue_detail'] = $detail;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Redirect to the Activity page.
	 *
	 * @param array<string,scalar> $args Query args.
	 */
	private static function redirect_activity( array $args ): void {
		$args['page'] = DeliveryListController::SLUG;
		if ( ! isset( $args['group'] ) && 'held_blocked' !== ( $args['pressqueue_message'] ?? '' ) ) {
			$args['group'] = 'held';
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Return the assessment service.
	 *
	 * @return OperatorActionAssessmentService
	 */
	private static function assessment_service(): OperatorActionAssessmentService {
		return self::$assessment_service ?? new OperatorActionAssessmentService();
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
