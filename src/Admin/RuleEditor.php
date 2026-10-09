<?php
/**
 * Rule editor view and form input.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\PublicIds;
use PressQueue\Storage\Record\ChannelRecord;
use PressQueue\Storage\Record\RuleRecord;
use PressQueue\Storage\Repository\ChannelRepository;
use PressQueue\Storage\Repository\TemplateRepository;
use PressQueue\Storage\RuleDocuments;
use PressQueue\Storage\StaleRecordException;
use PressQueue\Storage\StorageEnums;
use PressQueue\Storage\ValidationException;

/**
 * Renders the rule editor and turns its fields into rule documents.
 */
final class RuleEditor {
	private const DRAFT_KEY = 'pressqueue_rule_draft_';

	/**
	 * Choices in the "When" section mapped to stored triggers.
	 */
	private const WHEN_CHOICES = array( 'publish', 'update', 'bulk', 'repost' );

	/**
	 * Render the editor for a new or existing rule.
	 *
	 * @param RuleRecord|null $rule Rule, or null for a new rule.
	 * @param string          $effect include or exclude.
	 */
	public static function render( ?RuleRecord $rule, string $effect ): void {
		$channels   = ( new ChannelRepository() )->find_all();
		$draft      = self::take_draft( $rule, $effect );
		$state      = null !== $draft ? array_merge( self::form_state( $rule, $effect, $channels ), $draft['state'] ) : self::form_state( $rule, $effect, $channels );
		$error      = null !== $draft ? $draft['error'] : array(
			'section' => '',
			'message' => '',
		);
		$is_exclude = 'exclude' === $effect;

		if ( null !== $rule ) {
			$title = $rule->name;
		} else {
			$title = $is_exclude ? __( 'New "never share" rule', 'pressqueue' ) : __( 'New sharing rule', 'pressqueue' );
		}

		Ui::page_start(
			$title,
			array(
				'back_url'   => RulesController::url(),
				'back_label' => __( 'Rules', 'pressqueue' ),
				'badge'      => null === $rule ? Ui::pill( 'idle', __( 'Off', 'pressqueue' ) ) : RulesController::status_pill( $rule ),
			)
		);
		RulesController::render_notice();

		if ( 'top' === $error['section'] ) {
			Ui::notice( 'error', $error['message'] );
		}

		if ( null !== $rule && null !== $rule->invalid_reason ) {
			echo '<div class="pq-callout pq-callout--bad pq-editor-callout"><p><strong>' . esc_html__( 'PressQueue cannot read this rule.', 'pressqueue' ) . '</strong> ' . esc_html__( 'Until it is fixed, it stops every post it could affect. Check the conditions and the JSON under Advanced, then save.', 'pressqueue' ) . '</p></div>';
		}

		echo '<div class="pq-cols pq-editor">';

		$hidden = array(
			'rule_form' => '2',
			'effect'    => $effect,
		);
		if ( null !== $rule ) {
			$hidden['rule_public_id'] = $rule->public_id;
			$hidden['lock_version']   = (string) $rule->lock_version;
		}

		Ui::form_start(
			'pressqueue_save_rule',
			'pressqueue_save_rule',
			$hidden,
			array(
				'id'             => 'pq-rule-form',
				'class'          => 'pq-card pq-rule-form',
				'data-pq-effect' => $effect,
			)
		);

		self::render_name_section( $state, $error, $effect );
		self::render_when_section( $rule, $state, $error, $is_exclude );
		self::render_conditions_section( $state, $error, $effect, $channels );

		if ( $is_exclude ) {
			echo '<div class="pq-section"><h2 class="pq-section-heading">' . esc_html__( 'Where', 'pressqueue' ) . '</h2><p class="pq-muted pq-flush">' . esc_html__( '"Never share" rules apply to every channel.', 'pressqueue' ) . '</p></div>';
		} else {
			self::render_where_section( $state, $error, $channels );
			self::render_message_section( $rule, $state, $error, $channels );
			self::render_timing_section( $state );
			self::render_image_section( $state );
		}

		self::render_advanced_section( $rule, $state, $error, $is_exclude );
		self::render_save_bar( $rule );
		Ui::form_end();

		echo '<div class="pq-stack pq-sticky pq-editor-side">';
		if ( $is_exclude ) {
			echo '<div class="pq-card pq-card--pad pq-stack">';
			echo '<div class="pq-row">' . RulesController::shield_icon() . '<h2>' . esc_html__( 'How "never share" works', 'pressqueue' ) . '</h2></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG markup.
			echo '<p class="pq-flush">' . esc_html__( 'A post that matches is never shared, by any rule, to any channel. This also covers Share old posts and sharing a post again.', 'pressqueue' ) . '</p>';
			echo '<p class="pq-flush pq-muted">' . esc_html__( 'If PressQueue cannot check this rule for a post, it stops that post to be safe.', 'pressqueue' ) . '</p>';
			echo '</div>';
		} else {
			MessageComposer::render_preview(
				MessageComposer::preview_posts( self::preview_post_types( $state ) ),
				MessageComposer::preview_channels( $channels ),
				__( 'A quick preview of the message. The test after saving shows exactly what PressQueue would send.', 'pressqueue' )
			);
		}
		echo '<div class="pq-card pq-card--pad pq-stack">';
		echo '<h2>' . esc_html__( 'Before it goes on', 'pressqueue' ) . '</h2>';
		echo '<p class="pq-flush pq-muted">' . esc_html(
			sprintf(
				/* translators: %d: number of posts. */
				__( 'New and changed rules start switched off. Save and test checks the rule against your %d most recent posts without sharing anything. When the result looks right, you switch it on.', 'pressqueue' ),
				RuleTestView::DEFAULT_COUNT
			)
		) . '</p>';
		echo '</div>';
		echo '</div>';

		echo '</div>';

		if ( null !== $rule && ! $is_exclude ) {
			TemplateController::render_adopt_form( $rule );
		}

		if ( null !== $rule && ! $rule->enabled ) {
			Ui::form_start(
				'pressqueue_delete_rule',
				'pressqueue_delete_rule',
				array(
					'rule_public_id' => $rule->public_id,
					'lock_version'   => (string) $rule->lock_version,
				),
				array( 'id' => 'pq-delete-rule-form' )
			);
			Ui::form_end();
		}

		Ui::page_end();
	}

	/**
	 * Build rule repository input from the submitted editor.
	 *
	 * @param RuleRecord|null $current Existing rule.
	 * @return array<string,mixed>
	 */
	public static function input_from_request( ?RuleRecord $current = null ): array {
		$modern = '2' === RulesController::post_text( 'rule_form' );

		if ( $modern ) {
			$effect = null === $current ? ( 'exclude' === RulesController::post_text( 'effect' ) ? 'exclude' : 'include' ) : $current->effect;
		} else {
			$effect = RulesController::post_text( 'effect', 'include' );
		}

		$allowlist = self::custom_fields_from_request( $current );
		$input     = array(
			'condition_tree'         => self::decode_object( self::post_raw( 'condition_tree_json' ), 'conditions' ),
			'custom_field_allowlist' => $allowlist,
			'destination_set'        => self::destinations_from_request( $effect, $modern ),
			'effect'                 => $effect,
			'media_policy'           => self::mode_from_request( 'media_policy', $current, $effect, $modern ),
			'name'                   => RulesController::post_text( 'name' ),
			'priority'               => isset( $_POST['priority'] ) ? RulesController::post_int( 'priority', 100 ) : ( null === $current ? 100 : $current->priority ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller checks the nonce.
			'schedule_policy'        => self::mode_from_request( 'schedule_policy', $current, $effect, $modern ),
			'template_set'           => self::template_set_from_request( $current, $effect, $modern ),
			'triggers'               => self::triggers_from_request( $current, $effect, $modern ),
		);

		if ( '' === trim( $input['name'] ) ) {
			RuleFormException::raise( 'name', __( 'Give the rule a name.', 'pressqueue' ) );
		}

		if ( null !== $current && null !== $current->source_template_public_id && null !== $current->template_set && self::same_template_set( $input['template_set'], $current->template_set, $allowlist ) ) {
			$input['source_template_public_id'] = $current->source_template_public_id;
			$input['source_template_version']   = $current->source_template_version;
		}

		$adopted = strtoupper( RulesController::post_text( 'adopted_template_public_id' ) );
		if ( 'include' === $effect && PublicIds::is_valid( $adopted ) ) {
			$template = ( new TemplateRepository() )->find_by_public_id( $adopted );
			if ( null !== $template && $template->enabled && self::same_template_set( $input['template_set'], $template->template_set, $allowlist ) ) {
				$input['source_template_public_id'] = $template->public_id;
				$input['source_template_version']   = $template->version;
			}
		}

		return $input;
	}

	/**
	 * Return repository changes, leaving out a template snapshot the rule does not have yet when nothing changed.
	 *
	 * @param RuleRecord          $current Current rule.
	 * @param array<string,mixed> $input Input from input_from_request().
	 * @return array<string,mixed>
	 */
	public static function changes_for_update( RuleRecord $current, array $input ): array {
		if ( ! $current->has_template_snapshot && null !== $current->template_set && isset( $input['template_set'] ) && is_array( $input['template_set'] ) && ! isset( $input['source_template_public_id'] ) ) {
			if ( self::same_template_set( $input['template_set'], $current->template_set, $current->custom_field_allowlist ) ) {
				unset( $input['template_set'] );
			}
		}

		return $input;
	}

	/**
	 * Keep the submitted fields so the editor can show them again with the error.
	 *
	 * @param RuleRecord|null $current Existing rule.
	 * @param string          $effect Effect.
	 * @param \Throwable      $error Failure.
	 */
	public static function store_draft( ?RuleRecord $current, string $effect, \Throwable $error ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Called from a nonce-checked handler.
		$when = isset( $_POST['when'] ) && is_array( $_POST['when'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['when'] ) ) : array();
		$ids  = isset( $_POST['destination_channel_ids'] ) && is_array( $_POST['destination_channel_ids'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['destination_channel_ids'] ) ) : array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is sanitized below.
		$variants = isset( $_POST['message_service'] ) && is_array( $_POST['message_service'] ) ? wp_unslash( $_POST['message_service'] ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$service_messages = array();
		foreach ( $variants as $service => $message ) {
			if ( is_string( $service ) && is_string( $message ) ) {
				$service_messages[ sanitize_key( $service ) ] = wp_strip_all_tags( $message );
			}
		}

		$state = array(
			'name'                   => RulesController::post_text( 'name' ),
			'priority'               => RulesController::post_text( 'priority', '100' ),
			'when'                   => array_fill_keys( array_values( array_intersect( self::WHEN_CHOICES, $when ) ), true ),
			'add_immediate'          => '1' === RulesController::post_text( 'when_add_immediate' ),
			'condition_json'         => self::post_raw( 'condition_tree_json' ),
			'custom_fields'          => RulesController::post_text( 'custom_fields' ),
			'destinations'           => array_values( array_filter( $ids, array( PublicIds::class, 'is_valid' ) ) ),
			'message_default'        => wp_strip_all_tags( self::post_raw( 'message_default' ) ),
			'service_messages'       => $service_messages,
			'template_json'          => self::post_raw( 'template_set_json' ),
			'template_json_original' => self::post_raw( 'template_set_json_original' ),
			'schedule'               => sanitize_key( RulesController::post_text( 'schedule_policy_mode' ) ),
			'media'                  => sanitize_key( RulesController::post_text( 'media_policy_mode' ) ),
			'adopted_template'       => RulesController::post_text( 'adopted_template_public_id' ),
		);

		if ( 'exclude' === $effect ) {
			unset( $state['destinations'], $state['message_default'], $state['service_messages'], $state['template_json'], $state['template_json_original'], $state['schedule'], $state['media'] );
		}

		set_transient(
			self::DRAFT_KEY . get_current_user_id(),
			array(
				'rule'   => null === $current ? '' : $current->public_id,
				'effect' => $effect,
				'state'  => $state,
				'error'  => self::error_for( $error ),
			),
			10 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Return the starting condition tree for a new rule.
	 *
	 * @param string $effect Effect.
	 * @return array<string,mixed>
	 */
	public static function default_condition_tree( string $effect ): array {
		$condition = 'exclude' === $effect
			? array(
				'condition' => 'taxonomy',
				'operator'  => 'has_any',
				'type'      => 'condition',
				'value'     => array(
					'include_ancestors' => true,
					'taxonomy'          => 'category',
					'terms'             => array(),
				),
			)
			: array(
				'condition' => 'post_type',
				'operator'  => 'equals',
				'type'      => 'condition',
				'value'     => 'post',
			);

		return array(
			'children'       => array( $condition ),
			'mode'           => 'ALL',
			'schema_version' => 1,
			'type'           => 'group',
		);
	}

	/**
	 * Render the name section.
	 *
	 * @param array<string,mixed>                  $state Form state.
	 * @param array{section:string,message:string} $error Error.
	 * @param string                               $effect Effect.
	 */
	private static function render_name_section( array $state, array $error, string $effect ): void {
		echo '<div class="pq-section" id="pq-section-name">';
		self::render_section_error( 'name', $error );
		echo '<div><label class="pq-label" for="pq-rule-name">' . esc_html__( 'Rule name', 'pressqueue' ) . '</label>';
		$placeholder = 'exclude' === $effect ? __( 'For example: Keep private posts off social media', 'pressqueue' ) : __( 'For example: Share new recipes', 'pressqueue' );
		echo '<input id="pq-rule-name" class="pq-field pq-field--narrow" type="text" name="name" maxlength="191" required value="' . esc_attr( (string) $state['name'] ) . '" placeholder="' . esc_attr( $placeholder ) . '"' . self::invalid_attr( 'name', $error ) . ' /></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- invalid_attr returns a fixed attribute string.
		echo '</div>';
	}

	/**
	 * Render the "When" section.
	 *
	 * @param RuleRecord|null                      $rule Rule.
	 * @param array<string,mixed>                  $state Form state.
	 * @param array{section:string,message:string} $error Error.
	 * @param bool                                 $is_exclude Whether this is a never-share rule.
	 */
	private static function render_when_section( ?RuleRecord $rule, array $state, array $error, bool $is_exclude ): void {
		echo '<div class="pq-section" id="pq-section-when">';

		if ( $is_exclude ) {
			echo '<h2 class="pq-section-heading">' . esc_html__( 'When', 'pressqueue' ) . '</h2>';
			echo '<p class="pq-muted pq-flush">' . esc_html__( '"Never share" rules always apply: when a post is published or updated, and when posts are picked in Share old posts.', 'pressqueue' ) . '</p>';
			echo '</div>';
			return;
		}

		$when      = isset( $state['when'] ) && is_array( $state['when'] ) ? $state['when'] : array();
		$triggers  = null === $rule ? array() : ( $rule->triggers['triggers'] ?? array() );
		$scheduled = in_array( 'scheduled_publication', $triggers, true ) && ! in_array( 'first_publish', $triggers, true );

		echo '<h2 class="pq-section-heading" id="pq-when-title">' . esc_html__( 'When', 'pressqueue' ) . ' <small>' . esc_html__( 'What should start a share?', 'pressqueue' ) . '</small></h2>';
		self::render_section_error( 'when', $error );
		echo '<input type="hidden" name="when_controls_present" value="1" />';
		echo '<div class="pq-checks" role="group" aria-labelledby="pq-when-title">';

		$choices = array(
			'publish' => array( __( 'A post is published', 'pressqueue' ), __( 'Includes scheduled posts when they go live.', 'pressqueue' ) ),
			'update'  => array( __( 'A published post is updated', 'pressqueue' ), __( 'Shares it again after a real content change. Saving without changes does not count.', 'pressqueue' ) ),
			'bulk'    => array( __( 'I pick old posts in Share old posts', 'pressqueue' ), __( 'Nothing is shared until you review and confirm the posts there.', 'pressqueue' ) ),
			'repost'  => array( __( 'I pick shared posts to share again', 'pressqueue' ), __( 'Lets you share a post a second time from Share old posts.', 'pressqueue' ) ),
		);

		foreach ( $choices as $key => $choice ) {
			$help = $choice[1];
			if ( 'publish' === $key && $scheduled ) {
				$help = __( 'Right now this rule only runs when a scheduled post goes live. Posts published right away are not shared by it.', 'pressqueue' );
			}

			echo '<label><input type="checkbox" name="when[]" value="' . esc_attr( $key ) . '" ' . checked( ! empty( $when[ $key ] ), true, false ) . ' /><span><span class="pq-check-title">' . esc_html( $choice[0] ) . '</span><span class="pq-muted">' . esc_html( $help ) . '</span></span></label>';

			if ( 'publish' === $key && $scheduled ) {
				echo '<label class="pq-check-sub"><input type="checkbox" name="when_add_immediate" value="1" ' . checked( ! empty( $state['add_immediate'] ), true, false ) . ' /><span><span class="pq-check-title">' . esc_html__( 'Also share posts that are published right away', 'pressqueue' ) . '</span></span></label>';
			}
		}

		echo '</div></div>';
	}

	/**
	 * Render the "Which posts" section.
	 *
	 * @param array<string,mixed>                  $state Form state.
	 * @param array{section:string,message:string} $error Error.
	 * @param string                               $effect Effect.
	 * @param array<int,ChannelRecord>             $channels Channels.
	 */
	private static function render_conditions_section( array $state, array $error, string $effect, array $channels ): void {
		echo '<div class="pq-section" id="pq-section-conditions">';
		echo '<h2 class="pq-section-heading">' . esc_html__( 'Which posts', 'pressqueue' ) . ' <small>' . esc_html( 'exclude' === $effect ? __( 'Posts that match are never shared.', 'pressqueue' ) : __( 'Only posts that match are shared.', 'pressqueue' ) ) . '</small></h2>';
		self::render_section_error( 'conditions', $error );
		ConditionBuilderController::render_builder( 'pq-condition-json', (string) $state['condition_json'], $effect, new RuleSummary( $channels ) );
		echo '</div>';
	}

	/**
	 * Render the "Where" section.
	 *
	 * @param array<string,mixed>                  $state Form state.
	 * @param array{section:string,message:string} $error Error.
	 * @param array<int,ChannelRecord>             $channels Channels.
	 */
	private static function render_where_section( array $state, array $error, array $channels ): void {
		$selected = isset( $state['destinations'] ) && is_array( $state['destinations'] ) ? $state['destinations'] : array();
		$known    = array();

		echo '<div class="pq-section" id="pq-section-where">';
		echo '<h2 class="pq-section-heading" id="pq-where-title">' . esc_html__( 'Where', 'pressqueue' ) . ' <small>' . esc_html__( 'Which channels get the post?', 'pressqueue' ) . '</small></h2>';
		self::render_section_error( 'where', $error );
		echo '<input type="hidden" name="destination_controls_present" value="1" />';

		if ( array() === $channels ) {
			echo '<div class="pq-callout pq-callout--warn"><p>' . esc_html__( 'No channels yet.', 'pressqueue' ) . ' <a href="' . esc_url( Ui::url( Ui::PAGE_CHANNELS ) ) . '">' . esc_html__( 'Connect Buffer and switch on your channels', 'pressqueue' ) . '</a></p></div>';
		}

		$disconnected = array();
		foreach ( ( new \PressQueue\Storage\Repository\ConnectionRepository() )->find_all() as $connection ) {
			if ( 'disconnected' === $connection->connection_status ) {
				$disconnected[] = $connection->public_id;
			}
		}

		echo '<div class="pq-dest" role="group" aria-labelledby="pq-where-title">';
		foreach ( $channels as $channel ) {
			$known[]     = $channel->public_id;
			$checked     = in_array( $channel->public_id, $selected, true );
			$unsupported = 'available' !== $channel->availability;

			// Channels of a disconnected Buffer account stay hidden unless this rule still points at them.
			if ( ( $unsupported || in_array( $channel->connection_public_id, $disconnected, true ) ) && ! $checked ) {
				continue;
			}

			$limit = \PressQueue\Provider\Capability\DeliveryCapability::text_limit( $channel->capabilities );
			$name  = ActivitySummary::channel_display_name( $channel );
			echo '<label class="pq-dest-chip' . ( $channel->enabled ? '' : ' is-off' ) . '">';
			echo '<input type="checkbox" name="destination_channel_ids[]" value="' . esc_attr( $channel->public_id ) . '" data-service="' . esc_attr( $channel->service ) . '" data-name="' . esc_attr( $name ) . '" data-limit="' . esc_attr( (string) ( $limit > 0 ? $limit : MessageComposer::DEFAULT_LIMIT ) ) . '" ' . checked( $checked, true, false ) . ' />';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- service_badge returns escaped markup.
			echo Ui::service_badge( $channel->service, 'sm' ) . '<span>' . esc_html( $name ) . '</span><span class="screen-reader-text"> (' . esc_html( Labels::service( $channel->service ) ) . ')</span>';
			if ( $unsupported ) {
				echo ' <span class="pq-muted pq-small">' . esc_html__( '(PressQueue cannot post here)', 'pressqueue' ) . '</span>';
			} elseif ( ! $channel->enabled ) {
				echo ' <span class="pq-muted pq-small">' . esc_html__( '(off in Channels)', 'pressqueue' ) . '</span>';
			}
			echo '</label>';
		}

		foreach ( $selected as $public_id ) {
			if ( in_array( $public_id, $known, true ) ) {
				continue;
			}

			echo '<label class="pq-dest-chip is-missing"><input type="checkbox" name="destination_channel_ids[]" value="' . esc_attr( (string) $public_id ) . '" checked="checked" /><span>' . esc_html__( 'Missing channel', 'pressqueue' ) . '</span> <span class="pq-muted pq-small">' . esc_html__( '(no longer in Buffer, untick to remove)', 'pressqueue' ) . '</span></label>';
		}
		echo '</div>';

		$has_off = array() !== array_filter(
			$channels,
			static function ( ChannelRecord $channel ): bool {
				return ! $channel->enabled && 'available' === $channel->availability;
			}
		);
		if ( $has_off ) {
			echo '<p class="pq-help">' . esc_html__( 'A channel that is off is skipped until you switch it on in', 'pressqueue' ) . ' <a href="' . esc_url( Ui::url( Ui::PAGE_CHANNELS ) ) . '">' . esc_html__( 'Channels', 'pressqueue' ) . '</a>.</p>';
		}
		echo '</div>';
	}

	/**
	 * Render the message section.
	 *
	 * @param RuleRecord|null                      $rule Rule.
	 * @param array<string,mixed>                  $state Form state.
	 * @param array{section:string,message:string} $error Error.
	 * @param array<int,ChannelRecord>             $channels Channels.
	 */
	private static function render_message_section( ?RuleRecord $rule, array $state, array $error, array $channels ): void {
		$service_messages = isset( $state['service_messages'] ) && is_array( $state['service_messages'] ) ? $state['service_messages'] : array();

		echo '<div class="pq-section" id="pq-section-message">';
		echo '<h2 class="pq-section-heading">' . esc_html__( 'Message', 'pressqueue' ) . ' <small>' . esc_html__( 'The link to the post is added at the end.', 'pressqueue' ) . '</small></h2>';
		self::render_section_error( 'message', $error );
		MessageComposer::render_fields(
			array(
				'default_message'  => (string) $state['message_default'],
				'service_messages' => $service_messages,
				'services'         => MessageComposer::services( $channels, $service_messages ),
				'error'            => 'message' === $error['section'] ? $error['message'] : '',
			)
		);
		TemplateController::render_picker( $rule, (string) ( $state['adopted_template'] ?? '' ) );
		echo '</div>';
	}

	/**
	 * Render the timing section.
	 *
	 * @param array<string,mixed> $state Form state.
	 */
	private static function render_timing_section( array $state ): void {
		echo '<div class="pq-section" id="pq-section-timing">';
		echo '<h2 class="pq-section-heading" id="pq-timing-title">' . esc_html__( 'Timing', 'pressqueue' ) . ' <small>' . esc_html__( 'When should Buffer post it?', 'pressqueue' ) . '</small></h2>';
		echo '<div class="pq-choices pq-choices--two" role="radiogroup" aria-labelledby="pq-timing-title">';
		foreach ( Labels::schedules() as $mode => $schedule ) {
			$help = $schedule['help'];
			if ( 'queue_end' === $mode ) {
				$help .= ' ' . __( 'Recommended.', 'pressqueue' );
			}
			echo '<label class="pq-choice"><input type="radio" name="schedule_policy_mode" value="' . esc_attr( $mode ) . '" ' . checked( (string) $state['schedule'], $mode, false ) . ' /><span><b>' . esc_html( $schedule['label'] ) . '</b><span class="pq-choice-help">' . esc_html( $help ) . '</span></span></label>';
		}
		echo '</div></div>';
	}

	/**
	 * Render the image section.
	 *
	 * @param array<string,mixed> $state Form state.
	 */
	private static function render_image_section( array $state ): void {
		$modes   = Labels::media_modes();
		$current = isset( $modes[ (string) $state['media'] ] ) ? (string) $state['media'] : 'none';

		echo '<div class="pq-section" id="pq-section-image">';
		echo '<h2 class="pq-section-heading"><label for="pq-media-mode">' . esc_html__( 'Image', 'pressqueue' ) . '</label></h2>';
		echo '<select id="pq-media-mode" class="pq-field pq-field--narrow" name="media_policy_mode" data-pq-media aria-describedby="pq-media-help">';
		foreach ( $modes as $mode => $media ) {
			echo '<option value="' . esc_attr( $mode ) . '" data-help="' . esc_attr( $media['help'] ) . '" ' . selected( $current, $mode, false ) . '>' . esc_html( $media['label'] ) . '</option>';
		}
		echo '</select>';
		echo '<p class="pq-help" id="pq-media-help" data-pq-media-help>' . esc_html( $modes[ $current ]['help'] ) . '</p>';
		echo '</div>';
	}

	/**
	 * Render the Advanced section.
	 *
	 * @param RuleRecord|null                      $rule Rule.
	 * @param array<string,mixed>                  $state Form state.
	 * @param array{section:string,message:string} $error Error.
	 * @param bool                                 $is_exclude Whether this is a never-share rule.
	 */
	private static function render_advanced_section( ?RuleRecord $rule, array $state, array $error, bool $is_exclude ): void {
		$open = in_array( $error['section'], array( 'advanced', 'custom_fields', 'priority' ), true );

		echo '<div class="pq-section" id="pq-section-advanced">';
		echo '<details class="pq-more" id="pq-advanced"' . ( $open ? ' open' : '' ) . '><summary>' . esc_html__( 'Advanced', 'pressqueue' ) . '</summary>';
		echo '<div class="pq-more-body pq-stack">';
		self::render_section_error( 'advanced', $error );
		self::render_section_error( 'custom_fields', $error );
		self::render_section_error( 'priority', $error );

		echo '<div class="pq-adv-row"><label class="pq-label" for="pq-rule-priority">' . esc_html__( 'Order', 'pressqueue' ) . '</label>';
		echo '<input id="pq-rule-priority" class="pq-field pq-field--order" type="number" step="1" name="priority" value="' . esc_attr( (string) $state['priority'] ) . '" aria-describedby="pq-priority-help" />';
		echo '<p class="pq-help" id="pq-priority-help">' . esc_html__( 'Lower numbers run first. When several sharing rules match the same channel, the one with the lowest number is used. "Never share" rules always win.', 'pressqueue' ) . '</p></div>';

		echo '<div><label class="pq-label" for="pq-custom-fields">' . esc_html__( 'Custom fields used in this rule', 'pressqueue' ) . '</label>';
		echo '<input type="hidden" name="custom_fields_present" value="1" />';
		echo '<input id="pq-custom-fields" class="pq-field pq-field--narrow" type="text" name="custom_fields" value="' . esc_attr( (string) $state['custom_fields'] ) . '" placeholder="' . esc_attr__( 'For example: campaign, rating', 'pressqueue' ) . '" data-pq-custom-fields aria-describedby="pq-custom-fields-help" />';
		echo '<p class="pq-help" id="pq-custom-fields-help">' . esc_html__( 'Separate names with commas. Conditions and messages can only read the custom fields listed here. In a message, use {{ meta:name }}.', 'pressqueue' ) . '</p></div>';

		ConditionBuilderController::render_json_field( 'pq-condition-json', 'condition_tree_json', (string) $state['condition_json'] );

		if ( ! $is_exclude ) {
			echo '<div><label class="pq-label" for="pq-template-json">' . esc_html__( 'Messages as JSON', 'pressqueue' ) . '</label>';
			echo '<textarea id="pq-template-json" class="pq-field pq-code-field" name="template_set_json" rows="6" spellcheck="false" data-pq-template-json aria-describedby="pq-template-json-help">' . esc_textarea( (string) $state['template_json'] ) . '</textarea>';
			echo '<input type="hidden" name="template_set_json_original" value="' . esc_attr( (string) $state['template_json_original'] ) . '" />';
			echo '<p class="pq-help" id="pq-template-json-help">' . esc_html__( 'The message fields above write this for you. If you change it here, it replaces the message fields when you save.', 'pressqueue' ) . '</p></div>';
		}

		if ( null !== $rule ) {
			echo '<div><h3 class="pq-label">' . esc_html__( 'Technical details', 'pressqueue' ) . '</h3><dl class="pq-facts">';
			echo '<dt>' . esc_html__( 'Rule ID', 'pressqueue' ) . '</dt><dd><code class="pq-code">' . esc_html( $rule->public_id ) . '</code></dd>';
			echo '<dt>' . esc_html__( 'Version', 'pressqueue' ) . '</dt><dd>' . esc_html( (string) $rule->version ) . '</dd>';
			echo '<dt>' . esc_html__( 'Last tested version', 'pressqueue' ) . '</dt><dd>' . esc_html( null === $rule->last_previewed_version ? __( 'Not tested', 'pressqueue' ) : (string) $rule->last_previewed_version ) . '</dd>';
			if ( null !== $rule->source_template_public_id ) {
				echo '<dt>' . esc_html__( 'Template source', 'pressqueue' ) . '</dt><dd><code class="pq-code">' . esc_html( $rule->source_template_public_id . ' v' . (string) $rule->source_template_version ) . '</code></dd>';
			}
			echo '<dt>' . esc_html__( 'Created', 'pressqueue' ) . '</dt><dd>' . esc_html( Ui::local_datetime( $rule->created_at ) ) . '</dd>';
			echo '</dl></div>';
		}

		echo '</div></details></div>';
	}

	/**
	 * Render the save bar.
	 *
	 * @param RuleRecord|null $rule Rule.
	 */
	private static function render_save_bar( ?RuleRecord $rule ): void {
		echo '<div class="pq-actions-bar pq-savebar">';
		if ( null === $rule || ! $rule->enabled ) {
			echo '<button type="submit" class="button button-primary" name="save_intent" value="test">' . esc_html__( 'Save and test', 'pressqueue' ) . '</button>';
			echo '<button type="submit" class="button" name="save_intent" value="save">' . esc_html__( 'Save', 'pressqueue' ) . '</button>';
		} else {
			echo '<button type="submit" class="button button-primary" name="save_intent" value="save">' . esc_html__( 'Save', 'pressqueue' ) . '</button>';
		}

		echo '<span class="pq-muted pq-small pq-grow">' . esc_html( null === $rule ? __( 'New rules start switched off.', 'pressqueue' ) : __( 'Changing what a rule does switches it off until it is tested again.', 'pressqueue' ) ) . '</span>';

		if ( null !== $rule && ! $rule->enabled ) {
			echo '<button type="submit" form="pq-delete-rule-form" class="button-link pq-danger" data-pq-confirm="' . esc_attr( sprintf( /* translators: %s: rule name. */ __( 'Delete the rule "%s"? Past activity stays, but it will no longer show this rule\'s name. This cannot be undone.', 'pressqueue' ), $rule->name ) ) . '">' . esc_html__( 'Delete rule', 'pressqueue' ) . '</button>';
		}
		echo '</div>';
	}

	/**
	 * Render the error for one section.
	 *
	 * @param string                               $section Section key.
	 * @param array{section:string,message:string} $error Error.
	 */
	private static function render_section_error( string $section, array $error ): void {
		if ( $section !== $error['section'] || '' === $error['message'] ) {
			return;
		}

		echo '<p class="pq-field-error" id="' . esc_attr( 'pq-error-' . $section ) . '" role="alert">' . esc_html( $error['message'] ) . '</p>';
	}

	/**
	 * Return aria-invalid attributes for a section's main field.
	 *
	 * @param string                               $section Section key.
	 * @param array{section:string,message:string} $error Error.
	 * @return string
	 */
	private static function invalid_attr( string $section, array $error ): string {
		return $section === $error['section'] ? ' aria-invalid="true" aria-describedby="pq-error-' . $section . '"' : '';
	}

	/**
	 * Return the starting form state.
	 *
	 * @param RuleRecord|null          $rule Rule.
	 * @param string                   $effect Effect.
	 * @param array<int,ChannelRecord> $channels Channels.
	 * @return array<string,mixed>
	 */
	private static function form_state( ?RuleRecord $rule, string $effect, array $channels ): array {
		if ( null === $rule ) {
			$template = array(
				'default_message' => '{{ title }}',
				'schema_version'  => 1,
			);
			$selected = array();
			foreach ( $channels as $channel ) {
				if ( $channel->enabled && 'available' === $channel->availability ) {
					$selected[] = $channel->public_id;
				}
			}

			return array(
				'name'                   => '',
				'priority'               => '100',
				'when'                   => array( 'publish' => true ),
				'add_immediate'          => false,
				'condition_json'         => MessageComposer::pretty_json( self::default_condition_tree( $effect ) ),
				'custom_fields'          => '',
				'destinations'           => $selected,
				'message_default'        => '{{ title }}',
				'service_messages'       => array(),
				'template_json'          => MessageComposer::pretty_json( $template ),
				'template_json_original' => MessageComposer::pretty_json( $template ),
				'schedule'               => 'queue_end',
				'media'                  => 'featured_image_optional',
				'adopted_template'       => '',
			);
		}

		$triggers      = $rule->triggers['triggers'] ?? array();
		$template      = is_array( $rule->template_set ) ? $rule->template_set : array(
			'default_message' => (string) ( $rule->inline_template['message'] ?? '' ),
			'schema_version'  => 1,
		);
		$template_json = MessageComposer::pretty_json( $template );
		$destinations  = array();
		foreach ( ( $rule->destination_set['destinations'] ?? array() ) as $destination ) {
			if ( is_array( $destination ) && isset( $destination['channel_public_id'] ) ) {
				$destinations[] = (string) $destination['channel_public_id'];
			}
		}

		return array(
			'name'                   => $rule->name,
			'priority'               => (string) $rule->priority,
			'when'                   => array(
				'publish' => in_array( 'first_publish', $triggers, true ) || in_array( 'scheduled_publication', $triggers, true ),
				'update'  => in_array( 'update', $triggers, true ),
				'bulk'    => in_array( 'bulk', $triggers, true ),
				'repost'  => in_array( 'repost', $triggers, true ),
			),
			'add_immediate'          => false,
			'condition_json'         => MessageComposer::pretty_json( $rule->condition_tree ),
			'custom_fields'          => implode( ', ', $rule->custom_field_allowlist ),
			'destinations'           => $destinations,
			'message_default'        => (string) ( $template['default_message'] ?? '' ),
			'service_messages'       => isset( $template['service_messages'] ) && is_array( $template['service_messages'] ) ? $template['service_messages'] : array(),
			'template_json'          => $template_json,
			'template_json_original' => $template_json,
			'schedule'               => (string) ( $rule->schedule_policy_document['mode'] ?? $rule->schedule_policy ),
			'media'                  => (string) ( $rule->media_policy['mode'] ?? 'none' ),
			'adopted_template'       => '',
		);
	}

	/**
	 * Take a stored draft for this editor.
	 *
	 * @param RuleRecord|null $rule Rule.
	 * @param string          $effect Effect.
	 * @return array{state:array<string,mixed>,error:array{section:string,message:string}}|null
	 */
	private static function take_draft( ?RuleRecord $rule, string $effect ): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag.
		$message = isset( $_GET['pressqueue_message'] ) ? sanitize_key( wp_unslash( $_GET['pressqueue_message'] ) ) : '';
		if ( 'rule_invalid' !== $message ) {
			return null;
		}

		$key   = self::DRAFT_KEY . get_current_user_id();
		$draft = get_transient( $key );

		if ( ! is_array( $draft ) || ! isset( $draft['state'], $draft['error'] ) || ! is_array( $draft['state'] ) || ! is_array( $draft['error'] ) ) {
			return null;
		}

		$draft_rule   = $draft['rule'] ?? null;
		$draft_effect = $draft['effect'] ?? null;
		if ( ( null === $rule ? '' : $rule->public_id ) !== $draft_rule || $draft_effect !== $effect ) {
			return null;
		}

		delete_transient( $key );

		return array(
			'state' => $draft['state'],
			'error' => array(
				'section' => (string) ( $draft['error']['section'] ?? 'top' ),
				'message' => (string) ( $draft['error']['message'] ?? '' ),
			),
		);
	}

	/**
	 * Translate a save failure into a section and a plain sentence.
	 *
	 * @param \Throwable $error Failure.
	 * @return array{section:string,message:string}
	 */
	private static function error_for( \Throwable $error ): array {
		if ( $error instanceof RuleFormException ) {
			return array(
				'section' => $error->section,
				'message' => $error->getMessage(),
			);
		}

		if ( $error instanceof StaleRecordException ) {
			return array(
				'section' => 'top',
				'message' => __( 'This rule was changed somewhere else while you were editing. Your edits are below but were not saved. Save again to replace the other changes, or reload the rule to start from the latest version.', 'pressqueue' ),
			);
		}

		$message = $error instanceof ValidationException ? $error->getMessage() : '';
		$known   = array(
			'Label must be a non-empty bounded string.'   => array( 'name', __( 'Give the rule a name of up to 191 characters.', 'pressqueue' ) ),
			'Rules need at least one trigger.'            => array( 'when', __( 'Choose at least one moment that starts a share.', 'pressqueue' ) ),
			'Taxonomy condition terms are invalid.'       => array( 'conditions', __( 'Pick at least one category or tag in each condition.', 'pressqueue' ) ),
			'Taxonomy condition term does not exist.'     => array( 'conditions', __( 'One of the chosen categories or tags no longer exists. Remove it and pick again.', 'pressqueue' ) ),
			'Taxonomy condition taxonomy does not exist.' => array( 'conditions', __( 'A condition uses a kind of category that no longer exists on this site.', 'pressqueue' ) ),
			'Author condition user does not exist.'       => array( 'conditions', __( 'One of the chosen authors no longer exists. Remove them and pick again.', 'pressqueue' ) ),
			'Author condition value is invalid.'          => array( 'conditions', __( 'Pick at least one author in each author condition.', 'pressqueue' ) ),
			'Author condition comparison is invalid.'     => array( 'conditions', __( 'Pick at least one author in each author condition.', 'pressqueue' ) ),
			'String condition comparison is invalid.'     => array( 'conditions', __( 'Pick at least one post type in each post type condition.', 'pressqueue' ) ),
			'Condition post type does not exist.'         => array( 'conditions', __( 'A condition uses a post type that no longer exists on this site.', 'pressqueue' ) ),
			'Metadata condition key is not allowlisted.'  => array( 'conditions', __( 'A condition uses a custom field that is not listed under Advanced, Custom fields used in this rule. Add it there.', 'pressqueue' ) ),
			'Condition tree exceeds the allowed complexity.' => array( 'conditions', __( 'The conditions are too complex. Use at most 50 conditions nested 5 levels deep.', 'pressqueue' ) ),
			'Condition groups need children.'             => array( 'conditions', __( 'Add at least one condition.', 'pressqueue' ) ),
			'Template message must be a non-empty bounded string.' => array( 'message', __( 'Write a message of up to 2,000 characters.', 'pressqueue' ) ),
			'Template set default message is required.'   => array( 'message', __( 'Write a message of up to 2,000 characters.', 'pressqueue' ) ),
			'Template custom field tag is not allowlisted by the rule.' => array( 'message', __( 'The message uses a custom field that is not listed under Advanced, Custom fields used in this rule. Add it there.', 'pressqueue' ) ),
			'Text key contains invalid characters.'       => array( 'message', __( 'A channel-specific message has an invalid channel name.', 'pressqueue' ) ),
			'Custom field allowlist contains an invalid key.' => array( 'custom_fields', __( 'Custom field names may only use letters, numbers, dots, colons, dashes and underscores.', 'pressqueue' ) ),
			'Inclusion rules need a destination list.'    => array( 'where', __( 'Pick at least one channel.', 'pressqueue' ) ),
			'Public IDs must be uppercase 26 character ULIDs.' => array( 'where', __( 'One of the chosen channels is not valid. Reload the page and pick the channels again.', 'pressqueue' ) ),
		);

		if ( isset( $known[ $message ] ) ) {
			return array(
				'section' => $known[ $message ][0],
				'message' => $known[ $message ][1],
			);
		}

		$prefixes = array(
			'Condition' => 'conditions',
			'Taxonomy'  => 'conditions',
			'Author'    => 'conditions',
			'Metadata'  => 'conditions',
			'Post '     => 'conditions',
			'String '   => 'conditions',
			'Template'  => 'message',
			'Inline'    => 'message',
			'Schedule'  => 'advanced',
			'Media'     => 'advanced',
		);
		foreach ( $prefixes as $prefix => $section ) {
			if ( str_starts_with( $message, $prefix ) ) {
				return array(
					'section' => $section,
					'message' => 'conditions' === $section
						? __( 'The conditions are not valid. Check each condition, or the JSON under Advanced.', 'pressqueue' )
						: __( 'This part of the rule is not valid. Check it, or the JSON under Advanced.', 'pressqueue' ),
				);
			}
		}

		return array(
			'section' => 'top',
			'message' => __( 'The rule could not be saved. Check the fields below and try again.', 'pressqueue' ),
		);
	}

	/**
	 * Read the triggers.
	 *
	 * @param RuleRecord|null $current Existing rule.
	 * @param string          $effect Effect.
	 * @param bool            $modern Whether the request came from this editor.
	 * @return array<int,string>
	 */
	private static function triggers_from_request( ?RuleRecord $current, string $effect, bool $modern ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Caller checks the nonce.
		if ( ! $modern || '1' !== RulesController::post_text( 'when_controls_present' ) ) {
			if ( $modern && 'exclude' === $effect ) {
				return null === $current ? StorageEnums::RULE_TRIGGERS : ( $current->triggers['triggers'] ?? StorageEnums::RULE_TRIGGERS );
			}

			return isset( $_POST['triggers'] ) && is_array( $_POST['triggers'] ) ? array_values( array_map( 'sanitize_key', wp_unslash( $_POST['triggers'] ) ) ) : array();
		}

		$when = isset( $_POST['when'] ) && is_array( $_POST['when'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['when'] ) ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$current_triggers = null === $current ? array() : ( $current->triggers['triggers'] ?? array() );
		$triggers         = array();

		if ( in_array( 'publish', $when, true ) ) {
			$existing = array_values( array_intersect( $current_triggers, array( 'first_publish', 'scheduled_publication' ) ) );

			if ( array() !== $existing ) {
				// Keep the stored publish triggers exactly, so saving does not quietly change the rule.
				$triggers = $existing;
				if ( ! in_array( 'first_publish', $existing, true ) && '1' === RulesController::post_text( 'when_add_immediate' ) ) {
					$triggers[] = 'first_publish';
				}
			} else {
				$triggers = array( 'first_publish', 'scheduled_publication' );
			}
		}

		foreach ( array( 'update', 'bulk', 'repost' ) as $choice ) {
			if ( in_array( $choice, $when, true ) ) {
				$triggers[] = $choice;
			}
		}

		if ( array() === $triggers ) {
			RuleFormException::raise( 'when', __( 'Choose at least one moment that starts a share.', 'pressqueue' ) );
		}

		return $triggers;
	}

	/**
	 * Read the custom field list.
	 *
	 * @param RuleRecord|null $current Existing rule.
	 * @return array<int,string>
	 */
	private static function custom_fields_from_request( ?RuleRecord $current ): array {
		if ( '1' === RulesController::post_text( 'custom_fields_present' ) ) {
			$fields = array();
			foreach ( explode( ',', RulesController::post_text( 'custom_fields' ) ) as $field ) {
				$field = trim( $field );
				if ( '' !== $field ) {
					$fields[] = $field;
				}
			}

			try {
				return RuleDocuments::custom_field_allowlist( $fields );
			} catch ( ValidationException $exception ) {
				unset( $exception );
				RuleFormException::raise( 'custom_fields', __( 'Custom field names may only use letters, numbers, dots, colons, dashes and underscores.', 'pressqueue' ) );
			}
		}

		if ( '' !== self::post_raw( 'custom_field_allowlist_json' ) ) {
			$value = self::decode_json( self::post_raw( 'custom_field_allowlist_json' ), 'custom_fields' );
			if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
				RuleFormException::raise( 'custom_fields', __( 'The custom field list must be a JSON list.', 'pressqueue' ) );
			}

			return $value;
		}

		return null === $current ? array() : $current->custom_field_allowlist;
	}

	/**
	 * Read the destinations.
	 *
	 * @param string $effect Effect.
	 * @param bool   $modern Whether the request came from this editor.
	 * @return array<string,mixed>
	 */
	private static function destinations_from_request( string $effect, bool $modern ): array {
		if ( 'exclude' === $effect ) {
			return array(
				'schema_version' => 1,
				'scope'          => 'all',
			);
		}

		if ( '1' !== RulesController::post_text( 'destination_controls_present' ) ) {
			return self::decode_object( self::post_raw( 'destination_set_json' ), 'where' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Caller checks the nonce; values are type-checked and sanitized below.
		$ids = isset( $_POST['destination_channel_ids'] ) ? wp_unslash( $_POST['destination_channel_ids'] ) : array();
		if ( ! is_array( $ids ) || ! array_is_list( $ids ) ) {
			RuleFormException::raise( 'where', __( 'Pick the channels again.', 'pressqueue' ) );
		}

		$destinations = array();
		foreach ( $ids as $id ) {
			if ( ! is_string( $id ) ) {
				RuleFormException::raise( 'where', __( 'Pick the channels again.', 'pressqueue' ) );
			}
			$destinations[] = array( 'channel_public_id' => sanitize_text_field( $id ) );
		}

		if ( $modern && array() === $destinations ) {
			RuleFormException::raise( 'where', __( 'Pick at least one channel.', 'pressqueue' ) );
		}

		return array(
			'schema_version' => 1,
			'destinations'   => $destinations,
		);
	}

	/**
	 * Read a schedule or image choice.
	 *
	 * @param string          $name Policy name.
	 * @param RuleRecord|null $current Existing rule.
	 * @param string          $effect Effect.
	 * @param bool            $modern Whether the request came from this editor.
	 * @return array<string,mixed>
	 */
	private static function mode_from_request( string $name, ?RuleRecord $current, string $effect, bool $modern ): array {
		$mode = sanitize_key( RulesController::post_text( $name . '_mode' ) );

		if ( '' === $mode && $modern ) {
			if ( null !== $current ) {
				return 'schedule_policy' === $name ? $current->schedule_policy_document : $current->media_policy;
			}

			$mode = 'schedule_policy' === $name ? 'queue_end' : ( 'exclude' === $effect ? 'none' : 'featured_image_optional' );
		}

		if ( '' === $mode ) {
			return self::decode_object( self::post_raw( $name . '_json' ), 'advanced' );
		}

		return array(
			'schema_version' => 1,
			'mode'           => $mode,
		);
	}

	/**
	 * Read the message template set.
	 *
	 * @param RuleRecord|null $current Existing rule.
	 * @param string          $effect Effect.
	 * @param bool            $modern Whether the request came from this editor.
	 * @return array<string,mixed>
	 */
	private static function template_set_from_request( ?RuleRecord $current, string $effect, bool $modern ): array {
		if ( $modern && 'exclude' === $effect ) {
			if ( null !== $current && null !== $current->template_set ) {
				return $current->template_set;
			}

			return array(
				'default_message' => '{{ title }}',
				'schema_version'  => 1,
			);
		}

		return MessageComposer::template_set_from_request( null === $current || null === $current->template_set ? array() : $current->template_set );
	}

	/**
	 * Compare two template sets after canonical validation.
	 *
	 * @param array<string,mixed> $left Left set.
	 * @param array<string,mixed> $right Right set.
	 * @param array<int,string>   $allowlist Custom field allowlist.
	 * @return bool
	 */
	private static function same_template_set( array $left, array $right, array $allowlist ): bool {
		try {
			return CanonicalJson::encode_object( RuleDocuments::template_set( $left, $allowlist ) ) === CanonicalJson::encode_object( RuleDocuments::template_set( $right, $allowlist ) );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return false;
		}
	}

	/**
	 * Return the post types the preview should sample.
	 *
	 * @param array<string,mixed> $state Form state.
	 * @return array<int,string>
	 */
	private static function preview_post_types( array $state ): array {
		try {
			$tree = CanonicalJson::decode_object( (string) $state['condition_json'] );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return array();
		}

		return array_values( array_filter( RuleSummary::post_types( $tree ), 'post_type_exists' ) );
	}

	/**
	 * Decode a JSON object or raise a section error.
	 *
	 * @param string $json JSON text.
	 * @param string $section Section key.
	 * @return array<string,mixed>
	 */
	private static function decode_object( string $json, string $section ): array {
		$value = self::decode_json( $json, $section );

		if ( ! is_array( $value ) || ( array() !== $value && array_is_list( $value ) ) ) {
			RuleFormException::raise( $section, __( 'The JSON must be an object.', 'pressqueue' ) );
		}

		return $value;
	}

	/**
	 * Decode JSON or raise a section error.
	 *
	 * @param string $json JSON text.
	 * @param string $section Section key.
	 * @return mixed
	 */
	private static function decode_json( string $json, string $section ) {
		try {
			return json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $exception ) {
			unset( $exception );
			$message = 'conditions' === $section
				? __( 'The conditions JSON under Advanced is not valid. Check for a missing comma, quote or bracket.', 'pressqueue' )
				: __( 'The JSON under Advanced is not valid. Check for a missing comma, quote or bracket.', 'pressqueue' );
			RuleFormException::raise( $section, $message );
		}
	}

	/**
	 * Read a raw POST field. Callers decode and validate it structurally.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private static function post_raw( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Caller checks the nonce; the value is decoded and validated structurally.
		$raw = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';

		return is_string( $raw ) ? $raw : '';
	}
}
