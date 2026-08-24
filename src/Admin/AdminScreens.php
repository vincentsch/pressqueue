<?php
/**
 * Server-rendered administration screens.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Planning\EventNormalizer;
use PressQueue\Planning\Planner;
use PressQueue\Planning\PlanningResult;
use PressQueue\Security\Capabilities;
use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\Database;
use PressQueue\Storage\Record\DecisionRecord;
use PressQueue\Storage\Record\RuleRecord;
use PressQueue\Storage\Repository\ChannelRepository;
use PressQueue\Storage\Repository\DecisionRepository;
use PressQueue\Storage\Repository\DeliveryRepository;
use PressQueue\Storage\Repository\RuleRepository;
use PressQueue\Storage\SettingsRepository;
use PressQueue\Storage\ValidationException;

/**
 * Registers and renders the compact administration experience.
 */
final class AdminScreens {
	private const SLUG_ROOT      = 'pressqueue';
	private const SLUG_RULES     = 'pressqueue-rules';
	private const SLUG_SETTINGS  = 'pressqueue-settings';
	private const SLUG_PREVIEW   = 'pressqueue-preview';
	private const SLUG_DECISIONS = 'pressqueue-decisions';
	private const TRANSIENT_BASE = 'pressqueue_admin_preview_';

	/**
	 * Register hooks.
	 */
	public static function register_hooks(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_post_pressqueue_save_settings', array( self::class, 'handle_save_settings' ) );
		add_action( 'admin_post_pressqueue_save_rule', array( self::class, 'handle_save_rule' ) );
		add_action( 'admin_post_pressqueue_enable_rule', array( self::class, 'handle_enable_rule' ) );
		add_action( 'admin_post_pressqueue_disable_rule', array( self::class, 'handle_disable_rule' ) );
		add_action( 'admin_post_pressqueue_preview_rule_post', array( self::class, 'handle_rule_post_preview' ) );
		add_action( 'admin_post_pressqueue_preview_rule_recent', array( self::class, 'handle_rule_recent_preview' ) );
		add_action( 'admin_post_pressqueue_preview_post', array( self::class, 'handle_post_preview' ) );
		ConnectionController::register_hooks();
		ChannelController::register_hooks();
	}

	/**
	 * Register menu pages.
	 */
	public static function register_menu(): void {
		add_menu_page(
			__( 'PressQueue', 'pressqueue' ),
			__( 'PressQueue', 'pressqueue' ),
			Capabilities::ACCESS_ADMIN,
			self::SLUG_ROOT,
			array( self::class, 'render_default_page' ),
			'dashicons-share'
		);

		ConnectionController::register_menu( self::SLUG_ROOT );
		add_submenu_page( self::SLUG_ROOT, __( 'PressQueue Rules', 'pressqueue' ), __( 'Rules', 'pressqueue' ), Capabilities::MANAGE_RULES, self::SLUG_RULES, array( self::class, 'render_rules_page' ) );
		add_submenu_page( self::SLUG_ROOT, __( 'PressQueue Preview', 'pressqueue' ), __( 'Preview', 'pressqueue' ), Capabilities::VIEW_DECISIONS, self::SLUG_PREVIEW, array( self::class, 'render_preview_page' ) );
		add_submenu_page( self::SLUG_ROOT, __( 'PressQueue Decisions', 'pressqueue' ), __( 'Decisions', 'pressqueue' ), Capabilities::VIEW_DECISIONS, self::SLUG_DECISIONS, array( self::class, 'render_decisions_page' ) );
		add_submenu_page( self::SLUG_ROOT, __( 'PressQueue Settings', 'pressqueue' ), __( 'Settings', 'pressqueue' ), Capabilities::MANAGE_SETTINGS, self::SLUG_SETTINGS, array( self::class, 'render_settings_page' ) );
	}

	/**
	 * Render the best landing page for the current user's capabilities.
	 */
	public static function render_default_page(): void {
		if ( current_user_can( Capabilities::MANAGE_RULES ) ) {
			self::render_rules_page();
			return;
		}

		if ( current_user_can( Capabilities::MANAGE_CONNECTIONS ) ) {
			ConnectionController::render_connections_page();
			return;
		}

		if ( current_user_can( Capabilities::VIEW_DECISIONS ) ) {
			self::render_decisions_page();
			return;
		}

		self::require_capability( Capabilities::ACCESS_ADMIN );
	}

	/**
	 * Render settings.
	 */
	public static function render_settings_page(): void {
		self::require_capability( Capabilities::MANAGE_SETTINGS );

		$repository = new SettingsRepository();
		$settings   = $repository->read();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'PressQueue Settings', 'pressqueue' ) . '</h1>';
		self::render_notice();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'pressqueue_save_settings' );
		echo '<input type="hidden" name="action" value="pressqueue_save_settings" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Dry run', 'pressqueue' ) . '</th><td><label><input type="checkbox" name="dry_run_enabled" value="1" ' . checked( $settings->dry_run_enabled, true, false ) . ' /> ' . esc_html__( 'Create local dry-run delivery rows only.', 'pressqueue' ) . '</label></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Kill switch', 'pressqueue' ) . '</th><td><label><input type="checkbox" name="kill_switch_enabled" value="1" ' . checked( $settings->kill_switch_enabled, true, false ) . ' /> ' . esc_html__( 'Hold live-mode delivery rows locally.', 'pressqueue' ) . '</label></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Save Settings', 'pressqueue' ) );
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Render rules.
	 */
	public static function render_rules_page(): void {
		self::require_capability( Capabilities::MANAGE_RULES );

		$rules    = ( new RuleRepository() )->find_all();
		$channels = ( new ChannelRepository() )->find_all();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'PressQueue Rules', 'pressqueue' ) . '</h1>';
		self::render_notice();
		self::render_preview_result();

		if ( array() === $channels ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'No local destinations exist yet. Add a connection and synchronize channels first.', 'pressqueue' ) . '</p></div>';
		}

		echo '<h2>' . esc_html__( 'Saved Rules', 'pressqueue' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Name', 'pressqueue' ), __( 'Effect', 'pressqueue' ), __( 'Priority', 'pressqueue' ), __( 'Version', 'pressqueue' ), __( 'Enabled', 'pressqueue' ), __( 'Actions', 'pressqueue' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( array() === $rules ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No rules have been created.', 'pressqueue' ) . '</td></tr>';
		}

		foreach ( $rules as $rule ) {
			echo '<tr>';
			echo '<td><code>' . esc_html( $rule->public_id ) . '</code><br />' . esc_html( $rule->name ) . '</td>';
			echo '<td>' . esc_html( $rule->effect ) . '</td>';
			echo '<td>' . esc_html( (string) $rule->priority ) . '</td>';
			echo '<td>' . esc_html( (string) $rule->version ) . '</td>';
			echo '<td>' . esc_html( $rule->enabled ? __( 'Enabled', 'pressqueue' ) : __( 'Disabled', 'pressqueue' ) ) . '</td>';
			echo '<td>';
			self::render_rule_preview_forms( $rule );
			self::render_enable_form( $rule );
			self::render_disable_form( $rule );
			echo '</td>';
			echo '</tr>';
			echo '<tr><td colspan="6">';
			self::render_rule_form( $rule );
			echo '</td></tr>';
		}

		echo '</tbody></table>';
		self::render_rule_form( null );
		echo '</div>';
	}

	/**
	 * Render post preview page.
	 */
	public static function render_preview_page(): void {
		self::require_capability( Capabilities::VIEW_DECISIONS );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'PressQueue Preview', 'pressqueue' ) . '</h1>';
		self::render_notice();
		self::render_preview_result();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'pressqueue_preview_post' );
		echo '<input type="hidden" name="action" value="pressqueue_preview_post" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="pressqueue-post-id">' . esc_html__( 'Post ID', 'pressqueue' ) . '</label></th><td><input id="pressqueue-post-id" name="post_id" type="number" min="1" class="regular-text" /></td></tr>';
		echo '<tr><th scope="row"><label for="pressqueue-trigger">' . esc_html__( 'Trigger', 'pressqueue' ) . '</label></th><td>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trigger_select returns escaped markup.
		echo self::trigger_select( 'trigger', 'first_publish' );
		echo '</td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Preview Post', 'pressqueue' ) );
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Render recent decisions.
	 */
	public static function render_decisions_page(): void {
		self::require_capability( Capabilities::VIEW_DECISIONS );

		$limit      = self::bounded_int_query( 'limit', 50, 1, 100 );
		$page       = self::bounded_int_query( 'paged', 1, 1, 999999 );
		$offset     = ( $page - 1 ) * $limit;
		$repository = new DecisionRepository();
		$decisions  = $repository->find_recent( $limit, $offset );
		$total      = $repository->count_all();
		$deliveries = new DeliveryRepository();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'PressQueue Decisions', 'pressqueue' ) . '</h1>';
		self::render_notice();
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Created', 'pressqueue' ), __( 'Result', 'pressqueue' ), __( 'Post', 'pressqueue' ), __( 'Rule', 'pressqueue' ), __( 'Scope', 'pressqueue' ), __( 'Reasons', 'pressqueue' ), __( 'Delivery', 'pressqueue' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( array() === $decisions ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No decisions have been recorded.', 'pressqueue' ) . '</td></tr>';
		}

		foreach ( $decisions as $decision ) {
			self::render_decision_row( $decision, $deliveries->find_by_event_key( $decision->event_key ) );
		}

		echo '</tbody></table>';
		self::render_pagination( $page, $limit, $total );
		echo '</div>';
	}

	/**
	 * Handle settings save.
	 */
	public static function handle_save_settings(): void {
		self::require_capability( Capabilities::MANAGE_SETTINGS );
		check_admin_referer( 'pressqueue_save_settings' );

		$repository = new SettingsRepository();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked above.
		$repository->set_dry_run_enabled( isset( $_POST['dry_run_enabled'] ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked above.
		$repository->set_kill_switch_enabled( isset( $_POST['kill_switch_enabled'] ) );

		self::redirect( self::SLUG_SETTINGS, 'settings_saved' );
	}

	/**
	 * Handle rule save.
	 */
	public static function handle_save_rule(): void {
		self::require_capability( Capabilities::MANAGE_RULES );
		check_admin_referer( 'pressqueue_save_rule' );

		try {
			$repository = new RuleRepository();
			$input      = self::rule_input_from_request();
			$public_id  = self::post_text( 'rule_public_id' );

			if ( '' === $public_id ) {
				$repository->create( $input );
			} else {
				$repository->update( $public_id, self::post_int( 'lock_version', 1 ), $input );
			}

			self::redirect( self::SLUG_RULES, 'rule_saved' );
		} catch ( \Throwable $throwable ) {
			self::redirect( self::SLUG_RULES, 'rule_error', $throwable->getMessage() );
		}
	}

	/**
	 * Handle rule enable confirmation.
	 */
	public static function handle_enable_rule(): void {
		self::require_capability( Capabilities::MANAGE_RULES );
		check_admin_referer( 'pressqueue_enable_rule' );

		try {
			( new RuleRepository() )->enable_after_preview( self::post_text( 'rule_public_id' ), self::post_int( 'lock_version', 1 ) );
			self::redirect( self::SLUG_RULES, 'rule_enabled' );
		} catch ( \Throwable $throwable ) {
			self::redirect( self::SLUG_RULES, 'rule_error', $throwable->getMessage() );
		}
	}

	/**
	 * Handle rule disable.
	 */
	public static function handle_disable_rule(): void {
		self::require_capability( Capabilities::MANAGE_RULES );
		check_admin_referer( 'pressqueue_disable_rule' );

		try {
			$repository = new RuleRepository();
			$repository->update( self::post_text( 'rule_public_id' ), self::post_int( 'lock_version', 1 ), array( 'enabled' => false ) );
			self::redirect( self::SLUG_RULES, 'rule_disabled' );
		} catch ( \Throwable $throwable ) {
			self::redirect( self::SLUG_RULES, 'rule_error', $throwable->getMessage() );
		}
	}

	/**
	 * Handle one-rule post preview.
	 */
	public static function handle_rule_post_preview(): void {
		self::require_capability( Capabilities::MANAGE_RULES );
		check_admin_referer( 'pressqueue_preview_rule_post' );

		try {
			$rule   = self::requested_rule_from_request( true );
			$result = self::preview_rule_for_post( $rule, self::post_int( 'post_id', 0 ), self::post_text( 'trigger', 'first_publish' ) );
			( new RuleRepository() )->mark_version_previewed( $rule->public_id, self::post_int( 'lock_version', 0 ), self::post_int( 'rule_version', 0 ) );
			self::store_preview_result( $result );
			self::redirect( self::SLUG_RULES, 'preview_ready' );
		} catch ( \Throwable $throwable ) {
			self::redirect( self::SLUG_RULES, 'preview_error', $throwable->getMessage() );
		}
	}

	/**
	 * Handle one-rule recent post preview.
	 */
	public static function handle_rule_recent_preview(): void {
		self::require_capability( Capabilities::MANAGE_RULES );
		check_admin_referer( 'pressqueue_preview_rule_recent' );

		try {
			$rule       = self::requested_rule_from_request( true );
			$count      = self::post_int( 'count', 20 );
			$count      = max( 1, min( 50, $count ) );
			$trigger    = self::post_text( 'trigger', 'first_publish' );
			$post_types = self::post_types_for_rule( $rule );
			$query      = new \WP_Query(
				array(
					'fields'                 => 'ids',
					'ignore_sticky_posts'    => true,
					'no_found_rows'          => true,
					'orderby'                => array(
						'modified' => 'DESC',
						'ID'       => 'DESC',
					),
					'post_status'            => array( 'publish', 'future', 'draft', 'pending' ),
					'post_type'              => $post_types,
					'posts_per_page'         => $count,
					'suppress_filters'       => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			$results    = array();

			foreach ( $query->posts as $post_id ) {
				if ( self::can_preview_post( (int) $post_id ) ) {
					$results[] = self::preview_rule_for_post( $rule, (int) $post_id, $trigger )->to_array();
				}
			}

			( new RuleRepository() )->mark_version_previewed( $rule->public_id, self::post_int( 'lock_version', 0 ), self::post_int( 'rule_version', 0 ) );
			self::store_preview_array(
				array(
					'recent_results' => $results,
					'rule_public_id' => $rule->public_id,
					'trigger'        => $trigger,
				)
			);
			self::redirect( self::SLUG_RULES, 'preview_ready' );
		} catch ( \Throwable $throwable ) {
			self::redirect( self::SLUG_RULES, 'preview_error', $throwable->getMessage() );
		}
	}

	/**
	 * Handle post preview against enabled rules.
	 */
	public static function handle_post_preview(): void {
		self::require_capability( Capabilities::VIEW_DECISIONS );
		check_admin_referer( 'pressqueue_preview_post' );

		try {
			$post_id = self::post_int( 'post_id', 0 );
			$trigger = self::post_text( 'trigger', 'first_publish' );

			if ( ! self::can_preview_post( $post_id ) ) {
				throw new ValidationException( 'You are not allowed to preview this post.' );
			}

			$rules      = ( new RuleRepository() )->find_enabled_for_trigger( $trigger );
			$normalizer = new EventNormalizer();
			$event      = $normalizer->from_post_id( $post_id, 'preview', 'admin_post_preview', $trigger, $normalizer->metadata_allowlist_for_rules( $rules ) );

			if ( null === $event ) {
				throw new ValidationException( 'Post cannot be previewed for this trigger.' );
			}

			self::store_preview_result( ( new Planner() )->preview( $event, $rules ) );
			self::redirect( self::SLUG_PREVIEW, 'preview_ready' );
		} catch ( \Throwable $throwable ) {
			self::redirect( self::SLUG_PREVIEW, 'preview_error', $throwable->getMessage() );
		}
	}

	/**
	 * Render rule preview forms.
	 *
	 * @param RuleRecord $rule Rule.
	 */
	private static function render_rule_preview_forms( RuleRecord $rule ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0 0 .5em;">';
		wp_nonce_field( 'pressqueue_preview_rule_post' );
		echo '<input type="hidden" name="action" value="pressqueue_preview_rule_post" />';
		echo '<input type="hidden" name="rule_public_id" value="' . esc_attr( $rule->public_id ) . '" />';
		echo '<input type="hidden" name="lock_version" value="' . esc_attr( (string) $rule->lock_version ) . '" />';
		echo '<input type="hidden" name="rule_version" value="' . esc_attr( (string) $rule->version ) . '" />';
		echo '<input type="number" name="post_id" min="1" placeholder="' . esc_attr__( 'Post ID', 'pressqueue' ) . '" />';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trigger_select returns escaped markup.
		echo self::trigger_select( 'trigger', 'first_publish' );
		submit_button( __( 'Preview Rule', 'pressqueue' ), 'secondary small', 'submit', false );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0;">';
		wp_nonce_field( 'pressqueue_preview_rule_recent' );
		echo '<input type="hidden" name="action" value="pressqueue_preview_rule_recent" />';
		echo '<input type="hidden" name="rule_public_id" value="' . esc_attr( $rule->public_id ) . '" />';
		echo '<input type="hidden" name="lock_version" value="' . esc_attr( (string) $rule->lock_version ) . '" />';
		echo '<input type="hidden" name="rule_version" value="' . esc_attr( (string) $rule->version ) . '" />';
		echo '<input type="number" name="count" min="1" max="50" value="20" />';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trigger_select returns escaped markup.
		echo self::trigger_select( 'trigger', 'first_publish' );
		submit_button( __( 'Preview Recent', 'pressqueue' ), 'secondary small', 'submit', false );
		echo '</form>';
	}

	/**
	 * Render enable form.
	 *
	 * @param RuleRecord $rule Rule.
	 */
	private static function render_enable_form( RuleRecord $rule ): void {
		if ( $rule->enabled ) {
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:.5em;">';
		wp_nonce_field( 'pressqueue_enable_rule' );
		echo '<input type="hidden" name="action" value="pressqueue_enable_rule" />';
		echo '<input type="hidden" name="rule_public_id" value="' . esc_attr( $rule->public_id ) . '" />';
		echo '<input type="hidden" name="lock_version" value="' . esc_attr( (string) $rule->lock_version ) . '" />';
		$attributes = array();

		if ( $rule->last_previewed_version !== $rule->version ) {
			$attributes['disabled'] = 'disabled';
		}

		submit_button( __( 'Enable After Preview', 'pressqueue' ), 'primary small', 'submit', false, $attributes );
		echo '</form>';
	}

	/**
	 * Render disable form.
	 *
	 * @param RuleRecord $rule Rule.
	 */
	private static function render_disable_form( RuleRecord $rule ): void {
		if ( ! $rule->enabled ) {
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:.5em;">';
		wp_nonce_field( 'pressqueue_disable_rule' );
		echo '<input type="hidden" name="action" value="pressqueue_disable_rule" />';
		echo '<input type="hidden" name="rule_public_id" value="' . esc_attr( $rule->public_id ) . '" />';
		echo '<input type="hidden" name="lock_version" value="' . esc_attr( (string) $rule->lock_version ) . '" />';
		submit_button( __( 'Disable Rule', 'pressqueue' ), 'secondary small', 'submit', false );
		echo '</form>';
	}

	/**
	 * Render rule edit form.
	 *
	 * @param RuleRecord|null $rule Rule for edits.
	 */
	private static function render_rule_form( ?RuleRecord $rule ): void {
		$is_edit = null !== $rule;

		echo '<h2>' . esc_html( $is_edit ? __( 'Edit Rule', 'pressqueue' ) : __( 'Add Rule', 'pressqueue' ) ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'pressqueue_save_rule' );
		echo '<input type="hidden" name="action" value="pressqueue_save_rule" />';
		if ( $is_edit ) {
			echo '<input type="hidden" name="rule_public_id" value="' . esc_attr( $rule->public_id ) . '" />';
			echo '<input type="hidden" name="lock_version" value="' . esc_attr( (string) $rule->lock_version ) . '" />';
		}
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="' . esc_attr( self::field_id( 'rule-name', $rule ) ) . '">' . esc_html__( 'Name', 'pressqueue' ) . '</label></th><td><input id="' . esc_attr( self::field_id( 'rule-name', $rule ) ) . '" name="name" type="text" class="regular-text" value="' . esc_attr( null === $rule ? '' : $rule->name ) . '" required /></td></tr>';
		echo '<tr><th scope="row"><label for="' . esc_attr( self::field_id( 'effect', $rule ) ) . '">' . esc_html__( 'Effect', 'pressqueue' ) . '</label></th><td><select id="' . esc_attr( self::field_id( 'effect', $rule ) ) . '" name="effect"><option value="include" ' . selected( null === $rule ? 'include' : $rule->effect, 'include', false ) . '>' . esc_html__( 'Include', 'pressqueue' ) . '</option><option value="exclude" ' . selected( null === $rule ? 'include' : $rule->effect, 'exclude', false ) . '>' . esc_html__( 'Exclude', 'pressqueue' ) . '</option></select></td></tr>';
		echo '<tr><th scope="row"><label for="' . esc_attr( self::field_id( 'priority', $rule ) ) . '">' . esc_html__( 'Priority', 'pressqueue' ) . '</label></th><td><input id="' . esc_attr( self::field_id( 'priority', $rule ) ) . '" name="priority" type="number" value="' . esc_attr( null === $rule ? '100' : (string) $rule->priority ) . '" /></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Triggers', 'pressqueue' ) . '</th><td>';
		$selected_triggers = null === $rule ? array( 'first_publish' ) : ( $rule->triggers['triggers'] ?? array() );
		foreach ( array( 'first_publish', 'update', 'scheduled_publication' ) as $trigger ) {
			echo '<label><input type="checkbox" name="triggers[]" value="' . esc_attr( $trigger ) . '" ' . checked( in_array( $trigger, $selected_triggers, true ), true, false ) . ' /> ' . esc_html( $trigger ) . '</label><br />';
		}
		echo '</td></tr>';
		self::render_json_textarea( 'condition_tree_json', __( 'Condition Tree JSON', 'pressqueue' ), null === $rule ? self::default_condition_tree() : $rule->condition_tree, $rule );
		self::render_json_textarea(
			'destination_set_json',
			__( 'Destination Set JSON', 'pressqueue' ),
			null === $rule ? array(
				'schema_version' => 1,
				'destinations'   => array(),
			) : $rule->destination_set,
			$rule
		);
		self::render_json_textarea(
			'inline_template_json',
			__( 'Inline Template JSON', 'pressqueue' ),
			null === $rule ? array(
				'schema_version'         => 1,
				'message'                => '{{ title }} {{ canonical_url }}',
				'custom_field_allowlist' => array(),
			) : $rule->inline_template,
			$rule
		);
		self::render_json_textarea( 'custom_field_allowlist_json', __( 'Custom Field Allowlist JSON', 'pressqueue' ), null === $rule ? array() : $rule->custom_field_allowlist, $rule );
		echo '<tr><th scope="row">' . esc_html__( 'Schedule Policy', 'pressqueue' ) . '</th><td><code>immediate</code><input type="hidden" name="schedule_policy" value="immediate" /></td></tr>';
		echo '</tbody></table>';
		submit_button( $is_edit ? __( 'Save Rule', 'pressqueue' ) : __( 'Save Disabled Rule', 'pressqueue' ) );
		echo '</form>';
	}

	/**
	 * Render a JSON textarea.
	 *
	 * @param string                  $name Field name.
	 * @param string                  $label Label.
	 * @param array<int|string,mixed> $example Example value.
	 * @param RuleRecord|null         $rule Rule.
	 */
	private static function render_json_textarea( string $name, string $label, array $example, ?RuleRecord $rule ): void {
		$id = self::field_id( $name, $rule );

		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" class="large-text code" rows="8">' . esc_textarea( self::pretty_json( $example ) ) . '</textarea>';
		echo '</td></tr>';
	}

	/**
	 * Render a decision row.
	 *
	 * @param DecisionRecord                                       $decision Decision.
	 * @param array<int,\PressQueue\Storage\Record\DeliveryRecord> $deliveries Deliveries.
	 */
	private static function render_decision_row( DecisionRecord $decision, array $deliveries ): void {
		$delivery_text = array();

		foreach ( $deliveries as $delivery ) {
			if ( $delivery->decision_public_id !== $decision->public_id ) {
				continue;
			}

			$delivery_text[] = implode(
				' ',
				array(
					$delivery->delivery_key,
					$delivery->delivery_mode,
					$delivery->state,
					$delivery->payload_hash,
				)
			);
		}

		echo '<tr>';
		echo '<td>' . esc_html( $decision->created_at->format( 'Y-m-d H:i:s' ) ) . '<br /><code>' . esc_html( $decision->decision_key ) . '</code><br /><code>' . esc_html( $decision->event_key ) . '</code></td>';
		echo '<td>' . esc_html( $decision->result ) . '</td>';
		echo '<td>' . esc_html( (string) $decision->post_id ) . '<br />' . esc_html( $decision->post_type . ' ' . $decision->post_status ) . '<br />' . esc_html( $decision->trigger ) . '</td>';
		echo '<td>' . esc_html( null === $decision->rule_id ? '' : (string) $decision->rule_id ) . '<br />' . esc_html( null === $decision->rule_public_id ? '' : $decision->rule_public_id ) . '<br />' . esc_html( null === $decision->rule_version ? '' : (string) $decision->rule_version ) . '</td>';
		echo '<td>' . esc_html( $decision->scope_type ) . '<br />' . esc_html( null === $decision->scope_public_id ? '' : $decision->scope_public_id ) . '</td>';
		echo '<td><pre>' . esc_html( self::pretty_json( $decision->reasons ) ) . '</pre></td>';
		echo '<td><pre>' . esc_html( implode( "\n\n", $delivery_text ) ) . '</pre></td>';
		echo '</tr>';
	}

	/**
	 * Render a preview result from transient storage.
	 */
	private static function render_preview_result(): void {
		$result = self::take_preview_array();

		if ( null === $result ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Preview Result', 'pressqueue' ) . '</h2>';
		echo '<pre>' . esc_html( self::pretty_json( $result ) ) . '</pre>';
	}

	/**
	 * Render notice from request.
	 */
	private static function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Notice query args are display-only.
		$message = isset( $_GET['pressqueue_message'] ) ? sanitize_key( wp_unslash( $_GET['pressqueue_message'] ) ) : '';

		if ( '' === $message ) {
			return;
		}

		$messages = array(
			'preview_error'  => __( 'Preview failed.', 'pressqueue' ),
			'preview_ready'  => __( 'Preview is ready.', 'pressqueue' ),
			'rule_disabled'  => __( 'Rule disabled.', 'pressqueue' ),
			'rule_enabled'   => __( 'Rule enabled.', 'pressqueue' ),
			'rule_error'     => __( 'Rule change failed.', 'pressqueue' ),
			'rule_saved'     => __( 'Rule saved disabled.', 'pressqueue' ),
			'settings_saved' => __( 'Settings saved.', 'pressqueue' ),
		);

		$text = $messages[ $message ] ?? '';

		if ( '' === $text ) {
			return;
		}

		$type = str_contains( $message, 'error' ) ? 'error' : 'success';
		echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . esc_html( $text );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Error text is sanitized for display only.
		if ( isset( $_GET['pressqueue_detail'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Error text is sanitized for display only.
			echo ' ' . esc_html( sanitize_text_field( wp_unslash( $_GET['pressqueue_detail'] ) ) );
		}

		echo '</p></div>';
	}

	/**
	 * Build rule input from request.
	 *
	 * @return array<string,mixed>
	 */
	private static function rule_input_from_request(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked by caller.
		$triggers = isset( $_POST['triggers'] ) && is_array( $_POST['triggers'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['triggers'] ) ) : array();

		return array(
			'condition_tree'         => self::post_json_object( 'condition_tree_json' ),
			'custom_field_allowlist' => self::post_json_list( 'custom_field_allowlist_json' ),
			'destination_set'        => self::post_json_object( 'destination_set_json' ),
			'effect'                 => self::post_text( 'effect', 'include' ),
			'inline_template'        => self::post_json_object( 'inline_template_json' ),
			'name'                   => self::post_text( 'name' ),
			'priority'               => self::post_int( 'priority', 100 ),
			'schedule_policy'        => 'immediate',
			'triggers'               => $triggers,
		);
	}

	/**
	 * Preview one rule for one post.
	 *
	 * @param RuleRecord $rule Rule.
	 * @param int        $post_id Post ID.
	 * @param string     $trigger Trigger.
	 * @return PlanningResult
	 */
	private static function preview_rule_for_post( RuleRecord $rule, int $post_id, string $trigger ): PlanningResult {
		if ( ! self::can_preview_post( $post_id ) ) {
			throw new ValidationException( 'You are not allowed to preview this post.' );
		}

		$normalizer = new EventNormalizer();
		$event      = $normalizer->from_post_id( $post_id, 'preview', 'admin_rule_preview', $trigger, $normalizer->metadata_allowlist_for_rules( array( $rule ) ) );

		if ( null === $event ) {
			throw new ValidationException( 'Post cannot be previewed for this trigger.' );
		}

		return ( new Planner() )->preview( $event, array( $rule ) );
	}

	/**
	 * Check post preview authorization.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function can_preview_post( int $post_id ): bool {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		if ( 'publish' === $post->post_status ) {
			return true;
		}

		return current_user_can( 'read_post', $post_id );
	}

	/**
	 * Load requested rule.
	 *
	 * @param bool $require_exact_version Whether posted version fields must match storage.
	 * @return RuleRecord
	 */
	private static function requested_rule_from_request( bool $require_exact_version ): RuleRecord {
		$rule = ( new RuleRepository() )->find_by_public_id( self::post_text( 'rule_public_id' ) );

		if ( null === $rule ) {
			throw new ValidationException( 'Rule does not exist.' );
		}

		if ( $require_exact_version && ( self::post_int( 'lock_version', 0 ) !== $rule->lock_version || self::post_int( 'rule_version', 0 ) !== $rule->version ) ) {
			throw new ValidationException( 'Rule version changed before preview.' );
		}

		return $rule;
	}

	/**
	 * Render decision pagination.
	 *
	 * @param int $page Current page.
	 * @param int $limit Page size.
	 * @param int $total Total rows.
	 */
	private static function render_pagination( int $page, int $limit, int $total ): void {
		$total_pages = max( 1, (int) ceil( $total / $limit ) );

		if ( $total_pages <= 1 ) {
			return;
		}

		echo '<p class="tablenav-pages">';
		if ( $page > 1 ) {
			echo '<a class="button" href="' . esc_url(
				add_query_arg(
					array(
						'limit' => $limit,
						'page'  => self::SLUG_DECISIONS,
						'paged' => $page - 1,
					),
					admin_url( 'admin.php' )
				)
			) . '">' . esc_html__( 'Previous', 'pressqueue' ) . '</a> ';
		}

		/* translators: 1: current page number, 2: total page count. */
		echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'pressqueue' ), $page, $total_pages ) );

		if ( $page < $total_pages ) {
			echo ' <a class="button" href="' . esc_url(
				add_query_arg(
					array(
						'limit' => $limit,
						'page'  => self::SLUG_DECISIONS,
						'paged' => $page + 1,
					),
					admin_url( 'admin.php' )
				)
			) . '">' . esc_html__( 'Next', 'pressqueue' ) . '</a>';
		}
		echo '</p>';
	}

	/**
	 * Return a stable field ID.
	 *
	 * @param string          $name Field name.
	 * @param RuleRecord|null $rule Rule.
	 * @return string
	 */
	private static function field_id( string $name, ?RuleRecord $rule ): string {
		return 'pressqueue-' . $name . '-' . ( null === $rule ? 'new' : strtolower( $rule->public_id ) );
	}

	/**
	 * Infer recent-post preview post types from post_type conditions.
	 *
	 * @param RuleRecord $rule Rule.
	 * @return array<int,string>
	 */
	private static function post_types_for_rule( RuleRecord $rule ): array {
		$post_types = self::collect_post_type_conditions( $rule->condition_tree );

		if ( array() === $post_types ) {
			return array( 'post' );
		}

		sort( $post_types, SORT_STRING );

		return array_values( array_unique( $post_types ) );
	}

	/**
	 * Collect post type conditions from a tree.
	 *
	 * @param mixed $node Node.
	 * @return array<int,string>
	 */
	private static function collect_post_type_conditions( $node ): array {
		if ( ! is_array( $node ) ) {
			return array();
		}

		if ( ( $node['type'] ?? null ) === 'condition' && ( $node['condition'] ?? null ) === 'post_type' ) {
			$value = $node['value'] ?? null;

			if ( is_string( $value ) ) {
				return array( $value );
			}

			if ( is_array( $value ) ) {
				return array_values( array_filter( $value, 'is_string' ) );
			}
		}

		$post_types = array();
		if ( isset( $node['children'] ) && is_array( $node['children'] ) ) {
			foreach ( $node['children'] as $child ) {
				$post_types = array_merge( $post_types, self::collect_post_type_conditions( $child ) );
			}
		}

		return $post_types;
	}

	/**
	 * Return trigger select markup.
	 *
	 * @param string $name Field name.
	 * @param string $selected Selected value.
	 * @return string
	 */
	private static function trigger_select( string $name, string $selected ): string {
		$html = '<select name="' . esc_attr( $name ) . '">';

		foreach ( array( 'first_publish', 'update', 'scheduled_publication' ) as $trigger ) {
			$html .= '<option value="' . esc_attr( $trigger ) . '" ' . selected( $selected, $trigger, false ) . '>' . esc_html( $trigger ) . '</option>';
		}

		return $html . '</select>';
	}

	/**
	 * Store a preview result.
	 *
	 * @param PlanningResult $result Result.
	 */
	private static function store_preview_result( PlanningResult $result ): void {
		self::store_preview_array( $result->to_array() );
	}

	/**
	 * Store preview array.
	 *
	 * @param array<string,mixed> $result Result.
	 */
	private static function store_preview_array( array $result ): void {
		set_transient( self::preview_transient_key(), $result, 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * Take preview result.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function take_preview_array(): ?array {
		$key    = self::preview_transient_key();
		$result = get_transient( $key );

		if ( is_array( $result ) ) {
			delete_transient( $key );
			return $result;
		}

		return null;
	}

	/**
	 * Return current user's preview transient key.
	 *
	 * @return string
	 */
	private static function preview_transient_key(): string {
		return self::TRANSIENT_BASE . get_current_user_id();
	}

	/**
	 * Redirect to an admin page.
	 *
	 * @param string      $page Page slug.
	 * @param string      $message Message key.
	 * @param string|null $detail Optional detail.
	 */
	private static function redirect( string $page, string $message, ?string $detail = null ): void {
		$args = array(
			'page'               => $page,
			'pressqueue_message' => $message,
		);

		if ( null !== $detail && '' !== $detail ) {
			$args['pressqueue_detail'] = rawurlencode( $detail );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Require an administration capability.
	 *
	 * @param string $capability Capability.
	 */
	private static function require_capability( string $capability ): void {
		if ( ! current_user_can( $capability ) ) {
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
	private static function post_text( string $key, string $fallback = '' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers check nonces before reading mutations.
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : $fallback;
	}

	/**
	 * Return a POST integer field.
	 *
	 * @param string $key Key.
	 * @param int    $fallback Fallback.
	 * @return int
	 */
	private static function post_int( string $key, int $fallback ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers check nonces before reading mutations.
		return isset( $_POST[ $key ] ) ? (int) sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : $fallback;
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Pagination query args are read-only.
		$value = isset( $_GET[ $key ] ) ? (int) sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : $fallback;

		return max( $minimum, min( $maximum, $value ) );
	}

	/**
	 * Decode POST JSON object.
	 *
	 * @param string $key Key.
	 * @return array<string,mixed>
	 */
	private static function post_json_object( string $key ): array {
		$value = self::decode_post_json( $key );

		if ( ! is_array( $value ) || ( array() !== $value && array_is_list( $value ) ) ) {
			throw new ValidationException( 'JSON field must contain an object.' );
		}

		return $value;
	}

	/**
	 * Decode POST JSON list.
	 *
	 * @param string $key Key.
	 * @return array<int,mixed>
	 */
	private static function post_json_list( string $key ): array {
		$value = self::decode_post_json( $key );

		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			throw new ValidationException( 'JSON field must contain a list.' );
		}

		return $value;
	}

	/**
	 * Decode POST JSON.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	private static function decode_post_json( string $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON is decoded and validated structurally after unslashing.
		$raw = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';

		if ( ! is_string( $raw ) ) {
			throw new ValidationException( 'JSON field is invalid.' );
		}

		return json_decode( $raw, true, 512, JSON_THROW_ON_ERROR );
	}

	/**
	 * Return pretty JSON.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function pretty_json( $value ): string {
		$json = wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return is_string( $json ) ? $json : CanonicalJson::encode( $value );
	}

	/**
	 * Return default condition tree example.
	 *
	 * @return array<string,mixed>
	 */
	private static function default_condition_tree(): array {
		return array(
			'children'       => array(
				array(
					'condition' => 'post_type',
					'operator'  => 'equals',
					'type'      => 'condition',
					'value'     => 'post',
				),
			),
			'operator'       => 'ALL',
			'schema_version' => 1,
			'type'           => 'group',
		);
	}
}
