<?php
/**
 * "Social sharing" column in the WordPress post lists.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Editorial\PostControlMetadata;
use PressQueue\Security\Capabilities;
use PressQueue\Storage\Database;
use PressQueue\Storage\Schema;

/**
 * Shows, per post, which channels it went to and how that went.
 */
final class PostListColumn {
	private const COLUMN = 'pressqueue_sharing';

	/**
	 * Latest share state per post and channel for the current list page.
	 *
	 * @var array<int,array<string,array{state:string,service:string,name:string}>>|null
	 */
	private static ?array $shares = null;

	/**
	 * Posts on the current list page whose latest decision was a "never share" rule.
	 *
	 * @var array<int,bool>
	 */
	private static array $excluded = array();

	/**
	 * Register hooks.
	 */
	public static function register_hooks(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( 'admin_init', array( self::class, 'register_columns' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
	}

	/**
	 * Add the column to public post types.
	 */
	public static function register_columns(): void {
		if ( ! current_user_can( Capabilities::VIEW_DELIVERIES ) ) {
			return;
		}

		foreach ( get_post_types( array( 'show_ui' => true ), 'names' ) as $post_type ) {
			if ( 'attachment' === $post_type || ! is_post_type_viewable( $post_type ) ) {
				continue;
			}

			add_filter( 'manage_' . $post_type . '_posts_columns', array( self::class, 'add_column' ) );
			add_action( 'manage_' . $post_type . '_posts_custom_column', array( self::class, 'render_column' ), 10, 2 );
		}
	}

	/**
	 * Add the column after the title.
	 *
	 * @param array<string,string> $columns Columns.
	 * @return array<string,string>
	 */
	public static function add_column( array $columns ): array {
		$result = array();
		foreach ( $columns as $key => $label ) {
			$result[ $key ] = $label;
			if ( 'title' === $key ) {
				$result[ self::COLUMN ] = __( 'Social sharing', 'pressqueue' );
			}
		}

		if ( ! isset( $result[ self::COLUMN ] ) ) {
			$result[ self::COLUMN ] = __( 'Social sharing', 'pressqueue' );
		}

		return $result;
	}

	/**
	 * Render one cell.
	 *
	 * @param string $column Column key.
	 * @param int    $post_id Post ID.
	 */
	public static function render_column( string $column, int $post_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}

		try {
			$shares = self::shares_for( $post_id );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return;
		}

		if ( array() === $shares ) {
			if ( isset( self::$excluded[ $post_id ] ) ) {
				echo '<span class="pq-col-off">' . esc_html__( 'Never share', 'pressqueue' ) . '</span>';
			} elseif ( self::sharing_switched_off( $post_id ) ) {
				echo '<span class="pq-col-off">' . esc_html__( 'Sharing off', 'pressqueue' ) . '</span>';
			} else {
				echo '<span class="screen-reader-text">' . esc_html__( 'Not shared', 'pressqueue' ) . '</span>';
			}
			return;
		}

		echo '<a class="pq-col-shares" href="' . esc_url( Ui::url( Ui::PAGE_ACTIVITY, array( 'post_id' => $post_id ) ) ) . '">';
		foreach ( $shares as $share ) {
			$label = Labels::delivery_state( $share['state'] );
			$title = $share['name'] . ': ' . $label['label'];
			echo '<span class="pq-col-share pq-col-share--' . esc_attr( $label['tone'] ) . '" title="' . esc_attr( $title ) . '">';
			echo '<span class="pq-col-svc pq-col-svc--' . esc_attr( sanitize_html_class( $share['service'] ) ) . '" aria-hidden="true">' . esc_html( Labels::service_monogram( $share['service'] ) ) . '</span>';
			echo '<span class="screen-reader-text">' . esc_html( $title ) . '</span>';
			echo '</span>';
		}
		echo '</a>';
	}

	/**
	 * Enqueue the small column stylesheet on post list screens.
	 *
	 * @param string $hook_suffix Admin page hook.
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( 'edit.php' !== $hook_suffix || ! defined( 'PRESSQUEUE_FILE' ) || ! current_user_can( Capabilities::VIEW_DELIVERIES ) ) {
			return;
		}

		wp_enqueue_style( 'pressqueue-post-list', plugins_url( 'assets/admin/post-list.css', PRESSQUEUE_FILE ), array(), Ui::asset_version( 'assets/admin/post-list.css' ) );
	}

	/**
	 * Return the latest share per channel for a post, loading the whole list page at once.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,array{state:string,service:string,name:string}>
	 */
	private static function shares_for( int $post_id ): array {
		if ( null === self::$shares ) {
			self::$shares = self::load_page_shares();
		}

		return self::$shares[ $post_id ] ?? array();
	}

	/**
	 * Load shares for every post in the current list query.
	 *
	 * @return array<int,array<string,array{state:string,service:string,name:string}>>
	 */
	private static function load_page_shares(): array {
		global $wp_query;

		$ids = array();
		if ( isset( $wp_query->posts ) && is_array( $wp_query->posts ) ) {
			foreach ( $wp_query->posts as $post ) {
				$ids[] = $post instanceof \WP_Post ? (int) $post->ID : (int) $post;
			}
		}

		$ids = array_values( array_filter( array_unique( $ids ) ) );
		if ( array() === $ids ) {
			return array();
		}

		$database     = Database::from_globals();
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $database->get_results(
			'SELECT d.post_id AS post_id, l.channel_public_id AS channel_public_id, l.state AS state FROM `' . $database->table( Schema::DELIVERIES ) . '` l INNER JOIN `' . $database->table( Schema::DECISIONS ) . "` d ON d.id = l.decision_id WHERE d.post_id IN ($placeholders) AND d.event_mode = 'runtime' ORDER BY l.state_changed_at DESC, l.id DESC",
			$ids
		);

		$excluded = $database->get_results(
			'SELECT DISTINCT post_id FROM `' . $database->table( Schema::DECISIONS ) . "` WHERE post_id IN ($placeholders) AND event_mode = 'runtime' AND result = 'excluded'",
			$ids
		);
		foreach ( $excluded as $row ) {
			self::$excluded[ (int) $row->post_id ] = true;
		}

		$summary = new ActivitySummary( $database );
		$shares  = array();
		foreach ( $rows as $row ) {
			$post_id    = (int) $row->post_id;
			$channel_id = (string) $row->channel_public_id;
			if ( isset( $shares[ $post_id ][ $channel_id ] ) ) {
				continue;
			}

			$channel                           = $summary->channel( $channel_id );
			$shares[ $post_id ][ $channel_id ] = array(
				'state'   => (string) $row->state,
				'service' => null === $channel ? 'buffer' : $channel->service,
				'name'    => null === $channel ? __( 'Removed channel', 'pressqueue' ) : ActivitySummary::channel_display_name( $channel ),
			);
		}

		return $shares;
	}

	/**
	 * Check whether sharing is switched off for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function sharing_switched_off( int $post_id ): bool {
		try {
			$bundle = PostControlMetadata::read_for_post( $post_id );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return false;
		}

		return 'exclude' === ( $bundle['policy'] ?? 'inherit' );
	}
}
