<?php
/**
 * Rules screen: list, editor, test and message templates.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Security\Capabilities;
use PressQueue\Security\SafeOperatorError;
use PressQueue\Storage\PublicIds;
use PressQueue\Storage\Record\ChannelRecord;
use PressQueue\Storage\Record\RuleRecord;
use PressQueue\Storage\Repository\ChannelRepository;
use PressQueue\Storage\Repository\RuleRepository;
use PressQueue\Storage\Repository\TemplateRepository;
use PressQueue\Storage\ValidationException;

/**
 * Routes the Rules page and handles rule changes.
 */
final class RulesController {
	public const SLUG = 'pressqueue-rules';

	/**
	 * Views a rule form may return to.
	 */
	private const RETURN_VIEWS = array( 'list', 'test', 'edit' );

	/**
	 * Render the Rules page.
	 */
	public static function render_page(): void {
		self::require_capability();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Routing is read-only.
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'templates' === $tab ) {
			TemplateController::render_tab();
			return;
		}

		if ( 'new' === $view ) {
			RuleEditor::render( null, self::requested_effect() );
			return;
		}

		if ( 'edit' === $view || 'test' === $view ) {
			$rule = self::rule_from_query();
			if ( null === $rule ) {
				self::render_missing_rule();
				return;
			}

			if ( 'edit' === $view ) {
				RuleEditor::render( $rule, $rule->effect );
			} else {
				RuleTestView::render( $rule );
			}
			return;
		}

		self::render_list();
	}

	/**
	 * Render the page header shared by the list and the templates tab.
	 *
	 * @param string $tab Current tab.
	 */
	public static function render_header( string $tab ): void {
		$actions = '<a class="button button-primary" href="' . esc_url( self::url( array( 'view' => 'new' ) ) ) . '">' . esc_html__( 'Add sharing rule', 'pressqueue' ) . '</a>'
			. '<a class="button" href="' . esc_url(
				self::url(
					array(
						'view'   => 'new',
						'effect' => 'exclude',
					)
				)
			) . '">' . esc_html__( 'Add "never share" rule', 'pressqueue' ) . '</a>';

		Ui::page_start( __( 'Rules', 'pressqueue' ), array( 'actions' => $actions ) );
		Ui::tabs(
			array(
				'rules'     => array( __( 'Rules', 'pressqueue' ), self::url() ),
				'templates' => array( __( 'Message templates', 'pressqueue' ), self::url( array( 'tab' => 'templates' ) ) ),
			),
			$tab
		);
		self::render_notice();
	}

	/**
	 * Render the rule list.
	 */
	private static function render_list(): void {
		$rules    = ( new RuleRepository() )->find_all();
		$channels = ( new ChannelRepository() )->find_all();
		$summary  = new RuleSummary( $channels );
		$names    = array();

		foreach ( ( new TemplateRepository() )->find_all() as $template ) {
			$names[ $template->public_id ] = $template->name;
		}

		self::render_header( 'rules' );

		if ( array() === $rules ) {
			self::render_empty_state( $channels );
			Ui::page_end();
			return;
		}

		$include = array_values(
			array_filter(
				$rules,
				static function ( RuleRecord $rule ): bool {
					return 'exclude' !== $rule->effect;
				}
			)
		);
		$exclude = array_values(
			array_filter(
				$rules,
				static function ( RuleRecord $rule ): bool {
					return 'exclude' === $rule->effect;
				}
			)
		);

		echo '<h2 class="pq-heading pq-heading--first">' . esc_html__( 'Sharing rules', 'pressqueue' ) . '</h2>';
		echo '<div class="pq-card pq-rules">';
		if ( array() === $include ) {
			echo '<p class="pq-rules-empty pq-muted">' . esc_html__( 'No sharing rules yet. Add one to choose which posts go to which channels.', 'pressqueue' ) . ' <a href="' . esc_url( self::url( array( 'view' => 'new' ) ) ) . '">' . esc_html__( 'Add sharing rule', 'pressqueue' ) . '</a></p>';
		}
		foreach ( $include as $rule ) {
			self::render_rule_row( $rule, $summary, $names );
		}
		echo '</div>';

		echo '<div class="pq-heading-row"><h2 class="pq-heading">' . esc_html__( 'Never share', 'pressqueue' ) . '</h2><span class="pq-muted pq-small">' . esc_html__( 'These win over every sharing rule.', 'pressqueue' ) . '</span></div>';
		echo '<div class="pq-card pq-rules">';
		if ( array() === $exclude ) {
			echo '<p class="pq-rules-empty pq-muted">' . esc_html__( 'No "never share" rules. Add one to keep some posts off social media, for example a private category.', 'pressqueue' ) . ' <a href="' . esc_url(
				self::url(
					array(
						'view'   => 'new',
						'effect' => 'exclude',
					)
				)
			) . '">' . esc_html__( 'Add "never share" rule', 'pressqueue' ) . '</a></p>';
		}
		foreach ( $exclude as $rule ) {
			self::render_rule_row( $rule, $summary, $names );
		}
		echo '</div>';

		Ui::page_end();
	}

	/**
	 * Render one rule card row.
	 *
	 * @param RuleRecord           $rule Rule.
	 * @param RuleSummary          $summary Sentence builder.
	 * @param array<string,string> $template_names Template names by public ID.
	 */
	private static function render_rule_row( RuleRecord $rule, RuleSummary $summary, array $template_names ): void {
		$is_exclude = 'exclude' === $rule->effect;
		$edit_url   = self::url(
			array(
				'view' => 'edit',
				'rule' => $rule->public_id,
			)
		);

		echo '<div class="pq-rule' . ( $rule->enabled ? '' : ' pq-rule--off' ) . '" id="' . esc_attr( 'pq-rule-' . strtolower( $rule->public_id ) ) . '">';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG markup.
		echo $is_exclude ? self::shield_icon() : self::share_icon();
		echo '<div class="pq-rule-body">';
		echo '<h3 class="pq-rule-name"><a href="' . esc_url( $edit_url ) . '">' . esc_html( $rule->name ) . '</a> ';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui helpers return escaped markup.
		echo self::status_pill( $rule );
		echo '</h3>';

		if ( null !== $rule->invalid_reason ) {
			echo '<p class="pq-rule-sentence">' . esc_html__( 'PressQueue cannot read this rule, so it stops every post the rule could affect. Open it and save it again to fix it.', 'pressqueue' ) . '</p>';
		} else {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- RuleSummary returns escaped markup.
			echo '<p class="pq-rule-sentence">' . $summary->sentence( $rule ) . '</p>';
		}

		$meta = array();
		if ( ! $is_exclude ) {
			$message = RuleSummary::message_summary( $rule );
			if ( null !== $rule->source_template_public_id && isset( $template_names[ $rule->source_template_public_id ] ) ) {
				/* translators: %s: message template name. */
				$meta[] = esc_html( sprintf( __( 'Message from the template "%s"', 'pressqueue' ), $template_names[ $rule->source_template_public_id ] ) );
			}
			/* translators: %s: message with placeholders such as {{ title }}. */
			$line = sprintf( esc_html__( 'Message: %s plus the link', 'pressqueue' ), '<code class="pq-code">' . esc_html( $message['message'] ) . '</code>' );
			if ( array() !== $message['variants'] ) {
				/* translators: %s: service names such as "X, LinkedIn". */
				$line .= ' ' . esc_html( sprintf( __( '(own message for %s)', 'pressqueue' ), implode( ', ', $message['variants'] ) ) );
			}
			$meta[] = $line;
		}

		/* translators: %s: relative time such as "5 minutes ago". */
		$meta[] = '<span title="' . esc_attr( Ui::local_datetime( $rule->updated_at ) ) . '">' . esc_html( sprintf( __( 'Last changed %s', 'pressqueue' ), Ui::relative_time( $rule->updated_at ) ) ) . '</span>';

		if ( ! $rule->enabled ) {
			$meta[] = $rule->last_previewed_version === $rule->version
				? esc_html__( 'Tested. Switch it on when you are ready.', 'pressqueue' )
				: esc_html__( 'Off. Test it, then switch it on.', 'pressqueue' );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each part is escaped above.
		echo '<p class="pq-rule-meta">' . implode( ' <span aria-hidden="true">&middot;</span> ', $meta ) . '</p>';
		echo '</div>';

		echo '<div class="pq-rule-actions">';
		echo '<a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'pressqueue' ) . '<span class="screen-reader-text"> ' . esc_html( $rule->name ) . '</span></a>';
		echo '<span class="pq-muted" aria-hidden="true">&middot;</span>';
		self::render_test_button( $rule, __( 'Test', 'pressqueue' ), 'button-link' );
		self::render_toggle( $rule, 'list' );
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render the "run test" button for a rule.
	 *
	 * @param RuleRecord $rule Rule.
	 * @param string     $label Button label.
	 * @param string     $css_class Button classes.
	 * @param int        $count Number of posts.
	 * @param string     $trigger Trigger to test.
	 */
	public static function render_test_button( RuleRecord $rule, string $label, string $css_class, int $count = RuleTestView::DEFAULT_COUNT, string $trigger = '' ): void {
		Ui::form_start(
			'pressqueue_preview_rule_recent',
			'pressqueue_preview_rule_recent',
			array(
				'rule_public_id' => $rule->public_id,
				'lock_version'   => (string) $rule->lock_version,
				'rule_version'   => (string) $rule->version,
				'count'          => (string) $count,
				'trigger'        => '' === $trigger ? RuleTestView::default_trigger( $rule ) : $trigger,
			),
			array( 'class' => 'pq-inline' )
		);
		echo '<button type="submit" class="' . esc_attr( $css_class ) . '">' . esc_html( $label ) . '<span class="screen-reader-text"> ' . esc_html( $rule->name ) . '</span></button>';
		Ui::form_end();
	}

	/**
	 * Render the on/off switch for a rule.
	 *
	 * @param RuleRecord $rule Rule.
	 * @param string     $return_view View to come back to.
	 */
	public static function render_toggle( RuleRecord $rule, string $return_view ): void {
		Ui::toggle_form(
			'pressqueue_enable_rule',
			'pressqueue_disable_rule',
			array(
				'rule_public_id' => $rule->public_id,
				'lock_version'   => (string) $rule->lock_version,
				'return_view'    => $return_view,
			),
			$rule->enabled,
			sprintf(
				/* translators: %s: rule name. */
				$rule->enabled ? __( 'Turn off %s', 'pressqueue' ) : __( 'Turn on %s', 'pressqueue' ),
				$rule->name
			)
		);
	}

	/**
	 * Return the status pill for a rule.
	 *
	 * @param RuleRecord $rule Rule.
	 * @return string
	 */
	public static function status_pill( RuleRecord $rule ): string {
		if ( null !== $rule->invalid_reason ) {
			return Ui::pill( 'bad', __( 'Needs fixing', 'pressqueue' ) );
		}

		return $rule->enabled ? Ui::pill( 'ok', __( 'On', 'pressqueue' ) ) : Ui::pill( 'idle', __( 'Off', 'pressqueue' ) );
	}

	/**
	 * Render the first-run empty state.
	 *
	 * @param array<int,ChannelRecord> $channels Channels.
	 */
	private static function render_empty_state( array $channels ): void {
		$ready = self::quick_start_channels( $channels );

		echo '<div class="pq-card">';
		if ( array() === $ready ) {
			$actions = '<a class="button button-primary" href="' . esc_url( Ui::url( Ui::PAGE_CHANNELS ) ) . '">' . esc_html__( 'Go to Channels', 'pressqueue' ) . '</a>'
				. '<a class="button" href="' . esc_url( self::url( array( 'view' => 'new' ) ) ) . '">' . esc_html__( 'Add a rule yourself', 'pressqueue' ) . '</a>';
			self::empty_state(
				__( 'No rules yet', 'pressqueue' ),
				__( 'A rule decides which posts are shared, to which channels, and when. Switch on at least one channel first, then come back to add a rule.', 'pressqueue' ),
				$actions
			);
			echo '</div>';
			return;
		}

		ob_start();
		Ui::button_form( 'pressqueue_quick_start_rule', 'pressqueue_quick_start_rule', array(), __( 'Share every new post to all my channels', 'pressqueue' ), 'button button-primary' );
		$actions  = (string) ob_get_clean();
		$actions .= '<a class="button" href="' . esc_url( self::url( array( 'view' => 'new' ) ) ) . '">' . esc_html__( 'Add a rule yourself', 'pressqueue' ) . '</a>';

		$names = array();
		foreach ( $ready as $channel ) {
			$names[] = ActivitySummary::channel_display_name( $channel );
		}
		$last  = array_pop( $names );
		$names = array() === $names ? (string) $last : implode( ', ', $names ) . ' ' . __( 'and', 'pressqueue' ) . ' ' . $last;

		echo '<div class="pq-empty"><h2>' . esc_html__( 'No rules yet', 'pressqueue' ) . '</h2>';
		echo '<p>' . esc_html__( 'A rule decides which posts are shared, to which channels, and when.', 'pressqueue' ) . '</p>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped helpers above.
		echo '<div class="pq-row">' . $actions . '</div>';
		echo '<p class="pq-help">' . esc_html(
			sprintf(
				/* translators: %s: channel names. */
				__( 'The quick start sends every new post to %s at the end of your Buffer queue, with the featured image when there is one. It also works in Share old posts. The rule starts switched off and shows you a test first.', 'pressqueue' ),
				$names
			)
		) . '</p>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render an empty state with a second-level heading, so headings stay in order on these pages.
	 *
	 * @param string $title Heading.
	 * @param string $text Explanation.
	 * @param string $action_html Escaped action markup.
	 */
	public static function empty_state( string $title, string $text, string $action_html = '' ): void {
		echo '<div class="pq-empty"><h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $text ) . '</p>';
		if ( '' !== $action_html ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Callers pass escaped markup.
			echo '<div class="pq-row">' . $action_html . '</div>';
		}
		echo '</div>';
	}

	/**
	 * Render the page for a rule that does not exist.
	 */
	private static function render_missing_rule(): void {
		Ui::page_start(
			__( 'Rule not found', 'pressqueue' ),
			array(
				'back_url'   => self::url(),
				'back_label' => __( 'Rules', 'pressqueue' ),
			)
		);
		echo '<div class="pq-card">';
		self::empty_state( __( 'This rule does not exist', 'pressqueue' ), __( 'It may have been deleted. Go back to Rules to see the rules you have.', 'pressqueue' ) );
		echo '</div>';
		Ui::page_end();
	}

	/**
	 * Handle rule save.
	 */
	public static function handle_save_rule(): void {
		AdminPostBoundary::check( 'pressqueue_save_rule', Capabilities::MANAGE_RULES, __( 'You are not allowed to manage PressQueue rules.', 'pressqueue' ) );

		$repository = new RuleRepository();
		$public_id  = self::post_text( 'rule_public_id' );
		$current    = null;

		try {
			$current = '' === $public_id ? null : $repository->find_by_public_id( $public_id );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
		}

		if ( '' !== $public_id && null === $current ) {
			self::redirect( array( 'pressqueue_message' => 'rule_not_found' ) );
		}

		$effect = null === $current ? ( 'exclude' === self::post_text( 'effect' ) ? 'exclude' : 'include' ) : $current->effect;

		try {
			$input = RuleEditor::input_from_request( $current );

			if ( null === $current ) {
				$rule = $repository->create( $input );
			} else {
				$rule = $repository->update( $current->public_id, self::post_int( 'lock_version', 0 ), RuleEditor::changes_for_update( $current, $input ) );
			}
		} catch ( \Throwable $throwable ) {
			RuleEditor::store_draft( $current, $effect, $throwable );
			$args = null === $current
				? array(
					'view'   => 'new',
					'effect' => $effect,
				)
				: array(
					'view' => 'edit',
					'rule' => $current->public_id,
				);
			self::redirect( array_merge( $args, array( 'pressqueue_message' => 'rule_invalid' ) ) );
		}

		$was_enabled = null !== $current && $current->enabled;
		$wants_test  = 'test' === self::post_text( 'save_intent' );

		if ( $rule->enabled ) {
			self::redirect(
				array(
					'pressqueue_message' => 'rule_saved_on',
					'rule'               => $rule->public_id,
				)
			);
		}

		if ( ! $wants_test && ! $was_enabled ) {
			self::redirect(
				array(
					'view'               => 'edit',
					'rule'               => $rule->public_id,
					'pressqueue_message' => 'rule_saved_off',
				)
			);
		}

		self::run_test_and_redirect( $rule, $was_enabled ? 'rule_saved_switched_off' : 'rule_saved_test' );
	}

	/**
	 * Handle a request to switch a rule on.
	 *
	 * A rule whose current version was not tested is tested first; the person then confirms on the test view.
	 */
	public static function handle_enable_rule(): void {
		AdminPostBoundary::check( 'pressqueue_enable_rule', Capabilities::MANAGE_RULES, __( 'You are not allowed to manage PressQueue rules.', 'pressqueue' ) );

		$return_view = self::return_view();

		try {
			$repository = new RuleRepository();
			$rule       = $repository->find_by_public_id( self::post_text( 'rule_public_id' ) );
			$lock       = self::post_int( 'lock_version', 0 );

			if ( null === $rule ) {
				throw new ValidationException( 'Rule does not exist.' );
			}

			if ( $rule->lock_version !== $lock ) {
				throw new ValidationException( 'Rule version is stale.' );
			}

			if ( $rule->last_previewed_version !== $rule->version ) {
				self::run_test_and_redirect( $rule, 'rule_test_first' );
			}

			$rule = $repository->enable_after_preview( $rule->public_id, $lock );
			self::redirect_after_toggle( $rule, $return_view, 'rule_enabled' );
		} catch ( \Throwable $throwable ) {
			self::redirect( array_merge( self::return_args( $return_view ), array( 'pressqueue_message' => 'rule_error' ) ), SafeOperatorError::redirect_detail( $throwable ) );
		}
	}

	/**
	 * Handle rule disable.
	 */
	public static function handle_disable_rule(): void {
		AdminPostBoundary::check( 'pressqueue_disable_rule', Capabilities::MANAGE_RULES, __( 'You are not allowed to manage PressQueue rules.', 'pressqueue' ) );

		$return_view = self::return_view();

		try {
			$rule = ( new RuleRepository() )->update( self::post_text( 'rule_public_id' ), self::post_int( 'lock_version', 1 ), array( 'enabled' => false ) );
			self::redirect_after_toggle( $rule, $return_view, 'rule_disabled' );
		} catch ( \Throwable $throwable ) {
			self::redirect( array_merge( self::return_args( $return_view ), array( 'pressqueue_message' => 'rule_error' ) ), SafeOperatorError::redirect_detail( $throwable ) );
		}
	}

	/**
	 * Handle a one-post test.
	 */
	public static function handle_rule_post_preview(): void {
		AdminPostBoundary::check( 'pressqueue_preview_rule_post', Capabilities::MANAGE_RULES, __( 'You are not allowed to manage PressQueue rules.', 'pressqueue' ) );

		$public_id = self::post_text( 'rule_public_id' );

		try {
			$rule    = self::requested_rule_from_request();
			$post_id = self::post_int( 'post_id', 0 );

			if ( ! RuleTestView::can_preview_post( $post_id ) ) {
				throw new ValidationException( 'You are not allowed to preview this post.' );
			}

			RuleTestView::run( $rule, array( $post_id ), self::post_trigger( $rule ), 1, true );
			self::redirect(
				array(
					'view'               => 'test',
					'rule'               => $rule->public_id,
					'pressqueue_message' => 'rule_tested',
				)
			);
		} catch ( \Throwable $throwable ) {
			self::redirect( self::test_error_args( $public_id ), SafeOperatorError::redirect_detail( $throwable ) );
		}
	}

	/**
	 * Handle a recent-posts test.
	 */
	public static function handle_rule_recent_preview(): void {
		AdminPostBoundary::check( 'pressqueue_preview_rule_recent', Capabilities::MANAGE_RULES, __( 'You are not allowed to manage PressQueue rules.', 'pressqueue' ) );

		$public_id = self::post_text( 'rule_public_id' );

		try {
			$rule  = self::requested_rule_from_request();
			$count = max( 1, min( RuleTestView::MAX_COUNT, self::post_int( 'count', RuleTestView::DEFAULT_COUNT ) ) );

			RuleTestView::run( $rule, RuleTestView::recent_post_ids( $rule, $count ), self::post_trigger( $rule ), $count, false );
			self::redirect(
				array(
					'view'               => 'test',
					'rule'               => $rule->public_id,
					'pressqueue_message' => 'rule_tested',
				)
			);
		} catch ( \Throwable $throwable ) {
			self::redirect( self::test_error_args( $public_id ), SafeOperatorError::redirect_detail( $throwable ) );
		}
	}

	/**
	 * Create the quick-start rule and show its test.
	 */
	public static function handle_quick_start(): void {
		AdminPostBoundary::check( 'pressqueue_quick_start_rule', Capabilities::MANAGE_RULES, __( 'You are not allowed to manage PressQueue rules.', 'pressqueue' ) );

		try {
			$rule = self::create_quick_start_rule();
		} catch ( \Throwable $throwable ) {
			self::redirect( array( 'pressqueue_message' => 'rule_error' ), SafeOperatorError::redirect_detail( $throwable ) );
		}

		if ( null === $rule ) {
			self::redirect( array( 'pressqueue_message' => 'rule_quick_no_channels' ) );
		}

		self::run_test_and_redirect( $rule, 'rule_created_quick' );
	}

	/**
	 * Create the switched-off "share every new post to all my channels" rule.
	 *
	 * @return RuleRecord|null Null when no channel is switched on.
	 */
	public static function create_quick_start_rule(): ?RuleRecord {
		$channels = self::quick_start_channels( ( new ChannelRepository() )->find_all() );
		if ( array() === $channels ) {
			return null;
		}

		$destinations = array();
		foreach ( $channels as $channel ) {
			$destinations[] = array( 'channel_public_id' => $channel->public_id );
		}

		return ( new RuleRepository() )->create(
			array(
				'name'                   => __( 'Share new posts', 'pressqueue' ),
				'effect'                 => 'include',
				'priority'               => 100,
				'triggers'               => array( 'first_publish', 'scheduled_publication', 'bulk' ),
				'condition_tree'         => RuleEditor::default_condition_tree( 'include' ),
				'destination_set'        => array(
					'schema_version' => 1,
					'destinations'   => $destinations,
				),
				'template_set'           => array(
					'schema_version'  => 1,
					'default_message' => '{{ title }}',
				),
				'custom_field_allowlist' => array(),
				'schedule_policy'        => array(
					'schema_version' => 1,
					'mode'           => 'queue_end',
				),
				'media_policy'           => array(
					'schema_version' => 1,
					'mode'           => 'featured_image_optional',
				),
			)
		);
	}

	/**
	 * Permanently delete a rule that is switched off.
	 */
	public static function handle_delete_rule(): void {
		AdminPostBoundary::check( 'pressqueue_delete_rule', Capabilities::MANAGE_RULES, __( 'You are not allowed to manage PressQueue rules.', 'pressqueue' ) );

		$public_id = self::post_text( 'rule_public_id' );

		try {
			( new RuleRepository() )->delete_disabled( $public_id, self::post_int( 'lock_version', 0 ) );
		} catch ( \Throwable $throwable ) {
			$messages = array(
				'Rule must be disabled before it can be deleted.' => 'rule_delete_on',
				'Rule has deliveries waiting to be sent.' => 'rule_delete_waiting',
			);
			$args     = array( 'pressqueue_message' => $messages[ $throwable->getMessage() ] ?? 'rule_delete_error' );
			if ( PublicIds::is_valid( $public_id ) ) {
				$args['view'] = 'edit';
				$args['rule'] = $public_id;
			}
			self::redirect( $args, SafeOperatorError::redirect_detail( $throwable ) );
		}

		self::redirect( array( 'pressqueue_message' => 'rule_deleted' ) );
	}

	/**
	 * Run the default test, mark the version tested and show the test view.
	 *
	 * @param RuleRecord $rule Rule.
	 * @param string     $message Notice key.
	 */
	private static function run_test_and_redirect( RuleRecord $rule, string $message ): void {
		$args = array(
			'view'               => 'test',
			'rule'               => $rule->public_id,
			'pressqueue_message' => $message,
		);

		try {
			RuleTestView::run( $rule, RuleTestView::recent_post_ids( $rule, RuleTestView::DEFAULT_COUNT ), RuleTestView::default_trigger( $rule ), RuleTestView::DEFAULT_COUNT, false );
		} catch ( \Throwable $throwable ) {
			$args['pressqueue_message'] = 'preview_error';
			self::redirect( $args, SafeOperatorError::redirect_detail( $throwable ) );
		}

		self::redirect( $args );
	}

	/**
	 * Redirect after a switch change.
	 *
	 * @param RuleRecord $rule Rule.
	 * @param string     $return_view Return view.
	 * @param string     $message Notice key.
	 */
	private static function redirect_after_toggle( RuleRecord $rule, string $return_view, string $message ): void {
		$args = array(
			'pressqueue_message' => $message,
			'rule'               => $rule->public_id,
		);

		if ( 'list' !== $return_view ) {
			$args['view'] = $return_view;
		}

		self::redirect( $args );
	}

	/**
	 * Return query args for the view a form came from.
	 *
	 * @param string $return_view Return view.
	 * @return array<string,string>
	 */
	private static function return_args( string $return_view ): array {
		$public_id = self::post_text( 'rule_public_id' );

		if ( 'list' === $return_view || ! PublicIds::is_valid( $public_id ) ) {
			return array();
		}

		return array(
			'view' => $return_view,
			'rule' => $public_id,
		);
	}

	/**
	 * Return error args for a failed test.
	 *
	 * @param string $public_id Rule public ID.
	 * @return array<string,string>
	 */
	private static function test_error_args( string $public_id ): array {
		if ( ! PublicIds::is_valid( $public_id ) ) {
			return array( 'pressqueue_message' => 'preview_error' );
		}

		return array(
			'view'               => 'test',
			'rule'               => $public_id,
			'pressqueue_message' => 'preview_error',
		);
	}

	/**
	 * Return the posted return view.
	 *
	 * @return string
	 */
	private static function return_view(): string {
		$view = sanitize_key( self::post_text( 'return_view', 'list' ) );

		return in_array( $view, self::RETURN_VIEWS, true ) ? $view : 'list';
	}

	/**
	 * Return the posted trigger, or the rule's default.
	 *
	 * @param RuleRecord $rule Rule.
	 * @return string
	 */
	private static function post_trigger( RuleRecord $rule ): string {
		$trigger = sanitize_key( self::post_text( 'trigger' ) );

		return in_array( $trigger, array_keys( Labels::triggers() ), true ) ? $trigger : RuleTestView::default_trigger( $rule );
	}

	/**
	 * Load the posted rule and require that the posted versions are current.
	 *
	 * @return RuleRecord
	 */
	private static function requested_rule_from_request(): RuleRecord {
		$rule = ( new RuleRepository() )->find_by_public_id( self::post_text( 'rule_public_id' ) );

		if ( null === $rule ) {
			throw new ValidationException( 'Rule does not exist.' );
		}

		if ( self::post_int( 'lock_version', 0 ) !== $rule->lock_version || self::post_int( 'rule_version', 0 ) !== $rule->version ) {
			throw new ValidationException( 'Rule version changed before preview.' );
		}

		return $rule;
	}

	/**
	 * Return channels the quick start would use.
	 *
	 * @param array<int,ChannelRecord> $channels Channels.
	 * @return array<int,ChannelRecord>
	 */
	private static function quick_start_channels( array $channels ): array {
		return array_values(
			array_filter(
				$channels,
				static function ( ChannelRecord $channel ): bool {
					return $channel->enabled && 'available' === $channel->availability;
				}
			)
		);
	}

	/**
	 * Return the rule named in the query string.
	 *
	 * @return RuleRecord|null
	 */
	public static function rule_from_query(): ?RuleRecord {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing.
		$public_id = isset( $_GET['rule'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['rule'] ) ) ) : '';

		if ( ! PublicIds::is_valid( $public_id ) ) {
			return null;
		}

		return ( new RuleRepository() )->find_by_public_id( $public_id );
	}

	/**
	 * Return the effect requested for a new rule.
	 *
	 * @return string
	 */
	private static function requested_effect(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing.
		$effect = isset( $_GET['effect'] ) ? sanitize_key( wp_unslash( $_GET['effect'] ) ) : 'include';

		return 'exclude' === $effect ? 'exclude' : 'include';
	}

	/**
	 * Render notices from the query string.
	 */
	public static function render_notice(): void {
		// The Go live button on the Rules screens comes back here with the settings result.
		SettingsController::render_notice();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Notice query args are display-only.
		$message = isset( $_GET['pressqueue_message'] ) ? sanitize_key( wp_unslash( $_GET['pressqueue_message'] ) ) : '';
		$detail  = isset( $_GET['pressqueue_detail'] ) ? SafeOperatorError::notice_detail( sanitize_text_field( wp_unslash( $_GET['pressqueue_detail'] ) ) ) : '';
		$view    = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$messages = array(
			'rule_saved_on'           => array( 'success', __( 'Saved. The rule is on.', 'pressqueue' ) ),
			'rule_saved_off'          => array( 'success', __( 'Saved. The rule stays off until you test it and switch it on.', 'pressqueue' ) ),
			'rule_saved_test'         => array( 'success', __( 'Saved. Check the test below, then turn the rule on.', 'pressqueue' ) ),
			'rule_saved_switched_off' => array( 'warning', __( 'Saved. Changing what the rule does switched it off. Check the test below, then turn it on again.', 'pressqueue' ) ),
			'rule_created_quick'      => array( 'success', __( 'Rule created. It is off for now. Check the test below, then turn it on.', 'pressqueue' ) ),
			'rule_test_first'         => array( 'info', __( 'Before this rule goes on, check what it would share. If it looks right, turn it on below.', 'pressqueue' ) ),
			'rule_tested'             => array( 'success', __( 'Test finished.', 'pressqueue' ) ),
			'rule_enabled'            => array( 'success', __( 'The rule is on.', 'pressqueue' ) ),
			'rule_disabled'           => array( 'success', __( 'The rule is off.', 'pressqueue' ) ),
			'rule_deleted'            => array( 'success', __( 'Rule deleted.', 'pressqueue' ) ),
			'rule_delete_error'       => array( 'error', __( 'The rule could not be deleted.', 'pressqueue' ) ),
			'rule_delete_on'          => array( 'error', __( 'Turn the rule off before you delete it.', 'pressqueue' ) ),
			'rule_delete_waiting'     => array( 'error', __( 'This rule still has shares that are waiting, sending or need a check in Buffer. Finish or cancel them in Activity, then delete the rule.', 'pressqueue' ) ),
			'rule_quick_no_channels'  => array( 'error', __( 'Switch on at least one channel in Channels first.', 'pressqueue' ) ),
			'rule_invalid'            => array( 'error', __( 'The rule was not saved. Fix the problem marked below and save again.', 'pressqueue' ) ),
			'rule_not_found'          => array( 'error', __( 'That rule no longer exists.', 'pressqueue' ) ),
			'rule_error'              => array( 'error', __( 'The rule could not be changed.', 'pressqueue' ) ),
			'preview_error'           => array( 'error', __( 'The test could not run.', 'pressqueue' ) ),
			'template_adopted'        => array( 'success', __( 'The template was copied into this rule. Test the rule before you switch it on again.', 'pressqueue' ) ),
			'template_deleted'        => array( 'success', __( 'The template is no longer offered in rules.', 'pressqueue' ) ),
			'template_disabled'       => array( 'success', __( 'The template is no longer offered in rules.', 'pressqueue' ) ),
			'template_enabled'        => array( 'success', __( 'The template is now offered in rules.', 'pressqueue' ) ),
			'template_error'          => array( 'error', __( 'The template could not be changed.', 'pressqueue' ) ),
			'template_saved'          => array( 'success', __( 'Template saved.', 'pressqueue' ) ),
			'template_created'        => array( 'success', __( 'Template saved. Switch it on to offer it when you edit a rule.', 'pressqueue' ) ),
		);

		if ( ! isset( $messages[ $message ] ) ) {
			return;
		}

		// The test view already says so next to the switch, so only the list repeats it here.
		if ( in_array( $message, array( 'rule_enabled', 'rule_saved_on' ), true ) && 'test' !== $view && self::test_mode_applies() ) {
			echo '<div class="notice notice-success is-dismissible pq-notice"><p>' . esc_html( $messages[ $message ][1] ) . '</p>';
			self::render_test_mode_hint();
			echo '</div>';
			return;
		}

		Ui::notice( $messages[ $message ][0], $messages[ $message ][1], 'error' === $messages[ $message ][0] ? self::friendly_detail( $detail ) : '' );
	}

	/**
	 * Say that nothing reaches Buffer while PressQueue is in test mode, with the Go live button.
	 *
	 * The button is the same one the Overview checklist uses and comes back to the Rules screen.
	 */
	public static function render_test_mode_hint(): void {
		echo '<p class="pq-test-mode-hint">' . esc_html__( 'PressQueue is still in test mode, so nothing goes to Buffer yet.', 'pressqueue' ) . '</p>';

		if ( current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			echo '<div class="pq-row pq-test-mode-action">';
			SettingsController::render_mode_button( 'live', __( 'Go live', 'pressqueue' ), 'button button-primary', Ui::PAGE_RULES );
			echo '</div>';
		}
	}

	/**
	 * Check whether the test mode hint belongs after a rule was switched on.
	 *
	 * A "never share" rule sends nothing in any mode, so it gets no hint.
	 *
	 * @return bool
	 */
	private static function test_mode_applies(): bool {
		try {
			if ( 'test' !== Ui::mode() ) {
				return false;
			}

			$rule = self::rule_from_query();
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return false;
		}

		return null === $rule || 'exclude' !== $rule->effect;
	}

	/**
	 * Translate a stored error detail into words where we know it.
	 *
	 * @param string $detail Safe detail text.
	 * @return string
	 */
	private static function friendly_detail( string $detail ): string {
		$known = array(
			'Rule must be previewed before it can be enabled.' => __( 'Test the rule first.', 'pressqueue' ),
			'Rule version is stale.'                     => __( 'The rule changed in another window. Reload the page and try again.', 'pressqueue' ),
			'Rule version changed before preview.'       => __( 'The rule changed in another window. Reload the page and try again.', 'pressqueue' ),
			'Rule lock version is stale.'                => __( 'The rule changed in another window. Reload the page and try again.', 'pressqueue' ),
			'Rule does not exist.'                       => __( 'The rule no longer exists.', 'pressqueue' ),
			'You are not allowed to preview this post.'  => __( 'You cannot edit that post, so it cannot be tested.', 'pressqueue' ),
			'Post cannot be previewed for this trigger.' => __( 'That post cannot be shared, for example because it is in the trash or private.', 'pressqueue' ),
		);

		return $known[ $detail ] ?? $detail;
	}

	/**
	 * Return a Rules page URL.
	 *
	 * @param array<string,scalar> $args Query args.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return Ui::url( self::SLUG, $args );
	}

	/**
	 * Redirect to the Rules page.
	 *
	 * @param array<string,string> $args Query args.
	 * @param string|null          $detail Optional error detail.
	 * @return never
	 */
	public static function redirect( array $args, ?string $detail = null ): never {
		if ( null !== $detail && '' !== $detail ) {
			$args['pressqueue_detail'] = rawurlencode( $detail );
		}

		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	/**
	 * Require rule management.
	 */
	public static function require_capability(): void {
		if ( ! current_user_can( Capabilities::MANAGE_RULES ) ) {
			wp_die( esc_html__( 'You are not allowed to use this PressQueue screen.', 'pressqueue' ) );
		}
	}

	/**
	 * Return a POST text field.
	 *
	 * @param string $key Key.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	public static function post_text( string $key, string $fallback = '' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers check nonces before reading mutations.
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : $fallback;
	}

	/**
	 * Return a POST integer field.
	 *
	 * @param string $key Key.
	 * @param int    $fallback Fallback.
	 * @return int
	 */
	public static function post_int( string $key, int $fallback ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers check nonces before reading mutations.
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? (int) sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : $fallback;
	}

	/**
	 * Return the share arrow icon.
	 *
	 * @return string
	 */
	private static function share_icon(): string {
		return '<span class="pq-rule-icon pq-rule-icon--share" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 14 14" focusable="false"><path d="M2 7h8M7 3l4 4-4 4" stroke="currentColor" stroke-width="1.8" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg></span>';
	}

	/**
	 * Return the shield icon.
	 *
	 * @return string
	 */
	public static function shield_icon(): string {
		return '<span class="pq-rule-icon pq-rule-icon--never" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 14 14" focusable="false"><path d="M7 1.5 2.5 3.3v3.4c0 2.8 1.9 4.9 4.5 5.8 2.6-.9 4.5-3 4.5-5.8V3.3L7 1.5Z" stroke="currentColor" stroke-width="1.5" fill="none"/></svg></span>';
	}
}
