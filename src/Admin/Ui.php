<?php
/**
 * Shared rendering helpers for PressQueue administration screens.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Security\Capabilities;
use PressQueue\Storage\SettingsRepository;

/**
 * Small, escaped building blocks so every screen looks and behaves the same.
 */
final class Ui {
	public const PAGE_OVERVIEW = 'pressqueue';
	public const PAGE_ACTIVITY = 'pressqueue-deliveries';
	public const PAGE_DELIVERY = 'pressqueue-delivery';
	public const PAGE_RULES    = 'pressqueue-rules';
	public const PAGE_CHANNELS = 'pressqueue-connections';
	public const PAGE_BATCHES  = 'pressqueue-batches';
	public const PAGE_SETTINGS = 'pressqueue-settings';
	public const PAGE_PRIVACY  = 'pressqueue-privacy';

	/**
	 * Register asset hooks.
	 */
	public static function register_hooks(): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		}
	}

	/**
	 * Enqueue shared styles and behavior on PressQueue screens only.
	 */
	public static function enqueue_assets(): void {
		if ( ! self::is_pressqueue_screen() || ! defined( 'PRESSQUEUE_FILE' ) || ! function_exists( 'wp_enqueue_style' ) ) {
			return;
		}

		wp_enqueue_style( 'pressqueue-admin', plugins_url( 'assets/admin/admin.css', PRESSQUEUE_FILE ), array(), self::asset_version( 'assets/admin/admin.css' ) );
		wp_enqueue_script( 'pressqueue-admin', plugins_url( 'assets/admin/admin.js', PRESSQUEUE_FILE ), array(), self::asset_version( 'assets/admin/admin.js' ), true );
	}

	/**
	 * Return a cache-busting asset version.
	 *
	 * @param string $relative_path Path relative to the plugin directory.
	 * @return string
	 */
	public static function asset_version( string $relative_path ): string {
		$version = defined( 'PRESSQUEUE_VERSION' ) ? (string) PRESSQUEUE_VERSION : '0.1.0';
		$path    = defined( 'PRESSQUEUE_DIR' ) ? PRESSQUEUE_DIR . $relative_path : '';

		if ( '' !== $path && file_exists( $path ) ) {
			$version .= '.' . (string) filemtime( $path );
		}

		return $version;
	}

	/**
	 * Check whether the current admin request is a PressQueue screen.
	 *
	 * @return bool
	 */
	public static function is_pressqueue_screen(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Page routing is read-only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return 'pressqueue' === $page || str_starts_with( $page, 'pressqueue-' );
	}

	/**
	 * Open a PressQueue page.
	 *
	 * @param string              $title Visible page title.
	 * @param array<string,mixed> $options Optional keys: back_url, back_label, actions (escaped HTML), badge (escaped HTML), brand (bool).
	 */
	public static function page_start( string $title, array $options = array() ): void {
		echo '<div class="wrap pq-admin pq-no-js">';

		if ( isset( $options['back_url'] ) && is_string( $options['back_url'] ) ) {
			echo '<a class="pq-back" href="' . esc_url( $options['back_url'] ) . '">&larr; ' . esc_html( (string) ( $options['back_label'] ?? __( 'Back', 'pressqueue' ) ) ) . '</a>';
		}

		echo '<div class="pq-head">';
		if ( ! empty( $options['brand'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG markup.
			echo '<span class="pq-mark" aria-hidden="true">' . self::logo_svg() . '</span>';
		}
		echo '<h1>' . esc_html( $title ) . '</h1>';
		if ( isset( $options['badge'] ) && is_string( $options['badge'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Callers pass markup built from escaped helpers.
			echo $options['badge'];
		}
		if ( isset( $options['actions'] ) && is_string( $options['actions'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Callers pass markup built from escaped helpers.
			echo $options['actions'];
		}
		echo '<span class="pq-grow"></span>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- mode_pill returns escaped markup.
		echo self::mode_pill();
		echo '</div>';
		echo '<hr class="wp-header-end" />';
	}

	/**
	 * Close a PressQueue page.
	 */
	public static function page_end(): void {
		echo '</div>';
	}

	/**
	 * Render page tabs.
	 *
	 * @param array<string,array{0:string,1:string}> $tabs Tab key => [label, URL].
	 * @param string                                 $current Current tab key.
	 */
	public static function tabs( array $tabs, string $current ): void {
		echo '<nav class="pq-tabs" aria-label="' . esc_attr__( 'Sections', 'pressqueue' ) . '">';
		foreach ( $tabs as $key => $tab ) {
			$attributes = $key === $current ? ' aria-current="page"' : '';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $attributes is a fixed literal.
			echo '<a href="' . esc_url( $tab[1] ) . '"' . $attributes . '>' . esc_html( $tab[0] ) . '</a>';
		}
		echo '</nav>';
	}

	/**
	 * Return the current sharing mode.
	 *
	 * @return string One of test, live, paused.
	 */
	public static function mode(): string {
		return ( new SettingsRepository() )->sharing_mode();
	}

	/**
	 * Return the mode pill markup.
	 *
	 * @return string
	 */
	public static function mode_pill(): string {
		try {
			$mode = self::mode();
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return '';
		}

		$labels    = Labels::modes();
		$label     = $labels[ $mode ]['label'] ?? '';
		$title     = $labels[ $mode ]['help'] ?? '';
		$css_class = 'pq-mode pq-mode--' . $mode;

		if ( function_exists( 'current_user_can' ) && current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return '<a class="' . esc_attr( $css_class ) . '" href="' . esc_url( self::url( self::PAGE_SETTINGS ) ) . '" title="' . esc_attr( $title ) . '">' . esc_html( $label ) . '</a>';
		}

		return '<span class="' . esc_attr( $css_class ) . '" title="' . esc_attr( $title ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Return a status pill.
	 *
	 * @param string $tone One of ok, warn, bad, idle, info.
	 * @param string $label Visible label.
	 * @param string $title Optional tooltip.
	 * @return string
	 */
	public static function pill( string $tone, string $label, string $title = '' ): string {
		$tone = in_array( $tone, array( 'ok', 'warn', 'bad', 'idle', 'info' ), true ) ? $tone : 'idle';
		$attr = '' === $title ? '' : ' title="' . esc_attr( $title ) . '"';

		return '<span class="pq-pill pq-pill--' . esc_attr( $tone ) . '"' . $attr . '>' . esc_html( $label ) . '</span>';
	}

	/**
	 * Return a service badge.
	 *
	 * @param string $service Provider service slug.
	 * @param string $size Optional sm or lg.
	 * @return string
	 */
	public static function service_badge( string $service, string $size = '' ): string {
		$service   = strtolower( $service );
		$css_class = 'pq-svc pq-svc--' . sanitize_html_class( $service ) . ( '' === $size ? '' : ' pq-svc--' . sanitize_html_class( $size ) );

		return '<span class="' . esc_attr( $css_class ) . '" aria-hidden="true">' . esc_html( Labels::service_monogram( $service ) ) . '</span>';
	}

	/**
	 * Return a badge plus channel name.
	 *
	 * @param string $service Provider service slug.
	 * @param string $name Channel display name.
	 * @param string $size Badge size.
	 * @return string
	 */
	public static function channel_name( string $service, string $name, string $size = 'sm' ): string {
		return '<span class="pq-channel-name">' . self::service_badge( $service, $size ) . '<span class="pq-truncate">' . esc_html( $name ) . '</span><span class="screen-reader-text"> (' . esc_html( Labels::service( $service ) ) . ')</span></span>';
	}

	/**
	 * Open an admin-post form.
	 *
	 * @param string               $action admin-post action.
	 * @param string               $nonce_action Nonce action.
	 * @param array<string,string> $hidden Hidden fields.
	 * @param array<string,string> $attributes Extra form attributes.
	 */
	public static function form_start( string $action, string $nonce_action, array $hidden = array(), array $attributes = array() ): void {
		$extra = '';
		foreach ( $attributes as $name => $value ) {
			$extra .= ' ' . esc_attr( $name ) . '="' . esc_attr( $value ) . '"';
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes are escaped above.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . $extra . '>';
		wp_nonce_field( $nonce_action );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		foreach ( $hidden as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
		}
	}

	/**
	 * Close a form.
	 */
	public static function form_end(): void {
		echo '</form>';
	}

	/**
	 * Render a self-submitting toggle switch.
	 *
	 * The checkbox carries the desired next state; a no-JS submit button is shown when scripts are off.
	 *
	 * @param string               $action admin-post action used when switching on.
	 * @param string               $off_action admin-post action used when switching off.
	 * @param array<string,string> $hidden Hidden fields.
	 * @param bool                 $checked Current state.
	 * @param string               $label Accessible label.
	 * @param array<string,string> $options Optional keys: confirm, disabled.
	 */
	public static function toggle_form( string $action, string $off_action, array $hidden, bool $checked, string $label, array $options = array() ): void {
		$target     = $checked ? $off_action : $action;
		$attributes = array( 'class' => 'pq-toggle-form' );
		if ( isset( $options['confirm'] ) && '' !== $options['confirm'] ) {
			$attributes['data-pq-confirm'] = $options['confirm'];
		}

		self::form_start( $target, $target, $hidden, $attributes );
		$disabled = ! empty( $options['disabled'] ) ? ' disabled="disabled"' : '';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $disabled is a fixed literal.
		echo '<label class="pq-toggle"><input type="checkbox" aria-label="' . esc_attr( $label ) . '" ' . checked( $checked, true, false ) . $disabled . ' /><span></span></label>';
		echo '<button type="submit" class="button button-small pq-toggle-submit">' . esc_html( $checked ? __( 'Turn off', 'pressqueue' ) : __( 'Turn on', 'pressqueue' ) ) . '</button>';
		self::form_end();
	}

	/**
	 * Render a one-button form.
	 *
	 * @param string               $action admin-post action.
	 * @param string               $nonce_action Nonce action.
	 * @param array<string,string> $hidden Hidden fields.
	 * @param string               $label Button label.
	 * @param string               $css_class Button classes.
	 * @param string               $confirm Optional confirmation question.
	 */
	public static function button_form( string $action, string $nonce_action, array $hidden, string $label, string $css_class = 'button', string $confirm = '' ): void {
		$attributes = array( 'class' => 'pq-inline' );
		if ( '' !== $confirm ) {
			$attributes['data-pq-confirm'] = $confirm;
		}
		self::form_start( $action, $nonce_action, $hidden, $attributes );
		echo '<button type="submit" class="' . esc_attr( $css_class ) . '">' . esc_html( $label ) . '</button>';
		self::form_end();
	}

	/**
	 * Render an empty state.
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
	 * Render a WordPress notice.
	 *
	 * @param string $type success, error, warning or info.
	 * @param string $message Message.
	 * @param string $detail Optional detail appended after the message.
	 */
	public static function notice( string $type, string $message, string $detail = '' ): void {
		$type = in_array( $type, array( 'success', 'error', 'warning', 'info' ), true ) ? $type : 'info';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $message );
		if ( '' !== $detail ) {
			echo ' ' . esc_html( $detail );
		}
		echo '</p></div>';
	}

	/**
	 * Return a PressQueue admin URL.
	 *
	 * @param string               $page Page slug.
	 * @param array<string,scalar> $args Extra query args.
	 * @return string
	 */
	public static function url( string $page, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Return a human relative time such as "5 minutes ago" or a short date.
	 *
	 * @param \DateTimeInterface|null $time UTC time.
	 * @return string
	 */
	public static function relative_time( ?\DateTimeInterface $time ): string {
		if ( null === $time ) {
			return '';
		}

		$timestamp = $time->getTimestamp();
		$diff      = time() - $timestamp;

		if ( $diff >= 0 && $diff < 60 ) {
			return __( 'Just now', 'pressqueue' );
		}

		if ( $diff >= 0 && $diff < DAY_IN_SECONDS ) {
			/* translators: %s: human-readable time difference such as "5 mins". */
			return sprintf( __( '%s ago', 'pressqueue' ), human_time_diff( $timestamp, time() ) );
		}

		if ( $diff < 0 && -$diff < DAY_IN_SECONDS ) {
			/* translators: %s: human-readable time difference such as "5 mins". */
			return sprintf( __( 'in %s', 'pressqueue' ), human_time_diff( time(), $timestamp ) );
		}

		$date = wp_date( get_option( 'date_format', 'M j, Y' ), $timestamp );

		return is_string( $date ) ? $date : '';
	}

	/**
	 * Return a full local date and time for title attributes.
	 *
	 * @param \DateTimeInterface|null $time UTC time.
	 * @return string
	 */
	public static function local_datetime( ?\DateTimeInterface $time ): string {
		if ( null === $time ) {
			return '';
		}

		$format = trim( get_option( 'date_format', 'M j, Y' ) . ' ' . get_option( 'time_format', 'H:i' ) );

		$date = wp_date( $format, $time->getTimestamp() );

		return is_string( $date ) ? $date : '';
	}

	/**
	 * Return a post title for display, or a fallback when the post is gone or private to the viewer.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function post_title( int $post_id ): string {
		$post = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post instanceof \WP_Post ) {
			/* translators: %d: post ID. */
			return sprintf( __( 'Deleted post #%d', 'pressqueue' ), $post_id );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			/* translators: %d: post ID. */
			return sprintf( __( 'Post #%d', 'pressqueue' ), $post_id );
		}

		$title = trim( wp_strip_all_tags( get_the_title( $post ) ) );

		if ( '' === $title ) {
			/* translators: %d: post ID. */
			return sprintf( __( '(no title) #%d', 'pressqueue' ), $post_id );
		}

		return html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Return the small PressQueue mark.
	 *
	 * @return string
	 */
	public static function logo_svg(): string {
		return '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" focusable="false"><path d="M3 3h7a3 3 0 0 1 0 6H6v4H3V3Z" fill="#fff"/><circle cx="12.5" cy="12.5" r="2" fill="#8ea2ff"/></svg>';
	}
}
