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
use PressQueue\Security\SafeOperatorError;
use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\Database;
use PressQueue\Storage\Record\DecisionRecord;
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
		add_filter( 'submenu_file', array( self::class, 'highlight_hidden_pages' ), 10, 2 );
		add_filter( 'parent_file', array( self::class, 'open_parent_for_hidden_pages' ) );
		Ui::register_hooks();
		SettingsController::register_hooks();
		add_action( 'admin_post_pressqueue_save_rule', array( self::class, 'handle_save_rule' ) );
		add_action( 'admin_post_pressqueue_enable_rule', array( self::class, 'handle_enable_rule' ) );
		add_action( 'admin_post_pressqueue_disable_rule', array( self::class, 'handle_disable_rule' ) );
		add_action( 'admin_post_pressqueue_preview_rule_post', array( self::class, 'handle_rule_post_preview' ) );
		add_action( 'admin_post_pressqueue_preview_rule_recent', array( self::class, 'handle_rule_recent_preview' ) );
		add_action( 'admin_post_pressqueue_quick_start_rule', array( RulesController::class, 'handle_quick_start' ) );
		add_action( 'admin_post_pressqueue_delete_rule', array( RulesController::class, 'handle_delete_rule' ) );
		add_action( 'admin_post_pressqueue_preview_post', array( self::class, 'handle_post_preview' ) );
		ConnectionController::register_hooks();
		ChannelController::register_hooks();
		DeliveryActionController::register_hooks();
		PostControlsController::register_hooks();
		PostPreviewController::register_hooks();
		ConditionBuilderController::register_hooks();
		TemplateController::register_hooks();
		BatchController::register_hooks();
		PrivacyController::register_hooks();
	}

	/**
	 * Register menu pages.
	 *
	 * Visible items follow what people do most: see what happened, adjust rules,
	 * manage channels. Detail and legacy pages stay routable but hidden.
	 */
	public static function register_menu(): void {
		add_menu_page(
			__( 'PressQueue', 'pressqueue' ),
			__( 'PressQueue', 'pressqueue' ),
			Capabilities::ACCESS_ADMIN,
			self::SLUG_ROOT,
			array( self::class, 'render_default_page' ),
			self::menu_icon(),
			26
		);

		add_submenu_page( self::SLUG_ROOT, __( 'PressQueue Overview', 'pressqueue' ), __( 'Overview', 'pressqueue' ), Capabilities::ACCESS_ADMIN, self::SLUG_ROOT, array( self::class, 'render_default_page' ) );
		add_submenu_page( self::SLUG_ROOT, __( 'PressQueue Activity', 'pressqueue' ), __( 'Activity', 'pressqueue' ), Capabilities::VIEW_DELIVERIES, DeliveryListController::SLUG, array( DeliveryListController::class, 'render_page' ) );
		add_submenu_page( self::SLUG_ROOT, __( 'PressQueue Rules', 'pressqueue' ), __( 'Rules', 'pressqueue' ), Capabilities::MANAGE_RULES, self::SLUG_RULES, array( self::class, 'render_rules_page' ) );
		add_submenu_page( self::SLUG_ROOT, __( 'PressQueue Channels', 'pressqueue' ), __( 'Channels', 'pressqueue' ), Capabilities::MANAGE_CONNECTIONS, ConnectionController::SLUG, array( ConnectionController::class, 'render_connections_page' ) );
		add_submenu_page( self::SLUG_ROOT, __( 'Share Old Posts', 'pressqueue' ), __( 'Share old posts', 'pressqueue' ), Capabilities::OPERATE_BATCHES, BatchController::SLUG, array( BatchController::class, 'render_page' ) );
		add_submenu_page( self::SLUG_ROOT, __( 'PressQueue Settings', 'pressqueue' ), __( 'Settings', 'pressqueue' ), Capabilities::MANAGE_SETTINGS, self::SLUG_SETTINGS, array( SettingsController::class, 'render_page' ) );

		// Hidden pages stay registered under PressQueue so routing and access checks work.
		// open_parent_for_hidden_pages() drops them from the visible menu at render time.
		foreach ( self::hidden_pages() as $slug => $page ) {
			$hook = add_submenu_page( self::SLUG_ROOT, $page[0], $page[0], $page[1], $slug, $page[2] );
			if ( self::SLUG_PREVIEW === $slug && is_string( $hook ) ) {
				add_action( 'load-' . $hook, array( self::class, 'redirect_legacy_preview' ) );
			}
		}
	}

	/**
	 * Send the retired preview page to the overview's "Check a post" card.
	 */
	public static function redirect_legacy_preview(): void {
		wp_safe_redirect( Ui::url( Ui::PAGE_OVERVIEW ) . '#pq-check' );
		exit;
	}

	/**
	 * Return hidden but routable pages.
	 *
	 * @return array<string,array{0:string,1:string,2:callable}>
	 */
	private static function hidden_pages(): array {
		return array(
			DeliveryDetailController::SLUG => array( __( 'Share details', 'pressqueue' ), Capabilities::VIEW_DELIVERIES, array( DeliveryDetailController::class, 'render_page' ) ),
			Ui::PAGE_PRIVACY               => array( __( 'Data and privacy', 'pressqueue' ), Capabilities::MANAGE_SETTINGS, array( PrivacyController::class, 'render_page' ) ),
			self::SLUG_PREVIEW             => array( __( 'Check a post', 'pressqueue' ), Capabilities::VIEW_DECISIONS, array( self::class, 'render_preview_page' ) ),
			self::SLUG_DECISIONS           => array( __( 'Why posts were shared or skipped', 'pressqueue' ), Capabilities::VIEW_DECISIONS, array( DecisionsController::class, 'render_page' ) ),
		);
	}

	/**
	 * Keep the parent menu item highlighted on hidden pages.
	 *
	 * @param string|null $submenu_file Current submenu file.
	 * @param string      $parent_file Parent file.
	 * @return string|null
	 */
	public static function highlight_hidden_pages( $submenu_file, $parent_file = '' ) {
		unset( $parent_file );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Menu highlighting is read-only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$map  = array(
			DeliveryDetailController::SLUG => DeliveryListController::SLUG,
			self::SLUG_DECISIONS           => DeliveryListController::SLUG,
			Ui::PAGE_PRIVACY               => self::SLUG_SETTINGS,
			self::SLUG_PREVIEW             => self::SLUG_ROOT,
		);

		return $map[ $page ] ?? $submenu_file;
	}

	/**
	 * Keep the PressQueue menu open on hidden pages.
	 *
	 * @param string $parent_file Parent menu file.
	 * @return string
	 */
	public static function open_parent_for_hidden_pages( $parent_file ) {
		global $submenu;

		$hidden = self::hidden_pages();
		if ( isset( $submenu[ self::SLUG_ROOT ] ) && is_array( $submenu[ self::SLUG_ROOT ] ) ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Hides detail pages from the rendered menu only.
			$submenu[ self::SLUG_ROOT ] = array_values(
				array_filter(
					$submenu[ self::SLUG_ROOT ],
					static function ( $item ) use ( $hidden ): bool {
						return ! ( is_array( $item ) && isset( $item[2] ) && array_key_exists( (string) $item[2], $hidden ) );
					}
				)
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Menu highlighting is read-only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return array_key_exists( $page, $hidden ) ? self::SLUG_ROOT : $parent_file;
	}

	/**
	 * Return the menu icon as an SVG data URI that WordPress tints to match the admin scheme.
	 *
	 * @return string
	 */
	private static function menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M4 3h7.5a4.5 4.5 0 0 1 0 9H8v5H4V3Zm4 3.5v2h3.5a1 1 0 0 0 0-2H8Z"/><circle fill="black" cx="15.5" cy="15.5" r="2.5"/></svg>';

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Data URI for the admin menu icon.
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/**
	 * Render the best landing page for the current user's capabilities.
	 */
	public static function render_default_page(): void {
		if ( current_user_can( Capabilities::MANAGE_RULES ) || current_user_can( Capabilities::MANAGE_CONNECTIONS ) || current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			OverviewController::render_page();
			return;
		}

		if ( current_user_can( Capabilities::VIEW_DELIVERIES ) ) {
			DeliveryListController::render_page();
			return;
		}

		if ( current_user_can( Capabilities::VIEW_DECISIONS ) ) {
			self::render_decisions_page();
			return;
		}

		self::require_capability( Capabilities::ACCESS_ADMIN );
		OverviewController::render_page();
	}

	/**
	 * Render rules.
	 */
	public static function render_rules_page(): void {
		RulesController::render_page();
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
		echo '<table class="widefat striped"><caption class="screen-reader-text">' . esc_html__( 'Recent PressQueue decisions', 'pressqueue' ) . '</caption><thead><tr>';
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
	 * Handle rule save.
	 */
	public static function handle_save_rule(): void {
		RulesController::handle_save_rule();
	}

	/**
	 * Handle rule enable confirmation.
	 */
	public static function handle_enable_rule(): void {
		RulesController::handle_enable_rule();
	}

	/**
	 * Handle rule disable.
	 */
	public static function handle_disable_rule(): void {
		RulesController::handle_disable_rule();
	}

	/**
	 * Handle one-rule post preview.
	 */
	public static function handle_rule_post_preview(): void {
		RulesController::handle_rule_post_preview();
	}

	/**
	 * Handle one-rule recent post preview.
	 */
	public static function handle_rule_recent_preview(): void {
		RulesController::handle_rule_recent_preview();
	}

	/**
	 * Handle post preview against enabled rules.
	 */
	public static function handle_post_preview(): void {
		AdminPostBoundary::check( 'pressqueue_preview_post', Capabilities::VIEW_DECISIONS, __( 'You are not allowed to view PressQueue decisions.', 'pressqueue' ) );

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
			self::redirect( self::SLUG_PREVIEW, 'preview_error', SafeOperatorError::redirect_detail( $throwable ) );
		}
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
		echo '<td><pre>' . esc_html( self::pretty_json( current_user_can( 'edit_post', $decision->post_id ) ? $decision->reasons : array( array( 'code' => 'post_facts_redacted' ) ) ) ) . '</pre></td>';
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
			'preview_error'     => __( 'Preview failed.', 'pressqueue' ),
			'preview_ready'     => __( 'Preview is ready.', 'pressqueue' ),
			'rule_disabled'     => __( 'Rule disabled.', 'pressqueue' ),
			'rule_enabled'      => __( 'Rule enabled.', 'pressqueue' ),
			'rule_error'        => __( 'Rule change failed.', 'pressqueue' ),
			'rule_saved'        => __( 'Rule saved disabled.', 'pressqueue' ),
			'settings_saved'    => __( 'Settings saved.', 'pressqueue' ),
			'template_adopted'  => __( 'Template snapshot adopted. Preview the rule before re-enabling it.', 'pressqueue' ),
			'template_deleted'  => __( 'Template removed from selection.', 'pressqueue' ),
			'template_disabled' => __( 'Template disabled.', 'pressqueue' ),
			'template_enabled'  => __( 'Template enabled for selection.', 'pressqueue' ),
			'template_error'    => __( 'Template change failed.', 'pressqueue' ),
			'template_saved'    => __( 'Template saved disabled.', 'pressqueue' ),
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
			echo ' ' . esc_html( SafeOperatorError::notice_detail( sanitize_text_field( wp_unslash( $_GET['pressqueue_detail'] ) ) ) );
		}

		echo '</p></div>';
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

		return current_user_can( 'edit_post', $post_id );
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
	 * Return trigger select markup.
	 *
	 * @param string $name Field name.
	 * @param string $selected Selected value.
	 * @return string
	 */
	private static function trigger_select( string $name, string $selected ): string {
		$html = '<select name="' . esc_attr( $name ) . '">';

		foreach ( array( 'first_publish', 'update', 'scheduled_publication', 'bulk', 'repost' ) as $trigger ) {
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
	 * Return pretty JSON.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function pretty_json( $value ): string {
		$json = wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return is_string( $json ) ? $json : CanonicalJson::encode( $value );
	}
}
