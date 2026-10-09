<?php
/**
 * Overview screen: setup checklist, status and recent activity.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Planning\EventNormalizer;
use PressQueue\Planning\Planner;
use PressQueue\Security\Capabilities;
use PressQueue\Security\SafeOperatorError;
use PressQueue\Storage\Record\ChannelRecord;
use PressQueue\Storage\Record\ConnectionRecord;
use PressQueue\Storage\Record\RuleRecord;
use PressQueue\Storage\Repository\ChannelRepository;
use PressQueue\Storage\Repository\ConnectionRepository;
use PressQueue\Storage\Repository\RuleRepository;

/**
 * Renders the PressQueue landing page.
 */
final class OverviewController {
	/**
	 * Render the overview.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( Capabilities::ACCESS_ADMIN ) ) {
			wp_die( esc_html__( 'You are not allowed to use this PressQueue screen.', 'pressqueue' ) );
		}

		try {
			$connections = ( new ConnectionRepository() )->find_all();
			$channels    = ( new ChannelRepository() )->find_all();
			$rules       = ( new RuleRepository() )->find_all();
			$summary     = new ActivitySummary();
			$mode        = Ui::mode();
		} catch ( \Throwable $throwable ) {
			Ui::page_start( __( 'PressQueue', 'pressqueue' ), array( 'brand' => true ) );
			Ui::notice( 'error', __( 'PressQueue could not read its data.', 'pressqueue' ), SafeOperatorError::message( $throwable ) );
			Ui::page_end();
			return;
		}

		$steps = self::setup_steps( $connections, $channels, $rules, $mode );
		$done  = count( array_filter( array_column( $steps, 'done' ) ) );

		Ui::page_start( __( 'PressQueue', 'pressqueue' ), array( 'brand' => true ) );
		SettingsController::render_notice();

		if ( 'paused' === $mode ) {
			self::render_paused_banner( $summary );
		}

		echo '<div class="pq-cols">';
		echo '<div class="pq-stack">';
		if ( current_user_can( Capabilities::VIEW_DELIVERIES ) || current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			SchedulerHealth::render_card();
		}
		if ( $done < count( $steps ) ) {
			self::render_setup( $steps, $done );
		}
		self::render_feed( $summary, $mode );
		echo '</div>';

		echo '<div class="pq-stack">';
		self::render_stats( $summary, $mode );
		if ( current_user_can( Capabilities::VIEW_DECISIONS ) ) {
			self::render_check_post();
		}
		echo '</div>';
		echo '</div>';

		Ui::page_end();
	}

	/**
	 * Build the setup checklist.
	 *
	 * @param array<int,ConnectionRecord> $connections Connections.
	 * @param array<int,ChannelRecord>    $channels Channels.
	 * @param array<int,RuleRecord>       $rules Rules.
	 * @param string                      $mode Sharing mode.
	 * @return array<int,array{done:bool,title:string,text:string,action:string,url:string}>
	 */
	private static function setup_steps( array $connections, array $channels, array $rules, string $mode ): array {
		$connected = array_values(
			array_filter(
				$connections,
				static function ( ConnectionRecord $connection ): bool {
					return $connection->enabled && 'verified' === $connection->connection_status;
				}
			)
		);
		$enabled   = array_values(
			array_filter(
				$channels,
				static function ( ChannelRecord $channel ): bool {
					return $channel->enabled && 'available' === $channel->availability;
				}
			)
		);
		$sharing   = array_values(
			array_filter(
				$rules,
				static function ( RuleRecord $rule ): bool {
					return $rule->enabled && 'include' === $rule->effect;
				}
			)
		);

		$channel_names = array_map(
			static function ( ChannelRecord $channel ): string {
				return ActivitySummary::channel_display_name( $channel );
			},
			$enabled
		);

		$organization = '';
		if ( array() !== $connected ) {
			foreach ( (array) ( $connected[0]->account['organizations'] ?? array() ) as $org ) {
				if ( is_array( $org ) && ( $org['remote_organization_id'] ?? null ) === $connected[0]->remote_organization_id ) {
					$organization = (string) ( $org['display_label'] ?? '' );
				}
			}
		}

		return array(
			array(
				'done'   => array() !== $connected,
				'title'  => __( 'Connect Buffer', 'pressqueue' ),
				'text'   => array() !== $connected
					/* translators: %s: Buffer organization name. */
					? ( '' === $organization ? __( 'Connected.', 'pressqueue' ) : sprintf( __( 'Connected to %s.', 'pressqueue' ), $organization ) )
					: __( 'Paste an API key from your Buffer account. It takes about a minute.', 'pressqueue' ),
				'action' => array() !== $connected ? __( 'Manage', 'pressqueue' ) : __( 'Connect Buffer', 'pressqueue' ),
				'url'    => Ui::url( Ui::PAGE_CHANNELS ),
			),
			array(
				'done'   => array() !== $enabled,
				'title'  => __( 'Choose channels', 'pressqueue' ),
				'text'   => array() !== $enabled
					/* translators: %s: comma-separated channel names. */
					? sprintf( __( 'On: %s.', 'pressqueue' ), implode( ', ', $channel_names ) )
					: __( 'Switch on the Buffer channels PressQueue may post to. They all start off.', 'pressqueue' ),
				'action' => array() !== $enabled ? __( 'Edit', 'pressqueue' ) : __( 'Choose channels', 'pressqueue' ),
				'url'    => Ui::url( Ui::PAGE_CHANNELS ),
			),
			array(
				'done'   => array() !== $sharing,
				'title'  => __( 'Create a sharing rule', 'pressqueue' ),
				'text'   => array() !== $sharing
					? ( 1 === count( $sharing )
						/* translators: %s: rule name. */
						? sprintf( __( '"%s" is on.', 'pressqueue' ), $sharing[0]->name )
						/* translators: %d: number of rules. */
						: sprintf( __( '%d sharing rules are on.', 'pressqueue' ), count( $sharing ) ) )
					: __( 'A rule says which posts go to which channels, and when. Start with one for all new posts.', 'pressqueue' ),
				'action' => array() !== $sharing ? __( 'View', 'pressqueue' ) : __( 'Create a rule', 'pressqueue' ),
				'url'    => array() !== $sharing ? Ui::url( Ui::PAGE_RULES ) : Ui::url( Ui::PAGE_RULES, array( 'view' => 'new' ) ),
			),
			array(
				'done'   => 'live' === $mode,
				'title'  => __( 'Go live', 'pressqueue' ),
				'text'   => 'live' === $mode
					? __( 'Matching posts are sent to Buffer.', 'pressqueue' )
					: __( 'Test mode records what would be shared and sends nothing. When the results below look right, switch to Live.', 'pressqueue' ),
				'action' => 'golive',
				'url'    => Ui::url( Ui::PAGE_SETTINGS ),
			),
		);
	}

	/**
	 * Render the setup checklist.
	 *
	 * @param array<int,array{done:bool,title:string,text:string,action:string,url:string}> $steps Steps.
	 * @param int                                                                           $done Completed count.
	 */
	private static function render_setup( array $steps, int $done ): void {
		$total = count( $steps );
		echo '<div class="pq-card pq-card--pad pq-stack">';
		echo '<div><h2>' . esc_html( 0 === $done ? __( 'Set up PressQueue', 'pressqueue' ) : __( 'Almost ready to share', 'pressqueue' ) ) . '</h2>';
		/* translators: 1: completed steps, 2: total steps. */
		echo '<p class="pq-sub">' . esc_html( sprintf( __( '%1$d of %2$d steps done.', 'pressqueue' ), $done, $total ) ) . '</p></div>';
		echo '<div class="pq-progress" role="progressbar" aria-valuemin="0" aria-valuemax="' . esc_attr( (string) $total ) . '" aria-valuenow="' . esc_attr( (string) $done ) . '"><i style="width:' . esc_attr( (string) round( $done / max( 1, $total ) * 100 ) ) . '%"></i></div>';
		echo '<ol class="pq-steps">';

		$current_found = false;
		foreach ( $steps as $index => $step ) {
			$is_current = ! $step['done'] && ! $current_found;
			if ( $is_current ) {
				$current_found = true;
			}

			$dot_class = $step['done'] ? 'pq-step-dot pq-step-dot--done' : ( $is_current ? 'pq-step-dot pq-step-dot--now' : 'pq-step-dot' );
			echo '<li>';
			echo '<span class="' . esc_attr( $dot_class ) . '" aria-hidden="true">' . ( $step['done'] ? '&#x2713;' : esc_html( (string) ( $index + 1 ) ) ) . '</span>';
			echo '<div><div class="pq-step-title">' . esc_html( $step['title'] ) . ( $step['done'] ? '<span class="screen-reader-text"> ' . esc_html__( '(done)', 'pressqueue' ) . '</span>' : '' ) . '</div><div class="pq-step-text">' . esc_html( $step['text'] ) . '</div></div>';
			echo '<div>';
			if ( 'golive' === $step['action'] ) {
				if ( $is_current && current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
					SettingsController::render_mode_button( 'live', __( 'Go live', 'pressqueue' ), 'button button-primary', Ui::PAGE_OVERVIEW );
				}
			} elseif ( $step['done'] ) {
				echo '<a href="' . esc_url( $step['url'] ) . '">' . esc_html( $step['action'] ) . '</a>';
			} elseif ( $is_current ) {
				echo '<a class="button button-primary" href="' . esc_url( $step['url'] ) . '">' . esc_html( $step['action'] ) . '</a>';
			}
			echo '</div>';
			echo '</li>';
		}

		echo '</ol></div>';
	}

	/**
	 * Render the paused banner.
	 *
	 * @param ActivitySummary $summary Summary.
	 */
	private static function render_paused_banner( ActivitySummary $summary ): void {
		$held = $summary->count_in_states( array( 'held' ) );
		echo '<div class="pq-card pq-card--pad pq-row pq-row--between" style="margin-bottom:20px;border-color:#f1c1bd;background:#fffafa">';
		echo '<div><h2>' . esc_html__( 'PressQueue is paused', 'pressqueue' ) . '</h2><p class="pq-sub">';
		if ( $held > 0 ) {
			/* translators: %d: number of shares on hold. */
			echo esc_html( sprintf( _n( '%d share is on hold. Nothing is sent until you resume.', '%d shares are on hold. Nothing is sent until you resume.', $held, 'pressqueue' ), $held ) );
		} else {
			echo esc_html__( 'Nothing is sent to Buffer until you resume.', 'pressqueue' );
		}
		echo '</p></div><div class="pq-row">';
		if ( current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			SettingsController::render_mode_button( 'live', __( 'Resume sharing', 'pressqueue' ), 'button button-primary', Ui::PAGE_OVERVIEW );
		}
		if ( $held > 0 && current_user_can( Capabilities::VIEW_DELIVERIES ) ) {
			echo '<a class="button" href="' . esc_url( Ui::url( Ui::PAGE_ACTIVITY, array( 'state' => 'held' ) ) ) . '">' . esc_html__( 'See held shares', 'pressqueue' ) . '</a>';
		}
		echo '</div></div>';
	}

	/**
	 * Render recent activity.
	 *
	 * @param ActivitySummary $summary Summary.
	 * @param string          $mode Sharing mode.
	 */
	private static function render_feed( ActivitySummary $summary, string $mode ): void {
		$feed = $summary->recent_feed( 8 );

		echo '<div class="pq-card">';
		echo '<div class="pq-card-head"><h2>' . esc_html__( 'Recent activity', 'pressqueue' ) . '</h2>';
		if ( current_user_can( Capabilities::VIEW_DELIVERIES ) ) {
			echo '<a href="' . esc_url( Ui::url( Ui::PAGE_ACTIVITY ) ) . '">' . esc_html__( 'See all', 'pressqueue' ) . '</a>';
		}
		echo '</div>';

		if ( array() === $feed ) {
			Ui::empty_state(
				__( 'Nothing yet', 'pressqueue' ),
				'test' === $mode
					? __( 'Publish or update a post and PressQueue records here what it would share. In test mode nothing is sent.', 'pressqueue' )
					: __( 'When you publish a post that matches a rule, it shows up here.', 'pressqueue' )
			);
			echo '</div>';
			return;
		}

		echo '<ul class="pq-list">';
		foreach ( $feed as $entry ) {
			$post_id = (int) $entry['post_id'];
			$title   = Ui::post_title( $post_id );
			$link    = current_user_can( 'edit_post', $post_id ) ? get_edit_post_link( $post_id ) : '';
			$shares  = $entry['shares'];
			$meta    = '';

			if ( array() !== $shares ) {
				$states = array_unique( array_column( $shares, 'state' ) );
				$slot   = (string) ( $shares[0]['schedule_slot'] ?? '' );
				$parts  = array();
				if ( '' !== $entry['rule'] ) {
					$parts[] = $entry['rule'];
				}
				if ( array( 'dry_run' ) === array_values( $states ) ) {
					/* translators: %s: schedule phrase such as "end of the Buffer queue". */
					$parts[] = sprintf( __( 'test run, would go to the %s', 'pressqueue' ), Labels::schedule_phrase( $slot ) );
				} else {
					$parts[] = ucfirst( Labels::schedule_phrase( $slot ) );
				}
				$meta = implode( ' · ', $parts );
			} else {
				$meta = (string) $entry['not_shared'];
			}

			echo '<li>';
			echo '<div style="min-width:0"><div class="pq-list-title">';
			if ( is_string( $link ) && '' !== $link ) {
				echo '<a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a>';
			} else {
				echo esc_html( $title );
			}
			echo '</div><div class="pq-list-meta">' . esc_html( $meta ) . '</div></div>';

			if ( array() === $shares ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pill returns escaped markup.
				echo 'excluded' === $entry['blocked_kind'] ? Ui::pill( 'bad', __( 'Never share', 'pressqueue' ) ) : ( 'policy_error' === $entry['blocked_kind'] ? Ui::pill( 'bad', __( 'Blocked', 'pressqueue' ) ) : Ui::pill( 'idle', __( 'Not shared', 'pressqueue' ) ) );
			} else {
				echo '<span class="pq-row" style="gap:8px">';
				echo '<span class="pq-svcs">';
				foreach ( $shares as $share ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- service_badge returns escaped markup.
					echo Ui::service_badge( (string) $share['service'], 'sm' );
				}
				echo '</span>';
				$state = self::combined_state( array_column( $shares, 'state' ) );
				$label = Labels::delivery_state( $state );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pill returns escaped markup.
				echo Ui::pill( $label['tone'], $label['label'], $label['help'] );
				echo '</span>';
			}

			$created = $entry['created_at'] instanceof \DateTimeInterface ? $entry['created_at'] : null;
			echo '<time datetime="' . esc_attr( null === $created ? '' : $created->format( DATE_ATOM ) ) . '" title="' . esc_attr( Ui::local_datetime( $created ) ) . '">' . esc_html( Ui::relative_time( $created ) ) . '</time>';
			echo '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Pick the state that best represents several deliveries for one post.
	 *
	 * @param array<int,string> $states States.
	 * @return string
	 */
	private static function combined_state( array $states ): string {
		foreach ( array( 'ambiguous', 'failed', 'held', 'retrying', 'leased', 'queued', 'proposed', 'accepted', 'dry_run', 'cancelled' ) as $state ) {
			if ( in_array( $state, $states, true ) ) {
				return $state;
			}
		}

		return (string) ( $states[0] ?? 'proposed' );
	}

	/**
	 * Render the stats card.
	 *
	 * @param ActivitySummary $summary Summary.
	 * @param string          $mode Sharing mode.
	 */
	private static function render_stats( ActivitySummary $summary, string $mode ): void {
		$counts    = $summary->delivery_counts( 7 );
		$attention = $summary->count_in_states( array( 'failed', 'ambiguous' ) );
		$waiting   = $summary->count_in_states( array( 'proposed', 'queued', 'leased', 'retrying', 'held' ) );
		$can_view  = current_user_can( Capabilities::VIEW_DELIVERIES );

		echo '<div class="pq-card"><div class="pq-stats">';
		if ( 'test' === $mode ) {
			self::stat( (int) ( $counts['dry_run'] ?? 0 ), __( 'Would share', 'pressqueue' ), $can_view ? Ui::url( Ui::PAGE_ACTIVITY, array( 'state' => 'dry_run' ) ) : '' );
		} else {
			self::stat( (int) ( $counts['accepted'] ?? 0 ), __( 'Sent to Buffer', 'pressqueue' ), $can_view ? Ui::url( Ui::PAGE_ACTIVITY, array( 'state' => 'accepted' ) ) : '' );
			self::stat( $waiting, __( 'Waiting', 'pressqueue' ), $can_view ? Ui::url( Ui::PAGE_ACTIVITY, array( 'group' => 'waiting' ) ) : '' );
		}
		self::stat( $summary->skipped_count( 7 ), __( 'Not shared', 'pressqueue' ), current_user_can( Capabilities::VIEW_DECISIONS ) ? Ui::url( Ui::PAGE_ACTIVITY, array( 'tab' => 'decisions' ) ) : '' );
		if ( $attention > 0 ) {
			self::stat( $attention, __( 'Need attention', 'pressqueue' ), $can_view ? Ui::url( Ui::PAGE_ACTIVITY, array( 'group' => 'attention' ) ) : '', true );
		}
		echo '</div><div class="pq-divider"></div>';
		echo '<p class="pq-muted pq-small" style="margin:0;padding:10px 20px">' . esc_html( 'test' === $mode ? __( 'Last 7 days. In test mode nothing reaches Buffer.', 'pressqueue' ) : __( 'Shares from the last 7 days.', 'pressqueue' ) ) . '</p>';
		echo '</div>';
	}

	/**
	 * Render one stat.
	 *
	 * @param int    $value Number.
	 * @param string $label Label.
	 * @param string $url Optional link.
	 * @param bool   $bad Whether to highlight.
	 */
	private static function stat( int $value, string $label, string $url, bool $bad = false ): void {
		$class = 'pq-stat' . ( $bad ? ' pq-stat--bad' : '' );
		$inner = '<b>' . esc_html( number_format_i18n( $value ) ) . '</b><span>' . esc_html( $label ) . '</span>';

		if ( '' !== $url ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $inner is escaped above.
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . $inner . '</a>';
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $inner is escaped above.
		echo '<div class="' . esc_attr( $class ) . '">' . $inner . '</div>';
	}

	/**
	 * Render the "check a post" card. Read-only: a GET search and a non-durable preview without image probes.
	 */
	private static function render_check_post(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only search and preview.
		$search  = isset( $_GET['check'] ) ? sanitize_text_field( wp_unslash( $_GET['check'] ) ) : '';
		$post_id = isset( $_GET['check_post'] ) ? absint( wp_unslash( $_GET['check_post'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="pq-card pq-card--pad pq-stack" id="pq-check">';
		echo '<div><h2>' . esc_html__( 'Check a post', 'pressqueue' ) . '</h2><p class="pq-sub">' . esc_html__( 'See what would happen if this post were published now.', 'pressqueue' ) . '</p></div>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '#pq-check" class="pq-row" role="search">';
		echo '<input type="hidden" name="page" value="' . esc_attr( Ui::PAGE_OVERVIEW ) . '" />';
		echo '<label class="screen-reader-text" for="pq-check-search">' . esc_html__( 'Find a post by title', 'pressqueue' ) . '</label>';
		echo '<input class="pq-field" style="flex:1;min-width:0" id="pq-check-search" type="search" name="check" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Type part of a post title', 'pressqueue' ) . '" />';
		echo '<button class="button" type="submit">' . esc_html__( 'Find', 'pressqueue' ) . '</button>';
		echo '</form>';

		if ( $post_id > 0 ) {
			self::render_check_result( $post_id );
		} elseif ( '' !== $search ) {
			self::render_check_matches( $search );
		}

		echo '</div>';
	}

	/**
	 * Render search matches.
	 *
	 * @param string $search Search text.
	 */
	private static function render_check_matches( string $search ): void {
		$query = new \WP_Query(
			array(
				'fields'                 => 'ids',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'post_status'            => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'post_type'              => 'any',
				'posts_per_page'         => 6,
				's'                      => $search,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$ids = array_values(
			array_filter(
				array_map( 'intval', $query->posts ),
				static function ( int $id ): bool {
					return current_user_can( 'edit_post', $id );
				}
			)
		);

		if ( array() === $ids ) {
			echo '<p class="pq-muted" style="margin:0">' . esc_html__( 'No posts found. Try a shorter part of the title.', 'pressqueue' ) . '</p>';
			return;
		}

		echo '<ul class="pq-checklist">';
		foreach ( $ids as $id ) {
			$url = Ui::url(
				Ui::PAGE_OVERVIEW,
				array(
					'check'      => $search,
					'check_post' => $id,
				)
			) . '#pq-check';
			echo '<li><span aria-hidden="true">&rsaquo;</span><a href="' . esc_url( $url ) . '">' . esc_html( Ui::post_title( $id ) ) . '</a></li>';
		}
		echo '</ul>';
	}

	/**
	 * Render a preview for one post.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function render_check_result( int $post_id ): void {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			echo '<p class="pq-muted" style="margin:0">' . esc_html__( 'You cannot check this post.', 'pressqueue' ) . '</p>';
			return;
		}

		echo '<div class="pq-strong">' . esc_html( Ui::post_title( $post_id ) ) . '</div>';

		try {
			$trigger    = 'first_publish';
			$rules      = ( new RuleRepository() )->find_enabled_for_trigger( $trigger );
			$normalizer = new EventNormalizer();
			$event      = $normalizer->from_post_id( $post_id, 'preview', 'admin_post_preview', $trigger, $normalizer->metadata_allowlist_for_rules( $rules ) );

			if ( null === $event ) {
				echo '<p class="pq-muted" style="margin:0">' . esc_html__( 'This post type cannot be shared.', 'pressqueue' ) . '</p>';
				return;
			}

			$result = ( new Planner() )->preview( $event, $rules, false );
			PreviewPresenter::render( $result->to_array(), true );
		} catch ( \Throwable $throwable ) {
			echo '<div class="pq-callout pq-callout--bad"><p>' . esc_html__( 'This post could not be checked.', 'pressqueue' ) . ' ' . esc_html( SafeOperatorError::message( $throwable ) ) . '</p></div>';
		}
	}
}
