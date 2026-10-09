<?php
/**
 * Reusable message template administration.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Security\Capabilities;
use PressQueue\Security\SafeOperatorError;
use PressQueue\Storage\PublicIds;
use PressQueue\Storage\Record\RuleRecord;
use PressQueue\Storage\Record\TemplateRecord;
use PressQueue\Storage\Repository\ChannelRepository;
use PressQueue\Storage\Repository\RuleRepository;
use PressQueue\Storage\Repository\TemplateRepository;

/**
 * Renders and mutates reusable message templates on the Rules page.
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
	 * Render the "Message templates" tab.
	 */
	public static function render_tab(): void {
		self::require_capability();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing.
		$requested = isset( $_GET['template'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['template'] ) ) ) : '';

		if ( 'NEW' === $requested ) {
			self::render_form( null );
			return;
		}

		if ( PublicIds::is_valid( $requested ) ) {
			$template = ( new TemplateRepository() )->find_by_public_id( $requested );
			if ( null !== $template ) {
				self::render_form( $template );
				return;
			}
		}

		self::render_list();
	}

	/**
	 * Keep the old entry point working for callers that render the section directly.
	 */
	public static function render_section(): void {
		self::render_list_cards();
	}

	/**
	 * Render the template picker inside the rule editor.
	 *
	 * @param RuleRecord|null $rule Rule, or null for a new rule.
	 * @param string          $adopted Template already chosen in this edit.
	 */
	public static function render_picker( ?RuleRecord $rule, string $adopted = '' ): void {
		$templates = ( new TemplateRepository() )->find_enabled();

		if ( array() === $templates ) {
			return;
		}

		$form = null === $rule ? '' : ' form="pq-adopt-template-form"';

		echo '<div class="pq-template-picker' . ( null === $rule ? ' pq-js-only' : '' ) . '">';
		echo '<label class="pq-label" for="pq-template-pick">' . esc_html__( 'Start from a saved template', 'pressqueue' ) . '</label>';
		echo '<div class="pq-row">';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $form is a fixed attribute string.
		echo '<select id="pq-template-pick" class="pq-field pq-field--auto" name="template_public_id"' . $form . ' data-pq-template-pick>';
		echo '<option value="">' . esc_html__( 'Choose a template', 'pressqueue' ) . '</option>';
		foreach ( $templates as $template ) {
			echo '<option value="' . esc_attr( $template->public_id ) . '" data-set="' . esc_attr( (string) wp_json_encode( $template->template_set ) ) . '" ' . selected( $adopted, $template->public_id, false ) . '>' . esc_html( $template->name ) . '</option>';
		}
		echo '</select>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $form is a fixed attribute string.
		echo '<button type="submit" class="button"' . $form . ' data-pq-template-use>' . esc_html__( 'Use this template', 'pressqueue' ) . '</button>';
		echo '</div>';
		echo '<input type="hidden" name="adopted_template_public_id" value="' . esc_attr( $adopted ) . '" data-pq-adopted />';
		echo '<p class="pq-help" data-pq-template-status>' . esc_html__( 'Copies the template\'s message into this rule. Later changes to the template do not change the rule.', 'pressqueue' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Render the form the picker submits to when scripts are off.
	 *
	 * @param RuleRecord $rule Rule.
	 */
	public static function render_adopt_form( RuleRecord $rule ): void {
		if ( array() === ( new TemplateRepository() )->find_enabled() ) {
			return;
		}

		Ui::form_start(
			'pressqueue_adopt_template',
			'pressqueue_adopt_template',
			array(
				'rule_public_id' => $rule->public_id,
				'lock_version'   => (string) $rule->lock_version,
				'return_view'    => 'edit',
			),
			array(
				'id'              => 'pq-adopt-template-form',
				'data-pq-confirm' => __( 'Copy this template into the rule now? Unsaved changes on this page are lost, and the rule switches off until it is tested again.', 'pressqueue' ),
			)
		);
		Ui::form_end();
	}

	/**
	 * Keep the old adoption entry point: it renders the picker for an existing rule.
	 *
	 * @param RuleRecord $rule Rule.
	 */
	public static function render_rule_adoption_form( RuleRecord $rule ): void {
		self::render_adopt_form( $rule );
		self::render_picker( $rule );
	}

	/**
	 * Handle template create or update.
	 */
	public static function handle_save_template(): void {
		AdminPostBoundary::check( 'pressqueue_save_template', Capabilities::MANAGE_RULES, __( 'You are not allowed to manage PressQueue reusable templates.', 'pressqueue' ) );

		$public_id = self::post_text( 'template_public_id' );

		try {
			$repository = new TemplateRepository();
			$current    = '' === $public_id ? null : $repository->find_by_public_id( $public_id );
			$name       = self::post_text( 'name' );
			$set        = MessageComposer::template_set_from_request( null === $current ? array() : $current->template_set );

			if ( null === $current ) {
				$repository->create( $name, $set );
				self::redirect( 'template_created' );
			}

			$repository->update(
				$current->public_id,
				self::post_int( 'lock_version', 1 ),
				array(
					'name'         => $name,
					'template_set' => $set,
				)
			);
		} catch ( \Throwable $throwable ) {
			self::redirect( 'template_error', $throwable instanceof RuleFormException ? null : SafeOperatorError::redirect_detail( $throwable ), $public_id, self::form_error_key( $throwable ) );
		}

		self::redirect( 'template_saved' );
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
		} catch ( \Throwable $throwable ) {
			self::redirect( 'template_error', SafeOperatorError::redirect_detail( $throwable ) );
		}

		self::redirect( 'template_deleted' );
	}

	/**
	 * Adopt the latest selected template snapshot into a rule.
	 */
	public static function handle_adopt_template(): void {
		AdminPostBoundary::check( 'pressqueue_adopt_template', Capabilities::MANAGE_RULES, __( 'You are not allowed to manage PressQueue reusable templates.', 'pressqueue' ) );

		$rule_public_id = self::post_text( 'rule_public_id' );
		$to_editor      = 'edit' === self::post_text( 'return_view' ) && PublicIds::is_valid( $rule_public_id );

		try {
			( new RuleRepository() )->adopt_template_snapshot(
				$rule_public_id,
				self::post_int( 'lock_version', 1 ),
				self::post_text( 'template_public_id' )
			);
		} catch ( \Throwable $throwable ) {
			if ( $to_editor ) {
				self::redirect_to_rule( $rule_public_id, 'template_error', SafeOperatorError::redirect_detail( $throwable ) );
			}
			self::redirect( 'rule_error', SafeOperatorError::redirect_detail( $throwable ), '', '', false );
		}

		if ( $to_editor ) {
			self::redirect_to_rule( $rule_public_id, 'template_adopted' );
		}

		self::redirect( 'template_adopted', null, '', '', false );
	}

	/**
	 * Render the template list.
	 */
	private static function render_list(): void {
		RulesController::render_header( 'templates' );
		self::render_list_cards();
		Ui::page_end();
	}

	/**
	 * Render the template cards.
	 */
	private static function render_list_cards(): void {
		$templates = ( new TemplateRepository() )->find_all();
		$used_by   = array();

		foreach ( ( new RuleRepository() )->find_all() as $rule ) {
			if ( null !== $rule->source_template_public_id ) {
				$used_by[ $rule->source_template_public_id ] = ( $used_by[ $rule->source_template_public_id ] ?? 0 ) + 1;
			}
		}

		echo '<div class="pq-row pq-row--between pq-heading-row"><p class="pq-muted pq-flush">' . esc_html__( 'Save messages you use often. When you edit a sharing rule, you can start from one of them.', 'pressqueue' ) . '</p>';
		if ( array() !== $templates ) {
			echo '<a class="button" href="' . esc_url( self::tab_url( array( 'template' => 'new' ) ) ) . '">' . esc_html__( 'New message template', 'pressqueue' ) . '</a>';
		}
		echo '</div>';

		echo '<div class="pq-card pq-rules">';
		if ( array() === $templates ) {
			RulesController::empty_state(
				__( 'No message templates yet', 'pressqueue' ),
				__( 'A template is a message with placeholders, such as the post title and excerpt. Rules copy it, so changing a template later does not change existing rules.', 'pressqueue' ),
				'<a class="button button-primary" href="' . esc_url( self::tab_url( array( 'template' => 'new' ) ) ) . '">' . esc_html__( 'New message template', 'pressqueue' ) . '</a>'
			);
		}

		foreach ( $templates as $template ) {
			self::render_card( $template, $used_by[ $template->public_id ] ?? 0 );
		}
		echo '</div>';
	}

	/**
	 * Render one template card.
	 *
	 * @param TemplateRecord $template Template.
	 * @param int            $used_by Number of rules that copied this template.
	 */
	private static function render_card( TemplateRecord $template, int $used_by ): void {
		$edit_url = self::tab_url( array( 'template' => $template->public_id ) );
		$variants = array();

		if ( isset( $template->template_set['service_messages'] ) && is_array( $template->template_set['service_messages'] ) ) {
			foreach ( array_keys( $template->template_set['service_messages'] ) as $service ) {
				$variants[] = Labels::service( (string) $service );
			}
		}

		echo '<div class="pq-rule' . ( $template->enabled ? '' : ' pq-rule--off' ) . '">';
		echo '<span class="pq-rule-icon pq-rule-icon--template" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 14 14" focusable="false"><path d="M2.5 3h9M2.5 7h9M2.5 11h5" stroke="currentColor" stroke-width="1.6" fill="none" stroke-linecap="round"/></svg></span>';
		echo '<div class="pq-rule-body">';
		echo '<h3 class="pq-rule-name"><a href="' . esc_url( $edit_url ) . '">' . esc_html( $template->name ) . '</a> ';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::pill returns escaped markup.
		echo $template->enabled ? Ui::pill( 'ok', __( 'Offered in rules', 'pressqueue' ) ) : Ui::pill( 'idle', __( 'Hidden', 'pressqueue' ) );
		echo '</h3>';
		echo '<p class="pq-rule-sentence"><code class="pq-code">' . esc_html( (string) ( $template->template_set['default_message'] ?? '' ) ) . '</code> <span class="pq-muted">' . esc_html__( 'plus the link', 'pressqueue' ) . '</span></p>';

		$meta = array();
		if ( array() !== $variants ) {
			/* translators: %s: service names. */
			$meta[] = sprintf( __( 'Own message for %s', 'pressqueue' ), implode( ', ', $variants ) );
		}
		$meta[] = 0 === $used_by
			? __( 'Not used by any rule yet', 'pressqueue' )
			/* translators: %d: number of rules. */
			: sprintf( _n( 'Copied into %d rule', 'Copied into %d rules', $used_by, 'pressqueue' ), $used_by );
		/* translators: %s: relative time such as "5 minutes ago". */
		$meta[] = sprintf( __( 'Last changed %s', 'pressqueue' ), Ui::relative_time( $template->updated_at ) );
		echo '<p class="pq-rule-meta">' . esc_html( implode( ' · ', $meta ) ) . '</p>';
		echo '</div>';

		echo '<div class="pq-rule-actions">';
		echo '<a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'pressqueue' ) . '<span class="screen-reader-text"> ' . esc_html( $template->name ) . '</span></a>';
		Ui::toggle_form(
			'pressqueue_enable_template',
			'pressqueue_disable_template',
			array(
				'template_public_id' => $template->public_id,
				'lock_version'       => (string) $template->lock_version,
			),
			$template->enabled,
			sprintf(
				/* translators: %s: template name. */
				$template->enabled ? __( 'Stop offering %s in rules', 'pressqueue' ) : __( 'Offer %s in rules', 'pressqueue' ),
				$template->name
			)
		);
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render the create or edit form.
	 *
	 * @param TemplateRecord|null $template Template.
	 */
	private static function render_form( ?TemplateRecord $template ): void {
		$is_edit  = null !== $template;
		$set      = $is_edit ? $template->template_set : array(
			'default_message' => '{{ title }}',
			'schema_version'  => 1,
		);
		$json     = MessageComposer::pretty_json( $set );
		$channels = ( new ChannelRepository() )->find_all();
		$messages = isset( $set['service_messages'] ) && is_array( $set['service_messages'] ) ? $set['service_messages'] : array();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only error key.
		$error_key = isset( $_GET['pressqueue_form_error'] ) ? sanitize_key( wp_unslash( $_GET['pressqueue_form_error'] ) ) : '';
		$errors    = array(
			'name'    => __( 'Give the template a name of up to 191 characters.', 'pressqueue' ),
			'message' => __( 'Write a message of up to 2,000 characters.', 'pressqueue' ),
			'json'    => __( 'The template JSON under Advanced is not valid. Check for a missing comma, quote or bracket.', 'pressqueue' ),
			'invalid' => __( 'The template has a problem, for example an unknown setting in the JSON under Advanced. Check it and save again.', 'pressqueue' ),
		);
		$error     = $errors[ $error_key ] ?? '';

		Ui::page_start(
			$is_edit ? $template->name : __( 'New message template', 'pressqueue' ),
			array(
				'back_url'   => self::tab_url(),
				'back_label' => __( 'Message templates', 'pressqueue' ),
				'badge'      => $is_edit ? ( $template->enabled ? Ui::pill( 'ok', __( 'Offered in rules', 'pressqueue' ) ) : Ui::pill( 'idle', __( 'Hidden', 'pressqueue' ) ) ) : '',
			)
		);
		RulesController::render_notice();

		echo '<div class="pq-cols pq-editor">';
		$hidden = array();
		if ( $is_edit ) {
			$hidden['template_public_id'] = $template->public_id;
			$hidden['lock_version']       = (string) $template->lock_version;
		}
		Ui::form_start( 'pressqueue_save_template', 'pressqueue_save_template', $hidden, array( 'class' => 'pq-card pq-rule-form' ) );

		echo '<div class="pq-section"><div><label class="pq-label" for="pq-template-name">' . esc_html__( 'Template name', 'pressqueue' ) . '</label>';
		echo '<input id="pq-template-name" class="pq-field pq-field--narrow" type="text" name="name" maxlength="191" required value="' . esc_attr( $is_edit ? $template->name : '' ) . '" placeholder="' . esc_attr__( 'For example: Title and excerpt', 'pressqueue' ) . '" /></div></div>';

		echo '<div class="pq-section" id="pq-section-message"><h2 class="pq-section-heading">' . esc_html__( 'Message', 'pressqueue' ) . ' <small>' . esc_html__( 'The link to the post is added at the end.', 'pressqueue' ) . '</small></h2>';
		if ( '' !== $error ) {
			echo '<p class="pq-field-error" role="alert">' . esc_html( $error ) . '</p>';
		}
		MessageComposer::render_fields(
			array(
				'default_message'  => (string) ( $set['default_message'] ?? '' ),
				'service_messages' => $messages,
				'services'         => MessageComposer::services( $channels, $messages ),
			)
		);
		echo '<p class="pq-help">' . esc_html__( 'Custom field placeholders such as {{ meta:campaign }} work in rules that list that custom field.', 'pressqueue' ) . '</p>';
		echo '</div>';

		echo '<div class="pq-section"><details class="pq-more"><summary>' . esc_html__( 'Advanced', 'pressqueue' ) . '</summary><div class="pq-more-body pq-stack">';
		echo '<div><label class="pq-label" for="pq-template-json">' . esc_html__( 'Template as JSON', 'pressqueue' ) . '</label>';
		echo '<textarea id="pq-template-json" class="pq-field pq-code-field" name="template_set_json" rows="6" spellcheck="false" data-pq-template-json>' . esc_textarea( $json ) . '</textarea>';
		echo '<input type="hidden" name="template_set_json_original" value="' . esc_attr( $json ) . '" />';
		echo '<p class="pq-help">' . esc_html__( 'The fields above write this for you. If you change it here, it replaces them when you save.', 'pressqueue' ) . '</p></div>';
		if ( $is_edit ) {
			echo '<dl class="pq-facts"><dt>' . esc_html__( 'Template ID', 'pressqueue' ) . '</dt><dd><code class="pq-code">' . esc_html( $template->public_id ) . '</code></dd><dt>' . esc_html__( 'Version', 'pressqueue' ) . '</dt><dd>' . esc_html( (string) $template->version ) . '</dd></dl>';
		}
		echo '</div></details></div>';

		echo '<div class="pq-actions-bar"><button type="submit" class="button button-primary">' . esc_html__( 'Save template', 'pressqueue' ) . '</button>';
		echo '<span class="pq-muted pq-small">' . esc_html( $is_edit ? __( 'Rules that already copied this template keep their own message.', 'pressqueue' ) : __( 'New templates start hidden. Switch them on to offer them in rules.', 'pressqueue' ) ) . '</span></div>';
		Ui::form_end();

		echo '<div class="pq-stack pq-sticky pq-editor-side">';
		MessageComposer::render_preview( MessageComposer::preview_posts( array() ), MessageComposer::preview_channels( $channels ) );
		echo '</div>';
		echo '</div>';

		Ui::page_end();
	}

	/**
	 * Return the form error key for a failed save.
	 *
	 * @param \Throwable $throwable Failure.
	 * @return string
	 */
	private static function form_error_key( \Throwable $throwable ): string {
		if ( $throwable instanceof RuleFormException ) {
			return 'json';
		}

		$message = $throwable->getMessage();

		if ( 'Label must be a non-empty bounded string.' === $message ) {
			return 'name';
		}

		if ( in_array( $message, array( 'Template message must be a non-empty bounded string.', 'Template set default message is required.' ), true ) ) {
			return 'message';
		}

		return 'invalid';
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
		} catch ( \Throwable $throwable ) {
			self::redirect( 'template_error', SafeOperatorError::redirect_detail( $throwable ) );
		}

		self::redirect( $message );
	}

	/**
	 * Return a Message templates tab URL.
	 *
	 * @param array<string,scalar> $args Extra args.
	 * @return string
	 */
	private static function tab_url( array $args = array() ): string {
		return Ui::url( self::RULES_SLUG, array_merge( array( 'tab' => 'templates' ), $args ) );
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
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : $fallback;
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
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? (int) sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : $fallback;
	}

	/**
	 * Redirect back to the templates tab or the Rules list.
	 *
	 * @param string      $message Message key.
	 * @param string|null $detail Optional safe detail.
	 * @param string      $template_public_id Template to reopen on error.
	 * @param string      $form_error Form error key.
	 * @param bool        $templates_tab Whether to land on the templates tab.
	 * @return never
	 */
	private static function redirect( string $message, ?string $detail = null, string $template_public_id = '', string $form_error = '', bool $templates_tab = true ): never {
		$args = array(
			'page'               => self::RULES_SLUG,
			'pressqueue_message' => $message,
		);

		if ( $templates_tab ) {
			$args['tab'] = 'templates';
		}

		if ( '' !== $template_public_id && PublicIds::is_valid( $template_public_id ) ) {
			$args['template'] = $template_public_id;
		} elseif ( 'template_error' === $message && '' !== $form_error ) {
			$args['template'] = 'new';
		}

		if ( '' !== $form_error ) {
			$args['pressqueue_form_error'] = sanitize_key( $form_error );
		}

		if ( null !== $detail && '' !== $detail ) {
			$args['pressqueue_detail'] = rawurlencode( $detail );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Redirect to the rule editor.
	 *
	 * @param string      $rule_public_id Rule public ID.
	 * @param string      $message Message key.
	 * @param string|null $detail Optional detail.
	 * @return never
	 */
	private static function redirect_to_rule( string $rule_public_id, string $message, ?string $detail = null ): never {
		$args = array(
			'page'               => self::RULES_SLUG,
			'view'               => 'edit',
			'rule'               => $rule_public_id,
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
}
