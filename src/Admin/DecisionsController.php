<?php
/**
 * Activity tab that explains why posts were shared or skipped.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Security\Capabilities;
use PressQueue\Security\SafeOperatorError;
use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\Database;
use PressQueue\Storage\Record\DecisionRecord;
use PressQueue\Storage\Record\DeliveryRecord;
use PressQueue\Storage\Repository\DeliveryRepository;
use PressQueue\Storage\Schema;
use PressQueue\Storage\StorageException;
use PressQueue\Storage\StorageValidator;
use PressQueue\Storage\ValidationException;

/**
 * Groups runtime decisions per post event and explains each one in a sentence.
 *
 * A batch from Share old posts records one event per channel. Those events are
 * grouped by post and batch, so each post shows up once per batch.
 */
final class DecisionsController {
	/**
	 * Rows per page.
	 */
	private const PAGE_SIZE = 20;

	/**
	 * Prefix of row keys that group one post's events in one batch.
	 */
	private const BATCH_PREFIX = 'b:';

	/**
	 * Reason codes that describe a successful step, not a reason to skip.
	 */
	private const QUIET_CODES = array( 'destination_available', 'included_destination', 'matched_rule' );

	/**
	 * Render the decisions tab as a standalone Activity page.
	 *
	 * Lets routes such as the legacy decisions page show the same screen.
	 */
	public static function render_page(): void {
		self::require_capability();

		Ui::page_start( __( 'Activity', 'pressqueue' ) );
		DeliveryListController::render_tabs( 'decisions' );
		self::render_tab();
		Ui::page_end();
	}

	/**
	 * Return the outcome filters shown as chips.
	 *
	 * @return array<string,string>
	 */
	public static function outcomes(): array {
		return array(
			'all'        => __( 'All', 'pressqueue' ),
			'shared'     => __( 'Matched', 'pressqueue' ),
			'not_shared' => __( 'Not shared', 'pressqueue' ),
			'blocked'    => __( 'Blocked by an error', 'pressqueue' ),
		);
	}

	/**
	 * Render the decisions tab body.
	 */
	public static function render_tab(): void {
		self::require_capability();

		$outcome = sanitize_key( self::query_text( 'outcome', 20 ) );
		if ( ! isset( self::outcomes()[ $outcome ] ) ) {
			$outcome = 'all';
		}
		$page = max( 1, min( 999999, (int) self::query_text( 'paged', 7 ) ) );

		try {
			$database = Database::from_globals();
			$counts   = self::outcome_counts( $database );
			$keys     = self::group_keys( $database, $outcome, $page );
			$events   = self::load_events( $database, $keys );
		} catch ( StorageException | ValidationException $exception ) {
			echo '<div class="pq-callout pq-callout--bad"><p>' . esc_html( SafeOperatorError::message( $exception, __( 'Decisions could not be loaded.', 'pressqueue' ) ) ) . '</p></div>';
			return;
		}

		echo '<p class="pq-sub pq-decisions-intro">' . esc_html__( 'Each time a post is published, updated or picked in Share old posts, PressQueue checks your rules and records what it decided.', 'pressqueue' ) . '</p>';
		self::render_chips( $outcome, $counts );

		if ( array() === $events ) {
			echo '<div class="pq-card">';
			self::render_empty_state( $outcome );
			echo '</div>';
			return;
		}

		$summary  = new ActivitySummary();
		$post_ids = array_values( array_unique( array_map( static fn( array $event ): int => (int) $event['post_id'], $events ) ) );
		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $post_ids, false, false );
		}

		echo '<div class="pq-card"><ul class="pq-decisions">';
		foreach ( $events as $event ) {
			self::render_event( $event, $summary );
		}
		echo '</ul></div>';

		self::render_pagination( $page, $counts[ $outcome ] ?? 0, $outcome );
	}

	/**
	 * Count rows per outcome.
	 *
	 * @param Database $database Database.
	 * @return array<string,int>
	 */
	private static function outcome_counts( Database $database ): array {
		$row = $database->get_row(
			'SELECT COUNT(*) AS total, SUM(shared > 0) AS shared, SUM(shared = 0 AND errors = 0) AS not_shared, SUM(shared = 0 AND errors > 0) AS blocked FROM (' . self::grouped_sql( $database ) . ') AS grouped_events'
		);

		return array(
			'all'        => null === $row ? 0 : (int) $row->total,
			'shared'     => null === $row ? 0 : (int) $row->shared,
			'not_shared' => null === $row ? 0 : (int) $row->not_shared,
			'blocked'    => null === $row ? 0 : (int) $row->blocked,
		);
	}

	/**
	 * Return the row keys for one page, newest first.
	 *
	 * A row key is an event key, or a post and batch for events from Share old posts.
	 *
	 * @param Database $database Database.
	 * @param string   $outcome Outcome filter.
	 * @param int      $page Page number.
	 * @return array<int,string>
	 */
	private static function group_keys( Database $database, string $outcome, int $page ): array {
		$conditions = array(
			'all'        => '1 = 1',
			'shared'     => 'shared > 0',
			'not_shared' => 'shared = 0 AND errors = 0',
			'blocked'    => 'shared = 0 AND errors > 0',
		);

		$rows = $database->get_results(
			'SELECT group_key FROM (' . self::grouped_sql( $database ) . ') AS grouped_events WHERE ' . $conditions[ $outcome ] . ' ORDER BY last_id DESC LIMIT %d OFFSET %d',
			array( self::PAGE_SIZE, ( $page - 1 ) * self::PAGE_SIZE )
		);

		return array_map( static fn( object $row ): string => (string) $row->group_key, $rows );
	}

	/**
	 * Return the per-row aggregate used for counts and paging.
	 *
	 * Events from one batch item are joined through their occurrence key and
	 * grouped by batch and post. Every other event is its own row.
	 *
	 * @param Database $database Database.
	 * @return string
	 */
	private static function grouped_sql( Database $database ): string {
		return "SELECT COALESCE(CONCAT('" . self::BATCH_PREFIX . "', items.batch_public_id, ':', decisions.post_id), decisions.event_key) AS group_key, MAX(decisions.id) AS last_id, SUM(decisions.result = 'included') AS shared, SUM(decisions.result = 'policy_error') AS errors FROM `" . $database->table( Schema::DECISIONS ) . '` AS decisions LEFT JOIN `' . $database->table( Schema::BATCH_ITEMS ) . "` AS items ON items.intent_occurrence_key = decisions.intent_occurrence_key WHERE decisions.event_mode = 'runtime' GROUP BY group_key";
	}

	/**
	 * Map the event keys behind a page of rows to their row keys.
	 *
	 * @param Database          $database Database.
	 * @param array<int,string> $keys Row keys.
	 * @return array<string,string> Event key => row key.
	 */
	private static function event_map( Database $database, array $keys ): array {
		$map     = array();
		$batches = array();

		foreach ( $keys as $key ) {
			if ( ! str_starts_with( $key, self::BATCH_PREFIX ) ) {
				$map[ $key ] = $key;
				continue;
			}

			$parts = explode( ':', substr( $key, strlen( self::BATCH_PREFIX ) ) );
			if ( 2 === count( $parts ) ) {
				$batches[ $parts[0] ] = true;
			}
		}

		if ( array() === $batches ) {
			return $map;
		}

		$wanted = array_flip( $keys );
		$rows   = $database->get_results(
			'SELECT DISTINCT decisions.event_key, decisions.post_id, items.batch_public_id FROM `' . $database->table( Schema::DECISIONS ) . '` AS decisions INNER JOIN `' . $database->table( Schema::BATCH_ITEMS ) . "` AS items ON items.intent_occurrence_key = decisions.intent_occurrence_key WHERE decisions.event_mode = 'runtime' AND items.batch_public_id IN (" . implode( ', ', array_fill( 0, count( $batches ), '%s' ) ) . ')',
			array_keys( $batches )
		);

		foreach ( $rows as $row ) {
			$key = self::BATCH_PREFIX . (string) $row->batch_public_id . ':' . (string) (int) $row->post_id;
			if ( isset( $wanted[ $key ] ) ) {
				$map[ (string) $row->event_key ] = $key;
			}
		}

		return $map;
	}

	/**
	 * Load decisions and shares for a page of rows.
	 *
	 * @param Database          $database Database.
	 * @param array<int,string> $keys Row keys in display order.
	 * @return array<int,array{post_id:int,trigger:string,created_at:\DateTimeImmutable,decisions:array<int,DecisionRecord>,deliveries:array<int,DeliveryRecord>}>
	 */
	private static function load_events( Database $database, array $keys ): array {
		if ( array() === $keys ) {
			return array();
		}

		$map = self::event_map( $database, $keys );
		if ( array() === $map ) {
			return array();
		}

		$event_keys   = array_map( 'strval', array_keys( $map ) );
		$placeholders = implode( ', ', array_fill( 0, count( $event_keys ), '%s' ) );
		$events       = array();

		foreach ( $keys as $key ) {
			$events[ $key ] = array(
				'decisions'  => array(),
				'deliveries' => array(),
			);
		}

		$rows = $database->get_results(
			'SELECT * FROM `' . $database->table( Schema::DECISIONS ) . "` WHERE event_mode = 'runtime' AND event_key IN (" . $placeholders . ') ORDER BY id ASC',
			$event_keys
		);
		foreach ( $rows as $row ) {
			$decision = self::hydrate_decision( $row );
			if ( null !== $decision && isset( $map[ $decision->event_key ] ) ) {
				$events[ $map[ $decision->event_key ] ]['decisions'][] = $decision;
			}
		}

		$repository = new DeliveryRepository( $database );
		$rows       = $database->get_results(
			'SELECT * FROM `' . $database->table( Schema::DELIVERIES ) . '` WHERE event_key IN (' . $placeholders . ') ORDER BY id ASC',
			$event_keys
		);
		foreach ( $rows as $row ) {
			$delivery = $repository->hydrate_record( $row );
			if ( isset( $map[ $delivery->event_key ] ) ) {
				$events[ $map[ $delivery->event_key ] ]['deliveries'][] = $delivery;
			}
		}

		$result = array();
		foreach ( $events as $event ) {
			if ( array() === $event['decisions'] ) {
				continue;
			}

			$first    = $event['decisions'][0];
			$result[] = array(
				'post_id'    => $first->post_id,
				'trigger'    => $first->trigger,
				'created_at' => $first->created_at,
				'decisions'  => $event['decisions'],
				'deliveries' => $event['deliveries'],
			);
		}

		return $result;
	}

	/**
	 * Render the outcome chips.
	 *
	 * @param string            $current Current outcome.
	 * @param array<string,int> $counts Counts.
	 */
	private static function render_chips( string $current, array $counts ): void {
		echo '<nav class="pq-chips pq-activity-chips" aria-label="' . esc_attr__( 'Filter decisions by outcome', 'pressqueue' ) . '">';
		foreach ( self::outcomes() as $key => $label ) {
			$args = array( 'tab' => 'decisions' );
			if ( 'all' !== $key ) {
				$args['outcome'] = $key;
			}

			$total = (int) ( $counts[ $key ] ?? 0 );
			$class = 'blocked' === $key && $total > 0 ? ' class="pq-chip-alert"' : '';
			$aria  = $current === $key ? ' aria-current="true"' : '';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $class and $aria are fixed literals.
			echo '<a href="' . esc_url( Ui::url( DeliveryListController::SLUG, $args ) ) . '"' . $class . $aria . '>' . esc_html( $label ) . ' <b>' . esc_html( number_format_i18n( $total ) ) . '</b></a>';
		}
		echo '</nav>';
	}

	/**
	 * Render one post event.
	 *
	 * @param array{post_id:int,trigger:string,created_at:\DateTimeImmutable,decisions:array<int,DecisionRecord>,deliveries:array<int,DeliveryRecord>} $event Event.
	 * @param ActivitySummary                                                                                                                          $summary Summary.
	 */
	private static function render_event( array $event, ActivitySummary $summary ): void {
		$post_id  = $event['post_id'];
		$can_edit = current_user_can( 'edit_post', $post_id );
		$kind     = self::kind( $event['decisions'] );
		$channels = self::shared_channels( $event, $summary );
		$test_run = array() !== $event['deliveries'] && array() === array_filter(
			$event['deliveries'],
			static function ( DeliveryRecord $delivery ): bool {
				return 'dry_run' !== $delivery->state;
			}
		);

		echo '<li class="pq-decision pq-decision--' . esc_attr( $kind ) . '">';
		echo '<div class="pq-decision-main">';
		echo '<h2 class="pq-decision-title">';
		$link = $can_edit ? get_edit_post_link( $post_id ) : '';
		if ( is_string( $link ) && '' !== $link ) {
			echo '<a class="pq-row-link" href="' . esc_url( $link ) . '">' . esc_html( Ui::post_title( $post_id ) ) . '</a>';
		} else {
			echo esc_html( Ui::post_title( $post_id ) );
		}
		echo '</h2>';

		echo '<div class="pq-decision-summary">';
		if ( 'shared' === $kind && array() !== $channels ) {
			echo '<span class="pq-svcs" aria-hidden="true">';
			foreach ( $channels as $channel ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- service_badge returns escaped markup.
				echo Ui::service_badge( $channel['service'], 'sm' );
			}
			echo '</span>';
		}
		echo '<span>' . esc_html( self::summary_sentence( $kind, $event, $channels, $can_edit, $test_run, $summary ) ) . '</span>';
		$outcome = self::delivery_outcome( $event['deliveries'] );
		if ( '' !== $outcome['text'] ) {
			echo '<span class="pq-decision-status pq-decision-status--' . esc_attr( $outcome['tone'] ) . '">' . esc_html( $outcome['text'] ) . '</span>';
		}
		echo '</div>';

		$meta  = array( ucfirst( Labels::trigger_short( $event['trigger'] ) ) );
		$rules = in_array( $kind, array( 'not_shared', 'switched_off' ), true ) ? array() : self::rule_names( $event['decisions'], $kind, $summary );
		if ( array() !== $rules ) {
			/* translators: %s: one or more rule names. */
			$meta[] = sprintf( _n( 'Rule: %s', 'Rules: %s', count( $rules ), 'pressqueue' ), implode( ', ', $rules ) );
		}
		echo '<div class="pq-decision-foot">';
		echo '<span class="pq-decision-meta">' . esc_html( implode( ' · ', $meta ) ) . '</span>';
		self::render_why( $event, $can_edit, $summary );
		echo '</div>';
		echo '</div>';

		echo '<div class="pq-decision-side">';
		if ( $test_run ) {
			$state = Labels::delivery_state( 'dry_run' );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pill returns escaped markup.
			echo Ui::pill( $state['tone'], $state['label'], $state['help'] );
		} else {
			$result = Labels::decision_result( self::result_for_kind( $kind ) );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pill returns escaped markup.
			echo Ui::pill( $result['tone'], $result['label'], $result['help'] );
		}
		DeliveryListController::render_time( $event['created_at'] );
		echo '</div>';
		echo '</li>';
	}

	/**
	 * Render the per-event disclosure with every reason.
	 *
	 * @param array{post_id:int,trigger:string,created_at:\DateTimeImmutable,decisions:array<int,DecisionRecord>,deliveries:array<int,DeliveryRecord>} $event Event.
	 * @param bool                                                                                                                                     $can_edit Whether the viewer may edit the post.
	 * @param ActivitySummary                                                                                                                          $summary Summary.
	 */
	private static function render_why( array $event, bool $can_edit, ActivitySummary $summary ): void {
		echo '<details class="pq-more pq-decision-why"><summary>' . esc_html__( 'Why', 'pressqueue' ) . '</summary><div class="pq-more-body pq-stack">';

		if ( ! $can_edit ) {
			echo '<p class="pq-muted">' . esc_html( Labels::reason( 'post_facts_redacted' ) ) . '</p>';
			self::render_shares_link( $event );
			echo '</div></details>';
			return;
		}

		$rules = self::rule_lines( $event['decisions'], $summary );
		if ( array() !== $rules ) {
			echo '<div><h3 class="pq-why-heading">' . esc_html__( 'Rules checked', 'pressqueue' ) . '</h3><ul class="pq-checklist pq-why-list">';
			foreach ( $rules as $line ) {
				echo '<li><span class="' . esc_attr( $line['matched'] ? 'pq-yes' : 'pq-no' ) . '" aria-hidden="true">' . ( $line['matched'] ? '&#x2713;' : '&#x2715;' ) . '</span><span>' . esc_html( $line['text'] );
				if ( '' !== $line['detail'] ) {
					echo ' <span class="pq-muted">' . esc_html( $line['detail'] ) . '</span>';
				}
				echo '</span></li>';
			}
			echo '</ul></div>';
		}

		$channels = self::channel_lines( $event['decisions'], $summary );
		if ( array() !== $channels ) {
			echo '<div><h3 class="pq-why-heading">' . esc_html__( 'Channels', 'pressqueue' ) . '</h3><ul class="pq-checklist pq-why-list">';
			foreach ( $channels as $line ) {
				echo '<li><span class="' . esc_attr( $line['shared'] ? 'pq-yes' : 'pq-no' ) . '" aria-hidden="true">' . ( $line['shared'] ? '&#x2713;' : '&#x2715;' ) . '</span><span>';
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- channel_name returns escaped markup.
				echo Ui::channel_name( $line['service'], $line['name'] );
				echo ' <span class="pq-muted">' . esc_html( $line['reason'] ) . '</span></span></li>';
			}
			echo '</ul></div>';
		}

		$reasons = self::all_reasons( $event['decisions'] );
		if ( array() !== $reasons ) {
			echo '<div><h3 class="pq-why-heading">' . esc_html__( 'Reasons', 'pressqueue' ) . '</h3><ul class="pq-why-reasons">';
			foreach ( $reasons as $reason ) {
				echo '<li>' . esc_html( $reason ) . '</li>';
			}
			echo '</ul></div>';
		}

		self::render_shares_link( $event );
		echo '</div></details>';
	}

	/**
	 * Link to the shares of a post when there are any.
	 *
	 * @param array{post_id:int,trigger:string,created_at:\DateTimeImmutable,decisions:array<int,DecisionRecord>,deliveries:array<int,DeliveryRecord>} $event Event.
	 */
	private static function render_shares_link( array $event ): void {
		if ( array() === $event['deliveries'] || ! current_user_can( Capabilities::VIEW_DELIVERIES ) ) {
			return;
		}

		echo '<p><a href="' . esc_url( Ui::url( DeliveryListController::SLUG, array( 'post_id' => $event['post_id'] ) ) ) . '">' . esc_html__( 'See the shares for this post', 'pressqueue' ) . '</a></p>';
	}

	/**
	 * Return the event outcome: shared, never, switched_off, blocked or not_shared.
	 *
	 * @param array<int,DecisionRecord> $decisions Decisions.
	 * @return string
	 */
	private static function kind( array $decisions ): string {
		$results = array_map( static fn( DecisionRecord $decision ): string => $decision->result, $decisions );

		if ( in_array( 'included', $results, true ) ) {
			return 'shared';
		}

		$map = array(
			'excluded'     => 'never',
			'policy_error' => 'blocked',
			'switched_off' => 'switched_off',
		);

		return $map[ ActivitySummary::not_shared_kind( $decisions ) ] ?? 'not_shared';
	}

	/**
	 * Map an event kind to a stored decision result for labels.
	 *
	 * @param string $kind Kind.
	 * @return string
	 */
	private static function result_for_kind( string $kind ): string {
		$map = array(
			'shared'       => 'included',
			'blocked'      => 'policy_error',
			'never'        => 'excluded',
			'switched_off' => 'switched_off',
			'not_shared'   => 'skipped',
		);

		return $map[ $kind ] ?? 'skipped';
	}

	/**
	 * Return the one-line summary for an event.
	 *
	 * @param string                                                                                                                                   $kind Kind.
	 * @param array{post_id:int,trigger:string,created_at:\DateTimeImmutable,decisions:array<int,DecisionRecord>,deliveries:array<int,DeliveryRecord>} $event Event.
	 * @param array<int,array{service:string,name:string,public_id:string}>                                                                            $channels Shared channels.
	 * @param bool                                                                                                                                     $can_edit Whether the viewer may edit the post.
	 * @param bool                                                                                                                                     $test_run Whether every share is a test run.
	 * @param ActivitySummary                                                                                                                          $summary Summary.
	 * @return string
	 */
	private static function summary_sentence( string $kind, array $event, array $channels, bool $can_edit, bool $test_run, ActivitySummary $summary ): string {
		$decisions = $event['decisions'];

		if ( 'shared' === $kind ) {
			$names = self::channel_list( $channels );
			if ( '' === $names ) {
				return $test_run ? __( 'Test run: would be shared.', 'pressqueue' ) : __( 'A rule matched.', 'pressqueue' );
			}

			if ( $test_run ) {
				/* translators: %s: list of channels such as "LinkedIn, X and Bluesky". */
				return sprintf( __( 'Test run: would share to %s.', 'pressqueue' ), $names );
			}

			/* translators: %s: list of channels such as "LinkedIn, X and Bluesky". */
			return sprintf( __( 'Matched for %s.', 'pressqueue' ), $names );
		}

		if ( ! $can_edit ) {
			return Labels::reason( 'post_facts_redacted' );
		}

		if ( 'switched_off' === $kind ) {
			return Labels::decision_result( 'switched_off' )['help'];
		}

		if ( 'never' === $kind ) {
			/* translators: %s: why the post is never shared, such as "in a category you never share". */
			return sprintf( __( 'Never share: %s', 'pressqueue' ), self::exclusion_phrase( $decisions, $summary ) );
		}

		if ( 'blocked' === $kind ) {
			/* translators: %s: reason sentence. */
			return sprintf( __( 'Blocked by an error: %s', 'pressqueue' ), self::primary_reason( $decisions, array( 'policy_error' ) ) );
		}

		/* translators: %s: reason sentence. */
		return sprintf( __( 'Not shared: %s', 'pressqueue' ), self::primary_reason( $decisions, array( 'skipped' ) ) );
	}

	/**
	 * Say what happened to the shares, such as "2 sent, 1 failed".
	 *
	 * Test runs are left out: their pill already says so.
	 *
	 * @param array<int,DeliveryRecord> $deliveries Shares for the row.
	 * @return array{text:string,tone:string}
	 */
	private static function delivery_outcome( array $deliveries ): array {
		$counts = array(
			'cancelled' => 0,
			'check'     => 0,
			'failed'    => 0,
			'held'      => 0,
			'sent'      => 0,
			'waiting'   => 0,
		);

		foreach ( $deliveries as $delivery ) {
			if ( 'accepted' === $delivery->state ) {
				++$counts['sent'];
			} elseif ( 'failed' === $delivery->state ) {
				++$counts['failed'];
			} elseif ( 'ambiguous' === $delivery->state ) {
				++$counts['check'];
			} elseif ( 'held' === $delivery->state ) {
				++$counts['held'];
			} elseif ( 'cancelled' === $delivery->state ) {
				++$counts['cancelled'];
			} elseif ( in_array( $delivery->state, array( 'proposed', 'queued', 'leased', 'retrying' ), true ) ) {
				++$counts['waiting'];
			}
		}

		$parts = array();
		if ( $counts['sent'] > 0 ) {
			/* translators: %d: number of shares Buffer accepted. */
			$parts[] = sprintf( _n( '%d sent', '%d sent', $counts['sent'], 'pressqueue' ), $counts['sent'] );
		}
		if ( $counts['failed'] > 0 ) {
			/* translators: %d: number of shares. */
			$parts[] = sprintf( _n( '%d failed', '%d failed', $counts['failed'], 'pressqueue' ), $counts['failed'] );
		}
		if ( $counts['check'] > 0 ) {
			/* translators: %d: number of shares that may have reached Buffer. */
			$parts[] = sprintf( _n( '%d to check in Buffer', '%d to check in Buffer', $counts['check'], 'pressqueue' ), $counts['check'] );
		}
		if ( $counts['held'] > 0 ) {
			/* translators: %d: number of shares. */
			$parts[] = sprintf( _n( '%d on hold', '%d on hold', $counts['held'], 'pressqueue' ), $counts['held'] );
		}
		if ( $counts['waiting'] > 0 ) {
			/* translators: %d: number of shares. */
			$parts[] = sprintf( _n( '%d waiting', '%d waiting', $counts['waiting'], 'pressqueue' ), $counts['waiting'] );
		}
		if ( $counts['cancelled'] > 0 ) {
			/* translators: %d: number of shares. */
			$parts[] = sprintf( _n( '%d cancelled', '%d cancelled', $counts['cancelled'], 'pressqueue' ), $counts['cancelled'] );
		}

		$tone = 'idle';
		if ( $counts['failed'] + $counts['check'] > 0 ) {
			$tone = 'bad';
		} elseif ( $counts['held'] > 0 ) {
			$tone = 'warn';
		} elseif ( $counts['waiting'] > 0 ) {
			$tone = 'info';
		} elseif ( $counts['sent'] > 0 ) {
			$tone = 'ok';
		}

		return array(
			'text' => implode( ', ', $parts ),
			'tone' => $tone,
		);
	}

	/**
	 * Return the channels an event shared to.
	 *
	 * @param array{post_id:int,trigger:string,created_at:\DateTimeImmutable,decisions:array<int,DecisionRecord>,deliveries:array<int,DeliveryRecord>} $event Event.
	 * @param ActivitySummary                                                                                                                          $summary Summary.
	 * @return array<int,array{service:string,name:string,public_id:string}>
	 */
	private static function shared_channels( array $event, ActivitySummary $summary ): array {
		$ids = array();

		foreach ( $event['deliveries'] as $delivery ) {
			$ids[ $delivery->channel_public_id ] = $delivery->channel_public_id;
		}

		if ( array() === $ids ) {
			foreach ( $event['decisions'] as $decision ) {
				if ( 'included' === $decision->result && 'channel' === $decision->scope_type && null !== $decision->scope_public_id ) {
					$ids[ $decision->scope_public_id ] = $decision->scope_public_id;
				}
			}
		}

		$channels = array();
		foreach ( $ids as $public_id ) {
			$channel    = $summary->channel( $public_id );
			$channels[] = array(
				'name'      => null === $channel ? __( 'Removed channel', 'pressqueue' ) : ActivitySummary::channel_display_name( $channel ),
				'public_id' => $public_id,
				'service'   => null === $channel ? '' : $channel->service,
			);
		}

		return $channels;
	}

	/**
	 * Return "LinkedIn, X and Bluesky", using channel names when a service repeats.
	 *
	 * @param array<int,array{service:string,name:string,public_id:string}> $channels Channels.
	 * @return string
	 */
	private static function channel_list( array $channels ): string {
		$services = array_map( static fn( array $channel ): string => '' === $channel['service'] ? $channel['name'] : Labels::service( $channel['service'] ), $channels );
		$names    = count( array_unique( $services ) ) === count( $services ) ? $services : array_map( static fn( array $channel ): string => $channel['name'], $channels );

		if ( array() === $names ) {
			return '';
		}

		if ( 1 === count( $names ) ) {
			return $names[0];
		}

		$last = array_pop( $names );

		/* translators: 1: comma-separated list of channels, 2: the last channel. */
		return sprintf( __( '%1$s and %2$s', 'pressqueue' ), implode( ', ', $names ), $last );
	}

	/**
	 * Return the rule names that decided an event.
	 *
	 * @param array<int,DecisionRecord> $decisions Decisions.
	 * @param string                    $kind Kind.
	 * @param ActivitySummary           $summary Summary.
	 * @return array<int,string>
	 */
	private static function rule_names( array $decisions, string $kind, ActivitySummary $summary ): array {
		$result = self::result_for_kind( $kind );
		$names  = array();

		foreach ( $decisions as $decision ) {
			if ( $result !== $decision->result || null === $decision->rule_public_id ) {
				continue;
			}

			$name                               = $summary->rule_name( $decision->rule_public_id );
			$names[ $decision->rule_public_id ] = '' === $name ? __( 'A deleted rule', 'pressqueue' ) : $name;
		}

		return array_values( $names );
	}

	/**
	 * Return "matched / did not match" lines per rule.
	 *
	 * @param array<int,DecisionRecord> $decisions Decisions.
	 * @param ActivitySummary           $summary Summary.
	 * @return array<int,array{detail:string,matched:bool,text:string}>
	 */
	private static function rule_lines( array $decisions, ActivitySummary $summary ): array {
		$lines = array();

		foreach ( $decisions as $decision ) {
			if ( null === $decision->rule_public_id || isset( $lines[ $decision->rule_public_id ] ) ) {
				continue;
			}

			$name = $summary->rule_name( $decision->rule_public_id );
			$name = '' === $name ? __( 'A deleted rule', 'pressqueue' ) : $name;

			switch ( $decision->result ) {
				case 'included':
					/* translators: %s: rule name. */
					$text    = sprintf( __( '%s matched.', 'pressqueue' ), $name );
					$matched = true;
					break;

				case 'excluded':
					/* translators: %s: rule name. */
					$text    = sprintf( __( '%s matched, so the post is never shared.', 'pressqueue' ), $name );
					$matched = true;
					break;

				case 'policy_error':
					/* translators: %s: rule name. */
					$text    = sprintf( __( '%s could not be checked.', 'pressqueue' ), $name );
					$matched = false;
					break;

				default:
					/* translators: %s: rule name. */
					$text    = sprintf( __( '%s did not match.', 'pressqueue' ), $name );
					$matched = false;
			}

			$lines[ $decision->rule_public_id ] = array(
				'detail'  => implode( ' ', self::decision_details( $decision ) ),
				'matched' => $matched,
				'text'    => $text,
			);
		}

		return array_values( $lines );
	}

	/**
	 * Return the condition sentences recorded for one rule decision.
	 *
	 * Group summaries are only used when no single condition was recorded.
	 *
	 * @param DecisionRecord $decision Decision.
	 * @return array<int,string>
	 */
	private static function decision_details( DecisionRecord $decision ): array {
		$leaf  = array();
		$group = array();

		foreach ( $decision->reasons as $reason ) {
			if ( ! is_array( $reason ) || ! is_string( $reason['code'] ?? null ) || in_array( $reason['code'], self::QUIET_CODES, true ) ) {
				continue;
			}

			$sentence = self::reason_sentence( $reason );
			if ( str_starts_with( $reason['code'], 'condition_group_' ) ) {
				$group[ $sentence ] = $sentence;
			} else {
				$leaf[ $sentence ] = $sentence;
			}
		}

		return array_values( array() === $leaf ? $group : $leaf );
	}

	/**
	 * Return one line per channel decision.
	 *
	 * @param array<int,DecisionRecord> $decisions Decisions.
	 * @param ActivitySummary           $summary Summary.
	 * @return array<int,array{shared:bool,service:string,name:string,reason:string}>
	 */
	private static function channel_lines( array $decisions, ActivitySummary $summary ): array {
		$lines = array();

		foreach ( $decisions as $decision ) {
			if ( 'channel' !== $decision->scope_type || null === $decision->scope_public_id ) {
				continue;
			}

			$channel = $summary->channel( $decision->scope_public_id );
			$shared  = 'included' === $decision->result;
			$reason  = $shared ? __( 'The matching rule picks this channel.', 'pressqueue' ) : self::first_reason( $decision->reasons );

			$lines[ $decision->scope_public_id ] = array(
				'name'    => null === $channel ? __( 'Removed channel', 'pressqueue' ) : ActivitySummary::channel_display_name( $channel ),
				'reason'  => $reason,
				'service' => null === $channel ? '' : $channel->service,
				'shared'  => $shared,
			);
		}

		return array_values( $lines );
	}

	/**
	 * Return post-level reasons as sentences, without repeats.
	 *
	 * Rule and channel reasons are listed next to their rule or channel.
	 *
	 * @param array<int,DecisionRecord> $decisions Decisions.
	 * @return array<int,string>
	 */
	private static function all_reasons( array $decisions ): array {
		$sentences = array();

		foreach ( $decisions as $decision ) {
			if ( null !== $decision->rule_public_id || 'channel' === $decision->scope_type ) {
				continue;
			}

			foreach ( $decision->reasons as $reason ) {
				if ( ! is_array( $reason ) || ! is_string( $reason['code'] ?? null ) || in_array( $reason['code'], self::QUIET_CODES, true ) ) {
					continue;
				}

				$sentence               = self::reason_sentence( $reason );
				$sentences[ $sentence ] = $sentence;
			}
		}

		return array_values( $sentences );
	}

	/**
	 * Return the most useful reason for a post that was not shared.
	 *
	 * Post-level reasons win over rule conditions, which only say a rule did not match.
	 *
	 * @param array<int,DecisionRecord> $decisions Decisions.
	 * @param array<int,string>         $results Results to look at.
	 * @return string
	 */
	private static function primary_reason( array $decisions, array $results ): string {
		$ordered = array_merge(
			array_filter( $decisions, static fn( DecisionRecord $decision ): bool => null === $decision->rule_public_id ),
			array_filter( $decisions, static fn( DecisionRecord $decision ): bool => null !== $decision->rule_public_id )
		);

		foreach ( $ordered as $decision ) {
			if ( ! in_array( $decision->result, $results, true ) ) {
				continue;
			}

			foreach ( $decision->reasons as $reason ) {
				if ( ! is_array( $reason ) || ! is_string( $reason['code'] ?? null ) || self::is_condition_reason( $reason ) || in_array( $reason['code'], self::QUIET_CODES, true ) ) {
					continue;
				}

				return self::reason_sentence( $reason );
			}
		}

		return 'policy_error' === ( $results[0] ?? '' )
			? Labels::reason( 'malformed_rule' )
			: Labels::reason( 'no_matching_rules' );
	}

	/**
	 * Return the first meaningful reason in a list.
	 *
	 * @param array<int,mixed> $reasons Reasons.
	 * @return string
	 */
	private static function first_reason( array $reasons ): string {
		foreach ( $reasons as $reason ) {
			if ( ! is_array( $reason ) || ! is_string( $reason['code'] ?? null ) || in_array( $reason['code'], self::QUIET_CODES, true ) || str_starts_with( $reason['code'], 'condition_group_' ) ) {
				continue;
			}

			return self::reason_sentence( $reason );
		}

		return Labels::reason( 'no_matching_rules' );
	}

	/**
	 * Explain why a never share rule stopped the post.
	 *
	 * @param array<int,DecisionRecord> $decisions Decisions.
	 * @param ActivitySummary           $summary Summary.
	 * @return string
	 */
	private static function exclusion_phrase( array $decisions, ActivitySummary $summary ): string {
		foreach ( $decisions as $decision ) {
			// The post's own switch is explained on its own, never as a "never share" rule.
			if ( 'excluded' !== $decision->result || ActivitySummary::is_switched_off( $decision ) ) {
				continue;
			}

			foreach ( $decision->reasons as $reason ) {
				if ( ! is_array( $reason ) || ! is_string( $reason['code'] ?? null ) ) {
					continue;
				}

				if ( ! self::is_condition_reason( $reason ) || 'matched' !== ( $reason['condition_result'] ?? '' ) ) {
					continue;
				}

				$field = (string) ( $reason['field'] ?? '' );
				if ( str_starts_with( $field, 'taxonomy:' ) ) {
					/* translators: %s: taxonomy name such as "category" or "tag". */
					return sprintf( __( 'in a %s you never share.', 'pressqueue' ), self::taxonomy_label( substr( $field, 9 ) ) );
				}

				$phrases = array(
					'author'      => __( 'written by an author whose posts you never share.', 'pressqueue' ),
					'post_type'   => __( 'a kind of post you never share.', 'pressqueue' ),
					'post_status' => __( 'a post status you never share.', 'pressqueue' ),
					'post_policy' => __( 'a post sharing setting you never share.', 'pressqueue' ),
				);
				if ( isset( $phrases[ $field ] ) ) {
					return $phrases[ $field ];
				}

				if ( str_starts_with( $field, 'metadata:' ) ) {
					return __( 'a custom field value you never share.', 'pressqueue' );
				}
			}

			if ( null !== $decision->rule_public_id ) {
				$name = $summary->rule_name( $decision->rule_public_id );
				if ( '' !== $name ) {
					/* translators: %s: rule name. */
					return sprintf( __( 'matches the rule %s.', 'pressqueue' ), $name );
				}
			}
		}

		return __( 'a "never share" rule matches this post.', 'pressqueue' );
	}

	/**
	 * Turn one stored reason into a sentence.
	 *
	 * @param array<string,mixed> $reason Reason.
	 * @return string
	 */
	private static function reason_sentence( array $reason ): string {
		$code    = (string) $reason['code'];
		$label   = is_string( $reason['label'] ?? null ) ? (string) $reason['label'] : '';
		$known   = Labels::reason( $code, '' );
		$matched = 'matched' === ( $reason['condition_result'] ?? '' );

		if ( ( '' !== $known && $known !== $code ) || str_starts_with( $code, 'invalid_' ) ) {
			return $known;
		}

		if ( str_starts_with( $code, 'condition_group_' ) ) {
			if ( 'ANY' === ( $reason['operator'] ?? 'ALL' ) ) {
				return $matched ? __( 'At least one condition matched.', 'pressqueue' ) : __( 'None of the conditions matched.', 'pressqueue' );
			}

			return $matched ? __( 'All conditions matched.', 'pressqueue' ) : __( 'Not all conditions matched.', 'pressqueue' );
		}

		if ( self::is_condition_reason( $reason ) ) {
			$field = (string) ( $reason['field'] ?? '' );

			if ( str_starts_with( $field, 'taxonomy:' ) ) {
				$taxonomy = self::taxonomy_label( substr( $field, 9 ) );
				if ( $matched ) {
					/* translators: %s: taxonomy name such as "category". */
					return sprintf( __( 'The post is in a matching %s.', 'pressqueue' ), $taxonomy );
				}

				/* translators: %s: taxonomy name such as "category". */
				return sprintf( __( 'The post is not in a matching %s.', 'pressqueue' ), $taxonomy );
			}

			if ( str_starts_with( $field, 'metadata:' ) ) {
				return $matched ? __( 'A custom field matches.', 'pressqueue' ) : __( 'A custom field does not match.', 'pressqueue' );
			}

			$sentences = array(
				'author'      => array( __( 'The author matches.', 'pressqueue' ), __( 'The author does not match.', 'pressqueue' ) ),
				'post_policy' => array( __( 'The post sharing setting matches.', 'pressqueue' ), __( 'The post sharing setting does not match.', 'pressqueue' ) ),
				'post_status' => array( __( 'The post status matches.', 'pressqueue' ), __( 'The post status does not match.', 'pressqueue' ) ),
				'post_type'   => array( __( 'The kind of post matches.', 'pressqueue' ), __( 'The kind of post does not match.', 'pressqueue' ) ),
			);

			if ( isset( $sentences[ $field ] ) ) {
				return $sentences[ $field ][ $matched ? 0 : 1 ];
			}
		}

		return '' !== $label ? $label : $code;
	}

	/**
	 * Check whether a reason comes from a rule condition.
	 *
	 * @param array<string,mixed> $reason Reason.
	 * @return bool
	 */
	private static function is_condition_reason( array $reason ): bool {
		$code = (string) ( $reason['code'] ?? '' );

		return str_starts_with( $code, 'condition_group_' ) || 1 === preg_match( '/_(not_)?matched$/', $code );
	}

	/**
	 * Return a lowercase singular taxonomy label.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return string
	 */
	private static function taxonomy_label( string $taxonomy ): string {
		$object = get_taxonomy( $taxonomy );

		if ( $object instanceof \WP_Taxonomy && isset( $object->labels->singular_name ) && is_string( $object->labels->singular_name ) ) {
			return strtolower( $object->labels->singular_name );
		}

		return __( 'term', 'pressqueue' );
	}

	/**
	 * Render the empty state for a filter.
	 *
	 * @param string $outcome Outcome.
	 */
	private static function render_empty_state( string $outcome ): void {
		switch ( $outcome ) {
			case 'shared':
				Ui::empty_state( __( 'No post matched a rule yet', 'pressqueue' ), __( 'Posts that match a sharing rule show up here.', 'pressqueue' ) );
				return;

			case 'not_shared':
				Ui::empty_state( __( 'Every checked post matched a rule', 'pressqueue' ), __( 'Posts that no rule matched, or that a "never share" rule stopped, show up here.', 'pressqueue' ) );
				return;

			case 'blocked':
				Ui::empty_state( __( 'No errors', 'pressqueue' ), __( 'When a rule or channel cannot be checked, PressQueue stops the post to be safe and lists it here.', 'pressqueue' ) );
				return;
		}

		Ui::empty_state( __( 'No posts checked yet', 'pressqueue' ), __( 'When you publish or update a post, PressQueue records here whether it was shared and why.', 'pressqueue' ) );
	}

	/**
	 * Render pagination in plain words.
	 *
	 * @param int    $page Current page.
	 * @param int    $total Total events.
	 * @param string $outcome Outcome.
	 */
	private static function render_pagination( int $page, int $total, string $outcome ): void {
		$total_pages = max( 1, (int) ceil( $total / self::PAGE_SIZE ) );
		$first       = min( $total, ( $page - 1 ) * self::PAGE_SIZE + 1 );
		$last        = min( $total, $page * self::PAGE_SIZE );
		$args        = array( 'tab' => 'decisions' );
		if ( 'all' !== $outcome ) {
			$args['outcome'] = $outcome;
		}

		echo '<nav class="pq-pagination" aria-label="' . esc_attr__( 'Decision pages', 'pressqueue' ) . '"><span>';
		/* translators: 1: first row number, 2: last row number, 3: total number of rows. */
		echo esc_html( sprintf( __( 'Showing %1$s to %2$s of %3$s', 'pressqueue' ), number_format_i18n( $first ), number_format_i18n( $last ), number_format_i18n( $total ) ) );
		echo '</span>';

		if ( $page > 1 ) {
			echo '<a class="button" rel="prev" href="' . esc_url( Ui::url( DeliveryListController::SLUG, array_merge( $args, array( 'paged' => $page - 1 ) ) ) ) . '">' . esc_html__( 'Previous', 'pressqueue' ) . '</a>';
		}

		if ( $page < $total_pages ) {
			echo '<a class="button" rel="next" href="' . esc_url( Ui::url( DeliveryListController::SLUG, array_merge( $args, array( 'paged' => $page + 1 ) ) ) ) . '">' . esc_html__( 'Next', 'pressqueue' ) . '</a>';
		}
		echo '</nav>';
	}

	/**
	 * Hydrate one decision row.
	 *
	 * @param object $row Database row.
	 * @return DecisionRecord|null
	 */
	private static function hydrate_decision( object $row ): ?DecisionRecord {
		try {
			return new DecisionRecord(
				(int) $row->id,
				(string) $row->public_id,
				(string) $row->event_key,
				(string) $row->decision_key,
				property_exists( $row, 'intent_occurrence_key' ) && null !== $row->intent_occurrence_key ? (string) $row->intent_occurrence_key : null,
				(string) $row->scope_type,
				null === $row->scope_public_id ? null : (string) $row->scope_public_id,
				(int) $row->post_id,
				(string) $row->post_type,
				(string) $row->post_status,
				(int) $row->author_id,
				(string) $row->revision_fingerprint,
				(string) $row->trigger,
				(string) $row->event_mode,
				null === $row->rule_id ? null : (int) $row->rule_id,
				null === $row->rule_public_id ? null : (string) $row->rule_public_id,
				null === $row->rule_version ? null : (int) $row->rule_version,
				(string) $row->result,
				CanonicalJson::decode_list( (string) $row->reasons_json ),
				CanonicalJson::decode_object( (string) $row->snapshot_json ),
				(string) $row->snapshot_hash,
				StorageValidator::parse_required_date_time( (string) $row->created_at )
			);
		} catch ( ValidationException $exception ) {
			unset( $exception );
			return null;
		}
	}

	/**
	 * Require the decision view capability.
	 */
	private static function require_capability(): void {
		if ( ! current_user_can( Capabilities::VIEW_DECISIONS ) ) {
			wp_die( esc_html__( 'You are not allowed to view PressQueue decisions.', 'pressqueue' ) );
		}
	}

	/**
	 * Return bounded query text.
	 *
	 * @param string $key Key.
	 * @param int    $maximum Maximum bytes.
	 * @return string
	 */
	private static function query_text( string $key, int $maximum ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Decision filters are read-only and validated against fixed lists.
		$value = isset( $_GET[ $key ] ) ? wp_unslash( $_GET[ $key ] ) : '';

		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = trim( $value );

		return strlen( $value ) > $maximum ? substr( $value, 0, $maximum ) : $value;
	}
}
