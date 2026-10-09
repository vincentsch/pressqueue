<?php
/**
 * Delivery log administration.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Delivery\DeliveryDisplaySnapshot;
use PressQueue\Delivery\DeliveryQueryRepository;
use PressQueue\Scheduler\ActionSchedulerQueue;
use PressQueue\Security\Capabilities;
use PressQueue\Security\SafeOperatorError;
use PressQueue\Storage\Repository\DecisionRepository;
use PressQueue\Storage\StorageEnums;
use PressQueue\Storage\ValidationException;

/**
 * Renders the operator delivery log.
 */
final class DeliveryListController {
	public const SLUG = 'pressqueue-deliveries';

	/**
	 * Register menu pages.
	 *
	 * @param string $parent_slug Parent slug.
	 */
	public static function register_menu( string $parent_slug ): void {
		add_submenu_page(
			$parent_slug,
			__( 'PressQueue Delivery Log', 'pressqueue' ),
			__( 'Delivery Log', 'pressqueue' ),
			Capabilities::VIEW_DELIVERIES,
			self::SLUG,
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Render delivery log.
	 */
	public static function render_page(): void {
		self::require_capability();

		$page      = self::bounded_int_query( 'paged', 1, 1, 999999 );
		$page_size = self::bounded_int_query( 'limit', DeliveryQueryRepository::DEFAULT_PAGE_SIZE, 1, DeliveryQueryRepository::MAX_PAGE_SIZE );
		$filters   = self::filters_from_request();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'PressQueue Delivery Log', 'pressqueue' ) . '</h1>';
		self::render_notice();
		self::render_filter_form( $filters, $page_size );

		try {
			$result = ( new DeliveryQueryRepository() )->query( $filters, $page, $page_size );
			self::render_table( $result['rows'] );
			self::render_pagination( $result['page'], $result['page_size'], $result['total'], $filters );
		} catch ( ValidationException | \PressQueue\Storage\StorageException $exception ) {
			echo '<div class="notice notice-error"><p>' . esc_html( SafeOperatorError::message( $exception, __( 'PressQueue deliveries could not be loaded.', 'pressqueue' ) ) ) . '</p></div>';
		}

		echo '</div>';
	}

	/**
	 * Render the filter form.
	 *
	 * @param array<string,mixed> $filters Filters.
	 * @param int                 $page_size Page size.
	 */
	private static function render_filter_form( array $filters, int $page_size ): void {
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" style="margin:1em 0;">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="pressqueue-delivery-state">' . esc_html__( 'State', 'pressqueue' ) . '</label></th><td>';
		echo '<select id="pressqueue-delivery-state" name="delivery_state">';
		echo '<option value="">' . esc_html__( 'Any state', 'pressqueue' ) . '</option>';
		foreach ( StorageEnums::DELIVERY_STATES as $state ) {
			echo '<option value="' . esc_attr( $state ) . '" ' . selected( $filters['state'] ?? '', $state, false ) . '>' . esc_html( $state ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th scope="row"><label for="pressqueue-delivery-destination">' . esc_html__( 'Connection or channel public ID', 'pressqueue' ) . '</label></th><td><input id="pressqueue-delivery-destination" name="destination_public_id" type="text" class="regular-text" value="' . esc_attr( (string) ( $filters['destination_public_id'] ?? '' ) ) . '" /></td></tr>';
		echo '<tr><th scope="row"><label for="pressqueue-delivery-post">' . esc_html__( 'Post ID', 'pressqueue' ) . '</label></th><td><input id="pressqueue-delivery-post" name="post_id" type="number" min="1" value="' . esc_attr( (string) ( $filters['post_id'] ?? '' ) ) . '" /></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'State changed', 'pressqueue' ) . '</th><td><input name="state_changed_from" type="date" value="' . esc_attr( (string) ( $filters['state_changed_from'] ?? '' ) ) . '" /> <input name="state_changed_to" type="date" value="' . esc_attr( (string) ( $filters['state_changed_to'] ?? '' ) ) . '" /></td></tr>';
		echo '<tr><th scope="row"><label for="pressqueue-delivery-due-retry">' . esc_html__( 'Due retry', 'pressqueue' ) . '</label></th><td><select id="pressqueue-delivery-due-retry" name="due_retry">';
		echo '<option value="">' . esc_html__( 'Any', 'pressqueue' ) . '</option>';
		echo '<option value="1" ' . selected( $filters['due_retry'] ?? '', '1', false ) . '>' . esc_html__( 'Due now', 'pressqueue' ) . '</option>';
		echo '<option value="0" ' . selected( $filters['due_retry'] ?? '', '0', false ) . '>' . esc_html__( 'Not due', 'pressqueue' ) . '</option>';
		echo '</select></td></tr>';
		echo '<tr><th scope="row"><label for="pressqueue-delivery-identifier">' . esc_html__( 'Exact identifier', 'pressqueue' ) . '</label></th><td><input id="pressqueue-delivery-identifier" name="identifier" type="text" class="regular-text" maxlength="191" value="' . esc_attr( (string) ( $filters['identifier'] ?? '' ) ) . '" /></td></tr>';
		echo '<tr><th scope="row"><label for="pressqueue-delivery-limit">' . esc_html__( 'Page size', 'pressqueue' ) . '</label></th><td><input id="pressqueue-delivery-limit" name="limit" type="number" min="1" max="100" value="' . esc_attr( (string) $page_size ) . '" /></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Filter Deliveries', 'pressqueue' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Render delivery rows.
	 *
	 * @param array<int,\PressQueue\Storage\Record\DeliveryRecord> $deliveries Deliveries.
	 */
	private static function render_table( array $deliveries ): void {
		$decisions = new DecisionRepository();
		$scheduler = new ActionSchedulerQueue();

		echo '<table class="widefat striped"><caption class="screen-reader-text">' . esc_html__( 'PressQueue delivery log results', 'pressqueue' ) . '</caption><thead><tr>';
		foreach ( array( __( 'Delivery', 'pressqueue' ), __( 'State Changed', 'pressqueue' ), __( 'Post', 'pressqueue' ), __( 'Destination', 'pressqueue' ), __( 'Attempts', 'pressqueue' ), __( 'Retry', 'pressqueue' ), __( 'Next Attempt', 'pressqueue' ), __( 'Remote ID', 'pressqueue' ), __( 'Last Error', 'pressqueue' ), __( 'Signal', 'pressqueue' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( array() === $deliveries ) {
			echo '<tr><td colspan="10">' . esc_html__( 'No deliveries matched.', 'pressqueue' ) . '</td></tr>';
		}

		foreach ( $deliveries as $delivery ) {
			$decision   = $decisions->find_by_public_id( $delivery->decision_public_id );
			$snapshot   = new DeliveryDisplaySnapshot( $delivery, $decision );
			$detail_url = add_query_arg(
				array(
					'delivery_public_id' => $delivery->public_id,
					'page'               => DeliveryDetailController::SLUG,
				),
				admin_url( 'admin.php' )
			);

			echo '<tr>';
			echo '<td><a href="' . esc_url( $detail_url ) . '"><code>' . esc_html( $delivery->public_id ) . '</code></a><br /><code>' . esc_html( $delivery->delivery_key ) . '</code><br />' . esc_html( $snapshot->state_label() ) . '</td>';
			echo '<td>' . esc_html( DeliveryDisplaySnapshot::format_timestamp( $delivery->state_changed_at ) ) . '</td>';
			echo '<td>' . esc_html( $snapshot->post_identity() ) . '</td>';
			echo '<td>' . esc_html( $snapshot->channel_label ) . '<br />' . esc_html( $snapshot->connection_label ) . '</td>';
			echo '<td>' . esc_html( (string) $delivery->attempt_count ) . '<br />' . esc_html__( 'automatic', 'pressqueue' ) . ': ' . esc_html( (string) $delivery->automatic_attempt_count ) . '</td>';
			echo '<td>' . esc_html( $snapshot->retry_safety_label() ) . '</td>';
			echo '<td>' . esc_html( DeliveryDisplaySnapshot::format_timestamp( $delivery->next_attempt_at ) ) . '</td>';
			echo '<td><code>' . esc_html( $delivery->remote_id ?? '' ) . '</code></td>';
			echo '<td>' . esc_html( $snapshot->error_class_label( $delivery->error_class ) ) . '</td>';
			echo '<td>' . esc_html( $snapshot->stalled_worker_signal( $scheduler ) ?? '' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Render pagination.
	 *
	 * @param int                 $page Current page.
	 * @param int                 $limit Page size.
	 * @param int                 $total Total rows.
	 * @param array<string,mixed> $filters Filters.
	 */
	private static function render_pagination( int $page, int $limit, int $total, array $filters ): void {
		$total_pages = max( 1, (int) ceil( $total / $limit ) );

		if ( $total_pages <= 1 ) {
			return;
		}

		echo '<p class="tablenav-pages">';
		if ( $page > 1 ) {
			echo '<a class="button" href="' . esc_url( self::page_url( $filters, $limit, $page - 1 ) ) . '">' . esc_html__( 'Previous', 'pressqueue' ) . '</a> ';
		}

		/* translators: 1: current page number, 2: total page count. */
		echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'pressqueue' ), $page, $total_pages ) );

		if ( $page < $total_pages ) {
			echo ' <a class="button" href="' . esc_url( self::page_url( $filters, $limit, $page + 1 ) ) . '">' . esc_html__( 'Next', 'pressqueue' ) . '</a>';
		}
		echo '</p>';
	}

	/**
	 * Build a delivery log URL.
	 *
	 * @param array<string,mixed> $filters Filters.
	 * @param int                 $limit Page size.
	 * @param int                 $page Page.
	 * @return string
	 */
	private static function page_url( array $filters, int $limit, int $page ): string {
		$args = array(
			'limit' => $limit,
			'page'  => self::SLUG,
			'paged' => $page,
		);

		foreach ( array( 'state', 'destination_public_id', 'post_id', 'state_changed_from', 'state_changed_to', 'due_retry', 'identifier' ) as $key ) {
			if ( isset( $filters[ $key ] ) && '' !== (string) $filters[ $key ] ) {
				$query_key          = 'state' === $key ? 'delivery_state' : $key;
				$args[ $query_key ] = (string) $filters[ $key ];
			}
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Build filters from the current request.
	 *
	 * @return array<string,mixed>
	 */
	private static function filters_from_request(): array {
		$filters = array(
			'destination_public_id' => self::query_text( 'destination_public_id', 191 ),
			'due_retry'             => self::query_text( 'due_retry', 1 ),
			'identifier'            => self::query_text( 'identifier', 191 ),
			'post_id'               => self::query_text( 'post_id', 20 ),
			'state'                 => self::query_text( 'delivery_state', 32 ),
			'state_changed_from'    => self::query_text( 'state_changed_from', 10 ),
			'state_changed_to'      => self::query_text( 'state_changed_to', 10 ),
		);

		return array_filter(
			$filters,
			static function ( string $value ): bool {
				return '' !== $value;
			}
		);
	}

	/**
	 * Render notice from request.
	 */
	private static function render_notice(): void {
		$message = self::query_text( 'pressqueue_message', 64 );

		$messages = array(
			'delivery_cancelled'  => __( 'Delivery cancelled locally.', 'pressqueue' ),
			'delivery_resumed'    => __( 'Delivery resumed.', 'pressqueue' ),
			'delivery_retried'    => __( 'Delivery queued for manual retry.', 'pressqueue' ),
			'delivery_error'      => __( 'Delivery action failed.', 'pressqueue' ),
			'delivery_reconciled' => __( 'Delivery reconciliation saved.', 'pressqueue' ),
		);

		if ( ! isset( $messages[ $message ] ) ) {
			return;
		}

		$type = str_contains( $message, 'error' ) ? 'error' : 'success';
		echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . esc_html( $messages[ $message ] );

		$detail = SafeOperatorError::notice_detail( self::query_text( 'pressqueue_detail', 191 ) );
		if ( '' !== $detail ) {
			echo ' ' . esc_html( $detail );
		}

		echo '</p></div>';
	}

	/**
	 * Return bounded query text without wildcard interpretation.
	 *
	 * @param string $key Key.
	 * @param int    $maximum Maximum bytes.
	 * @return string
	 */
	private static function query_text( string $key, int $maximum ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Delivery log filters are read-only exact values escaped on output.
		$value = isset( $_GET[ $key ] ) ? wp_unslash( $_GET[ $key ] ) : '';

		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = trim( $value );

		return strlen( $value ) > $maximum ? substr( $value, 0, $maximum ) : $value;
	}

	/**
	 * Return bounded integer query value.
	 *
	 * @param string $key Key.
	 * @param int    $fallback Fallback.
	 * @param int    $minimum Minimum.
	 * @param int    $maximum Maximum.
	 * @return int
	 */
	private static function bounded_int_query( string $key, int $fallback, int $minimum, int $maximum ): int {
		$value = self::query_text( $key, 20 );

		return '' === $value ? $fallback : max( $minimum, min( $maximum, (int) $value ) );
	}

	/**
	 * Require delivery view capability.
	 */
	private static function require_capability(): void {
		if ( ! current_user_can( Capabilities::VIEW_DELIVERIES ) ) {
			wp_die( esc_html__( 'You are not allowed to view PressQueue deliveries.', 'pressqueue' ) );
		}
	}
}
