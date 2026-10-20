<?php
/**
 * Rule test view: what a rule would do with recent posts.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Planning\EventNormalizer;
use PressQueue\Planning\Planner;
use PressQueue\Security\SafeOperatorError;
use PressQueue\Storage\Record\RuleRecord;
use PressQueue\Storage\Repository\RuleRepository;
use PressQueue\Storage\ValidationException;

/**
 * Runs non-durable previews for one rule, marks the version tested and renders the result.
 */
final class RuleTestView {
	public const DEFAULT_COUNT = 10;
	public const MAX_COUNT     = 50;

	private const TRANSIENT_BASE = 'pressqueue_rule_test_';

	/**
	 * Triggers tested as if the post were published today.
	 */
	private const PUBLICATION_TRIGGERS = array( 'first_publish', 'scheduled_publication' );

	/**
	 * Return the trigger a test uses unless the person picks another.
	 *
	 * @param RuleRecord $rule Rule.
	 * @return string
	 */
	public static function default_trigger( RuleRecord $rule ): string {
		if ( 'exclude' === $rule->effect ) {
			return 'first_publish';
		}

		$triggers = $rule->triggers['triggers'] ?? array();
		foreach ( array( 'first_publish', 'scheduled_publication', 'update', 'bulk', 'repost' ) as $trigger ) {
			if ( in_array( $trigger, $triggers, true ) ) {
				return $trigger;
			}
		}

		return 'first_publish';
	}

	/**
	 * Return the triggers a person can test as, with labels.
	 *
	 * @param RuleRecord $rule Rule.
	 * @return array<string,string>
	 */
	public static function trigger_choices( RuleRecord $rule ): array {
		$labels = Labels::triggers();

		if ( 'exclude' === $rule->effect ) {
			unset( $labels['scheduled_publication'] );
			return $labels;
		}

		$triggers = $rule->triggers['triggers'] ?? array();
		$choices  = array();

		foreach ( $labels as $trigger => $label ) {
			if ( 'scheduled_publication' === $trigger && in_array( 'first_publish', $triggers, true ) ) {
				continue;
			}

			if ( in_array( $trigger, $triggers, true ) ) {
				$choices[ $trigger ] = $label;
			}
		}

		return array() === $choices ? $labels : $choices;
	}

	/**
	 * Return the most recent posts the rule could apply to.
	 *
	 * @param RuleRecord $rule Rule.
	 * @param int        $count Number of posts.
	 * @return array<int,int>
	 */
	public static function recent_post_ids( RuleRecord $rule, int $count ): array {
		$post_types = array_values( array_filter( RuleSummary::post_types( $rule->condition_tree ), 'post_type_exists' ) );
		$query      = new \WP_Query(
			array(
				'fields'                 => 'ids',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'orderby'                => array(
					'date' => 'DESC',
					'ID'   => 'DESC',
				),
				'post_status'            => array( 'publish', 'future', 'draft', 'pending' ),
				'post_type'              => array() === $post_types ? array( 'post' ) : $post_types,
				'posts_per_page'         => max( 1, min( self::MAX_COUNT, $count ) ),
				// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- Operator inventory spans languages; each post is separately capability-checked.
				'suppress_filters'       => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return array_values(
			array_filter(
				array_map( 'intval', $query->posts ),
				array( self::class, 'can_preview_post' )
			)
		);
	}

	/**
	 * Check post preview authorization.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function can_preview_post( int $post_id ): bool {
		$post = $post_id > 0 ? get_post( $post_id ) : null;

		return $post instanceof \WP_Post && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Run the test, mark this exact rule version tested and keep the result for the test view.
	 *
	 * @param RuleRecord     $rule Rule.
	 * @param array<int,int> $post_ids Posts to test.
	 * @param string         $trigger Trigger to test as.
	 * @param int            $count Requested number of posts.
	 * @param bool           $single Whether this tests one chosen post.
	 * @return array<string,mixed>
	 */
	public static function run( RuleRecord $rule, array $post_ids, string $trigger, int $count, bool $single ): array {
		$rules = array( $rule );

		if ( 'include' === $rule->effect ) {
			foreach ( ( new RuleRepository() )->find_enabled() as $candidate ) {
				if ( 'exclude' === $candidate->effect && $candidate->public_id !== $rule->public_id ) {
					$rules[] = $candidate;
				}
			}
		}

		$normalizer = new EventNormalizer();
		$planner    = new Planner();
		$allowlist  = $normalizer->metadata_allowlist_for_rules( $rules );
		$rows       = array();

		foreach ( $post_ids as $post_id ) {
			$rows[] = self::test_post( $rule, $rules, (int) $post_id, $trigger, $normalizer, $planner, $allowlist );
		}

		if ( $rule->last_previewed_version !== $rule->version ) {
			( new RuleRepository() )->mark_version_previewed( $rule->public_id, $rule->lock_version, $rule->version );
		}

		$result = array(
			'rule'    => $rule->public_id,
			'version' => $rule->version,
			'trigger' => $trigger,
			'count'   => $count,
			'single'  => $single,
			'rows'    => $rows,
			'time'    => time(),
		);

		set_transient( self::transient_key(), $result, 30 * MINUTE_IN_SECONDS );

		return $result;
	}

	/**
	 * Render the test view.
	 *
	 * @param RuleRecord $rule Rule.
	 */
	public static function render( RuleRecord $rule ): void {
		$result  = self::stored_result( $rule );
		$current = $rule->last_previewed_version === $rule->version;
		$edit    = RulesController::url(
			array(
				'view' => 'edit',
				'rule' => $rule->public_id,
			)
		);

		Ui::page_start(
			sprintf(
				/* translators: %s: rule name. */
				__( 'Test "%s"', 'pressqueue' ),
				$rule->name
			),
			array(
				'back_url'   => RulesController::url(),
				'back_label' => __( 'Rules', 'pressqueue' ),
				'badge'      => RulesController::status_pill( $rule ),
				'actions'    => '<a class="button" href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit rule', 'pressqueue' ) . '</a>',
			)
		);
		RulesController::render_notice();

		echo '<div class="pq-stack">';
		self::render_status_card( $rule, $current, null !== $result );

		echo '<div class="pq-card">';
		echo '<div class="pq-card-head"><div><h2>' . esc_html__( 'Recent posts', 'pressqueue' ) . '</h2><p class="pq-sub">' . esc_html( 'exclude' === $rule->effect ? __( 'Which posts this rule would keep off social media. Nothing is shared during a test.', 'pressqueue' ) : __( 'What this rule would do with each post, together with your "never share" rules. Nothing is shared during a test.', 'pressqueue' ) ) . '</p></div></div>';
		self::render_controls( $rule, $result );

		if ( null === $result ) {
			echo '<div class="pq-empty"><p>' . esc_html( $current ? __( 'Run the test to see the result here.', 'pressqueue' ) : __( 'This version of the rule has not been tested yet. Run the test to see what it would do.', 'pressqueue' ) ) . '</p></div>';
		} else {
			self::render_results( $rule, $result );
		}
		echo '</div>';

		self::render_single_post_search( $rule );
		echo '</div>';

		Ui::page_end();
	}

	/**
	 * Render the on/off state and the main action.
	 *
	 * @param RuleRecord $rule Rule.
	 * @param bool       $current Whether the current version was tested.
	 * @param bool       $has_result Whether a result is shown.
	 */
	private static function render_status_card( RuleRecord $rule, bool $current, bool $has_result ): void {
		if ( $rule->enabled ) {
			echo '<div class="pq-card pq-card--pad pq-test-status pq-test-status--on">';
			echo '<div><h2>' . esc_html__( 'This rule is on', 'pressqueue' ) . '</h2><p class="pq-sub">' . esc_html( 'exclude' === $rule->effect ? __( 'Matching posts are never shared.', 'pressqueue' ) : __( 'Matching posts are shared as shown below.', 'pressqueue' ) ) . '</p>';
			if ( 'exclude' !== $rule->effect && self::in_test_mode() ) {
				RulesController::render_test_mode_hint();
			}
			echo '</div>';
			RulesController::render_toggle( $rule, 'test' );
			echo '</div>';
			return;
		}

		if ( ! $current || ! $has_result ) {
			echo '<div class="pq-card pq-card--pad pq-test-status">';
			echo '<div><h2>' . esc_html__( 'This rule is off', 'pressqueue' ) . '</h2><p class="pq-sub">' . esc_html__( 'Run the test first. When the result looks right, you can turn the rule on.', 'pressqueue' ) . '</p></div>';
			RulesController::render_test_button( $rule, __( 'Run the test', 'pressqueue' ), 'button button-primary' );
			echo '</div>';
			return;
		}

		echo '<div class="pq-card pq-card--pad pq-test-status pq-test-status--ready">';
		echo '<div><h2>' . esc_html__( 'Ready to turn on', 'pressqueue' ) . '</h2><p class="pq-sub">' . esc_html( 'exclude' === $rule->effect ? __( 'Once it is on, posts that match are never shared.', 'pressqueue' ) : __( 'Once it is on, new posts that match are shared as shown below. Your current sharing mode still applies, so in test mode nothing is sent to Buffer.', 'pressqueue' ) ) . '</p></div>';
		Ui::form_start(
			'pressqueue_enable_rule',
			'pressqueue_enable_rule',
			array(
				'rule_public_id' => $rule->public_id,
				'lock_version'   => (string) $rule->lock_version,
				'return_view'    => 'test',
			),
			array( 'class' => 'pq-inline' )
		);
		echo '<button type="submit" class="button button-primary button-hero pq-turn-on">' . esc_html__( 'Turn on this rule', 'pressqueue' ) . '</button>';
		Ui::form_end();
		echo '</div>';
	}

	/**
	 * Render the run controls.
	 *
	 * @param RuleRecord               $rule Rule.
	 * @param array<string,mixed>|null $result Stored result.
	 */
	private static function render_controls( RuleRecord $rule, ?array $result ): void {
		$trigger = null === $result ? self::default_trigger( $rule ) : (string) $result['trigger'];
		$count   = null === $result || ! empty( $result['single'] ) ? self::DEFAULT_COUNT : (int) $result['count'];

		Ui::form_start(
			'pressqueue_preview_rule_recent',
			'pressqueue_preview_rule_recent',
			array(
				'rule_public_id' => $rule->public_id,
				'lock_version'   => (string) $rule->lock_version,
				'rule_version'   => (string) $rule->version,
			),
			array( 'class' => 'pq-test-controls pq-row' )
		);
		echo '<label for="pq-test-count">' . esc_html__( 'Test the', 'pressqueue' ) . '</label>';
		echo '<select id="pq-test-count" name="count" class="pq-test-select">';
		foreach ( array( 5, 10, 20, 50 ) as $option ) {
			echo '<option value="' . esc_attr( (string) $option ) . '" ' . selected( $count, $option, false ) . '>' . esc_html( (string) $option ) . '</option>';
		}
		echo '</select>';
		echo '<label for="pq-test-trigger">' . esc_html__( 'most recent posts as if', 'pressqueue' ) . '</label>';
		echo '<select id="pq-test-trigger" name="trigger" class="pq-test-select">';
		foreach ( self::trigger_choices( $rule ) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $trigger, $value, false ) . '>' . esc_html( lcfirst( $label ) ) . '</option>';
		}
		echo '</select>';
		echo '<button type="submit" class="button">' . esc_html( null === $result ? __( 'Run test', 'pressqueue' ) : __( 'Run again', 'pressqueue' ) ) . '</button>';
		Ui::form_end();
	}

	/**
	 * Render stored results.
	 *
	 * @param RuleRecord          $rule Rule.
	 * @param array<string,mixed> $result Stored result.
	 */
	private static function render_results( RuleRecord $rule, array $result ): void {
		$rows       = isset( $result['rows'] ) && is_array( $result['rows'] ) ? $result['rows'] : array();
		$is_exclude = 'exclude' === $rule->effect;
		$single     = ! empty( $result['single'] );
		$positive   = 0;
		$old        = 0;

		foreach ( $rows as $row ) {
			$outcome = (string) ( $row['outcome'] ?? '' );
			if ( ( $is_exclude && 'blocked_by_rule' === $outcome ) || ( ! $is_exclude && 'share' === $outcome ) ) {
				++$positive;
			}
			if ( 'old' === $outcome || '' !== (string) ( $row['published_at'] ?? '' ) ) {
				++$old;
			}
		}

		if ( array() === $rows ) {
			echo '<div class="pq-empty"><p>' . esc_html__( 'No posts to test yet. Write a post or pick one below.', 'pressqueue' ) . '</p></div>';
			return;
		}

		$summary = $is_exclude
			/* translators: 1: number of posts this rule blocks, 2: number of posts tested. */
			? sprintf( _n( '%1$d of %2$d post would never be shared because of this rule.', '%1$d of %2$d posts would never be shared because of this rule.', count( $rows ), 'pressqueue' ), $positive, count( $rows ) )
			/* translators: 1: number of posts shared, 2: number of posts tested. */
			: sprintf( _n( '%1$d of %2$d post would be shared.', '%1$d of %2$d posts would be shared.', count( $rows ), 'pressqueue' ), $positive, count( $rows ) );

		echo '<p class="pq-test-summary"><strong>' . esc_html( $summary ) . '</strong>';
		$time = isset( $result['time'] ) ? (int) $result['time'] : 0;
		if ( $time > 0 ) {
			/* translators: %s: relative time such as "2 minutes ago". */
			echo ' <span class="pq-muted pq-small">' . esc_html( sprintf( __( 'Tested %s.', 'pressqueue' ), Ui::relative_time( ( new \DateTimeImmutable() )->setTimestamp( $time ) ) ) ) . '</span>';
		}
		echo '</p>';

		$hint = $old > 0 && in_array( (string) $result['trigger'], self::PUBLICATION_TRIGGERS, true ) ? self::old_posts_hint( $rule ) : '';
		if ( '' !== $hint ) {
			echo '<p class="pq-callout pq-test-hint">' . esc_html( $hint ) . '</p>';
		}

		if ( $is_exclude ) {
			usort(
				$rows,
				static function ( $left, $right ): int {
					$left_blocked  = is_array( $left ) && 'blocked_by_rule' === ( $left['outcome'] ?? '' ) ? 0 : 1;
					$right_blocked = is_array( $right ) && 'blocked_by_rule' === ( $right['outcome'] ?? '' ) ? 0 : 1;

					return $left_blocked <=> $right_blocked;
				}
			);
		}

		echo '<ul class="pq-test-list">';
		foreach ( $rows as $row ) {
			self::render_row( is_array( $row ) ? $row : array(), $single );
		}
		echo '</ul>';
	}

	/**
	 * Render one tested post.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param bool                $open Whether to show the messages open.
	 */
	private static function render_row( array $row, bool $open ): void {
		$post_id = (int) ( $row['post_id'] ?? 0 );
		$outcome = (string) ( $row['outcome'] ?? 'skip' );
		$shares  = isset( $row['shares'] ) && is_array( $row['shares'] ) ? $row['shares'] : array();
		$blocked = isset( $row['blocked'] ) && is_array( $row['blocked'] ) ? $row['blocked'] : array();
		$status  = (string) ( $row['status'] ?? '' );
		$icons   = array(
			'share'           => array( 'pq-yes', '&#x2713;', __( 'Would be shared', 'pressqueue' ) ),
			'blocked_by_rule' => array( 'pq-no', '&#x2715;', __( 'Never shared because of this rule', 'pressqueue' ) ),
			'not_affected'    => array( 'pq-muted', '&ndash;', __( 'Not affected by this rule', 'pressqueue' ) ),
			'old'             => array( 'pq-muted', '&ndash;', __( 'Too old to share automatically', 'pressqueue' ) ),
			'error'           => array( 'pq-maybe', '!', __( 'Stopped by an error', 'pressqueue' ) ),
		);
		$icon    = $icons[ $outcome ] ?? array( 'pq-no', '&#x2715;', __( 'Would not be shared', 'pressqueue' ) );
		$edit    = get_edit_post_link( $post_id );

		echo '<li class="pq-test-row pq-test-row--' . esc_attr( $outcome ) . '">';
		echo '<span class="pq-test-icon ' . esc_attr( $icon[0] ) . '" aria-hidden="true">' . wp_kses( $icon[1], array() ) . '</span>';
		echo '<div class="pq-test-main">';
		echo '<div class="pq-test-line">';
		echo '<span class="screen-reader-text">' . esc_html( $icon[2] ) . ': </span>';
		if ( is_string( $edit ) && '' !== $edit ) {
			echo '<a class="pq-test-title" href="' . esc_url( $edit ) . '">' . esc_html( Ui::post_title( $post_id ) ) . '</a>';
		} else {
			echo '<span class="pq-test-title">' . esc_html( Ui::post_title( $post_id ) ) . '</span>';
		}
		$statuses = array(
			'draft'   => __( 'Draft', 'pressqueue' ),
			'pending' => __( 'Pending review', 'pressqueue' ),
			'future'  => __( 'Scheduled', 'pressqueue' ),
			'private' => __( 'Private', 'pressqueue' ),
		);
		if ( isset( $statuses[ $status ] ) ) {
			echo '<span class="pq-tag">' . esc_html( $statuses[ $status ] ) . '</span>';
		}

		if ( array() !== $shares ) {
			echo '<span class="pq-svcs pq-test-svcs">';
			foreach ( $shares as $share ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- service_badge returns escaped markup.
				echo '<span title="' . esc_attr( (string) ( $share['channel_name'] ?? '' ) ) . '">' . Ui::service_badge( (string) ( $share['service'] ?? '' ) ) . '</span>';
			}
			echo '</span>';
		}
		echo '</div>';

		if ( array() !== $shares ) {
			$names = array();
			foreach ( $shares as $share ) {
				$names[] = Labels::channel_label( (string) ( $share['service'] ?? '' ), (string) ( $share['channel_name'] ?? '' ) );
			}
			echo '<div class="pq-small pq-muted">' . esc_html(
				sprintf(
					/* translators: 1: number of channels, 2: channel names. */
					_n( 'Would share to %1$d channel: %2$s', 'Would share to %1$d channels: %2$s', count( $shares ), 'pressqueue' ),
					count( $shares ),
					implode( ', ', $names )
				)
			) . '</div>';
		} else {
			echo '<div class="pq-small pq-muted">' . esc_html( (string) ( $row['reason'] ?? '' ) ) . '</div>';
		}

		$published_on = self::published_on( (string) ( $row['published_at'] ?? '' ) );
		if ( '' !== $published_on && 'error' !== $outcome ) {
			echo '<div class="pq-small pq-muted pq-test-published">' . esc_html(
				sprintf(
					/* translators: %s: short publication date such as "Oct 2". */
					__( 'Published on %s. Tested as if it were published today.', 'pressqueue' ),
					$published_on
				)
			) . '</div>';
		}

		if ( array() !== $shares || ( array() !== $blocked && in_array( $outcome, array( 'skip', 'error' ), true ) ) ) {
			echo '<details class="pq-more pq-test-details"' . ( $open ? ' open' : '' ) . '><summary>' . esc_html( array() !== $shares ? __( 'Show messages', 'pressqueue' ) : __( 'Show channels', 'pressqueue' ) ) . '</summary><div class="pq-more-body pq-stack">';
			foreach ( $shares as $share ) {
				PreviewPresenter::render_share( self::share_defaults( is_array( $share ) ? $share : array() ), true );
			}
			foreach ( $blocked as $item ) {
				echo '<div class="pq-row pq-small">';
				$service = (string) ( $item['service'] ?? '' );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- channel_name returns escaped markup.
				echo '' === $service ? '<span>' . esc_html( (string) ( $item['channel_name'] ?? '' ) ) . '</span>' : Ui::channel_name( $service, (string) ( $item['channel_name'] ?? '' ) );
				echo '<span class="pq-muted">' . esc_html( (string) ( $item['reason'] ?? '' ) ) . '</span>';
				echo '</div>';
			}
			echo '</div></details>';
		}

		echo '</div></li>';
	}

	/**
	 * Render the "test with a specific post" search.
	 *
	 * @param RuleRecord $rule Rule.
	 */
	private static function render_single_post_search( RuleRecord $rule ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search.
		$search = isset( $_GET['find'] ) ? sanitize_text_field( wp_unslash( $_GET['find'] ) ) : '';

		echo '<div class="pq-card pq-card--pad pq-stack" id="pq-test-post">';
		echo '<div><h2>' . esc_html__( 'Test with a specific post', 'pressqueue' ) . '</h2><p class="pq-sub">' . esc_html__( 'Find a post by its title and test the rule with just that post.', 'pressqueue' ) . '</p></div>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '#pq-test-post" class="pq-row" role="search">';
		echo '<input type="hidden" name="page" value="' . esc_attr( RulesController::SLUG ) . '" />';
		echo '<input type="hidden" name="view" value="test" />';
		echo '<input type="hidden" name="rule" value="' . esc_attr( $rule->public_id ) . '" />';
		echo '<label class="screen-reader-text" for="pq-test-find">' . esc_html__( 'Find a post by title', 'pressqueue' ) . '</label>';
		echo '<input id="pq-test-find" class="pq-field pq-find-input" type="search" name="find" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Type part of a post title', 'pressqueue' ) . '" />';
		echo '<button type="submit" class="button">' . esc_html__( 'Find', 'pressqueue' ) . '</button>';
		echo '</form>';

		if ( '' !== $search ) {
			$query = new \WP_Query(
				array(
					'fields'                 => 'ids',
					'ignore_sticky_posts'    => true,
					'no_found_rows'          => true,
					'post_status'            => array( 'publish', 'future', 'draft', 'pending', 'private' ),
					'post_type'              => 'any',
					'posts_per_page'         => 8,
					's'                      => $search,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			$ids   = array_values( array_filter( array_map( 'intval', $query->posts ), array( self::class, 'can_preview_post' ) ) );

			if ( array() === $ids ) {
				echo '<p class="pq-muted pq-flush">' . esc_html__( 'No posts found. Try a shorter part of the title.', 'pressqueue' ) . '</p>';
			} else {
				$trigger = self::default_trigger( $rule );
				echo '<ul class="pq-find-list">';
				foreach ( $ids as $id ) {
					echo '<li><span class="pq-truncate">' . esc_html( Ui::post_title( $id ) ) . '</span>';
					Ui::form_start(
						'pressqueue_preview_rule_post',
						'pressqueue_preview_rule_post',
						array(
							'rule_public_id' => $rule->public_id,
							'lock_version'   => (string) $rule->lock_version,
							'rule_version'   => (string) $rule->version,
							'post_id'        => (string) $id,
							'trigger'        => $trigger,
						),
						array( 'class' => 'pq-inline' )
					);
					echo '<button type="submit" class="button button-small">' . esc_html__( 'Test this post', 'pressqueue' ) . '<span class="screen-reader-text"> ' . esc_html( Ui::post_title( $id ) ) . '</span></button>';
					Ui::form_end();
					echo '</li>';
				}
				echo '</ul>';
			}
		}

		echo '</div>';
	}

	/**
	 * Test one post.
	 *
	 * @param RuleRecord            $rule Rule under test.
	 * @param array<int,RuleRecord> $rules Rules to plan with.
	 * @param int                   $post_id Post ID.
	 * @param string                $trigger Trigger.
	 * @param EventNormalizer       $normalizer Normalizer.
	 * @param Planner               $planner Planner.
	 * @param array<int,string>     $allowlist Metadata allowlist.
	 * @return array<string,mixed>
	 */
	private static function test_post( RuleRecord $rule, array $rules, int $post_id, string $trigger, EventNormalizer $normalizer, Planner $planner, array $allowlist ): array {
		$post   = get_post( $post_id );
		$as_new = in_array( $trigger, self::PUBLICATION_TRIGGERS, true );
		$row    = array(
			'post_id'      => $post_id,
			'status'       => $post instanceof \WP_Post ? (string) $post->post_status : '',
			'outcome'      => 'skip',
			'shares'       => array(),
			'blocked'      => array(),
			'reason'       => '',
			'published_at' => $as_new && $post instanceof \WP_Post && 'publish' === $post->post_status ? (string) $post->post_date : '',
		);

		try {
			if ( ! self::can_preview_post( $post_id ) ) {
				throw new ValidationException( 'You are not allowed to preview this post.' );
			}

			$event = $normalizer->from_post_id( $post_id, 'preview', 'admin_rule_preview', $trigger, $allowlist );
			if ( null === $event ) {
				$row['outcome'] = 'error';
				$row['reason']  = __( 'This post cannot be shared, for example because it is private or in the trash.', 'pressqueue' );
				return $row;
			}

			// Already published posts are tested as if they were published today, so an older
			// post shows what the rule does instead of the backdated-publication skip.
			$result  = ( $as_new ? $planner->preview_as_new_publication( $event, $rules ) : $planner->preview( $event, $rules ) )->to_array();
			$summary = PreviewPresenter::summarize( $result );

			$row['shares']  = $summary['shares'];
			$row['blocked'] = $summary['blocked'];
			$explained      = self::explain( $rule, $result, $summary );
			$row['outcome'] = $explained[0];
			$row['reason']  = $explained[1];
		} catch ( \Throwable $throwable ) {
			$detail         = SafeOperatorError::message( $throwable, '-' );
			$row['outcome'] = 'error';
			$row['reason']  = __( 'This post could not be tested.', 'pressqueue' ) . ( '-' === $detail ? '' : ' ' . $detail );
		}

		return $row;
	}

	/**
	 * Explain a planning result in one outcome and one sentence.
	 *
	 * @param RuleRecord          $rule Rule under test.
	 * @param array<string,mixed> $result PlanningResult::to_array().
	 * @param array<string,mixed> $summary PreviewPresenter::summarize().
	 * @return array{0:string,1:string}
	 */
	private static function explain( RuleRecord $rule, array $result, array $summary ): array {
		$decisions = isset( $result['decisions'] ) && is_array( $result['decisions'] ) ? $result['decisions'] : array();
		$names     = new ActivitySummary();

		if ( array() !== $summary['shares'] ) {
			return array( 'share', '' );
		}

		foreach ( $decisions as $decision ) {
			foreach ( self::codes( $decision ) as $code ) {
				if ( 'backdated_publication' === $code ) {
					return array( 'old', Labels::reason( $code ) );
				}
				if ( 'explicit_post_exclusion' === $code ) {
					return array( 'exclude' === $rule->effect ? 'not_affected' : 'blocked', Labels::reason( $code ) );
				}
			}
		}

		foreach ( $decisions as $decision ) {
			if ( ! is_array( $decision ) || 'excluded' !== ( $decision['result'] ?? '' ) ) {
				continue;
			}

			$rule_id = (string) ( $decision['rule_public_id'] ?? '' );
			if ( $rule_id === $rule->public_id ) {
				return array( 'blocked_by_rule', __( 'This rule keeps it from being shared.', 'pressqueue' ) );
			}

			$name = '' === $rule_id ? '' : $names->rule_name( $rule_id );
			return array(
				'blocked',
				'' === $name
					? __( 'A "never share" rule keeps it from being shared.', 'pressqueue' )
					/* translators: %s: rule name. */
					: sprintf( __( 'Blocked by the "never share" rule "%s".', 'pressqueue' ), $name ),
			);
		}

		foreach ( $decisions as $decision ) {
			if ( is_array( $decision ) && 'policy_error' === ( $decision['result'] ?? '' ) ) {
				return array( 'error', self::first_reason( $decision ) );
			}
		}

		if ( 'exclude' === $rule->effect ) {
			return array( 'not_affected', __( 'Not affected by this rule.', 'pressqueue' ) );
		}

		foreach ( $decisions as $decision ) {
			if ( is_array( $decision ) && ( $decision['rule_public_id'] ?? null ) === $rule->public_id && 'skipped' === ( $decision['result'] ?? '' ) ) {
				return array( 'skip', self::not_matched_reason( isset( $decision['reasons'] ) && is_array( $decision['reasons'] ) ? $decision['reasons'] : array() ) );
			}
		}

		if ( array() !== $summary['blocked'] ) {
			return array( 'skip', (string) $summary['blocked'][0]['reason'] );
		}

		return array( 'skip', (string) $summary['reason'] );
	}

	/**
	 * Explain why a post did not match the rule's conditions.
	 *
	 * @param array<int,mixed> $reasons Reasons of the rule's skipped decision.
	 * @return string
	 */
	private static function not_matched_reason( array $reasons ): string {
		foreach ( $reasons as $reason ) {
			if ( ! is_array( $reason ) ) {
				continue;
			}

			$code  = (string) ( $reason['code'] ?? '' );
			$field = (string) ( $reason['field'] ?? '' );

			if ( 'trigger_not_selected' === $code ) {
				return Labels::reason( $code );
			}

			if ( 'not_matched' !== ( $reason['condition_result'] ?? '' ) || 'group' === $field ) {
				continue;
			}

			$expected = $reason['expected'] ?? null;
			$observed = $reason['observed'] ?? null;
			$operator = (string) ( $reason['operator'] ?? '' );

			if ( 'post_type' === $field && is_string( $observed ) ) {
				/* translators: %s: post type name such as "Page". */
				return sprintf( __( 'It is a %s, and this rule covers other kinds of posts.', 'pressqueue' ), RuleSummary::post_type_label( $observed ) );
			}

			if ( 'author' === $field && is_array( $observed ) && isset( $observed['id'] ) ) {
				/* translators: %s: author name. */
				return sprintf( __( 'It is by %s, and this rule covers other authors.', 'pressqueue' ), RuleSummary::author_name( (int) $observed['id'] ) );
			}

			if ( str_starts_with( $field, 'taxonomy:' ) ) {
				return self::taxonomy_reason( substr( $field, 9 ), $operator, is_array( $expected ) ? $expected : array(), is_array( $observed ) ? $observed : array() );
			}

			if ( str_starts_with( $field, 'metadata:' ) ) {
				/* translators: %s: custom field name. */
				return sprintf( __( 'Its custom field %s does not match.', 'pressqueue' ), substr( $field, 9 ) );
			}

			if ( 'post_status' === $field ) {
				return __( 'Its status does not match this rule.', 'pressqueue' );
			}

			return __( 'It does not match this rule\'s conditions.', 'pressqueue' );
		}

		return __( 'It does not match this rule\'s conditions.', 'pressqueue' );
	}

	/**
	 * Explain a taxonomy mismatch.
	 *
	 * @param string           $taxonomy Taxonomy.
	 * @param string           $operator Operator.
	 * @param array<int,mixed> $expected Expected term IDs.
	 * @param array<int,mixed> $observed Observed term facts.
	 * @return string
	 */
	private static function taxonomy_reason( string $taxonomy, string $operator, array $expected, array $observed ): string {
		$names = array();
		$found = array();

		foreach ( $expected as $term ) {
			if ( is_scalar( $term ) && ctype_digit( (string) $term ) ) {
				$name    = RuleSummary::term_name( (int) $term, $taxonomy );
				$names[] = $name;
				if ( in_array( (string) $term, array_map( 'strval', $observed ), true ) ) {
					$found[] = $name;
				}
			}
		}

		$label = 'category' === $taxonomy ? '' : RuleSummary::taxonomy_label( $taxonomy ) . ' ';

		if ( 'has_none' === $operator ) {
			/* translators: 1: taxonomy label followed by a space, or nothing for categories, 2: term names. */
			return sprintf( __( 'It is in %1$s%2$s, which this rule leaves out.', 'pressqueue' ), $label, implode( ', ', array() === $found ? $names : $found ) );
		}

		if ( 'has_all' === $operator ) {
			/* translators: 1: taxonomy label followed by a space, or nothing for categories, 2: term names. */
			return sprintf( __( 'It is not in all of %1$s%2$s.', 'pressqueue' ), $label, implode( ', ', $names ) );
		}

		if ( 'post_tag' === $taxonomy ) {
			/* translators: %s: tag names. */
			return sprintf( __( 'It is not tagged %s.', 'pressqueue' ), implode( ' ' . __( 'or', 'pressqueue' ) . ' ', $names ) );
		}

		/* translators: 1: taxonomy label followed by a space, or nothing for categories, 2: term names. */
		return sprintf( __( 'It is not in %1$s%2$s.', 'pressqueue' ), $label, implode( ' ' . __( 'or', 'pressqueue' ) . ' ', $names ) );
	}

	/**
	 * Return the reason codes of a decision.
	 *
	 * @param mixed $decision Decision.
	 * @return array<int,string>
	 */
	private static function codes( $decision ): array {
		$codes = array();

		if ( ! is_array( $decision ) || ! isset( $decision['reasons'] ) || ! is_array( $decision['reasons'] ) ) {
			return $codes;
		}

		foreach ( $decision['reasons'] as $reason ) {
			if ( is_array( $reason ) && is_string( $reason['code'] ?? null ) ) {
				$codes[] = $reason['code'];
			}
		}

		return $codes;
	}

	/**
	 * Return the first useful reason sentence of a decision.
	 *
	 * @param array<string,mixed> $decision Decision.
	 * @return string
	 */
	private static function first_reason( array $decision ): string {
		foreach ( self::codes( $decision ) as $code ) {
			if ( in_array( $code, array( 'destination_available', 'included_destination' ), true ) ) {
				continue;
			}

			return Labels::reason( $code );
		}

		return Labels::reason( 'malformed_rule' );
	}

	/**
	 * Explain how older posts are tested and how to share them.
	 *
	 * @param RuleRecord $rule Rule under test.
	 * @return string
	 */
	private static function old_posts_hint( RuleRecord $rule ): string {
		$triggers = Labels::triggers();

		if ( 'exclude' === $rule->effect ) {
			return __( 'Already published posts are tested as if they were published today. A "never share" rule also keeps matching posts out of updates and Share old posts.', 'pressqueue' );
		}

		if ( in_array( 'bulk', $rule->triggers['triggers'] ?? array(), true ) ) {
			return sprintf(
				/* translators: %s: name of the Share old posts trigger, for example "Old posts are picked in Share old posts". */
				__( 'Already published posts are tested as if they were published today. To see what this rule does when you pick them in Share old posts, test as "%s".', 'pressqueue' ),
				$triggers['bulk'] ?? ''
			);
		}

		return __( 'Already published posts are tested as if they were published today. To share older posts with this rule, tick "I pick old posts in Share old posts" when you edit it, then pick the posts in Share old posts.', 'pressqueue' );
	}

	/**
	 * Return a short publication date, with the year only when it is not this year.
	 *
	 * @param string $post_date Local post date (Y-m-d H:i:s).
	 * @return string
	 */
	private static function published_on( string $post_date ): string {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $post_date ) || str_starts_with( $post_date, '0000' ) ) {
			return '';
		}

		$same_year = substr( $post_date, 0, 4 ) === current_time( 'Y' );
		$formatted = mysql2date( $same_year ? __( 'M j', 'pressqueue' ) : __( 'M j, Y', 'pressqueue' ), $post_date );

		return is_string( $formatted ) ? $formatted : '';
	}

	/**
	 * Fill missing share fields so stored rows always render.
	 *
	 * @param array<string,mixed> $share Share.
	 * @return array<string,mixed>
	 */
	private static function share_defaults( array $share ): array {
		return array_merge(
			array(
				'service'           => '',
				'channel_name'      => '',
				'text'              => '',
				'length'            => 0,
				'limit'             => 0,
				'schedule_slot'     => '',
				'scheduled_for_utc' => '',
				'image_count'       => 0,
				'rule_name'         => '',
			),
			$share
		);
	}

	/**
	 * Return the stored result when it belongs to this rule version.
	 *
	 * @param RuleRecord $rule Rule.
	 * @return array<string,mixed>|null
	 */
	private static function stored_result( RuleRecord $rule ): ?array {
		$result = get_transient( self::transient_key() );

		if ( ! is_array( $result ) || ( $result['rule'] ?? '' ) !== $rule->public_id || (int) ( $result['version'] ?? 0 ) !== $rule->version ) {
			return null;
		}

		return $result;
	}

	/**
	 * Return the current user's transient key.
	 *
	 * @return string
	 */
	private static function transient_key(): string {
		return self::TRANSIENT_BASE . get_current_user_id();
	}

	/**
	 * Check whether PressQueue is in test mode. A mode that cannot be read gives no hint.
	 *
	 * @return bool
	 */
	private static function in_test_mode(): bool {
		try {
			return 'test' === Ui::mode();
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return false;
		}
	}
}
