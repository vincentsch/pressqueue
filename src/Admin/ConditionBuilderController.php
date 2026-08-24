<?php
/**
 * Visual condition builder controller.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

/**
 * Renders and loads the rule condition builder enhancement.
 */
final class ConditionBuilderController {
	/**
	 * Register hooks.
	 */
	public static function register_hooks(): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		}
	}

	/**
	 * Enqueue builder assets on PressQueue rule screens.
	 */
	public static function enqueue_assets(): void {
		if ( ! defined( 'PRESSQUEUE_FILE' ) || ! function_exists( 'wp_enqueue_script' ) || ! function_exists( 'plugins_url' ) ) {
			return;
		}

		if ( ! self::is_rules_screen() ) {
			return;
		}

		$path = defined( 'PRESSQUEUE_DIR' ) ? PRESSQUEUE_DIR . 'assets/admin/condition-builder.js' : '';
		if ( '' === $path || ! file_exists( $path ) ) {
			return;
		}

		wp_enqueue_script(
			'pressqueue-condition-builder',
			plugins_url( 'assets/admin/condition-builder.js', PRESSQUEUE_FILE ),
			array(),
			defined( 'PRESSQUEUE_VERSION' ) ? PRESSQUEUE_VERSION : '0.1.0-dev',
			true
		);
	}

	/**
	 * Render the canonical textarea and visual builder container.
	 *
	 * @param string $id Field ID.
	 * @param string $name Field name.
	 * @param string $label Label.
	 * @param string $json Canonical JSON value.
	 */
	public static function render_field( string $id, string $name, string $label, string $json ): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" class="large-text code pressqueue-condition-json" rows="8">' . esc_textarea( $json ) . '</textarea>';
		echo '<div class="pressqueue-condition-builder" data-target="' . esc_attr( $id ) . '">';
		echo '<div class="pressqueue-condition-builder__toolbar">';
		foreach ( self::toolbar_actions() as $action => $button_label ) {
			echo '<button type="button" class="button" data-pressqueue-condition-action="' . esc_attr( $action ) . '">' . esc_html( $button_label ) . '</button> ';
		}
		echo '<label>' . esc_html__( 'Group mode', 'pressqueue' ) . ' <select data-pressqueue-condition-action="set-mode"><option value="ALL">ALL</option><option value="ANY">ANY</option></select></label>';
		echo '</div>';
		echo '<div class="pressqueue-condition-builder__tree" role="tree" aria-label="' . esc_attr__( 'PressQueue conditions', 'pressqueue' ) . '"></div>';
		echo '</div>';
		echo '</td></tr>';
	}

	/**
	 * Return toolbar actions.
	 *
	 * @return array<string,string>
	 */
	private static function toolbar_actions(): array {
		return array(
			'add-condition' => __( 'Add Condition', 'pressqueue' ),
			'add-group'     => __( 'Add Group', 'pressqueue' ),
			'delete'        => __( 'Delete', 'pressqueue' ),
			'move-up'       => __( 'Move Up', 'pressqueue' ),
			'move-down'     => __( 'Move Down', 'pressqueue' ),
			'indent'        => __( 'Indent', 'pressqueue' ),
			'outdent'       => __( 'Outdent', 'pressqueue' ),
		);
	}

	/**
	 * Check whether the current admin page is the rule screen.
	 *
	 * @return bool
	 */
	private static function is_rules_screen(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Page routing is read-only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return 'pressqueue-rules' === $page || 'pressqueue' === $page;
	}
}
