<?php
/**
 * Plugin list links and the one-time welcome notice.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Security\Capabilities;

/**
 * Points new users at the setup checklist.
 */
final class PluginLinks {
	public const WELCOME_TRANSIENT = 'pressqueue_welcome_notice';
	public const DOCS_URL          = 'https://www.pressqueue.com/docs/';
	public const SUPPORT_URL       = 'https://github.com/vincentsch/pressqueue/issues';

	/**
	 * Register hooks.
	 */
	public static function register_hooks(): void {
		if ( ! function_exists( 'add_filter' ) || ! defined( 'PRESSQUEUE_BASENAME' ) ) {
			return;
		}

		add_filter( 'plugin_action_links_' . PRESSQUEUE_BASENAME, array( self::class, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( self::class, 'row_meta' ), 10, 2 );
		add_filter( 'admin_footer_text', array( self::class, 'footer_text' ) );
		add_action( 'admin_notices', array( self::class, 'render_welcome' ) );
	}

	/**
	 * Remember to greet the administrator once after activation.
	 */
	public static function queue_welcome(): void {
		if ( function_exists( 'set_transient' ) ) {
			set_transient( self::WELCOME_TRANSIENT, '1', DAY_IN_SECONDS );
		}
	}

	/**
	 * Add "Get started" and "Settings" to the plugin row.
	 *
	 * @param array<int|string,string> $links Existing links.
	 * @return array<int|string,string>
	 */
	public static function action_links( array $links ): array {
		if ( ! current_user_can( Capabilities::ACCESS_ADMIN ) ) {
			return $links;
		}

		$extra = array( 'pressqueue-start' => '<a href="' . esc_url( Ui::url( Ui::PAGE_OVERVIEW ) ) . '">' . esc_html__( 'Get started', 'pressqueue' ) . '</a>' );
		if ( current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			$extra['pressqueue-settings'] = '<a href="' . esc_url( Ui::url( Ui::PAGE_SETTINGS ) ) . '">' . esc_html__( 'Settings', 'pressqueue' ) . '</a>';
		}

		return array_merge( $extra, $links );
	}

	/**
	 * Add "Docs" and "Support" under PressQueue's description on the Plugins screen.
	 *
	 * @param array<int|string,string> $links Existing links.
	 * @param string                   $file Plugin file of the row.
	 * @return array<int|string,string>
	 */
	public static function row_meta( array $links, string $file ): array {
		if ( ! defined( 'PRESSQUEUE_BASENAME' ) || PRESSQUEUE_BASENAME !== $file ) {
			return $links;
		}

		$links['pressqueue-docs']    = self::external_link( self::DOCS_URL, __( 'Docs', 'pressqueue' ) );
		$links['pressqueue-support'] = self::external_link( self::SUPPORT_URL, __( 'Support', 'pressqueue' ) );

		return $links;
	}

	/**
	 * Show the version and help links in the footer of PressQueue's own screens.
	 *
	 * @param mixed $text Footer text from WordPress.
	 * @return mixed
	 */
	public static function footer_text( $text ) {
		if ( ! Ui::is_pressqueue_screen() ) {
			return $text;
		}

		$version = defined( 'PRESSQUEUE_VERSION' ) ? (string) PRESSQUEUE_VERSION : '';
		/* translators: %s: PressQueue version number. */
		$name = sprintf( __( 'PressQueue %s', 'pressqueue' ), $version );

		return '<span class="pq-footer">' . esc_html( $name )
			. ' &middot; ' . self::external_link( self::DOCS_URL, __( 'Docs', 'pressqueue' ) )
			. ' &middot; ' . self::external_link( self::SUPPORT_URL, __( 'Support', 'pressqueue' ) )
			. '</span>';
	}

	/**
	 * Return a link to a page outside this site that opens in a new tab.
	 *
	 * @param string $url URL.
	 * @param string $label Visible label.
	 * @return string
	 */
	private static function external_link( string $url, string $label ): string {
		return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'pressqueue' ) . '</span></a>';
	}

	/**
	 * Show the welcome notice once, on the Plugins screen.
	 */
	public static function render_welcome(): void {
		if ( ! function_exists( 'get_current_screen' ) || ! current_user_can( Capabilities::ACCESS_ADMIN ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( null === $screen || 'plugins' !== $screen->base || '1' !== get_transient( self::WELCOME_TRANSIENT ) ) {
			return;
		}

		delete_transient( self::WELCOME_TRANSIENT );
		echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html__( 'PressQueue is ready to set up.', 'pressqueue' ) . '</strong> ';
		echo esc_html__( 'Connect Buffer, pick your channels and create a rule. Nothing is shared until you go live.', 'pressqueue' );
		echo ' <a href="' . esc_url( Ui::url( Ui::PAGE_OVERVIEW ) ) . '">' . esc_html__( 'Get started', 'pressqueue' ) . '</a></p></div>';
	}
}
