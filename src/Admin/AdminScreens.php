<?php
/**
 * Server-rendered administration screens.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Security\Capabilities;

/**
 * Registers and renders the compact administration experience.
 */
final class AdminScreens {
	private const SLUG_ROOT      = 'pressqueue';
	private const SLUG_RULES     = 'pressqueue-rules';
	private const SLUG_SETTINGS  = 'pressqueue-settings';
	private const SLUG_PREVIEW   = 'pressqueue-preview';
	private const SLUG_DECISIONS = 'pressqueue-decisions';

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
		PostShareNowController::register_hooks();
		ConditionBuilderController::register_hooks();
		TemplateController::register_hooks();
		BatchController::register_hooks();
		PrivacyController::register_hooks();
		SocialCardsController::register_hooks();
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
			SocialCardsController::SLUG    => array( __( 'Social images', 'pressqueue' ), Capabilities::MANAGE_SETTINGS, array( SocialCardsController::class, 'render_page' ) ),
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
			SocialCardsController::SLUG    => self::SLUG_SETTINGS,
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
			DecisionsController::render_page();
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
		// The old post preview page now lives on the overview as "Check a post".
		OverviewController::render_page();
	}

	/**
	 * Render recent decisions.
	 */
	public static function render_decisions_page(): void {
		DecisionsController::render_page();
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

		// The old preview form now hands over to the overview's "Check a post" card.
		wp_safe_redirect( Ui::url( Ui::PAGE_OVERVIEW, array( 'check_post' => self::post_int( 'post_id', 0 ) ) ) . '#pq-check' );
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
}
