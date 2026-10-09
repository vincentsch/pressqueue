<?php
/**
 * Activity page: the share log and the decisions tab.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Delivery\DeliveryQueryRepository;
use PressQueue\Security\Capabilities;
use PressQueue\Security\SafeOperatorError;
use PressQueue\Storage\Database;
use PressQueue\Storage\PublicIds;
use PressQueue\Storage\Record\DeliveryRecord;
use PressQueue\Storage\Repository\ChannelRepository;
use PressQueue\Storage\Repository\DeliveryEventRepository;
use PressQueue\Storage\Schema;
use PressQueue\Storage\StorageEnums;
use PressQueue\Storage\StorageException;
use PressQueue\Storage\ValidationException;

/**
 * Renders the Activity page with the Shares tab and the decisions tab.
 */
final class DeliveryListController {
	public const SLUG = 'pressqueue-deliveries';

	/**
	 * Largest number of matching post IDs a title search passes to the share query.
	 */
	private const SEARCH_POST_LIMIT = 200;

	/**
	 * Register the legacy standalone menu page.
	 *
	 * @param string $parent_slug Parent slug.
	 */
	public static function register_menu( string $parent_slug ): void {
		add_submenu_page(
			$parent_slug,
			__( 'PressQueue Activity', 'pressqueue' ),
			__( 'Activity', 'pressqueue' ),
			Capabilities::VIEW_DELIVERIES,
			self::SLUG,
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Enqueue Activity styles on the Activity, share details and legacy decisions screens.
	 */
	public static function enqueue_assets(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Asset routing is read-only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( ! in_array( $page, array( self::SLUG, DeliveryDetailController::SLUG, 'pressqueue-decisions' ), true ) || ! defined( 'PRESSQUEUE_FILE' ) || ! function_exists( 'wp_enqueue_style' ) ) {
			return;
		}

		wp_enqueue_style( 'pressqueue-activity', plugins_url( 'assets/admin/activity.css', PRESSQUEUE_FILE ), array( 'pressqueue-admin' ), Ui::asset_version( 'assets/admin/activity.css' ) );
	}

	/**
	 * Return the share filter groups shown as chips.
	 *
	 * @return array<string,array{label:string,states:array<int,string>}>
	 */
	public static function groups(): array {
		return array(
			'all'       => array(
				'label'  => __( 'All', 'pressqueue' ),
				'states' => array(),
			),
			'waiting'   => array(
				'label'  => __( 'Waiting', 'pressqueue' ),
				'states' => array( 'proposed', 'queued', 'leased', 'retrying' ),
			),
			'held'      => array(
				'label'  => __( 'On hold', 'pressqueue' ),
				'states' => array( 'held' ),
			),
			'attention' => array(
				'label'  => __( 'Needs attention', 'pressqueue' ),
				'states' => array( 'failed', 'ambiguous' ),
			),
			'accepted'  => array(
				'label'  => __( 'Sent to Buffer', 'pressqueue' ),
				'states' => array( 'accepted' ),
			),
			'dry_run'   => array(
				'label'  => __( 'Test runs', 'pressqueue' ),
				'states' => array( 'dry_run' ),
			),
			'cancelled' => array(
				'label'  => __( 'Cancelled', 'pressqueue' ),
				'states' => array( 'cancelled' ),
			),
		);
	}

	/**
	 * Render the Activity page.
	 */
	public static function render_page(): void {
		$can_shares    = current_user_can( Capabilities::VIEW_DELIVERIES );
		$can_decisions = current_user_can( Capabilities::VIEW_DECISIONS );

		if ( ! $can_shares && ! $can_decisions ) {
			wp_die( esc_html__( 'You are not allowed to view PressQueue deliveries.', 'pressqueue' ) );
		}

		$tab = ( 'decisions' === self::query_text( 'tab', 20 ) && $can_decisions ) || ! $can_shares ? 'decisions' : 'shares';

		Ui::page_start( __( 'Activity', 'pressqueue' ) );
		self::render_tabs( $tab );
		self::render_notice();

		if ( 'decisions' === $tab ) {
			DecisionsController::render_tab();
		} else {
			self::render_shares();
		}

		Ui::page_end();
	}

	/**
	 * Render the Activity tabs the viewer may open.
	 *
	 * @param string $current Current tab key.
	 */
	public static function render_tabs( string $current ): void {
		$tabs = array();

		if ( current_user_can( Capabilities::VIEW_DELIVERIES ) ) {
			$tabs['shares'] = array( __( 'Shares', 'pressqueue' ), Ui::url( self::SLUG ) );
		}

		if ( current_user_can( Capabilities::VIEW_DECISIONS ) ) {
			$tabs['decisions'] = array( __( 'Why posts were shared or skipped', 'pressqueue' ), Ui::url( self::SLUG, array( 'tab' => 'decisions' ) ) );
		}

		if ( count( $tabs ) > 1 ) {
			Ui::tabs( $tabs, $current );
		}
	}

	/**
	 * Render the notice that follows a share action.
	 */
	public static function render_notice(): void {
		$message = self::query_text( 'pressqueue_message', 64 );

		if ( in_array( $message, array( 'held_resumed', 'held_cancelled', 'held_blocked' ), true ) ) {
			self::render_bulk_notice( $message );
			return;
		}

		$messages = array(
			'delivery_cancelled'            => __( 'Cancelled. It will not be sent.', 'pressqueue' ),
			'delivery_resumed'              => __( 'Resumed. PressQueue will send it shortly.', 'pressqueue' ),
			'delivery_retried'              => __( 'Retry scheduled. PressQueue will send it again shortly.', 'pressqueue' ),
			'delivery_reconciled'           => __( 'Saved.', 'pressqueue' ),
			'delivery_reconciled_found'     => __( 'Saved. The share is marked as sent to Buffer, and PressQueue will not send it again.', 'pressqueue' ),
			'delivery_reconciled_not_found' => __( 'Saved. You can retry it now, or cancel it.', 'pressqueue' ),
			'delivery_reconciled_cancelled' => __( 'Cancelled. PressQueue will not send it again.', 'pressqueue' ),
			'delivery_error'                => __( 'That did not work.', 'pressqueue' ),
		);

		if ( ! isset( $messages[ $message ] ) ) {
			return;
		}

		$detail = DeliveryExplainer::notice_detail( self::query_text( 'pressqueue_detail', 191 ) );

		if ( 'delivery_error' === $message ) {
			Ui::notice( 'error', $messages[ $message ], '' === $detail ? __( 'Nothing was changed. Check the share and try again.', 'pressqueue' ) : $detail );
			return;
		}

		Ui::notice( 'success', $messages[ $message ], $detail );
	}

	/**
	 * Render the notice that follows a bulk action on held shares.
	 *
	 * @param string $message Message key.
	 */
	private static function render_bulk_notice( string $message ): void {
		$done    = absint( self::query_text( 'pq_done', 6 ) );
		$skipped = absint( self::query_text( 'pq_skipped', 6 ) );
		$left    = absint( self::query_text( 'pq_left', 9 ) );
		$reason  = self::query_text( 'pq_reason', 64 );
		$parts   = array();

		if ( 'held_blocked' === $message ) {
			Ui::notice( 'warning', __( 'Nothing was resumed.', 'pressqueue' ), DeliveryExplainer::block_sentence( '' === $reason ? 'unknown' : $reason ) );
			return;
		}

		if ( 'held_resumed' === $message ) {
			$lead = __( 'Nothing was resumed.', 'pressqueue' );
			if ( $done > 0 ) {
				/* translators: %d: number of shares resumed. */
				$lead = sprintf( _n( 'Resumed %d share. PressQueue will send it shortly.', 'Resumed %d shares. PressQueue will send them shortly.', $done, 'pressqueue' ), $done );
			}
		} else {
			$lead = __( 'Nothing was cancelled.', 'pressqueue' );
			if ( $done > 0 ) {
				/* translators: %d: number of shares cancelled. */
				$lead = sprintf( _n( 'Cancelled %d share. It will not be sent.', 'Cancelled %d shares. They will not be sent.', $done, 'pressqueue' ), $done );
			}
		}

		if ( $skipped > 0 ) {
			/* translators: %d: number of shares that stayed on hold. */
			$parts[] = sprintf( _n( '%d share stayed on hold.', '%d shares stayed on hold.', $skipped, 'pressqueue' ), $skipped );
			if ( '' !== $reason ) {
				$parts[] = DeliveryExplainer::block_sentence( $reason );
			}
		}

		if ( $left > 0 ) {
			/* translators: %d: number of shares still on hold. */
			$parts[] = sprintf( _n( '%d more share is on hold. Run it again for the rest.', '%d more shares are on hold. Run it again for the rest.', $left, 'pressqueue' ), $left );
		}

		Ui::notice( 0 === $done ? 'warning' : 'success', $lead, implode( ' ', $parts ) );
	}

	/**
	 * Render the Shares tab.
	 */
	private static function render_shares(): void {
		$request   = self::request();
		$groups    = self::groups();
		$group     = $request['group'];
		$page_size = $request['limit'];
		$summary   = new ActivitySummary();
		$mode      = self::safe_mode();
		$base      = self::base_filters( $request );
		$counts    = array();
		$result    = null;
		$error     = '';

		try {
			$repository = new DeliveryQueryRepository();
			$counts     = $repository->count_by_state( $base );
			$filters    = $base;
			if ( 'all' !== $group ) {
				$filters['states'] = $groups[ $group ]['states'];
			}
			if ( '' !== $request['state'] ) {
				$filters['state'] = $request['state'];
			}
			$result = $repository->query( $filters, $request['paged'], $page_size );
		} catch ( ValidationException | StorageException $exception ) {
			$error = SafeOperatorError::message( $exception, __( 'Shares could not be loaded.', 'pressqueue' ) );
		}

		$held_total = $summary->count_in_states( array( 'held' ) );
		if ( $held_total > 0 && in_array( $group, array( 'all', 'held' ), true ) ) {
			self::render_held_banner( $held_total, $mode );
		}

		self::render_chips( $request, $counts );
		self::render_filter_form( $request );
		self::render_active_filters( $request );

		if ( '' !== $error ) {
			echo '<div class="pq-callout pq-callout--bad pq-activity-error"><p>' . esc_html__( 'These filters could not be used.', 'pressqueue' ) . ' ' . esc_html( $error ) . '</p></div>';
			return;
		}

		$attention = (int) ( $counts['failed'] ?? 0 ) + (int) ( $counts['ambiguous'] ?? 0 );
		if ( 'all' === $group && '' === $request['state'] && $attention > 0 ) {
			self::render_attention_callout( $request, $attention );
		}

		if ( null === $result || array() === $result['rows'] ) {
			echo '<div class="pq-card">';
			self::render_empty_state( $request, $mode );
			echo '</div>';
			return;
		}

		self::render_table( $result['rows'], $request, $mode, $summary );
		self::render_pagination( $result['page'], $result['page_size'], $result['total'], $request );
	}

	/**
	 * Render the bar that offers to resume or cancel every held share.
	 *
	 * @param int    $held Number of held shares.
	 * @param string $mode Sharing mode.
	 */
	private static function render_held_banner( int $held, string $mode ): void {
		$can_operate = current_user_can( Capabilities::OPERATE_DELIVERIES );
		$batch       = DeliveryActionController::BULK_LIMIT;

		echo '<div class="pq-card pq-held-banner">';
		echo '<div class="pq-held-banner-text">';
		/* translators: %d: number of shares on hold. */
		echo '<h2>' . esc_html( sprintf( _n( '%d share is on hold', '%d shares are on hold', $held, 'pressqueue' ), $held ) ) . '</h2>';

		if ( 'paused' === $mode ) {
			echo '<p class="pq-sub">' . esc_html__( 'PressQueue is paused, so nothing is sent. Switch sharing back to Live, then resume these shares here.', 'pressqueue' ) . '</p>';
		} elseif ( 'test' === $mode ) {
			echo '<p class="pq-sub">' . esc_html__( 'PressQueue is in test mode, so these shares cannot be sent. Switch to Live first, then resume them here.', 'pressqueue' ) . '</p>';
		} else {
			echo '<p class="pq-sub">' . esc_html__( 'They were held while PressQueue was paused or because something changed. Resume them to send them to Buffer now, or cancel them.', 'pressqueue' ) . '</p>';
			if ( $can_operate && $held > $batch ) {
				/* translators: %d: number of shares handled per click. */
				echo '<p class="pq-sub">' . esc_html( sprintf( __( 'PressQueue handles %d at a time. Click again for the rest.', 'pressqueue' ), $batch ) ) . '</p>';
			}
		}
		echo '</div>';

		echo '<div class="pq-held-banner-actions">';
		if ( 'live' !== $mode && current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			echo '<a class="button" href="' . esc_url( Ui::url( Ui::PAGE_SETTINGS ) ) . '">' . esc_html__( 'Open Settings', 'pressqueue' ) . '</a>';
		}

		if ( $can_operate ) {
			if ( 'live' === $mode ) {
				Ui::button_form(
					'pressqueue_resume_held_deliveries',
					'pressqueue_resume_held_deliveries',
					array(),
					__( 'Resume all on hold', 'pressqueue' ),
					'button button-primary',
					/* translators: %d: number of shares on hold. */
					sprintf( _n( 'Resume %d share on hold? PressQueue sends it to Buffer shortly.', 'Resume %d shares on hold? PressQueue sends them to Buffer shortly.', $held, 'pressqueue' ), $held )
				);
			}

			echo '<details class="pq-more pq-held-cancel"><summary>' . esc_html__( 'Cancel all on hold', 'pressqueue' ) . '</summary><div class="pq-more-body">';
			Ui::form_start( 'pressqueue_cancel_held_deliveries', 'pressqueue_cancel_held_deliveries', array(), array( 'class' => 'pq-stack pq-confirm-form' ) );
			echo '<label class="pq-confirm-check"><input type="checkbox" name="confirm_local_effect" value="1" required /> ' . esc_html__( 'Cancel the shares on hold. PressQueue will not send them. Nothing changes in Buffer.', 'pressqueue' ) . '</label>';
			echo '<div><button type="submit" class="button pq-danger">' . esc_html__( 'Cancel shares on hold', 'pressqueue' ) . '</button></div>';
			Ui::form_end();
			echo '</div></details>';
		}
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render the filter chips.
	 *
	 * @param array<string,mixed> $request Request.
	 * @param array<string,int>   $counts Totals per state.
	 */
	private static function render_chips( array $request, array $counts ): void {
		$current = '' === $request['state'] ? $request['group'] : '';

		echo '<nav class="pq-chips pq-activity-chips" aria-label="' . esc_attr__( 'Filter shares by status', 'pressqueue' ) . '">';
		foreach ( self::groups() as $key => $group ) {
			$total = 'all' === $key ? array_sum( $counts ) : self::sum_states( $counts, $group['states'] );

			if ( in_array( $key, array( 'held', 'dry_run', 'cancelled' ), true ) && 0 === $total && $current !== $key ) {
				continue;
			}

			$args = self::link_args( $request, array( 'group' => $key ) );
			unset( $args['state'], $args['paged'] );
			if ( 'all' === $key ) {
				unset( $args['group'] );
			}

			$class = 'attention' === $key && $total > 0 ? ' class="pq-chip-alert"' : '';
			$aria  = $current === $key ? ' aria-current="true"' : '';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $class and $aria are fixed literals.
			echo '<a href="' . esc_url( Ui::url( self::SLUG, $args ) ) . '"' . $class . $aria . '>' . esc_html( $group['label'] ) . ' <b>' . esc_html( number_format_i18n( $total ) ) . '</b></a>';
		}
		echo '</nav>';
	}

	/**
	 * Render the search box, channel picker and the "More filters" disclosure.
	 *
	 * @param array<string,mixed> $request Request.
	 */
	private static function render_filter_form( array $request ): void {
		$advanced = '' !== $request['state_changed_from'] || '' !== $request['state_changed_to'] || '' !== $request['due_retry'] || '' !== $request['identifier'] || DeliveryQueryRepository::DEFAULT_PAGE_SIZE !== $request['limit'];

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="pq-activity-filters" role="search">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		foreach ( array( 'group', 'state', 'post_id', 'destination_public_id' ) as $key ) {
			if ( '' !== (string) $request[ $key ] && ( 'group' !== $key || 'all' !== $request['group'] ) ) {
				echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $request[ $key ] ) . '" />';
			}
		}

		echo '<div class="pq-activity-filter-row">';
		echo '<label class="screen-reader-text" for="pq-activity-search">' . esc_html__( 'Search post titles', 'pressqueue' ) . '</label>';
		echo '<input class="pq-field pq-activity-search" id="pq-activity-search" type="search" name="search" maxlength="100" value="' . esc_attr( $request['search'] ) . '" placeholder="' . esc_attr__( 'Search post titles', 'pressqueue' ) . '" />';

		$channels = self::channel_options();
		if ( array() !== $channels ) {
			echo '<label class="screen-reader-text" for="pq-activity-channel">' . esc_html__( 'Channel', 'pressqueue' ) . '</label>';
			echo '<select class="pq-field pq-activity-channel" id="pq-activity-channel" name="channel">';
			echo '<option value="">' . esc_html__( 'All channels', 'pressqueue' ) . '</option>';
			foreach ( $channels as $public_id => $label ) {
				echo '<option value="' . esc_attr( $public_id ) . '" ' . selected( $request['channel'], $public_id, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select>';
		}

		echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'pressqueue' ) . '</button>';
		if ( self::has_filters( $request ) ) {
			$clear = 'all' === $request['group'] ? array() : array( 'group' => $request['group'] );
			echo '<a class="pq-activity-clear" href="' . esc_url( Ui::url( self::SLUG, $clear ) ) . '">' . esc_html__( 'Clear filters', 'pressqueue' ) . '</a>';
		}
		echo '</div>';

		echo '<details class="pq-more pq-activity-more"' . ( $advanced ? ' open' : '' ) . '><summary>' . esc_html__( 'More filters', 'pressqueue' ) . '</summary><div class="pq-more-body pq-field-row">';
		echo '<div><label class="pq-label" for="pq-activity-from">' . esc_html__( 'Changed from', 'pressqueue' ) . '</label><input class="pq-field" id="pq-activity-from" type="date" name="state_changed_from" value="' . esc_attr( $request['state_changed_from'] ) . '" /></div>';
		echo '<div><label class="pq-label" for="pq-activity-to">' . esc_html__( 'Changed until', 'pressqueue' ) . '</label><input class="pq-field" id="pq-activity-to" type="date" name="state_changed_to" value="' . esc_attr( $request['state_changed_to'] ) . '" /></div>';
		echo '<div><label class="pq-label" for="pq-activity-due">' . esc_html__( 'Retries', 'pressqueue' ) . '</label><select class="pq-field" id="pq-activity-due" name="due_retry">';
		echo '<option value="">' . esc_html__( 'Any', 'pressqueue' ) . '</option>';
		echo '<option value="1" ' . selected( $request['due_retry'], '1', false ) . '>' . esc_html__( 'Retry due now', 'pressqueue' ) . '</option>';
		echo '<option value="0" ' . selected( $request['due_retry'], '0', false ) . '>' . esc_html__( 'No retry due', 'pressqueue' ) . '</option>';
		echo '</select></div>';
		echo '<div><label class="pq-label" for="pq-activity-identifier">' . esc_html__( 'Exact ID', 'pressqueue' ) . '</label><input class="pq-field pq-mono" id="pq-activity-identifier" type="text" name="identifier" maxlength="191" value="' . esc_attr( $request['identifier'] ) . '" aria-describedby="pq-activity-identifier-help" /><p class="pq-help" id="pq-activity-identifier-help">' . esc_html__( 'A share ID, delivery key or Buffer post ID.', 'pressqueue' ) . '</p></div>';
		echo '<div><label class="pq-label" for="pq-activity-limit">' . esc_html__( 'Rows per page', 'pressqueue' ) . '</label><input class="pq-field" id="pq-activity-limit" type="number" name="limit" min="1" max="' . esc_attr( (string) DeliveryQueryRepository::MAX_PAGE_SIZE ) . '" value="' . esc_attr( (string) $request['limit'] ) . '" /></div>';
		echo '</div></details>';
		echo '</form>';
	}

	/**
	 * Render tags for filters that arrived through links.
	 *
	 * @param array<string,mixed> $request Request.
	 */
	private static function render_active_filters( array $request ): void {
		$tags = array();

		if ( '' !== $request['post_id'] ) {
			/* translators: %s: post title. */
			$tags['post_id'] = sprintf( __( 'Post: %s', 'pressqueue' ), Ui::post_title( (int) $request['post_id'] ) );
		}

		if ( '' !== $request['state'] ) {
			$label = Labels::delivery_state( $request['state'] );
			/* translators: %s: status label such as "Failed". */
			$tags['state'] = sprintf( __( 'Status: %s', 'pressqueue' ), $label['label'] );
		}

		if ( '' !== $request['destination_public_id'] ) {
			$channel = ( new ActivitySummary() )->channel( $request['destination_public_id'] );
			/* translators: %s: channel name. */
			$tags['destination_public_id'] = null === $channel ? __( 'One Buffer connection', 'pressqueue' ) : sprintf( __( 'Channel: %s', 'pressqueue' ), ActivitySummary::channel_display_name( $channel ) );
		}

		if ( array() === $tags ) {
			return;
		}

		echo '<div class="pq-activity-tags pq-row">';
		foreach ( $tags as $key => $label ) {
			$args = self::link_args( $request, array() );
			unset( $args[ $key ], $args['paged'] );
			/* translators: %s: filter description. */
			echo '<span class="pq-tag">' . esc_html( $label ) . ' <a href="' . esc_url( Ui::url( self::SLUG, $args ) ) . '" aria-label="' . esc_attr( sprintf( __( 'Remove filter: %s', 'pressqueue' ), $label ) ) . '">&times;</a></span>';
		}
		echo '</div>';
	}

	/**
	 * Point at shares that need attention when they are mixed into the All view.
	 *
	 * @param array<string,mixed> $request Request.
	 * @param int                 $attention Number of shares that need attention.
	 */
	private static function render_attention_callout( array $request, int $attention ): void {
		$args = self::link_args( $request, array( 'group' => 'attention' ) );
		unset( $args['paged'] );

		echo '<div class="pq-callout pq-callout--bad pq-attention-callout"><p>';
		/* translators: %d: number of shares. */
		echo '<strong>' . esc_html( sprintf( _n( '%d share needs attention.', '%d shares need attention.', $attention, 'pressqueue' ), $attention ) ) . '</strong> ';
		echo esc_html( _n( 'Buffer did not accept it, or PressQueue could not confirm that it arrived.', 'Buffer did not accept them, or PressQueue could not confirm that they arrived.', $attention, 'pressqueue' ) ) . ' ';
		echo '<a href="' . esc_url( Ui::url( self::SLUG, $args ) ) . '">' . esc_html( _n( 'Show it', 'Show them', $attention, 'pressqueue' ) ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * Render the share table.
	 *
	 * @param array<int,DeliveryRecord> $deliveries Deliveries.
	 * @param array<string,mixed>       $request Request.
	 * @param string                    $mode Sharing mode.
	 * @param ActivitySummary           $summary Summary with channel lookups.
	 */
	private static function render_table( array $deliveries, array $request, string $mode, ActivitySummary $summary ): void {
		$posts        = self::post_ids_for( $deliveries );
		$hold_reasons = self::hold_reasons_for( $deliveries );

		if ( function_exists( '_prime_post_caches' ) && array() !== $posts ) {
			_prime_post_caches( array_values( array_unique( $posts ) ), false, false );
		}

		echo '<div class="pq-card pq-activity-card"><div class="pq-table-wrap">';
		echo '<table class="pq-table pq-shares"><caption class="screen-reader-text">' . esc_html__( 'Shares', 'pressqueue' ) . '</caption><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Post', 'pressqueue' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Channel', 'pressqueue' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Status', 'pressqueue' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'When', 'pressqueue' ) . '</th>';
		echo '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Action', 'pressqueue' ) . '</span></th>';
		echo '</tr></thead><tbody>';

		foreach ( $deliveries as $delivery ) {
			$post_id   = $posts[ $delivery->decision_id ] ?? 0;
			$attention = in_array( $delivery->state, array( 'failed', 'ambiguous' ), true );
			$note      = DeliveryExplainer::row_note( $delivery, $hold_reasons[ $delivery->id ] ?? null );

			echo '<tr' . ( $attention ? ' class="pq-attention"' : '' ) . '>';

			echo '<td class="pq-col-post">';
			self::render_post_title( $post_id );
			if ( '' !== $note['text'] ) {
				echo '<div class="pq-row-note pq-row-note--' . esc_attr( $note['tone'] ) . '">' . esc_html( $note['text'] ) . '</div>';
			}
			echo '</td>';

			echo '<td class="pq-col-channel">';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- channel_markup returns escaped markup.
			echo self::channel_markup( $delivery, $summary );
			echo '</td>';

			$label = Labels::delivery_state( $delivery->state );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pill returns escaped markup.
			echo '<td class="pq-col-status">' . Ui::pill( $label['tone'], $label['label'], $label['help'] ) . '</td>';

			echo '<td class="pq-col-when">';
			self::render_when( $delivery );
			echo '</td>';

			echo '<td class="pq-col-action">';
			self::render_row_action( $delivery, $request, $mode );
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table></div></div>';
	}

	/**
	 * Render a post title linked to its editor when the viewer may edit it.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function render_post_title( int $post_id ): void {
		$title = 0 === $post_id ? __( 'Unknown post', 'pressqueue' ) : Ui::post_title( $post_id );
		$link  = $post_id > 0 && current_user_can( 'edit_post', $post_id ) ? get_edit_post_link( $post_id ) : '';

		if ( is_string( $link ) && '' !== $link ) {
			echo '<a class="pq-row-link pq-post-title" href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a>';
			return;
		}

		echo '<span class="pq-post-title">' . esc_html( $title ) . '</span>';
	}

	/**
	 * Return the channel badge and name for a share.
	 *
	 * @param DeliveryRecord  $delivery Delivery.
	 * @param ActivitySummary $summary Summary.
	 * @return string Escaped markup.
	 */
	public static function channel_markup( DeliveryRecord $delivery, ActivitySummary $summary ): string {
		$channel = $summary->channel( $delivery->channel_public_id );

		if ( null !== $channel ) {
			return Ui::channel_name( $channel->service, ActivitySummary::channel_display_name( $channel ) );
		}

		$service = is_string( $delivery->payload_preview['service'] ?? null ) ? (string) $delivery->payload_preview['service'] : '';
		$name    = is_string( $delivery->payload_preview['channel_label'] ?? null ) && '' !== $delivery->payload_preview['channel_label'] ? (string) $delivery->payload_preview['channel_label'] : __( 'Removed channel', 'pressqueue' );

		return '' === $service ? '<span class="pq-channel-name"><span class="pq-truncate">' . esc_html( $name ) . '</span></span>' : Ui::channel_name( $service, $name );
	}

	/**
	 * Render the When cell.
	 *
	 * @param DeliveryRecord $delivery Delivery.
	 */
	private static function render_when( DeliveryRecord $delivery ): void {
		$time = $delivery->state_changed_at ?? $delivery->created_at;
		self::render_time( $time );

		$due = self::due_line( $delivery );
		if ( '' !== $due ) {
			echo '<div class="pq-small pq-muted">' . esc_html( $due ) . '</div>';
		}
	}

	/**
	 * Return when a scheduled or retrying share is due, or an empty string.
	 *
	 * @param DeliveryRecord $delivery Delivery.
	 * @return string
	 */
	public static function due_line( DeliveryRecord $delivery ): string {
		if ( 'retrying' === $delivery->state && null !== $delivery->next_attempt_at ) {
			/* translators: %s: time such as "11:05" or "Oct 12, 11:05". */
			return sprintf( __( 'Next try %s', 'pressqueue' ), DeliveryExplainer::short_time( $delivery->next_attempt_at ) );
		}

		if ( in_array( $delivery->state, array( 'proposed', 'queued', 'leased', 'held', 'dry_run' ), true ) && 'explicit_time' === $delivery->schedule_slot && null !== $delivery->scheduled_for_utc ) {
			/* translators: %s: time such as "11:05" or "Oct 12, 11:05". */
			return sprintf( __( 'Scheduled for %s', 'pressqueue' ), DeliveryExplainer::short_time( $delivery->scheduled_for_utc ) );
		}

		if ( 'accepted' === $delivery->state ) {
			$due = self::receipt_due_at( $delivery );
			if ( null !== $due && $due->getTimestamp() > time() ) {
				/* translators: %s: time such as "11:05" or "Oct 12, 11:05". */
				return sprintf( __( 'Buffer posts it %s', 'pressqueue' ), DeliveryExplainer::short_time( $due ) );
			}
		}

		return '';
	}

	/**
	 * Return the receipt due time when Buffer reported one.
	 *
	 * @param DeliveryRecord $delivery Delivery.
	 * @return \DateTimeImmutable|null
	 */
	public static function receipt_due_at( DeliveryRecord $delivery ): ?\DateTimeImmutable {
		$value = is_array( $delivery->receipt ) && is_string( $delivery->receipt['due_at'] ?? null ) ? (string) $delivery->receipt['due_at'] : '';

		if ( '' === $value ) {
			return null;
		}

		$timestamp = strtotime( $value );

		return false === $timestamp ? null : ( new \DateTimeImmutable( '@' . $timestamp ) );
	}

	/**
	 * Render a time element with the full date in its title.
	 *
	 * @param \DateTimeInterface $time UTC time.
	 */
	public static function render_time( \DateTimeInterface $time ): void {
		echo '<time class="pq-time" datetime="' . esc_attr( $time->format( DATE_ATOM ) ) . '" title="' . esc_attr( Ui::local_datetime( $time ) ) . '">' . esc_html( Ui::relative_time( $time ) ) . '</time>';
	}

	/**
	 * Render the one action offered for a row.
	 *
	 * @param DeliveryRecord      $delivery Delivery.
	 * @param array<string,mixed> $request Request.
	 * @param string              $mode Sharing mode.
	 */
	private static function render_row_action( DeliveryRecord $delivery, array $request, string $mode ): void {
		$detail_url  = DeliveryDetailController::url( $delivery->public_id );
		$can_operate = current_user_can( Capabilities::OPERATE_DELIVERIES );
		$hidden      = array(
			'delivery_public_id' => $delivery->public_id,
			'pq_group'           => (string) $request['group'],
			'pq_return'          => 'activity',
		);

		if ( $can_operate && 'held' === $delivery->state && 'live' === $mode ) {
			Ui::button_form( 'pressqueue_resume_delivery', 'pressqueue_resume_delivery', $hidden, __( 'Resume', 'pressqueue' ), 'button button-small button-primary', __( 'Resume this share? PressQueue sends it to Buffer shortly.', 'pressqueue' ) );
			echo ' <a class="pq-row-secondary" href="' . esc_url( $detail_url ) . '">' . esc_html__( 'Details', 'pressqueue' ) . '</a>';
			return;
		}

		if ( $can_operate && 'live' === $mode && DeliveryDetailController::can_retry( $delivery ) ) {
			Ui::button_form( 'pressqueue_retry_delivery', 'pressqueue_retry_delivery', $hidden, __( 'Retry', 'pressqueue' ), 'button button-small button-primary', __( 'Send this share to Buffer again?', 'pressqueue' ) );
			echo ' <a class="pq-row-secondary" href="' . esc_url( $detail_url ) . '">' . esc_html__( 'Details', 'pressqueue' ) . '</a>';
			return;
		}

		if ( 'ambiguous' === $delivery->state ) {
			echo '<a class="button button-small" href="' . esc_url( $detail_url . '#resolve' ) . '">' . esc_html__( 'Resolve', 'pressqueue' ) . '</a>';
			return;
		}

		echo '<a href="' . esc_url( $detail_url ) . '">' . esc_html__( 'Details', 'pressqueue' ) . '</a>';
	}

	/**
	 * Render an empty state that fits the current filter.
	 *
	 * @param array<string,mixed> $request Request.
	 * @param string              $mode Sharing mode.
	 */
	private static function render_empty_state( array $request, string $mode ): void {
		if ( self::has_filters( $request ) || '' !== $request['state'] ) {
			$clear = 'all' === $request['group'] ? array() : array( 'group' => $request['group'] );
			Ui::empty_state(
				__( 'No shares match these filters', 'pressqueue' ),
				__( 'Try a shorter part of the title, another channel or a wider date range.', 'pressqueue' ),
				'<a class="button" href="' . esc_url( Ui::url( self::SLUG, $clear ) ) . '">' . esc_html__( 'Clear filters', 'pressqueue' ) . '</a>'
			);
			return;
		}

		$test = 'test' === $mode;

		switch ( $request['group'] ) {
			case 'waiting':
				Ui::empty_state( __( 'Nothing is waiting', 'pressqueue' ), __( 'Shares show up here while PressQueue sends them to Buffer. Most take less than a minute.', 'pressqueue' ) );
				return;

			case 'held':
				Ui::empty_state( __( 'Nothing is on hold', 'pressqueue' ), __( 'Shares wait here when PressQueue is paused or something changes before they are sent.', 'pressqueue' ) );
				return;

			case 'attention':
				Ui::empty_state( __( 'Nothing needs attention', 'pressqueue' ), __( 'Shares that Buffer did not accept, or that may have reached Buffer without confirmation, show up here.', 'pressqueue' ) );
				return;

			case 'accepted':
				Ui::empty_state(
					__( 'Nothing sent to Buffer yet', 'pressqueue' ),
					$test ? __( 'PressQueue is in test mode, so nothing is sent. Test runs show what PressQueue would have sent.', 'pressqueue' ) : __( 'When Buffer accepts a share, it shows up here.', 'pressqueue' )
				);
				return;

			case 'dry_run':
				Ui::empty_state(
					__( 'No test runs yet', 'pressqueue' ),
					$test ? __( 'Test runs show what PressQueue would have sent. Publish or update a post to see one.', 'pressqueue' ) : __( 'Test runs show what PressQueue would have sent. They are recorded while PressQueue is in test mode.', 'pressqueue' )
				);
				return;

			case 'cancelled':
				Ui::empty_state( __( 'Nothing was cancelled', 'pressqueue' ), __( 'Shares you cancel stay here so you can see what was not sent.', 'pressqueue' ) );
				return;
		}

		Ui::empty_state(
			__( 'No shares yet', 'pressqueue' ),
			$test ? __( 'Publish or update a post and PressQueue records here what it would share. In test mode nothing is sent.', 'pressqueue' ) : __( 'When you publish a post that matches a rule, its shares show up here.', 'pressqueue' )
		);
	}

	/**
	 * Render pagination in plain words.
	 *
	 * @param int                 $page Current page.
	 * @param int                 $limit Page size.
	 * @param int                 $total Total rows.
	 * @param array<string,mixed> $request Request.
	 */
	private static function render_pagination( int $page, int $limit, int $total, array $request ): void {
		$total_pages = max( 1, (int) ceil( $total / max( 1, $limit ) ) );
		$first       = min( $total, ( $page - 1 ) * $limit + 1 );
		$last        = min( $total, $page * $limit );

		echo '<nav class="pq-pagination" aria-label="' . esc_attr__( 'Share pages', 'pressqueue' ) . '">';
		echo '<span>';
		if ( $total_pages > 1 ) {
			/* translators: 1: first row number, 2: last row number, 3: total number of shares. */
			echo esc_html( sprintf( _n( 'Showing %1$s to %2$s of %3$s share', 'Showing %1$s to %2$s of %3$s shares', $total, 'pressqueue' ), number_format_i18n( $first ), number_format_i18n( $last ), number_format_i18n( $total ) ) );
		} else {
			/* translators: %s: number of shares. */
			echo esc_html( sprintf( _n( '%s share', '%s shares', $total, 'pressqueue' ), number_format_i18n( $total ) ) );
		}
		echo '</span>';

		if ( $page > 1 ) {
			echo '<a class="button" rel="prev" href="' . esc_url( Ui::url( self::SLUG, self::link_args( $request, array( 'paged' => $page - 1 ) ) ) ) . '">' . esc_html__( 'Previous', 'pressqueue' ) . '</a>';
		}

		if ( $page < $total_pages ) {
			echo '<a class="button" rel="next" href="' . esc_url( Ui::url( self::SLUG, self::link_args( $request, array( 'paged' => $page + 1 ) ) ) ) . '">' . esc_html__( 'Next', 'pressqueue' ) . '</a>';
		}
		echo '</nav>';
	}

	/**
	 * Read and normalize the request.
	 *
	 * @return array{group:string,state:string,search:string,channel:string,destination_public_id:string,post_id:string,state_changed_from:string,state_changed_to:string,due_retry:string,identifier:string,limit:int,paged:int}
	 */
	private static function request(): array {
		$group = sanitize_key( self::query_text( 'group', 20 ) );
		$state = self::query_text( 'state', 32 );
		if ( '' === $state ) {
			$state = self::query_text( 'delivery_state', 32 );
		}

		if ( ! in_array( $state, StorageEnums::DELIVERY_STATES, true ) ) {
			$state = '';
		}

		if ( ! isset( self::groups()[ $group ] ) ) {
			$group = 'all';
		}

		$single_state_groups = array(
			'held'      => 'held',
			'accepted'  => 'accepted',
			'dry_run'   => 'dry_run',
			'cancelled' => 'cancelled',
		);
		if ( '' !== $state && 'all' === $group && isset( $single_state_groups[ $state ] ) ) {
			$group = $single_state_groups[ $state ];
			$state = '';
		}

		$channel = self::query_text( 'channel', 26 );
		if ( '' !== $channel && ! PublicIds::is_valid( $channel ) ) {
			$channel = '';
		}

		$post_id = self::query_text( 'post_id', 20 );

		return array(
			'group'                 => $group,
			'state'                 => $state,
			'search'                => self::query_text( 'search', 100 ),
			'channel'               => $channel,
			'destination_public_id' => self::query_text( 'destination_public_id', 191 ),
			'post_id'               => 1 === preg_match( '/^\d+$/', $post_id ) ? $post_id : '',
			'state_changed_from'    => self::query_text( 'state_changed_from', 10 ),
			'state_changed_to'      => self::query_text( 'state_changed_to', 10 ),
			'due_retry'             => in_array( self::query_text( 'due_retry', 1 ), array( '0', '1' ), true ) ? self::query_text( 'due_retry', 1 ) : '',
			'identifier'            => self::query_text( 'identifier', 191 ),
			'limit'                 => self::bounded_int_query( 'limit', DeliveryQueryRepository::DEFAULT_PAGE_SIZE, 1, DeliveryQueryRepository::MAX_PAGE_SIZE ),
			'paged'                 => self::bounded_int_query( 'paged', 1, 1, 999999 ),
		);
	}

	/**
	 * Build repository filters that do not depend on the state chips.
	 *
	 * @param array<string,mixed> $request Request.
	 * @return array<string,mixed>
	 */
	private static function base_filters( array $request ): array {
		$filters = array();

		foreach ( array( 'destination_public_id', 'post_id', 'state_changed_from', 'state_changed_to', 'due_retry', 'identifier' ) as $key ) {
			if ( '' !== (string) $request[ $key ] ) {
				$filters[ $key ] = (string) $request[ $key ];
			}
		}

		if ( '' !== $request['channel'] ) {
			$filters['channel_public_id'] = $request['channel'];
		}

		if ( '' !== $request['search'] ) {
			$filters['post_ids'] = self::search_post_ids( $request['search'] );
		}

		return $filters;
	}

	/**
	 * Find editable posts whose title contains the search text.
	 *
	 * @param string $search Search text.
	 * @return array<int,int>
	 */
	private static function search_post_ids( string $search ): array {
		$query = new \WP_Query(
			array(
				'fields'                 => 'ids',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'post_status'            => 'any',
				'post_type'              => array_values( get_post_types( array( 'show_ui' => true ) ) ),
				'posts_per_page'         => self::SEARCH_POST_LIMIT,
				's'                      => $search,
				'search_columns'         => array( 'post_title' ),
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return array_values(
			array_filter(
				array_map( 'intval', $query->posts ),
				static function ( int $post_id ): bool {
					return current_user_can( 'edit_post', $post_id );
				}
			)
		);
	}

	/**
	 * Return channel choices for the channel picker.
	 *
	 * @return array<string,string> Channel public ID => label.
	 */
	private static function channel_options(): array {
		try {
			$channels = ( new ChannelRepository() )->find_all();
		} catch ( StorageException | ValidationException $exception ) {
			unset( $exception );
			return array();
		}

		$options = array();
		foreach ( $channels as $channel ) {
			/* translators: 1: channel name, 2: service name such as LinkedIn. */
			$options[ $channel->public_id ] = sprintf( __( '%1$s (%2$s)', 'pressqueue' ), ActivitySummary::channel_display_name( $channel ), Labels::service( $channel->service ) );
		}

		asort( $options, SORT_NATURAL | SORT_FLAG_CASE );

		return $options;
	}

	/**
	 * Map decision IDs to post IDs for a page of shares.
	 *
	 * @param array<int,DeliveryRecord> $deliveries Deliveries.
	 * @return array<int,int> Decision ID => post ID.
	 */
	private static function post_ids_for( array $deliveries ): array {
		$ids = array_values( array_unique( array_map( static fn( DeliveryRecord $delivery ): int => $delivery->decision_id, $deliveries ) ) );

		if ( array() === $ids ) {
			return array();
		}

		$database = Database::from_globals();
		$rows     = $database->get_results(
			'SELECT id, post_id FROM `' . $database->table( Schema::DECISIONS ) . '` WHERE id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')',
			$ids
		);

		$map = array();
		foreach ( $rows as $row ) {
			$map[ (int) $row->id ] = (int) $row->post_id;
		}

		return $map;
	}

	/**
	 * Return the latest hold classification for held shares on the page.
	 *
	 * @param array<int,DeliveryRecord> $deliveries Deliveries.
	 * @return array<int,string|null> Delivery ID => classification, null when created on hold.
	 */
	private static function hold_reasons_for( array $deliveries ): array {
		$events  = new DeliveryEventRepository();
		$reasons = array();

		foreach ( $deliveries as $delivery ) {
			if ( 'held' !== $delivery->state ) {
				continue;
			}

			$reasons[ $delivery->id ] = null;
			foreach ( $events->find_by_delivery_id( $delivery->id ) as $event ) {
				if ( 'held' === $event->event_type ) {
					$reasons[ $delivery->id ] = is_string( $event->detail['classification'] ?? null ) ? (string) $event->detail['classification'] : '';
				}
			}
		}

		return $reasons;
	}

	/**
	 * Build link arguments that keep the current filters.
	 *
	 * @param array<string,mixed> $request Request.
	 * @param array<string,mixed> $overrides Overrides.
	 * @return array<string,scalar>
	 */
	private static function link_args( array $request, array $overrides ): array {
		$args = array();

		foreach ( array( 'group', 'state', 'search', 'channel', 'destination_public_id', 'post_id', 'state_changed_from', 'state_changed_to', 'due_retry', 'identifier' ) as $key ) {
			if ( '' !== (string) $request[ $key ] && ( 'group' !== $key || 'all' !== $request['group'] ) ) {
				$args[ $key ] = (string) $request[ $key ];
			}
		}

		if ( DeliveryQueryRepository::DEFAULT_PAGE_SIZE !== $request['limit'] ) {
			$args['limit'] = (int) $request['limit'];
		}

		foreach ( $overrides as $key => $value ) {
			$args[ $key ] = is_scalar( $value ) ? $value : '';
		}

		return $args;
	}

	/**
	 * Check whether search or power-user filters are active.
	 *
	 * @param array<string,mixed> $request Request.
	 * @return bool
	 */
	private static function has_filters( array $request ): bool {
		foreach ( array( 'search', 'channel', 'destination_public_id', 'post_id', 'state_changed_from', 'state_changed_to', 'due_retry', 'identifier' ) as $key ) {
			if ( '' !== (string) $request[ $key ] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Sum totals for a set of states.
	 *
	 * @param array<string,int> $counts Totals.
	 * @param array<int,string> $states States.
	 * @return int
	 */
	private static function sum_states( array $counts, array $states ): int {
		$total = 0;
		foreach ( $states as $state ) {
			$total += (int) ( $counts[ $state ] ?? 0 );
		}

		return $total;
	}

	/**
	 * Return the sharing mode without failing the page.
	 *
	 * @return string
	 */
	public static function safe_mode(): string {
		try {
			return Ui::mode();
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return 'paused';
		}
	}

	/**
	 * Return bounded query text without wildcard interpretation.
	 *
	 * @param string $key Key.
	 * @param int    $maximum Maximum bytes.
	 * @return string
	 */
	private static function query_text( string $key, int $maximum ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Activity filters are read-only exact values escaped on output.
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
}
