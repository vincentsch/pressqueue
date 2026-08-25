<?php
/**
 * Reusable template administration.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Security\Capabilities;
use PressQueue\Security\SafeOperatorError;
use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\Record\RuleRecord;
use PressQueue\Storage\Record\TemplateRecord;
use PressQueue\Storage\Repository\RuleRepository;
use PressQueue\Storage\Repository\TemplateRepository;
use PressQueue\Storage\ValidationException;

/**
 * Renders and mutates reusable template sources.
 */
final class TemplateController {
	private const RULES_SLUG = 'pressqueue-rules';

	/**
	 * Register POST handlers.
	 */
	public static function register_hooks(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( 'admin_post_pressqueue_save_template', array( self::class, 'handle_save_template' ) );
		add_action( 'admin_post_pressqueue_enable_template', array( self::class, 'handle_enable_template' ) );
		add_action( 'admin_post_pressqueue_disable_template', array( self::class, 'handle_disable_template' ) );
		add_action( 'admin_post_pressqueue_delete_template', array( self::class, 'handle_delete_template' ) );
		add_action( 'admin_post_pressqueue_adopt_template', array( self::class, 'handle_adopt_template' ) );
	}

	/**
	 * Render template management under rules.
	 */
	public static function render_section(): void {
		self::require_capability();

		$templates = ( new TemplateRepository() )->find_all();

		echo '<h2>' . esc_html__( 'Reusable Templates', 'pressqueue' ) . '</h2>';
		echo '<table class="widefat striped"><caption class="screen-reader-text">' . esc_html__( 'Reusable PressQueue templates', 'pressqueue' ) . '</caption><thead><tr>';
		foreach ( array( __( 'Name', 'pressqueue' ), __( 'Version', 'pressqueue' ), __( 'Enabled', 'pressqueue' ), __( 'Actions', 'pressqueue' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( array() === $templates ) {
			echo '<tr><td colspan="4">' . esc_html__( 'No reusable templates have been created.', 'pressqueue' ) . '</td></tr>';
		}

		foreach ( $templates as $template ) {
			echo '<tr>';
			echo '<td><code>' . esc_html( $template->public_id ) . '</code><br />' . esc_html( $template->name ) . '</td>';
			echo '<td>' . esc_html( (string) $template->version ) . '</td>';
			echo '<td>' . esc_html( $template->enabled ? __( 'Enabled', 'pressqueue' ) : __( 'Disabled', 'pressqueue' ) ) . '</td>';
			echo '<td>';
			self::render_template_state_form( $template );
			echo '</td>';
			echo '</tr>';
			echo '<tr><td colspan="4">';
			self::render_template_form( $template );
			echo '</td></tr>';
		}

		echo '</tbody></table>';
		self::render_template_form( null );
	}

	/**
	 * Render an adoption action for a rule.
	 *
	 * @param RuleRecord $rule Rule.
	 */
	public static function render_rule_adoption_form( RuleRecord $rule ): void {
		$templates = ( new TemplateRepository() )->find_enabled();

		if ( array() === $templates ) {
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:.5em;">';
		wp_nonce_field( 'pressqueue_adopt_template' );
		echo '<input type="hidden" name="action" value="pressqueue_adopt_template" />';
		echo '<input type="hidden" name="rule_public_id" value="' . esc_attr( $rule->public_id ) . '" />';
		echo '<input type="hidden" name="lock_version" value="' . esc_attr( (string) $rule->lock_version ) . '" />';
		echo '<select name="template_public_id">';
		foreach ( $templates as $template ) {
			$label = $template->name . ' v' . (string) $template->version;
			echo '<option value="' . esc_attr( $template->public_id ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select> ';
		submit_button( __( 'Adopt Latest Template', 'pressqueue' ), 'secondary small', 'submit', false );
		echo '</form>';
	}

	/**
	 * Handle template create or update.
	 */
	public static function handle_save_template(): void {
		AdminPostBoundary::check( 'pressqueue_save_template', Capabilities::MANAGE_RULES, __( 'You are not allowed to manage PressQueue reusable templates.', 'pressqueue' ) );

		try {
			$repository   = new TemplateRepository();
			$public_id    = self::post_text( 'template_public_id' );
			$name         = self::post_text( 'name' );
			$template_set = self::post_json_object( 'template_set_json' );

			if ( '' === $public_id ) {
				$repository->create( $name, $template_set );
			} else {
				$repository->update(
					$public_id,
					self::post_int( 'lock_version', 1 ),
					array(
						'name'         => $name,
						'template_set' => $template_set,
					)
				);
			}

			self::redirect( 'template_saved' );
		} catch ( \Throwable $throwable ) {
			self::redirect( 'template_error', SafeOperatorError::redirect_detail( $throwable ) );
		}
	}

	/**
	 * Enable a template for selection.
	 */
	public static function handle_enable_template(): void {
		self::set_template_enabled( true, 'template_enabled' );
	}

	/**
	 * Disable a template.
	 */
	public static function handle_disable_template(): void {
		self::set_template_enabled( false, 'template_disabled' );
	}

	/**
	 * Soft-delete a template from selection.
	 */
	public static function handle_delete_template(): void {
		AdminPostBoundary::check( 'pressqueue_delete_template', Capabilities::MANAGE_RULES, __( 'You are not allowed to manage PressQueue reusable templates.', 'pressqueue' ) );

		try {
			( new TemplateRepository() )->delete_from_selection( self::post_text( 'template_public_id' ), self::post_int( 'lock_version', 1 ) );
			self::redirect( 'template_deleted' );
		} catch ( \Throwable $throwable ) {
			self::redirect( 'template_error', SafeOperatorError::redirect_detail( $throwable ) );
		}
	}

	/**
	 * Adopt the latest selected template snapshot into a rule.
	 */
	public static function handle_adopt_template(): void {
		AdminPostBoundary::check( 'pressqueue_adopt_template', Capabilities::MANAGE_RULES, __( 'You are not allowed to manage PressQueue reusable templates.', 'pressqueue' ) );

		try {
			( new RuleRepository() )->adopt_template_snapshot(
				self::post_text( 'rule_public_id' ),
				self::post_int( 'lock_version', 1 ),
				self::post_text( 'template_public_id' )
			);
			self::redirect( 'template_adopted' );
		} catch ( \Throwable $throwable ) {
			self::redirect( 'template_error', SafeOperatorError::redirect_detail( $throwable ) );
		}
	}

	/**
	 * Enable or disable a template.
	 *
	 * @param bool   $enabled Enabled.
	 * @param string $message Success message key.
	 */
	private static function set_template_enabled( bool $enabled, string $message ): void {
		AdminPostBoundary::check( $enabled ? 'pressqueue_enable_template' : 'pressqueue_disable_template', Capabilities::MANAGE_RULES, __( 'You are not allowed to manage PressQueue reusable templates.', 'pressqueue' ) );

		try {
			( new TemplateRepository() )->set_enabled( self::post_text( 'template_public_id' ), self::post_int( 'lock_version', 1 ), $enabled );
			self::redirect( $message );
		} catch ( \Throwable $throwable ) {
			self::redirect( 'template_error', SafeOperatorError::redirect_detail( $throwable ) );
		}
	}

	/**
	 * Render enable, disable, and delete forms.
	 *
	 * @param TemplateRecord $template Template.
	 */
	private static function render_template_state_form( TemplateRecord $template ): void {
		if ( $template->enabled ) {
			self::small_template_action_form( 'pressqueue_disable_template', 'pressqueue_disable_template', __( 'Disable Template', 'pressqueue' ), $template );
		} else {
			self::small_template_action_form( 'pressqueue_enable_template', 'pressqueue_enable_template', __( 'Enable For Selection', 'pressqueue' ), $template );
		}

		self::small_template_action_form( 'pressqueue_delete_template', 'pressqueue_delete_template', __( 'Remove From Selection', 'pressqueue' ), $template );
	}

	/**
	 * Render a compact template state form.
	 *
	 * @param string         $action Admin action.
	 * @param string         $nonce Nonce action.
	 * @param string         $label Button label.
	 * @param TemplateRecord $template Template.
	 */
	private static function small_template_action_form( string $action, string $nonce, string $label, TemplateRecord $template ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0 0 .5em;">';
		wp_nonce_field( $nonce );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		echo '<input type="hidden" name="template_public_id" value="' . esc_attr( $template->public_id ) . '" />';
		echo '<input type="hidden" name="lock_version" value="' . esc_attr( (string) $template->lock_version ) . '" />';
		submit_button( $label, 'secondary small', 'submit', false );
		echo '</form>';
	}

	/**
	 * Render create or edit form.
	 *
	 * @param TemplateRecord|null $template Template.
	 */
	private static function render_template_form( ?TemplateRecord $template ): void {
		$is_edit = null !== $template;
		$id      = $is_edit ? strtolower( $template->public_id ) : 'new';
		$value   = $is_edit ? $template->template_set : self::default_template_set();

		echo '<h3>' . esc_html( $is_edit ? __( 'Edit Template', 'pressqueue' ) : __( 'Add Template', 'pressqueue' ) ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'pressqueue_save_template' );
		echo '<input type="hidden" name="action" value="pressqueue_save_template" />';
		if ( $is_edit ) {
			echo '<input type="hidden" name="template_public_id" value="' . esc_attr( $template->public_id ) . '" />';
			echo '<input type="hidden" name="lock_version" value="' . esc_attr( (string) $template->lock_version ) . '" />';
		}
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="pressqueue-template-name-' . esc_attr( $id ) . '">' . esc_html__( 'Name', 'pressqueue' ) . '</label></th><td><input id="pressqueue-template-name-' . esc_attr( $id ) . '" name="name" type="text" class="regular-text" value="' . esc_attr( $is_edit ? $template->name : '' ) . '" required /></td></tr>';
		echo '<tr><th scope="row"><label for="pressqueue-template-set-' . esc_attr( $id ) . '">' . esc_html__( 'Template Set JSON', 'pressqueue' ) . '</label></th><td><textarea id="pressqueue-template-set-' . esc_attr( $id ) . '" name="template_set_json" class="large-text code" rows="8">' . esc_textarea( self::pretty_json( $value ) ) . '</textarea></td></tr>';
		echo '</tbody></table>';
		submit_button( $is_edit ? __( 'Save Template', 'pressqueue' ) : __( 'Save Disabled Template', 'pressqueue' ) );
		echo '</form>';
	}

	/**
	 * Return a starter template set.
	 *
	 * @return array<string,mixed>
	 */
	private static function default_template_set(): array {
		return array(
			'default_message' => '{{ title }}',
			'schema_version'  => 1,
		);
	}

	/**
	 * Decode a POST JSON object.
	 *
	 * @param string $key Key.
	 * @return array<string,mixed>
	 */
	private static function post_json_object( string $key ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is checked by caller and JSON is decoded structurally.
		$raw = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';

		if ( ! is_string( $raw ) ) {
			throw new ValidationException( 'Template JSON field is invalid.' );
		}

		$value = json_decode( $raw, true, 512, JSON_THROW_ON_ERROR );
		if ( ! is_array( $value ) || ( array() !== $value && array_is_list( $value ) ) ) {
			throw new ValidationException( 'Template JSON field must contain an object.' );
		}

		return $value;
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
	 * Redirect back to rules.
	 *
	 * @param string      $message Message key.
	 * @param string|null $detail Optional detail.
	 */
	private static function redirect( string $message, ?string $detail = null ): void {
		$args = array(
			'page'               => self::RULES_SLUG,
			'pressqueue_message' => $message,
		);

		if ( null !== $detail && '' !== $detail ) {
			$args['pressqueue_detail'] = rawurlencode( $detail );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Require rule management.
	 */
	private static function require_capability(): void {
		if ( ! current_user_can( Capabilities::MANAGE_RULES ) ) {
			wp_die( esc_html__( 'You are not allowed to manage PressQueue templates.', 'pressqueue' ) );
		}
	}

	/**
	 * Return pretty JSON.
	 *
	 * @param array<string,mixed> $value Value.
	 * @return string
	 */
	private static function pretty_json( array $value ): string {
		$encoded = CanonicalJson::encode_object( $value );
		$decoded = CanonicalJson::decode_object( $encoded );
		$json    = function_exists( 'wp_json_encode' ) ? wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : null;

		return str_replace( '    ', "\t", is_string( $json ) ? $json : $encoded );
	}
}
