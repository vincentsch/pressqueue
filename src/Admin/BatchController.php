<?php
/**
 * Share old posts: guided manual batches.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PressQueue\Batch\BatchCancellationService;
use PressQueue\Batch\BatchConfirmationService;
use PressQueue\Batch\BatchLimitService;
use PressQueue\Batch\BatchPreviewService;
use PressQueue\Batch\BatchQueueService;
use PressQueue\Planning\ScheduleResolver;
use PressQueue\Runtime\RuntimeDisabledLatch;
use PressQueue\Security\Capabilities;
use PressQueue\Security\SafeOperatorError;
use PressQueue\Storage\Record\BatchItemRecord;
use PressQueue\Storage\Record\BatchRecord;
use PressQueue\Storage\Record\RuleRecord;
use PressQueue\Storage\Repository\BatchItemRepository;
use PressQueue\Storage\Repository\BatchRepository;
use PressQueue\Storage\Repository\DeliveryRepository;
use PressQueue\Storage\Repository\RuleRepository;
use PressQueue\Storage\RuleDocuments;
use PressQueue\Storage\StaleRecordException;
use PressQueue\Storage\ValidationException;
use Throwable;

/**
 * Renders the three-step "Share old posts" screen and handles its actions.
 *
 * Step 1 picks posts, step 2 reviews the stored preview, step 3 picks the timing.
 * The domain services own every rule: this controller only turns form fields into
 * the batch filter and limit documents and explains the results in words.
 */
final class BatchController {
	public const SLUG = 'pressqueue-batches';

	private const FORM_ID             = 'pq-batch-form';
	private const DEFAULT_MAX_POSTS   = 10;
	private const DEFAULT_REPOST_DAYS = 30;
	private const DEFAULT_INTERVAL    = 1440;
	private const MAX_TAGS            = 20;
	private const RECENT_LIMIT        = 20;
	private const FORM_TTL_SECONDS    = 1800;
	private const INTERVALS           = array( 15, 30, 60, 120, 180, 360, 720, 1440 );
	private const TIMINGS             = array( 'queue_end', 'staggered_explicit_time', 'draft', 'inherit', 'queue_next', 'immediate' );

	/**
	 * Exact domain messages mapped to plain error codes.
	 */
	private const DOMAIN_ERRORS = array(
		'PressQueue runtime is disabled.'               => 'runtime',
		'Batch preview is truncated. Narrow the filters before confirming.' => 'too_many',
		'Batch contains too many destination items.'    => 'too_many',
		'Batch destination item limit is out of range.' => 'too_many',
		'Multi-item batches cannot use immediate or queue-next scheduling.' => 'single_timing',
		'Choose a staggered start at least 15 minutes from now.' => 'start_soon',
		'Staggered schedule exceeds the supported horizon.' => 'start_far',
		'Staggered schedule interval is out of range.'  => 'interval',
		'Staggered schedule start is invalid.'          => 'start_invalid',
		'Staggered schedule timezone is invalid.'       => 'start_invalid',
		'Batch candidate limit is out of range.'        => 'max_posts',
		'Batch preview changed. Create a fresh preview before confirming.' => 'changed',
		'Batch preview changed before reservation.'     => 'changed',
		'Batch item set changed. Create a fresh preview before confirming.' => 'changed',
		'Batch item order changed. Create a fresh preview before confirming.' => 'changed',
		'Batch item changed. Create a fresh preview before confirming.' => 'changed',
		'Batch preview snapshot does not match the reviewed snapshot.' => 'changed',
		'Batch preview is no longer confirmable.'       => 'not_confirmable',
		'Batch does not exist.'                         => 'missing',
		'Taxonomy term filter does not exist.'          => 'term',
		'Taxonomy filter does not exist.'               => 'term',
		'Exact post ID does not exist.'                 => 'post_gone',
		'Exact post ID does not match the selected post filters.' => 'post_gone',
		'Exact post ID is not authorized for preview.'  => 'post_gone',
		'You are not allowed to operate PressQueue batches.' => 'permission',
		'Minimum days since prior acceptance is out of range.' => 'repost_days',
	);

	/**
	 * Register hooks.
	 */
	public static function register_hooks(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( 'admin_post_pressqueue_preview_batch', array( self::class, 'handle_preview' ) );
		add_action( 'admin_post_pressqueue_confirm_batch', array( self::class, 'handle_confirm' ) );
		add_action( 'admin_post_pressqueue_cancel_batch', array( self::class, 'handle_cancel' ) );
		add_action( 'admin_post_pressqueue_recover_batch', array( self::class, 'handle_recover' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ), 20 );
	}

	/**
	 * Register menu page.
	 *
	 * @param string $parent_slug Parent slug.
	 */
	public static function register_menu( string $parent_slug ): void {
		add_submenu_page(
			$parent_slug,
			__( 'Share old posts', 'pressqueue' ),
			__( 'Share old posts', 'pressqueue' ),
			Capabilities::OPERATE_BATCHES,
			self::SLUG,
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Enqueue the screen's own styles and behavior.
	 */
	public static function enqueue_assets(): void {
		if ( ! defined( 'PRESSQUEUE_FILE' ) || ! function_exists( 'wp_enqueue_style' ) || self::SLUG !== self::query_key( 'page' ) ) {
			return;
		}

		wp_enqueue_style( 'pressqueue-batches', plugins_url( 'assets/admin/batches.css', PRESSQUEUE_FILE ), array( 'pressqueue-admin' ), Ui::asset_version( 'assets/admin/batches.css' ) );
		wp_enqueue_script( 'pressqueue-batches', plugins_url( 'assets/admin/batches.js', PRESSQUEUE_FILE ), array( 'pressqueue-admin' ), Ui::asset_version( 'assets/admin/batches.js' ), true );
	}

	/**
	 * Render the builder or one batch.
	 */
	public static function render_page(): void {
		self::require_capability();

		try {
			BatchQueueService::recover_queueing_batches();
		} catch ( Throwable $throwable ) {
			unset( $throwable );
		}

		$batch_public_id = self::query_text( 'batch_public_id', 26 );
		$batch           = '' === $batch_public_id ? null : self::load_batch( $batch_public_id );

		if ( null !== $batch && 'previewed' !== $batch->status ) {
			self::render_detail( $batch );
			return;
		}

		self::render_builder( $batch, '' !== $batch_public_id && null === $batch );
	}

	/**
	 * Handle a preview request.
	 */
	public static function handle_preview(): void {
		AdminPostBoundary::check( 'pressqueue_preview_batch', Capabilities::OPERATE_BATCHES, __( 'You are not allowed to operate PressQueue batches.', 'pressqueue' ) );

		$form = null;
		try {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Shared boundary checked above.
			if ( isset( $_POST['filters_json'] ) ) {
				// Older forms and scripts still post raw filter and limit documents.
				$batch = ( new BatchPreviewService() )->preview(
					self::post_text( 'batch_type', 'bulk' ),
					self::post_json_object( 'filters_json' ),
					self::post_json_object( 'batch_limits_json' ),
					self::current_user_id()
				);
				self::redirect( 'batch_previewed', array( 'batch_public_id' => $batch->public_id ) );
			}

			$form = self::form_from_post();
			if ( array() === self::participating_rules()[ $form['batch_type'] ] ) {
				throw new DomainException( 'no_rule' );
			}

			$documents = self::documents_from_form( $form );
			$batch     = ( new BatchPreviewService() )->preview( $documents['batch_type'], $documents['filters'], $documents['limits'], self::current_user_id() );
			self::remember_form( $form, $batch->public_id );
			self::cancel_replaced_preview( self::post_text( 'replaces_batch_public_id' ), $batch->public_id );
			self::redirect( 'batch_previewed', array( 'batch_public_id' => $batch->public_id ) );
		} catch ( Throwable $throwable ) {
			if ( null !== $form ) {
				self::remember_form( $form, '' );
			}
			self::redirect_error( $throwable, null, null !== $form );
		}
	}

	/**
	 * Cancel the unconfirmed preview that a fresh preview replaces.
	 *
	 * @param string $replaced_public_id Replaced batch public ID.
	 * @param string $new_public_id New batch public ID.
	 */
	private static function cancel_replaced_preview( string $replaced_public_id, string $new_public_id ): void {
		if ( '' === $replaced_public_id || $replaced_public_id === $new_public_id ) {
			return;
		}

		try {
			$replaced = ( new BatchRepository() )->find_by_public_id( $replaced_public_id );
			if ( null !== $replaced && 'previewed' === $replaced->status ) {
				( new BatchCancellationService() )->cancel( $replaced->public_id, self::current_user_id() );
			}
		} catch ( Throwable $throwable ) {
			// The old preview expires on its own; the new preview is what matters.
			unset( $throwable );
		}
	}

	/**
	 * Handle a confirmation request.
	 */
	public static function handle_confirm(): void {
		AdminPostBoundary::check( 'pressqueue_confirm_batch', Capabilities::OPERATE_BATCHES, __( 'You are not allowed to operate PressQueue batches.', 'pressqueue' ) );

		try {
			$batch = ( new BatchConfirmationService() )->confirm( self::post_text( 'batch_public_id' ), self::post_text( 'preview_snapshot_hash' ), self::current_user_id() );
			self::redirect( 'batch_confirmed', array( 'batch_public_id' => $batch->public_id ) );
		} catch ( Throwable $throwable ) {
			self::redirect_error( $throwable, self::post_text( 'batch_public_id' ) );
		}
	}

	/**
	 * Handle a cancellation request.
	 */
	public static function handle_cancel(): void {
		AdminPostBoundary::check( 'pressqueue_cancel_batch', Capabilities::OPERATE_BATCHES, __( 'You are not allowed to operate PressQueue batches.', 'pressqueue' ) );

		try {
			$batch_public_id = self::post_text( 'batch_public_id' );
			( new BatchCancellationService() )->cancel( $batch_public_id, self::current_user_id() );
			self::redirect( 'batch_cancelled', array( 'batch_public_id' => $batch_public_id ) );
		} catch ( Throwable $throwable ) {
			self::redirect_error( $throwable, self::post_text( 'batch_public_id' ) );
		}
	}

	/**
	 * Handle a queue restart request.
	 */
	public static function handle_recover(): void {
		AdminPostBoundary::check( 'pressqueue_recover_batch', Capabilities::OPERATE_BATCHES, __( 'You are not allowed to operate PressQueue batches.', 'pressqueue' ) );

		try {
			$batch_public_id = self::post_text( 'batch_public_id' );
			$batch           = ( new BatchRepository() )->find_by_public_id( $batch_public_id );
			if ( null === $batch ) {
				throw new ValidationException( 'Batch does not exist.' );
			}
			( new BatchQueueService() )->ensure_queue_action( $batch->public_id, $batch->preview_snapshot_hash );
			self::redirect( 'batch_recovered', array( 'batch_public_id' => $batch->public_id ) );
		} catch ( Throwable $throwable ) {
			self::redirect_error( $throwable, self::post_text( 'batch_public_id' ) );
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Builder screen
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Render the three-step builder, optionally showing a stored preview.
	 *
	 * @param BatchRecord|null $batch Previewed batch.
	 * @param bool             $missing Whether a requested batch was not found.
	 */
	private static function render_builder( ?BatchRecord $batch, bool $missing ): void {
		$rules   = self::participating_rules();
		$runtime = RuntimeDisabledLatch::is_open();
		$form    = self::builder_form( $batch, $rules );
		$review  = null === $batch ? null : self::review( $batch, self::load_items( $batch ) );

		Ui::page_start( __( 'Share old posts', 'pressqueue' ) );
		self::render_notice();

		if ( $missing ) {
			Ui::notice( 'warning', __( 'That batch no longer exists. Start a new one below.', 'pressqueue' ) );
		}

		if ( ! $runtime ) {
			self::render_runtime_callout();
		}

		echo '<div class="pq-cols pq-batch">';
		echo '<div class="pq-card pq-batch-main">';
		self::render_pick_step( $form, $rules, $batch );
		self::render_review_step( $batch, $review );
		self::render_timing_step( $form, $rules, $review );
		self::render_builder_actions( $batch, $review, $rules, $runtime );
		echo '</div>';
		self::render_side_card();
		echo '</div>';

		self::render_recent();
		Ui::page_end();
	}

	/**
	 * Render step 1.
	 *
	 * @param array<string,mixed>                 $form Form values.
	 * @param array<string,array<int,RuleRecord>> $rules Participating rules by batch type.
	 * @param BatchRecord|null                    $batch Preview this form would replace.
	 */
	private static function render_pick_step( array $form, array $rules, ?BatchRecord $batch ): void {
		$types  = self::batch_types();
		$hidden = null === $batch ? array() : array( 'replaces_batch_public_id' => $batch->public_id );

		Ui::form_start( 'pressqueue_preview_batch', 'pressqueue_preview_batch', $hidden, array( 'id' => self::FORM_ID ) );
		echo '<div class="pq-section pq-batch-step">';
		echo '<h2 id="pq-batch-pick">' . self::step_dot( 1 ) . esc_html__( 'Pick posts', 'pressqueue' ) . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- step_dot returns escaped markup.

		echo '<div class="pq-choices" role="radiogroup" aria-labelledby="pq-batch-pick">';
		foreach ( $types as $type => $copy ) {
			$available = array() !== $rules[ $type ];
			echo '<label class="pq-choice' . ( $available ? '' : ' pq-choice--off' ) . '"><input type="radio" name="batch_type" value="' . esc_attr( $type ) . '" ' . checked( $form['batch_type'], $type, false ) . disabled( $available, false, false ) . ' />';
			echo '<span><b>' . esc_html( $copy['label'] ) . '</b><span class="pq-choice-help">' . esc_html( $copy['help'] ) . '</span>';
			if ( ! $available ) {
				echo '<span class="pq-choice-help pq-batch-warn">' . esc_html( $copy['no_rule'] ) . '</span>';
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The format is escaped and rules_link returns escaped markup.
				echo '<span class="pq-choice-help">' . sprintf( esc_html( $copy['tick'] ), self::rules_link() ) . '</span>';
			}
			echo '</span></label>';
		}
		echo '</div>';

		if ( array() === $rules['bulk'] && array() === $rules['repost'] ) {
			echo '<div class="pq-callout pq-callout--warn"><p><strong>' . esc_html__( 'No rule takes part yet.', 'pressqueue' ) . '</strong> ' . esc_html__( 'Open a rule, tick "I pick old posts in Share old posts" or "I pick shared posts to share again" under When, and save it. Then come back here.', 'pressqueue' ) . '</p>';
			echo '<p><a class="button" href="' . esc_url( Ui::url( Ui::PAGE_RULES ) ) . '">' . esc_html__( 'Open Rules', 'pressqueue' ) . '</a></p></div>';
		} else {
			foreach ( $types as $type => $copy ) {
				echo '<p class="pq-help pq-only-' . esc_attr( $type ) . '">' . esc_html( $copy['rules_note'] ) . ' ';
				if ( array() === $rules[ $type ] ) {
					echo esc_html( $copy['no_rule'] ) . ' ';
				} else {
					echo esc_html(
						sprintf(
							/* translators: %s: comma-separated rule names. */
							__( 'Taking part: %s.', 'pressqueue' ),
							self::rule_names( $rules[ $type ] )
						)
					) . ' ';
				}
				echo '<a href="' . esc_url( Ui::url( Ui::PAGE_RULES ) ) . '">' . esc_html__( 'Edit rules', 'pressqueue' ) . '</a></p>';
			}
		}

		echo '<div class="pq-field-row">';
		self::render_category_field( (int) $form['category'] );
		echo '<div><label class="pq-label" for="pq-batch-after">' . esc_html__( 'Published after', 'pressqueue' ) . '</label><input class="pq-field" type="date" id="pq-batch-after" name="published_after" value="' . esc_attr( (string) $form['published_after'] ) . '" /></div>';
		echo '<div><label class="pq-label" for="pq-batch-before">' . esc_html__( 'Published before', 'pressqueue' ) . '</label><input class="pq-field" type="date" id="pq-batch-before" name="published_before" value="' . esc_attr( (string) $form['published_before'] ) . '" /></div>';
		echo '<div><label class="pq-label" id="pq-batch-max-label" for="pq-batch-max">' . esc_html__( 'At most', 'pressqueue' ) . '</label><span class="pq-batch-unit"><input class="pq-field" type="number" id="pq-batch-max" name="max_posts" min="1" max="' . esc_attr( (string) BatchLimitService::MAX_CANDIDATE_LIMIT ) . '" step="1" value="' . esc_attr( (string) $form['max_posts'] ) . '" aria-labelledby="pq-batch-max-label pq-batch-max-unit" aria-describedby="pq-batch-max-help" /><span id="pq-batch-max-unit">' . esc_html__( 'posts', 'pressqueue' ) . '</span></span></div>';
		echo '</div>';
		echo '<p class="pq-help" id="pq-batch-max-help">' . esc_html(
			sprintf(
				/* translators: 1: maximum posts per batch, 2: maximum shares per batch. */
				__( 'Up to %1$d posts and %2$d shares per batch. The most recently updated posts come first.', 'pressqueue' ),
				BatchLimitService::MAX_CANDIDATE_LIMIT,
				BatchLimitService::MAX_DESTINATION_ITEMS
			)
		) . '</p>';

		echo '<div class="pq-only-repost pq-batch-repost">';
		echo '<label class="pq-label" id="pq-batch-days-label" for="pq-batch-days">' . esc_html__( 'Only posts last sent at least', 'pressqueue' ) . '</label>';
		echo '<span class="pq-batch-unit"><input class="pq-field" type="number" id="pq-batch-days" name="repost_min_days" min="1" max="3650" step="1" value="' . esc_attr( (string) $form['repost_min_days'] ) . '" aria-labelledby="pq-batch-days-label pq-batch-days-unit" aria-describedby="pq-batch-days-help" /><span id="pq-batch-days-unit">' . esc_html__( 'days ago', 'pressqueue' ) . '</span></span>';
		echo '<p class="pq-help" id="pq-batch-days-help">' . esc_html__( 'Counted from the last time Buffer accepted the post on that channel. Leave empty to allow any gap. A post is never shared again while an earlier share of it is still waiting.', 'pressqueue' ) . '</p>';
		echo '</div>';

		$more_open = '' !== $form['title_search'] || '' !== $form['tags'] || 'post' !== $form['post_type'] || 0 !== (int) $form['author'];
		echo '<details class="pq-more"' . ( $more_open ? ' open' : '' ) . '><summary>' . esc_html__( 'More ways to pick posts', 'pressqueue' ) . '</summary><div class="pq-more-body pq-field-row">';
		echo '<div><label class="pq-label" for="pq-batch-title">' . esc_html__( 'Title contains', 'pressqueue' ) . '</label><input class="pq-field" type="search" id="pq-batch-title" name="title_search" maxlength="200" value="' . esc_attr( (string) $form['title_search'] ) . '" /></div>';
		echo '<div><label class="pq-label" for="pq-batch-tags">' . esc_html__( 'Tags', 'pressqueue' ) . '</label><input class="pq-field" type="text" id="pq-batch-tags" name="tags" maxlength="500" value="' . esc_attr( (string) $form['tags'] ) . '" aria-describedby="pq-batch-tags-help" /><p class="pq-help" id="pq-batch-tags-help">' . esc_html__( 'Separate tags with commas. Posts with any of them match.', 'pressqueue' ) . '</p></div>';
		self::render_post_type_field( (string) $form['post_type'] );
		self::render_author_field( (int) $form['author'] );
		echo '</div></details>';

		if ( isset( $form['picked_posts'] ) && (int) $form['picked_posts'] > 0 && '' === $form['title_search'] ) {
			echo '<p class="pq-help">' . esc_html(
				sprintf(
					/* translators: %d: number of posts. */
					_n( 'This preview uses %d post found by a title search.', 'This preview uses %d posts found by a title search.', (int) $form['picked_posts'], 'pressqueue' ),
					(int) $form['picked_posts']
				)
			) . '</p>';
		}

		echo '</div>';
		Ui::form_end();
	}

	/**
	 * Render the category select.
	 *
	 * @param int $selected Selected term ID.
	 */
	private static function render_category_field( int $selected ): void {
		$terms = function_exists( 'get_terms' ) ? get_terms(
			array(
				'hide_empty' => false,
				'number'     => 500,
				'orderby'    => 'name',
				'taxonomy'   => 'category',
			)
		) : array();

		echo '<div><label class="pq-label" for="pq-batch-category">' . esc_html__( 'Category', 'pressqueue' ) . '</label><select class="pq-field" id="pq-batch-category" name="category">';
		echo '<option value="0">' . esc_html__( 'Any category', 'pressqueue' ) . '</option>';
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( $term instanceof \WP_Term ) {
					echo '<option value="' . esc_attr( (string) $term->term_id ) . '" ' . selected( $selected, $term->term_id, false ) . '>' . esc_html( html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) ) . '</option>';
				}
			}
		}
		echo '</select></div>';
	}

	/**
	 * Render the post type select.
	 *
	 * @param string $selected Selected post type.
	 */
	private static function render_post_type_field( string $selected ): void {
		echo '<div><label class="pq-label" for="pq-batch-type">' . esc_html__( 'Post type', 'pressqueue' ) . '</label><select class="pq-field" id="pq-batch-type" name="post_type">';
		foreach ( self::post_type_choices() as $slug => $label ) {
			echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $selected, $slug, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></div>';
	}

	/**
	 * Render the author select when the site has more than one author.
	 *
	 * @param int $selected Selected user ID.
	 */
	private static function render_author_field( int $selected ): void {
		$authors = function_exists( 'get_users' ) ? get_users(
			array(
				'fields'              => array( 'ID', 'display_name' ),
				'has_published_posts' => true,
				'number'              => 200,
				'orderby'             => 'display_name',
			)
		) : array();

		if ( count( $authors ) < 2 && 0 === $selected ) {
			return;
		}

		echo '<div><label class="pq-label" for="pq-batch-author">' . esc_html__( 'Author', 'pressqueue' ) . '</label><select class="pq-field" id="pq-batch-author" name="author">';
		echo '<option value="0">' . esc_html__( 'Any author', 'pressqueue' ) . '</option>';
		foreach ( $authors as $author ) {
			if ( is_object( $author ) && isset( $author->ID, $author->display_name ) ) {
				echo '<option value="' . esc_attr( (string) $author->ID ) . '" ' . selected( $selected, (int) $author->ID, false ) . '>' . esc_html( (string) $author->display_name ) . '</option>';
			}
		}
		echo '</select></div>';
	}

	/**
	 * Render step 2.
	 *
	 * @param BatchRecord|null         $batch Previewed batch.
	 * @param array<string,mixed>|null $review Review data.
	 */
	private static function render_review_step( ?BatchRecord $batch, ?array $review ): void {
		echo '<div class="pq-section pq-batch-step" id="pq-batch-review">';

		if ( null === $batch || null === $review ) {
			echo '<h2>' . self::step_dot( 2 ) . esc_html__( 'Review', 'pressqueue' ) . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- step_dot returns escaped markup.
			echo '<p class="pq-muted pq-batch-placeholder">' . esc_html__( 'Pick posts above and click Preview posts. PressQueue checks each post against your rules and lists what would be shared and what would be skipped, and why. Nothing is shared yet.', 'pressqueue' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<h2>' . self::step_dot( 2 ) . esc_html__( 'Review', 'pressqueue' ) . ' <small>' . esc_html( self::totals_line( $review ) ) . '</small></h2>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- step_dot returns escaped markup.

		foreach ( self::review_problems( $review ) as $problem ) {
			echo '<div class="pq-callout pq-callout--bad"><p>' . esc_html( $problem ) . '</p></div>';
		}

		if ( (int) $review['unauthorized'] > 0 ) {
			echo '<p class="pq-help">' . esc_html(
				sprintf(
					/* translators: %d: number of posts. */
					_n( '%d post was left out because you cannot preview it.', '%d posts were left out because you cannot preview them.', (int) $review['unauthorized'], 'pressqueue' ),
					(int) $review['unauthorized']
				)
			) . '</p>';
		}

		if ( array() === $review['posts'] ) {
			Ui::empty_state(
				__( 'No posts match', 'pressqueue' ),
				'repost' === $batch->batch_type
					? __( 'No post Buffer accepted before matches these choices. Try a wider date range, another category or a shorter gap.', 'pressqueue' )
					: __( 'Try a wider date range or another category.', 'pressqueue' )
			);
			echo '</div>';
			return;
		}

		$sharing = array();
		$skipped = array();
		foreach ( $review['posts'] as $post ) {
			if ( array() === $post['shares'] ) {
				$skipped[] = $post;
			} else {
				$sharing[] = $post;
			}
		}

		if ( array() !== $sharing ) {
			echo '<ul class="pq-batch-review">';
			foreach ( $sharing as $post ) {
				self::render_review_post( $post );
			}
			echo '</ul>';
		}

		if ( array() !== $skipped ) {
			$fold = count( $skipped ) > 3 && array() !== $sharing;
			if ( $fold ) {
				echo '<details class="pq-more pq-batch-skipped"><summary>' . esc_html(
					sprintf(
						/* translators: %d: number of posts. */
						_n( 'Show %d skipped post and why', 'Show %d skipped posts and why', count( $skipped ), 'pressqueue' ),
						count( $skipped )
					)
				) . '</summary><div class="pq-more-body">';
			}
			echo '<ul class="pq-batch-review">';
			foreach ( $skipped as $post ) {
				self::render_review_post( $post );
			}
			echo '</ul>';
			if ( $fold ) {
				echo '</div></details>';
			}
		}

		echo '</div>';
	}

	/**
	 * Render one post in the review list.
	 *
	 * @param array<string,mixed> $post Grouped post.
	 */
	private static function render_review_post( array $post ): void {
		$shares = $post['shares'];
		$yes    = array() !== $shares;

		echo '<li class="pq-batch-post">';
		echo '<span class="pq-batch-mark ' . ( $yes ? 'pq-yes' : 'pq-no' ) . '" aria-hidden="true">' . ( $yes ? '&#x2713;' : '&#x2715;' ) . '</span>';
		echo '<div class="pq-batch-post-body">';
		$status = $yes ? __( 'Will be shared', 'pressqueue' ) : __( 'Skipped', 'pressqueue' );
		if ( $yes && '' !== $post['timing_blocked'] ) {
			$status = __( 'Shared once you pick another timing', 'pressqueue' );
		}
		self::render_post_heading( $post, $status );

		if ( $yes ) {
			echo '<div class="pq-batch-channels"><span class="pq-muted pq-small">' . esc_html__( 'Shares to', 'pressqueue' ) . '</span>';
			foreach ( $shares as $share ) {
				if ( null !== $share['channel'] ) {
					echo self::channel_markup( $share['channel'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- channel_markup returns escaped markup.
				}
			}
			echo '</div>';
		}

		self::render_skips( $post['skipped'] );

		if ( $yes && $post['can_edit'] ) {
			$cards = self::share_cards( $shares );
			if ( array() !== $cards ) {
				echo '<details class="pq-more pq-small"><summary>' . esc_html( _n( 'Show message', 'Show messages', count( $cards ), 'pressqueue' ) ) . '</summary><div class="pq-more-body pq-stack">';
				foreach ( $cards as $card ) {
					if ( '' !== $post['timing_blocked'] ) {
						// Show the timing that was picked, which these shares cannot use yet.
						$card['schedule_slot'] = $post['timing_blocked'];
					}
					PreviewPresenter::render_share( $card );
				}
				echo '</div></details>';
			}
		}

		echo '</div></li>';
	}

	/**
	 * Render a post title line.
	 *
	 * @param array<string,mixed> $post Grouped post.
	 * @param string              $status Screen reader status.
	 */
	private static function render_post_heading( array $post, string $status ): void {
		$title = Ui::post_title( (int) $post['post_id'] );
		$link  = $post['can_edit'] && function_exists( 'get_edit_post_link' ) ? get_edit_post_link( (int) $post['post_id'] ) : null;

		echo '<div class="pq-batch-post-title">';
		if ( is_string( $link ) && '' !== $link ) {
			echo '<a class="pq-row-link" href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a>';
		} else {
			echo '<span>' . esc_html( $title ) . '</span>';
		}
		if ( '' !== $post['published'] ) {
			echo ' <span class="pq-muted pq-small">&middot; ' . esc_html( $post['published'] ) . '</span>';
		}
		if ( '' !== $status ) {
			echo '<span class="screen-reader-text">, ' . esc_html( $status ) . '</span>';
		}
		echo '</div>';
	}

	/**
	 * Render skipped channels and post-level reasons.
	 *
	 * @param array<int,array<string,mixed>> $skipped Skipped entries.
	 */
	private static function render_skips( array $skipped ): void {
		if ( array() === $skipped ) {
			return;
		}

		echo '<ul class="pq-batch-skips">';
		foreach ( $skipped as $skip ) {
			echo '<li>';
			if ( 1 === count( $skip['channels'] ) ) {
				echo self::channel_markup( $skip['channels'][0] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- channel_markup returns escaped markup.
			} elseif ( count( $skip['channels'] ) > 1 ) {
				$names = array();
				echo '<span class="pq-svcs">';
				foreach ( $skip['channels'] as $channel ) {
					echo Ui::service_badge( $channel['service'], 'sm' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- service_badge returns escaped markup.
					$names[] = $channel['name'];
				}
				echo '</span><span class="pq-batch-names">' . esc_html( implode( ', ', $names ) ) . '</span>';
			}
			echo '<span class="pq-muted">' . esc_html( (string) $skip['reason'] ) . '</span></li>';
		}
		echo '</ul>';
	}

	/**
	 * Merge skipped channels that share the same reason into one line.
	 *
	 * @param array<int,array<string,mixed>> $skipped Skipped entries with channel and reason.
	 * @return array<int,array{channels:array<int,array{service:string,name:string}>,reason:string}>
	 */
	private static function merge_skips( array $skipped ): array {
		$merged = array();

		foreach ( $skipped as $skip ) {
			$reason = (string) $skip['reason'];
			if ( ! isset( $merged[ $reason ] ) ) {
				$merged[ $reason ] = array(
					'channels' => array(),
					'reason'   => $reason,
				);
			}

			if ( is_array( $skip['channel'] ) ) {
				$merged[ $reason ]['channels'][] = $skip['channel'];
			}
		}

		return array_values( $merged );
	}

	/**
	 * Render step 3.
	 *
	 * Post right away and Next free queue slot only work for a single share, so
	 * they are switched off once a preview shows more than one share.
	 *
	 * @param array<string,mixed>                 $form Form values.
	 * @param array<string,array<int,RuleRecord>> $rules Participating rules.
	 * @param array<string,mixed>|null            $review Review data of the current preview.
	 */
	private static function render_timing_step( array $form, array $rules, ?array $review = null ): void {
		$shares = null === $review ? 0 : (int) $review['share_count'];

		echo '<div class="pq-section pq-batch-step">';
		echo '<h2 id="pq-batch-timing">' . self::step_dot( 3 ) . esc_html__( 'Timing', 'pressqueue' ) . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- step_dot returns escaped markup.
		echo '<div class="pq-checks pq-batch-timings" role="radiogroup" aria-labelledby="pq-batch-timing">';

		foreach ( self::timing_choices( $rules, (string) $form['batch_type'] ) as $value => $choice ) {
			$single   = in_array( $value, array( 'queue_next', 'immediate' ), true ) && $shares > 1;
			$selected = $form['timing'] === $value;
			$note     = '';
			if ( $single ) {
				$note = $selected
					? sprintf(
						/* translators: %d: number of shares in the preview. */
						_n( 'Pick another timing: this preview has %d share.', 'Pick another timing: this preview has %d shares.', $shares, 'pressqueue' ),
						$shares
					)
					: sprintf(
						/* translators: %d: number of shares in the preview. */
						_n( 'Not available: this preview has %d share.', 'Not available: this preview has %d shares.', $shares, 'pressqueue' ),
						$shares
					);
			}

			echo '<label' . ( $single && ! $selected ? ' class="pq-batch-timing-off"' : '' ) . '><input type="radio" form="' . esc_attr( self::FORM_ID ) . '" name="timing" value="' . esc_attr( $value ) . '" ' . checked( $selected, true, false ) . disabled( $single && ! $selected, true, false ) . ( $single ? ' data-pq-single-timing' : '' ) . ' />';
			echo '<span><span class="pq-check-title">' . esc_html( $choice['label'] ) . '</span><span class="pq-muted">' . esc_html( $choice['help'] ) . '</span>';
			if ( '' !== $note ) {
				echo '<span class="pq-batch-warn" data-pq-single-note>' . esc_html( $note ) . '</span>';
			}
			echo '</span></label>';

			if ( 'staggered_explicit_time' === $value ) {
				self::render_stagger_fields( $form );
			}
		}

		echo '</div></div>';
	}

	/**
	 * Render the start time and gap fields.
	 *
	 * @param array<string,mixed> $form Form values.
	 */
	private static function render_stagger_fields( array $form ): void {
		$form_attr = ' form="' . esc_attr( self::FORM_ID ) . '"';

		echo '<div class="pq-batch-stagger">';
		echo '<div class="pq-field-row">';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $form_attr is escaped above.
		echo '<div><label class="pq-label" for="pq-batch-start-date">' . esc_html__( 'First share on', 'pressqueue' ) . '</label><input class="pq-field" type="date" id="pq-batch-start-date" name="start_date"' . $form_attr . ' value="' . esc_attr( (string) $form['start_date'] ) . '" /></div>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $form_attr is escaped above.
		echo '<div><label class="pq-label" for="pq-batch-start-time">' . esc_html__( 'At', 'pressqueue' ) . '</label><input class="pq-field" type="time" id="pq-batch-start-time" name="start_time"' . $form_attr . ' value="' . esc_attr( (string) $form['start_time'] ) . '" /></div>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $form_attr is escaped above.
		echo '<div><label class="pq-label" for="pq-batch-interval">' . esc_html__( 'Then one every', 'pressqueue' ) . '</label><select class="pq-field" id="pq-batch-interval" name="interval"' . $form_attr . '>';
		foreach ( self::INTERVALS as $minutes ) {
			echo '<option value="' . esc_attr( (string) $minutes ) . '" ' . selected( (int) $form['interval'], $minutes, false ) . '>' . esc_html( self::interval_label( $minutes ) ) . '</option>';
		}
		echo '</select></div>';
		echo '</div>';
		echo '<p class="pq-help">' . esc_html(
			sprintf(
				/* translators: %s: site time zone name. */
				__( 'Shares go out one at a time in the order shown in Review. Times use the site time zone (%s). Pick a start at least 15 minutes from now.', 'pressqueue' ),
				self::timezone_name()
			)
		) . '</p>';
		echo '</div>';
	}

	/**
	 * Render the bar with the preview and share buttons.
	 *
	 * @param BatchRecord|null                    $batch Previewed batch.
	 * @param array<string,mixed>|null            $review Review data.
	 * @param array<string,array<int,RuleRecord>> $rules Participating rules.
	 * @param bool                                $runtime Whether background work is running.
	 */
	private static function render_builder_actions( ?BatchRecord $batch, ?array $review, array $rules, bool $runtime ): void {
		$can_preview = $runtime && ( array() !== $rules['bulk'] || array() !== $rules['repost'] );
		$confirmable = null !== $batch && null !== $review && $runtime && self::is_confirmable( $batch, $review );
		$disabled    = $can_preview ? '' : ' disabled="disabled"';

		echo '<div class="pq-actions-bar pq-batch-actions">';

		if ( null !== $batch && null !== $review && $confirmable ) {
			Ui::form_start(
				'pressqueue_confirm_batch',
				'pressqueue_confirm_batch',
				array(
					'batch_public_id'       => $batch->public_id,
					'preview_snapshot_hash' => $batch->preview_snapshot_hash,
				),
				array(
					'class'           => 'pq-inline',
					'data-pq-confirm' => self::confirm_text( $batch, $review ),
				)
			);
			echo '<button type="submit" class="button button-primary" data-pq-batch-confirm>' . esc_html(
				sprintf(
					/* translators: %d: number of posts. */
					_n( 'Share %d post', 'Share %d posts', (int) $review['post_count'], 'pressqueue' ),
					(int) $review['post_count']
				)
			) . '</button>';
			Ui::form_end();
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $disabled is a fixed literal.
			echo '<button type="submit" form="' . esc_attr( self::FORM_ID ) . '" class="button" data-pq-batch-update' . $disabled . '>' . esc_html__( 'Update preview', 'pressqueue' ) . '</button>';
			$expiry = self::expiry_text( $batch );
			if ( 'dry_run' === $review['delivery_mode'] ) {
				$expiry = __( 'Test mode: PressQueue records test runs and sends nothing to Buffer.', 'pressqueue' ) . ' ' . $expiry;
			}
			echo '<span class="pq-muted pq-small">' . esc_html( $expiry ) . '</span>';
			echo '<p class="pq-batch-stale" data-pq-batch-stale hidden>' . esc_html__( 'You changed your choices. Update the preview before you share.', 'pressqueue' ) . '</p>';
		} else {
			$label = null === $batch ? __( 'Preview posts', 'pressqueue' ) : __( 'Update preview', 'pressqueue' );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $disabled is a fixed literal.
			echo '<button type="submit" form="' . esc_attr( self::FORM_ID ) . '" class="button button-primary"' . $disabled . '>' . esc_html( $label ) . '</button>';
			$note = null === $batch
				? __( 'Nothing is shared until you review the posts and confirm.', 'pressqueue' )
				: __( 'Nothing in this preview can be shared yet. Change your choices and update the preview.', 'pressqueue' );
			if ( null !== $batch && null !== $review && (int) $review['share_count'] > 0 && $batch->preview_expires_at->getTimestamp() > time() ) {
				$note = __( 'Fix the problem above, then update the preview.', 'pressqueue' );
			}
			echo '<span class="pq-muted pq-small">' . esc_html( $note ) . '</span>';
		}

		if ( null !== $batch ) {
			echo '<a class="pq-batch-reset" href="' . esc_url( Ui::url( self::SLUG ) ) . '">' . esc_html__( 'Start over', 'pressqueue' ) . '</a>';
		}

		echo '</div>';
	}

	/**
	 * Render the "Before you start" card.
	 */
	private static function render_side_card(): void {
		echo '<div class="pq-card pq-card--pad pq-stack pq-batch-side">';
		echo '<h2>' . esc_html__( 'Before you start', 'pressqueue' ) . '</h2>';
		echo '<p class="pq-muted">' . esc_html__( 'Followers see old posts as new ones. A small batch spread across your queue looks natural. Fifty posts in one morning does not.', 'pressqueue' ) . '</p>';
		echo '<p class="pq-muted">' . esc_html__( 'Your "never share" rules still apply, and nothing is shared twice by accident: posts that already have a share on a channel are skipped.', 'pressqueue' ) . '</p>';

		$mode = self::current_mode();
		if ( 'test' === $mode ) {
			echo '<p class="pq-muted">' . esc_html__( 'You are in Test mode. A batch records test runs in Activity and sends nothing to Buffer, so you can try it safely.', 'pressqueue' ) . '</p>';
		} elseif ( 'paused' === $mode ) {
			echo '<p class="pq-muted">' . esc_html__( 'PressQueue is paused. Shares from a batch wait on hold until you resume sharing.', 'pressqueue' ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * Render recent batches.
	 */
	private static function render_recent(): void {
		try {
			$repository = new BatchRepository();
			$batches    = array_map(
				static function ( BatchRecord $batch ) use ( $repository ): BatchRecord {
					return $repository->expire_if_due( $batch );
				},
				$repository->find_recent( self::RECENT_LIMIT, 0 )
			);
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			$batches = array();
		}

		echo '<h2 class="pq-heading">' . esc_html__( 'Recent batches', 'pressqueue' ) . '</h2>';
		echo '<div class="pq-card">';

		if ( array() === $batches ) {
			Ui::empty_state( __( 'No batches yet', 'pressqueue' ), __( 'Your first preview shows up here. A preview shares nothing until you confirm it.', 'pressqueue' ) );
			echo '</div>';
			return;
		}

		$types = self::batch_types();
		echo '<div class="pq-table-wrap"><table class="pq-table pq-batch-table"><caption class="screen-reader-text">' . esc_html__( 'Recent batches', 'pressqueue' ) . '</caption><thead><tr>';
		foreach ( array( __( 'Batch', 'pressqueue' ), __( 'Posts and shares', 'pressqueue' ), __( 'Started by', 'pressqueue' ), __( 'Status', 'pressqueue' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $batches as $batch ) {
			$status = self::status_label( $batch );
			$counts = isset( $batch->preview_summary['counts'] ) && is_array( $batch->preview_summary['counts'] ) ? $batch->preview_summary['counts'] : array();
			$posts  = (int) ( $batch->preview_summary['candidate_posts'] ?? 0 );
			$shares = (int) ( $counts['queueable'] ?? 0 );

			echo '<tr>';
			echo '<td><a class="pq-row-link pq-strong" href="' . esc_url( self::batch_url( $batch->public_id ) ) . '">' . esc_html( $types[ $batch->batch_type ]['label'] ?? $batch->batch_type ) . '</a>';
			echo '<div class="pq-muted pq-small" title="' . esc_attr( Ui::local_datetime( $batch->created_at ) ) . '">' . esc_html( Ui::relative_time( $batch->created_at ) ) . '</div></td>';
			echo '<td>' . esc_html( self::posts_and_shares( $posts, $shares ) ) . '</td>';
			echo '<td>' . esc_html( self::user_name( $batch->created_by ) ) . '</td>';
			echo '<td>' . Ui::pill( $status['tone'], $status['label'], $status['help'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::pill returns escaped markup.
			echo '</tr>';
		}

		echo '</tbody></table></div></div>';
	}

	/*
	 * ---------------------------------------------------------------------
	 * Detail screen
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Render a batch that is past the preview step.
	 *
	 * @param BatchRecord $batch Batch.
	 */
	private static function render_detail( BatchRecord $batch ): void {
		$status = self::status_label( $batch );
		$items  = self::load_items( $batch );
		$posts  = self::group_items( $items );
		$types  = self::batch_types();
		$busy   = in_array( $batch->status, array( 'confirming', 'queueing' ), true );

		Ui::page_start(
			sprintf(
				/* translators: %s: date and time the batch was created. */
				__( 'Batch from %s', 'pressqueue' ),
				Ui::local_datetime( $batch->created_at )
			),
			array(
				'back_url'   => Ui::url( self::SLUG ),
				'back_label' => __( 'Share old posts', 'pressqueue' ),
				'badge'      => Ui::pill( $status['tone'], $status['label'], $status['help'] ),
			)
		);
		self::render_notice();

		echo '<div class="pq-cols pq-batch"' . ( $busy ? ' data-pq-refresh="6"' : '' ) . '>';
		echo '<div class="pq-card pq-batch-main">';
		echo '<div class="pq-card-head"><div><h2>' . esc_html( $types[ $batch->batch_type ]['label'] ?? $batch->batch_type ) . '</h2>';
		echo '<p class="pq-sub">' . esc_html( self::detail_summary( $batch, $items ) ) . '</p></div></div>';

		if ( array() === $posts ) {
			Ui::empty_state( __( 'No posts in this batch', 'pressqueue' ), __( 'The preview found no matching posts.', 'pressqueue' ) );
		} else {
			echo '<ul class="pq-batch-review pq-batch-review--detail">';
			foreach ( $posts as $post ) {
				self::render_detail_post( $post );
			}
			echo '</ul>';
		}
		echo '</div>';

		self::render_detail_side( $batch, $status );
		echo '</div>';
		Ui::page_end();
	}

	/**
	 * Render one post with each of its shares.
	 *
	 * @param array<string,mixed> $post Grouped post.
	 */
	private static function render_detail_post( array $post ): void {
		$deliveries = new DeliveryRepository();
		$can_view   = current_user_can( Capabilities::VIEW_DELIVERIES );

		echo '<li class="pq-batch-post">';
		echo '<div class="pq-batch-post-body">';
		self::render_post_heading( $post, '' );
		echo '<ul class="pq-batch-lines">';

		foreach ( $post['items'] as $entry ) {
			$item   = $entry['item'];
			$state  = self::item_state( $item, $deliveries );
			$reason = in_array( $item->state, array( 'duplicate', 'excluded', 'policy_error', 'failed' ), true ) ? self::item_reason( $item, (bool) $post['can_edit'] ) : '';

			echo '<li>';
			echo null === $entry['channel'] ? '<span class="pq-muted">' . esc_html__( 'Whole post', 'pressqueue' ) . '</span>' : self::channel_markup( $entry['channel'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- channel_markup returns escaped markup.
			echo Ui::pill( $state['tone'], $state['label'], $state['help'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::pill returns escaped markup.
			if ( '' !== $reason ) {
				echo '<span class="pq-muted pq-small">' . esc_html( $reason ) . '</span>';
			}
			if ( null !== $item->delivery_public_id && $can_view ) {
				echo '<a class="pq-small" href="' . esc_url( Ui::url( Ui::PAGE_DELIVERY, array( 'delivery_public_id' => $item->delivery_public_id ) ) ) . '">' . esc_html__( 'View in Activity', 'pressqueue' ) . '</a>';
			}
			echo '</li>';
		}

		echo '</ul></div></li>';
	}

	/**
	 * Render the side card with status, actions and technical details.
	 *
	 * @param BatchRecord                                 $batch Batch.
	 * @param array{label:string,tone:string,help:string} $status Status label.
	 */
	private static function render_detail_side( BatchRecord $batch, array $status ): void {
		echo '<div class="pq-card pq-card--pad pq-stack pq-batch-side">';
		echo '<h2>' . esc_html( $status['label'] ) . '</h2>';
		echo '<p class="pq-muted">' . esc_html( self::status_explanation( $batch ) ) . '</p>';

		if ( in_array( $batch->status, array( 'previewed', 'confirming', 'queueing' ), true ) ) {
			echo '<div class="pq-row">';
			if ( 'queueing' === $batch->status ) {
				Ui::button_form(
					'pressqueue_recover_batch',
					'pressqueue_recover_batch',
					array( 'batch_public_id' => $batch->public_id ),
					__( 'Restart', 'pressqueue' ),
					'button',
					__( 'Restart this batch? PressQueue continues where it stopped. Shares that were already added are not added again.', 'pressqueue' )
				);
			}
			Ui::button_form(
				'pressqueue_cancel_batch',
				'pressqueue_cancel_batch',
				array( 'batch_public_id' => $batch->public_id ),
				__( 'Cancel batch', 'pressqueue' ),
				'button pq-danger',
				__( 'Cancel this batch? Shares from it that were not sent yet are cancelled. Anything Buffer already accepted stays in Buffer.', 'pressqueue' )
			);
			echo '</div>';
			if ( 'queueing' === $batch->status ) {
				echo '<p class="pq-help">' . esc_html__( 'If this batch has been adding shares for more than a few minutes, click Restart.', 'pressqueue' ) . '</p>';
			}
		} else {
			echo '<div class="pq-row"><a class="button" href="' . esc_url( Ui::url( self::SLUG, array( 'from' => $batch->public_id ) ) ) . '">' . esc_html__( 'Preview again with the same choices', 'pressqueue' ) . '</a></div>';
		}

		if ( 'handed_off' === $batch->status && current_user_can( Capabilities::VIEW_DELIVERIES ) && ! self::batch_is_test( $batch ) ) {
			echo '<p class="pq-help">' . esc_html__( 'To stop a share that is still waiting, open it in Activity and cancel it there.', 'pressqueue' ) . '</p>';
		}

		echo '<details class="pq-more pq-small"><summary>' . esc_html__( 'Technical details', 'pressqueue' ) . '</summary><div class="pq-more-body"><dl class="pq-batch-tech">';
		$rows = array(
			__( 'Batch ID', 'pressqueue' )       => $batch->public_id,
			__( 'Type', 'pressqueue' )           => $batch->batch_type,
			__( 'Status', 'pressqueue' )         => $batch->status,
			__( 'Created', 'pressqueue' )        => $batch->created_at->format( 'Y-m-d H:i:s' ) . ' UTC',
			__( 'Confirmed', 'pressqueue' )      => null === $batch->confirmed_at ? '' : $batch->confirmed_at->format( 'Y-m-d H:i:s' ) . ' UTC',
			__( 'Preview expiry', 'pressqueue' ) => $batch->preview_expires_at->format( 'Y-m-d H:i:s' ) . ' UTC',
			__( 'Snapshot hash', 'pressqueue' )  => $batch->preview_snapshot_hash,
		);
		foreach ( $rows as $label => $value ) {
			if ( '' !== $value ) {
				echo '<dt>' . esc_html( $label ) . '</dt><dd class="pq-mono">' . esc_html( $value ) . '</dd>';
			}
		}
		echo '</dl></div></details>';
		echo '</div>';
	}

	/*
	 * ---------------------------------------------------------------------
	 * Review data
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Build the review data for a stored preview.
	 *
	 * @param BatchRecord                $batch Batch.
	 * @param array<int,BatchItemRecord> $items Items.
	 * @return array<string,mixed>
	 */
	private static function review( BatchRecord $batch, array $items ): array {
		$posts       = array();
		$share_count = 0;
		$skipped     = 0;
		$channels    = array();
		$mode        = '';
		$rule_ids    = array();
		$blocked     = 0;
		$slots       = array();

		foreach ( self::group_items( $items ) as $post ) {
			$post['shares']         = array();
			$post['skipped']        = array();
			$post['timing_blocked'] = '';

			foreach ( $post['items'] as $entry ) {
				$item = $entry['item'];

				// A batch timing that needs a single share stops every share. Count them as
				// shares so the review shows what the batch would share with another timing.
				$timing_blocked = 'policy_error' === $item->state && 'batch_multi_item_schedule_not_allowed' === ( $item->reason['code'] ?? null );
				if ( $timing_blocked ) {
					++$blocked;
					$post['timing_blocked'] = (string) ( $batch->batch_limits['schedule']['mode'] ?? '' );
				}

				if ( 'previewed' === $item->state || $timing_blocked ) {
					$post['shares'][] = $entry;
					++$share_count;
					if ( null !== $item->channel_public_id ) {
						$channels[ $item->channel_public_id ] = true;
					}
					if ( null !== $item->inclusion_rule_public_id ) {
						$rule_ids[ $item->inclusion_rule_public_id ] = true;
					}
					$mode    = (string) ( $item->preview_item['delivery']['delivery_mode'] ?? $mode );
					$slots[] = (string) ( $item->preview_item['delivery']['payload_preview']['schedule_slot'] ?? '' );
					continue;
				}

				$post['skipped'][] = array(
					'channel' => $entry['channel'],
					'reason'  => self::item_reason( $item, (bool) $post['can_edit'] ),
				);
			}

			$post['skipped'] = self::merge_skips( $post['skipped'] );
			if ( array() === $post['shares'] ) {
				++$skipped;
			}
			$posts[] = $post;
		}

		$counts = isset( $batch->preview_summary['counts'] ) && is_array( $batch->preview_summary['counts'] ) ? $batch->preview_summary['counts'] : array();

		return array(
			'all_drafts'       => array() !== $slots && array( 'draft' ) === array_values( array_unique( $slots ) ),
			'channel_count'    => count( $channels ),
			'delivery_mode'    => $mode,
			'draft_conflicts'  => self::draft_conflicts( $batch, array_keys( $rule_ids ) ),
			'invalid_schedule' => true === ( $batch->preview_summary['invalid_multi_item_schedule'] ?? false ),
			'post_count'       => count( $posts ) - $skipped,
			'posts'            => $posts,
			'share_count'      => $share_count,
			'single_timing'    => $blocked > 0 ? (string) ( $batch->batch_limits['schedule']['mode'] ?? '' ) : '',
			'skipped_posts'    => $skipped,
			'truncated'        => true === ( $batch->preview_summary['truncated'] ?? false ),
			'unauthorized'     => (int) ( $counts['unauthorized'] ?? 0 ),
		);
	}

	/**
	 * Group items by post, keeping preview order.
	 *
	 * @param array<int,BatchItemRecord> $items Items.
	 * @return array<int,array<string,mixed>>
	 */
	private static function group_items( array $items ): array {
		$summary = new ActivitySummary();
		$posts   = array();

		foreach ( $items as $item ) {
			$post_id = $item->post_id;
			if ( ! isset( $posts[ $post_id ] ) ) {
				$published         = $item->preview_item['post']['published_at_gmt'] ?? null;
				$posts[ $post_id ] = array(
					'can_edit'  => current_user_can( 'edit_post', $post_id ),
					'items'     => array(),
					'post_id'   => $post_id,
					'published' => is_string( $published ) ? self::local_date( $published ) : '',
				);
			}

			$posts[ $post_id ]['items'][] = array(
				'channel' => self::item_channel( $item, $summary ),
				'item'    => $item,
			);
		}

		return array_values( $posts );
	}

	/**
	 * Return channel display facts for an item.
	 *
	 * @param BatchItemRecord $item Item.
	 * @param ActivitySummary $summary Channel lookup.
	 * @return array{service:string,name:string}|null
	 */
	private static function item_channel( BatchItemRecord $item, ActivitySummary $summary ): ?array {
		if ( null === $item->channel_public_id ) {
			return null;
		}

		try {
			$channel = $summary->channel( $item->channel_public_id );
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			$channel = null;
		}

		if ( null !== $channel ) {
			return array(
				'name'    => ActivitySummary::channel_display_name( $channel ),
				'service' => $channel->service,
			);
		}

		$payload = $item->preview_item['delivery']['payload_preview'] ?? array();

		return array(
			'name'    => is_array( $payload ) && is_string( $payload['channel_label'] ?? null ) ? $payload['channel_label'] : __( 'Removed channel', 'pressqueue' ),
			'service' => is_array( $payload ) && is_string( $payload['service'] ?? null ) ? $payload['service'] : '',
		);
	}

	/**
	 * Return names of rules that only save drafts but would get another timing.
	 *
	 * @param BatchRecord       $batch Batch.
	 * @param array<int,string> $rule_ids Rules behind queueable items.
	 * @return array<int,string>
	 */
	private static function draft_conflicts( BatchRecord $batch, array $rule_ids ): array {
		$mode = (string) ( $batch->batch_limits['schedule']['mode'] ?? 'inherit' );
		if ( in_array( $mode, array( 'inherit', 'draft' ), true ) || array() === $rule_ids ) {
			return array();
		}

		$names = array();
		try {
			$repository = new RuleRepository();
			foreach ( $rule_ids as $rule_id ) {
				$rule = $repository->find_by_public_id( $rule_id );
				if ( null !== $rule && 'draft' === self::rule_schedule_mode( $rule ) ) {
					$names[] = $rule->name;
				}
			}
		} catch ( Throwable $throwable ) {
			unset( $throwable );
		}

		return $names;
	}

	/**
	 * Return problems that stop a preview from being shared.
	 *
	 * @param array<string,mixed> $review Review data.
	 * @return array<int,string>
	 */
	private static function review_problems( array $review ): array {
		$problems = array();

		if ( $review['truncated'] ) {
			$problems[] = sprintf(
				/* translators: %d: maximum shares per batch. */
				__( 'This batch would create more than %d shares. Lower At most or narrow your choices, then update the preview.', 'pressqueue' ),
				BatchLimitService::MAX_DESTINATION_ITEMS
			);
		}

		if ( '' !== $review['single_timing'] ) {
			$problems[] = sprintf(
				/* translators: 1: timing such as "Post right away", 2: number of shares in the batch. */
				_n( '%1$s only works when the batch has one share. This batch has %2$d share. Pick another timing below, such as Spread across the Buffer queue, and update the preview.', '%1$s only works when the batch has one share. This batch has %2$d shares. Pick another timing below, such as Spread across the Buffer queue, and update the preview.', (int) $review['share_count'], 'pressqueue' ),
				'immediate' === $review['single_timing'] ? __( 'Post right away', 'pressqueue' ) : __( 'Next free queue slot', 'pressqueue' ),
				(int) $review['share_count']
			);
		}

		if ( $review['invalid_schedule'] ) {
			$problems[] = __( 'A rule in this batch posts right away or in the next free queue slot. That only works for a single share. Pick another timing below, such as Spread across the Buffer queue, and update the preview.', 'pressqueue' );
		}

		if ( array() !== $review['draft_conflicts'] ) {
			$problems[] = sprintf(
				/* translators: %s: rule names. */
				__( 'The rule %s only saves Buffer drafts, so it cannot use this timing. Pick Save all as Buffer drafts or Use each rule\'s timing, and update the preview.', 'pressqueue' ),
				implode( ', ', $review['draft_conflicts'] )
			);
		}

		return $problems;
	}

	/**
	 * Check whether a preview can be confirmed from this screen.
	 *
	 * @param BatchRecord         $batch Batch.
	 * @param array<string,mixed> $review Review data.
	 * @return bool
	 */
	private static function is_confirmable( BatchRecord $batch, array $review ): bool {
		return 'previewed' === $batch->status
			&& $batch->preview_expires_at->getTimestamp() > time()
			&& (int) $review['share_count'] > 0
			&& array() === self::review_problems( $review );
	}

	/**
	 * Return the plain-language reason a share was skipped or stopped.
	 *
	 * Also used by Share now in the post editor, which reviews the same batch items.
	 *
	 * @param BatchItemRecord $item Item.
	 * @param bool            $can_read_post Whether post-specific facts may show.
	 * @return string
	 */
	public static function item_reason( BatchItemRecord $item, bool $can_read_post ): string {
		$reason = $item->reason;
		$code   = is_string( $reason['code'] ?? null ) ? $reason['code'] : '';

		switch ( $code ) {
			case 'duplicate_delivery':
			case 'duplicate_repost':
				return self::blocking_text( isset( $reason['blocking_history'] ) && is_array( $reason['blocking_history'] ) ? $reason['blocking_history'] : null );
			case 'repost_without_prior_acceptance':
				return self::repost_gap_text( $item );
			case 'global_exclusion':
				if ( self::is_switched_off( $item ) ) {
					return Labels::reason( 'explicit_post_exclusion' );
				}
				$rule_id = $item->preview_item['decision']['rule_public_id'] ?? null;
				$name    = is_string( $rule_id ) ? self::rule_name( $rule_id ) : '';
				return '' === $name
					? __( 'A "never share" rule matches this post.', 'pressqueue' )
					/* translators: %s: rule name. */
					: sprintf( __( 'The "never share" rule "%s" matches this post.', 'pressqueue' ), $name );
			case 'no_queueable_destination':
				return self::planner_reason( $reason, $can_read_post );
			case 'post_not_previewable':
			case 'batch_runtime_event_unavailable':
				return __( 'This post cannot be shared. It may be private, password protected or no longer published.', 'pressqueue' );
			case 'batch_multi_item_schedule_not_allowed':
				return __( 'This timing only works for a single share.', 'pressqueue' );
			case 'batch_item_has_no_destination':
				return __( 'No channel was left for this post.', 'pressqueue' );
			case 'batch_runtime_gate_blocked':
			case 'batch_runtime_handoff_blocked':
				return __( 'Your rules no longer share this post to this channel, so it was not added.', 'pressqueue' );
			case 'batch_runtime_preview_changed':
			case 'batch_existing_delivery_changed':
				return __( 'The post or its rule changed after the preview, so it was not added.', 'pressqueue' );
			case 'confirmer_lost_authority':
				return __( 'The person who confirmed this batch can no longer share old posts.', 'pressqueue' );
			case 'batch_handoff_failed':
				return __( 'PressQueue could not add this share. Preview the post again to see why.', 'pressqueue' );
			case 'missing_included_decision':
				return __( 'PressQueue could not check this share. Update the preview.', 'pressqueue' );
		}

		if ( 'duplicate' === $item->state ) {
			return __( 'This post already has a share there.', 'pressqueue' );
		}

		if ( 'excluded' === $item->state ) {
			return self::is_switched_off( $item ) ? Labels::reason( 'explicit_post_exclusion' ) : __( 'A "never share" rule matches this post.', 'pressqueue' );
		}

		$label = Labels::reason( $code, '' );

		return '' === $label || $label === $code ? __( 'This share cannot be added.', 'pressqueue' ) : $label;
	}

	/**
	 * Check whether an item was skipped only because "Share this post" is switched off.
	 *
	 * @param BatchItemRecord $item Item.
	 * @return bool
	 */
	private static function is_switched_off( BatchItemRecord $item ): bool {
		$decision = $item->preview_item['decision'] ?? null;
		if ( ! is_array( $decision ) || 'excluded' !== ( $decision['result'] ?? null ) || null !== ( $decision['rule_public_id'] ?? null ) ) {
			return false;
		}

		foreach ( is_array( $decision['reasons'] ?? null ) ? $decision['reasons'] : array() as $reason ) {
			if ( is_array( $reason ) && 'explicit_post_exclusion' === ( $reason['code'] ?? null ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Explain an earlier share that blocks this one.
	 *
	 * @param array<string,mixed>|null $history Blocking history entry.
	 * @return string
	 */
	private static function blocking_text( ?array $history ): string {
		if ( null === $history ) {
			return __( 'This post already has a share there.', 'pressqueue' );
		}

		$classification = (string) ( $history['classification'] ?? '' );
		if ( in_array( $classification, array( 'reserved_batch_item', 'unresolved_repost_batch_item' ), true ) ) {
			return __( 'Already part of another batch that is not finished.', 'pressqueue' );
		}

		$state = (string) ( $history['state'] ?? '' );
		if ( 'accepted' === $state ) {
			$date = is_string( $history['state_changed_at'] ?? null ) ? self::local_date( $history['state_changed_at'] ) : '';
			return '' === $date
				? __( 'Already sent to Buffer.', 'pressqueue' )
				/* translators: %s: date. */
				: sprintf( __( 'Already sent to Buffer on %s.', 'pressqueue' ), $date );
		}

		if ( in_array( $state, array( 'proposed', 'queued', 'leased', 'retrying', 'held' ), true ) ) {
			return __( 'A share of this post is already waiting.', 'pressqueue' );
		}

		if ( 'ambiguous' === $state ) {
			return __( 'An earlier share needs checking in Activity first.', 'pressqueue' );
		}

		if ( 'failed' === $state ) {
			return __( 'An earlier share failed and can still be retried in Activity.', 'pressqueue' );
		}

		return __( 'This post already has a share there.', 'pressqueue' );
	}

	/**
	 * Explain why a post cannot be shared again on a channel.
	 *
	 * @param BatchItemRecord $item Item.
	 * @return string
	 */
	private static function repost_gap_text( BatchItemRecord $item ): string {
		$history = $item->preview_item['duplicate_history'] ?? array();
		$latest  = null;

		if ( is_array( $history ) ) {
			foreach ( $history as $entry ) {
				if ( is_array( $entry ) && 'accepted' === ( $entry['state'] ?? null ) && is_string( $entry['state_changed_at'] ?? null ) ) {
					$latest = $entry['state_changed_at'];
					break;
				}
			}
		}

		if ( null === $latest ) {
			return __( 'Not sent to Buffer before, so there is nothing to share again.', 'pressqueue' );
		}

		return sprintf(
			/* translators: %s: date. */
			__( 'Last sent on %s, which is too recent for your choices.', 'pressqueue' ),
			self::local_date( $latest )
		);
	}

	/**
	 * Return the first meaningful planner reason.
	 *
	 * @param array<string,mixed> $reason Item reason document.
	 * @param bool                $can_read_post Whether stored labels may show.
	 * @return string
	 */
	private static function planner_reason( array $reason, bool $can_read_post ): string {
		$planner = isset( $reason['planner_reasons'] ) && is_array( $reason['planner_reasons'] ) ? $reason['planner_reasons'] : array();

		foreach ( $planner as $entry ) {
			if ( ! is_array( $entry ) || ! is_string( $entry['code'] ?? null ) ) {
				continue;
			}

			if ( in_array( $entry['code'], array( 'destination_available', 'included_destination' ), true ) ) {
				continue;
			}

			$fallback = $can_read_post && is_string( $entry['label'] ?? null ) ? $entry['label'] : '';
			$text     = Labels::reason( $entry['code'], $fallback );

			return $text === $entry['code'] ? __( 'No rule shares this post right now.', 'pressqueue' ) : $text;
		}

		return __( 'No rule shares this post right now.', 'pressqueue' );
	}

	/**
	 * Return an item state label for the detail list.
	 *
	 * @param BatchItemRecord    $item Item.
	 * @param DeliveryRepository $deliveries Delivery lookup.
	 * @return array{label:string,tone:string,help:string}
	 */
	private static function item_state( BatchItemRecord $item, DeliveryRepository $deliveries ): array {
		if ( 'queued' === $item->state && null !== $item->delivery_public_id ) {
			try {
				$delivery = $deliveries->find_by_public_id( $item->delivery_public_id );
			} catch ( Throwable $throwable ) {
				unset( $throwable );
				$delivery = null;
			}

			if ( null !== $delivery ) {
				return Labels::delivery_state( $delivery->state );
			}
		}

		$states = array(
			'previewed'    => array( __( 'Ready', 'pressqueue' ), 'info', __( 'Part of the preview. Not shared yet.', 'pressqueue' ) ),
			'reserved'     => array( __( 'Being added', 'pressqueue' ), 'info', __( 'PressQueue is adding this share to the queue.', 'pressqueue' ) ),
			'queued'       => array( __( 'Added', 'pressqueue' ), 'ok', __( 'Added to the queue. Follow it in Activity.', 'pressqueue' ) ),
			'duplicate'    => array( __( 'Skipped', 'pressqueue' ), 'idle', __( 'Skipped so the post is not shared twice.', 'pressqueue' ) ),
			'excluded'     => array( __( 'Skipped', 'pressqueue' ), 'idle', __( 'A "never share" rule matches this post.', 'pressqueue' ) ),
			'policy_error' => array( __( 'Skipped', 'pressqueue' ), 'warn', __( 'Your rules do not allow this share.', 'pressqueue' ) ),
			'cancelled'    => array( __( 'Cancelled', 'pressqueue' ), 'idle', __( 'Cancelled before it was added.', 'pressqueue' ) ),
			'failed'       => array( __( 'Not added', 'pressqueue' ), 'bad', __( 'PressQueue stopped this share when adding it.', 'pressqueue' ) ),
		);

		$entry = $states[ $item->state ] ?? array( $item->state, 'idle', '' );
		if ( 'excluded' === $item->state && self::is_switched_off( $item ) ) {
			$off   = Labels::decision_result( 'switched_off' );
			$entry = array( $off['label'], $off['tone'], $off['help'] );
		}

		return array(
			'help'  => $entry[2],
			'label' => $entry[0],
			'tone'  => $entry[1],
		);
	}

	/**
	 * Return a batch status label.
	 *
	 * @param BatchRecord $batch Batch.
	 * @return array{label:string,tone:string,help:string}
	 */
	private static function status_label( BatchRecord $batch ): array {
		$statuses = array(
			'previewed'  => array( __( 'Preview ready', 'pressqueue' ), 'info', __( 'Waiting for someone to review and share it.', 'pressqueue' ) ),
			'confirming' => array( __( 'Starting', 'pressqueue' ), 'info', __( 'PressQueue is checking the posts one last time.', 'pressqueue' ) ),
			'queueing'   => array( __( 'Adding shares', 'pressqueue' ), 'info', __( 'PressQueue is adding the shares to the queue.', 'pressqueue' ) ),
			'cancelled'  => array( __( 'Cancelled', 'pressqueue' ), 'idle', __( 'Cancelled. Shares that were not sent yet were cancelled too.', 'pressqueue' ) ),
			'handed_off' => array( __( 'Done', 'pressqueue' ), 'ok', __( 'Every share was added or skipped. Follow them in Activity.', 'pressqueue' ) ),
			'failed'     => array( __( 'Failed', 'pressqueue' ), 'bad', __( 'Something went wrong while adding the shares.', 'pressqueue' ) ),
			'expired'    => array( __( 'Preview expired', 'pressqueue' ), 'idle', __( 'Nobody shared this preview in time. Nothing was shared.', 'pressqueue' ) ),
		);

		if ( 'cancelled' === $batch->status && null === $batch->confirmed_at ) {
			$statuses['cancelled'] = array( __( 'Preview cancelled', 'pressqueue' ), 'idle', __( 'Cancelled or replaced by a newer preview. Nothing was shared.', 'pressqueue' ) );
		}

		$entry = $statuses[ $batch->status ] ?? array( $batch->status, 'idle', '' );

		return array(
			'help'  => $entry[2],
			'label' => $entry[0],
			'tone'  => $entry[1],
		);
	}

	/**
	 * Return the longer status explanation for the detail side card.
	 *
	 * @param BatchRecord $batch Batch.
	 * @return string
	 */
	private static function status_explanation( BatchRecord $batch ): string {
		switch ( $batch->status ) {
			case 'confirming':
			case 'queueing':
				return __( 'PressQueue is adding these shares to the queue in small steps. This page refreshes on its own.', 'pressqueue' );
			case 'handed_off':
				return self::batch_is_test( $batch )
					? __( 'Every share was recorded as a test run or skipped. Nothing was sent to Buffer.', 'pressqueue' )
					: __( 'Every share was added to the queue or skipped. Follow each share in Activity.', 'pressqueue' );
			case 'cancelled':
				return null === $batch->confirmed_at
					? __( 'This preview was cancelled or replaced by a newer preview before anyone shared it. Nothing was shared.', 'pressqueue' )
					: __( 'This batch was cancelled. Shares from it that were not sent yet were cancelled too.', 'pressqueue' );
			case 'expired':
				return __( 'This preview expired before anyone shared it. Nothing was shared. Previews last 30 minutes so the result is always current.', 'pressqueue' );
			case 'failed':
				return __( 'Something went wrong while adding the shares. Shares that were added are listed with a link to Activity.', 'pressqueue' );
		}

		return __( 'This preview is waiting for someone to review and share it.', 'pressqueue' );
	}

	/**
	 * Return a one-line summary for the detail screen.
	 *
	 * @param BatchRecord                $batch Batch.
	 * @param array<int,BatchItemRecord> $items Items.
	 * @return string
	 */
	private static function detail_summary( BatchRecord $batch, array $items ): string {
		$added   = 0;
		$pending = 0;
		$skipped = 0;
		foreach ( $items as $item ) {
			if ( 'queued' === $item->state ) {
				++$added;
			} elseif ( 'reserved' === $item->state ) {
				++$pending;
			} elseif ( in_array( $item->state, array( 'duplicate', 'excluded', 'policy_error', 'failed' ), true ) ) {
				++$skipped;
			}
		}

		$parts = array();
		if ( null !== $batch->confirmed_by && null !== $batch->confirmed_at ) {
			$parts[] = sprintf(
				/* translators: 1: user name, 2: date and time. */
				__( 'Confirmed by %1$s on %2$s.', 'pressqueue' ),
				self::user_name( $batch->confirmed_by ),
				Ui::local_datetime( $batch->confirmed_at )
			);
		} else {
			$parts[] = sprintf(
				/* translators: %s: user name. */
				__( 'Previewed by %s.', 'pressqueue' ),
				self::user_name( $batch->created_by )
			);
		}

		if ( null !== $batch->confirmed_at ) {
			$parts[] = sprintf(
				/* translators: %d: number of shares. */
				_n( '%d share added.', '%d shares added.', $added, 'pressqueue' ),
				$added
			);
		}

		if ( $pending > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of shares. */
				_n( '%d share is being added.', '%d shares are being added.', $pending, 'pressqueue' ),
				$pending
			);
		}

		if ( $skipped > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of shares. */
				_n( '%d skipped.', '%d skipped.', $skipped, 'pressqueue' ),
				$skipped
			);
		}

		return implode( ' ', $parts );
	}

	/**
	 * Return the totals line, for example "6 posts match, 18 shares in total".
	 *
	 * @param array<string,mixed> $review Review data.
	 * @return string
	 */
	private static function totals_line( array $review ): string {
		$line = sprintf(
			/* translators: 1: number of posts, 2: number of shares. */
			_n( '%1$d post matches, %2$d shares in total', '%1$d posts match, %2$d shares in total', (int) $review['post_count'], 'pressqueue' ),
			(int) $review['post_count'],
			(int) $review['share_count']
		);

		if ( 1 === (int) $review['share_count'] ) {
			$line = sprintf(
				/* translators: %d: number of posts. */
				_n( '%d post matches, 1 share in total', '%d posts match, 1 share in total', (int) $review['post_count'], 'pressqueue' ),
				(int) $review['post_count']
			);
		}

		if ( (int) $review['skipped_posts'] > 0 ) {
			$line .= ', ' . sprintf(
				/* translators: %d: number of posts. */
				_n( '%d post skipped', '%d posts skipped', (int) $review['skipped_posts'], 'pressqueue' ),
				(int) $review['skipped_posts']
			);
		}

		return $line;
	}

	/**
	 * Return the confirmation dialog text.
	 *
	 * @param BatchRecord         $batch Batch.
	 * @param array<string,mixed> $review Review data.
	 * @return string
	 */
	private static function confirm_text( BatchRecord $batch, array $review ): string {
		$posts  = (int) $review['post_count'];
		$shares = (int) $review['share_count'];

		if ( 'dry_run' === $review['delivery_mode'] ) {
			return sprintf(
				/* translators: %d: number of posts. */
				_n( 'Share %d post in Test mode?', 'Share %d posts in Test mode?', $posts, 'pressqueue' ),
				$posts
			) . ' ' . sprintf(
				/* translators: %d: number of test runs. */
				_n( 'PressQueue records %d test run in Activity.', 'PressQueue records %d test runs in Activity.', $shares, 'pressqueue' ),
				$shares
			) . ' ' . __( 'Nothing is sent to Buffer.', 'pressqueue' );
		}

		$question = sprintf(
			/* translators: %d: number of posts. */
			_n( 'Share %d post?', 'Share %d posts?', $posts, 'pressqueue' ),
			$posts
		);

		if ( 'paused' === self::current_mode() ) {
			return $question . ' ' . sprintf(
				/* translators: %d: number of shares. */
				_n( 'PressQueue is paused, so the %d share waits on hold until you resume sharing.', 'PressQueue is paused, so the %d shares wait on hold until you resume sharing.', $shares, 'pressqueue' ),
				$shares
			);
		}

		$text = $question . ' ' . sprintf(
			/* translators: %d: number of shares. */
			_n( 'PressQueue sends %d share to Buffer.', 'PressQueue sends %d shares to Buffer.', $shares, 'pressqueue' ),
			$shares
		) . ' ' . self::timing_sentence( $batch->batch_limits, ! empty( $review['all_drafts'] ) );

		if ( empty( $review['all_drafts'] ) ) {
			$text .= ' ' . __( 'Your followers see them as new posts.', 'pressqueue' );
		}

		return $text;
	}

	/**
	 * Return a sentence describing a batch's timing.
	 *
	 * @param array<string,mixed> $limits Batch limits.
	 * @param bool                $all_drafts Whether every share is saved as a Buffer draft.
	 * @return string
	 */
	private static function timing_sentence( array $limits, bool $all_drafts = false ): string {
		$schedule = isset( $limits['schedule'] ) && is_array( $limits['schedule'] ) ? $limits['schedule'] : array();
		$mode     = (string) ( $schedule['mode'] ?? 'inherit' );
		if ( 'inherit' === $mode && $all_drafts ) {
			$mode = 'draft';
		}

		switch ( $mode ) {
			case 'queue_end':
				return __( 'They go to the end of your Buffer queue.', 'pressqueue' );
			case 'draft':
				return __( 'They are saved as Buffer drafts. Nothing goes public until you schedule them in Buffer.', 'pressqueue' );
			case 'queue_next':
				return __( 'It goes to the next free slot in your Buffer queue.', 'pressqueue' );
			case 'immediate':
				return __( 'It is posted right away.', 'pressqueue' );
			case 'staggered_explicit_time':
				$first = is_string( $schedule['first_due_at_utc'] ?? null ) ? self::local_datetime_text( $schedule['first_due_at_utc'] ) : '';
				return sprintf(
					/* translators: 1: date and time, 2: gap such as "day" or "2 hours". */
					__( 'The first goes out on %1$s, then one every %2$s.', 'pressqueue' ),
					$first,
					self::interval_label( (int) ( $schedule['interval_minutes'] ?? self::DEFAULT_INTERVAL ) )
				);
		}

		return __( 'Each share uses the timing from its rule.', 'pressqueue' );
	}

	/**
	 * Return the preview expiry sentence.
	 *
	 * @param BatchRecord $batch Batch.
	 * @return string
	 */
	private static function expiry_text( BatchRecord $batch ): string {
		$minutes = (int) ceil( ( $batch->preview_expires_at->getTimestamp() - time() ) / 60 );

		return sprintf(
			/* translators: %d: minutes. */
			_n( 'This preview expires in %d minute.', 'This preview expires in %d minutes.', max( 1, $minutes ), 'pressqueue' ),
			max( 1, $minutes )
		) . ' ' . __( 'If a post or rule changes before you share, PressQueue asks you to preview again.', 'pressqueue' );
	}

	/**
	 * Build share cards for a post's queueable items.
	 *
	 * @param array<int,array<string,mixed>> $shares Share entries.
	 * @return array<int,array<string,mixed>>
	 */
	private static function share_cards( array $shares ): array {
		$deliveries = array();
		foreach ( $shares as $entry ) {
			$delivery = $entry['item']->preview_item['delivery'] ?? null;
			if ( is_array( $delivery ) ) {
				$deliveries[] = $delivery;
			}
		}

		if ( array() === $deliveries ) {
			return array();
		}

		try {
			return PreviewPresenter::summarize(
				array(
					'decisions'  => array(),
					'deliveries' => $deliveries,
				)
			)['shares'];
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			return array();
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Form handling
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Return default form values.
	 *
	 * @param array<string,array<int,RuleRecord>> $rules Participating rules.
	 * @return array<string,mixed>
	 */
	private static function form_defaults( array $rules = array() ): array {
		$type     = isset( $rules['bulk'] ) && array() === $rules['bulk'] && ! empty( $rules['repost'] ) ? 'repost' : 'bulk';
		$tomorrow = ( new DateTimeImmutable( 'tomorrow', self::site_timezone() ) )->format( 'Y-m-d' );
		$timing   = 'queue_end';

		foreach ( $rules[ $type ] ?? array() as $rule ) {
			if ( 'draft' === self::rule_schedule_mode( $rule ) ) {
				$timing = 'inherit';
			}
		}

		return array(
			'author'           => 0,
			'batch_type'       => $type,
			'category'         => 0,
			'interval'         => self::DEFAULT_INTERVAL,
			'max_posts'        => self::DEFAULT_MAX_POSTS,
			'post_type'        => 'post',
			'published_after'  => '',
			'published_before' => '',
			'repost_min_days'  => (string) self::DEFAULT_REPOST_DAYS,
			'start_date'       => $tomorrow,
			'start_time'       => '09:00',
			'tags'             => '',
			'timing'           => $timing,
			'title_search'     => '',
		);
	}

	/**
	 * Choose form values for the builder.
	 *
	 * @param BatchRecord|null                    $batch Previewed batch.
	 * @param array<string,array<int,RuleRecord>> $rules Participating rules.
	 * @return array<string,mixed>
	 */
	private static function builder_form( ?BatchRecord $batch, array $rules ): array {
		$remembered = self::remembered_form();

		if ( null !== $batch ) {
			if ( null !== $remembered && $remembered['batch_public_id'] === $batch->public_id ) {
				return $remembered['form'];
			}

			return self::form_from_batch( $batch, $rules );
		}

		$from = self::query_text( 'from', 26 );
		if ( '' !== $from ) {
			$source = self::load_batch( $from );
			if ( null !== $source ) {
				return self::form_from_batch( $source, $rules );
			}
		}

		if ( null !== $remembered && '' === $remembered['batch_public_id'] && 'batch_error' === self::query_text( 'pressqueue_message', 64 ) ) {
			return $remembered['form'];
		}

		return self::form_defaults( $rules );
	}

	/**
	 * Read and sanitize the guided form fields.
	 *
	 * @return array<string,mixed>
	 */
	private static function form_from_post(): array {
		$type = self::post_text( 'batch_type', 'bulk' );

		return array(
			'author'           => absint( self::post_text( 'author', '0' ) ),
			'batch_type'       => in_array( $type, array( 'bulk', 'repost' ), true ) ? $type : 'bulk',
			'category'         => absint( self::post_text( 'category', '0' ) ),
			'interval'         => absint( self::post_text( 'interval', (string) self::DEFAULT_INTERVAL ) ),
			'max_posts'        => (int) self::post_text( 'max_posts', (string) self::DEFAULT_MAX_POSTS ),
			'post_type'        => sanitize_key( self::post_text( 'post_type', 'post' ) ),
			'published_after'  => self::post_text( 'published_after' ),
			'published_before' => self::post_text( 'published_before' ),
			'repost_min_days'  => self::post_text( 'repost_min_days' ),
			'start_date'       => self::post_text( 'start_date' ),
			'start_time'       => self::post_text( 'start_time' ),
			'tags'             => substr( self::post_text( 'tags' ), 0, 500 ),
			'timing'           => sanitize_key( self::post_text( 'timing', 'queue_end' ) ),
			'title_search'     => substr( self::post_text( 'title_search' ), 0, 200 ),
		);
	}

	/**
	 * Rebuild form values from a stored batch.
	 *
	 * @param BatchRecord                         $batch Batch.
	 * @param array<string,array<int,RuleRecord>> $rules Participating rules.
	 * @return array<string,mixed>
	 */
	private static function form_from_batch( BatchRecord $batch, array $rules = array() ): array {
		$form    = self::form_defaults( $rules );
		$filters = $batch->filters;
		$limits  = $batch->batch_limits;

		$form['batch_type'] = $batch->batch_type;
		$form['post_type']  = is_array( $filters['post_types'] ?? null ) && is_string( $filters['post_types'][0] ?? null ) ? $filters['post_types'][0] : 'post';

		foreach ( is_array( $filters['taxonomies'] ?? null ) ? $filters['taxonomies'] : array() as $taxonomy ) {
			if ( ! is_array( $taxonomy ) || 'has_any' !== ( $taxonomy['operator'] ?? null ) || ! is_array( $taxonomy['term_ids'] ?? null ) ) {
				continue;
			}

			if ( 'category' === ( $taxonomy['taxonomy'] ?? null ) && isset( $taxonomy['term_ids'][0] ) ) {
				$form['category'] = (int) $taxonomy['term_ids'][0];
			} elseif ( 'post_tag' === ( $taxonomy['taxonomy'] ?? null ) ) {
				$form['tags'] = self::tag_names( $taxonomy['term_ids'] );
			}
		}

		if ( is_string( $filters['published_from_utc'] ?? null ) ) {
			$form['published_after'] = self::local_date( $filters['published_from_utc'], 'Y-m-d' );
		}
		if ( is_string( $filters['published_to_utc'] ?? null ) ) {
			$form['published_before'] = self::local_date( $filters['published_to_utc'], 'Y-m-d' );
		}
		if ( is_array( $filters['author_ids'] ?? null ) && isset( $filters['author_ids'][0] ) ) {
			$form['author'] = (int) $filters['author_ids'][0];
		}
		if ( 'repost' === $batch->batch_type ) {
			$form['repost_min_days'] = isset( $filters['minimum_days_since_prior_acceptance'] ) ? (string) (int) $filters['minimum_days_since_prior_acceptance'] : '';
		}
		if ( is_array( $filters['post_ids'] ?? null ) ) {
			$form['picked_posts'] = count( $filters['post_ids'] );
		}

		$form['max_posts'] = (int) ( $limits['candidate_limit'] ?? self::DEFAULT_MAX_POSTS );
		$schedule          = isset( $limits['schedule'] ) && is_array( $limits['schedule'] ) ? $limits['schedule'] : array();
		$mode              = (string) ( $schedule['mode'] ?? 'inherit' );
		$form['timing']    = in_array( $mode, self::TIMINGS, true ) ? $mode : 'inherit';

		if ( 'staggered_explicit_time' === $mode && is_string( $schedule['first_due_at_utc'] ?? null ) ) {
			$form['start_date'] = self::local_date( $schedule['first_due_at_utc'], 'Y-m-d' );
			$form['start_time'] = self::local_date( $schedule['first_due_at_utc'], 'H:i' );
			$form['interval']   = (int) ( $schedule['interval_minutes'] ?? self::DEFAULT_INTERVAL );
		}

		return $form;
	}

	/**
	 * Turn guided form values into the batch filter and limit documents.
	 *
	 * @param array<string,mixed> $form Form values.
	 * @return array{batch_type:string,filters:array<string,mixed>,limits:array<string,mixed>}
	 */
	private static function documents_from_form( array $form ): array {
		$type      = 'repost' === $form['batch_type'] ? 'repost' : 'bulk';
		$post_type = (string) $form['post_type'];
		if ( '' === $post_type || ( function_exists( 'post_type_exists' ) && ! post_type_exists( $post_type ) ) ) {
			throw new DomainException( 'post_type' );
		}

		$filters = array(
			'post_statuses'  => array( 'publish' ),
			'post_types'     => array( $post_type ),
			'schema_version' => 1,
		);

		$taxonomies = array();
		if ( (int) $form['category'] > 0 ) {
			$taxonomies[] = array(
				'operator' => 'has_any',
				'taxonomy' => 'category',
				'term_ids' => array( (int) $form['category'] ),
			);
		}

		$tag_ids = self::tag_ids( (string) $form['tags'] );
		if ( array() !== $tag_ids ) {
			$taxonomies[] = array(
				'operator' => 'has_any',
				'taxonomy' => 'post_tag',
				'term_ids' => $tag_ids,
			);
		}

		if ( array() !== $taxonomies ) {
			$filters['taxonomies'] = $taxonomies;
		}

		$from = '' === (string) $form['published_after'] ? '' : self::local_day_to_utc( (string) $form['published_after'], false );
		$to   = '' === (string) $form['published_before'] ? '' : self::local_day_to_utc( (string) $form['published_before'], true );
		if ( '' !== $from && '' !== $to && $from > $to ) {
			throw new DomainException( 'date_order' );
		}
		if ( '' !== $from ) {
			$filters['published_from_utc'] = $from;
		}
		if ( '' !== $to ) {
			$filters['published_to_utc'] = $to;
		}

		if ( (int) $form['author'] > 0 ) {
			$filters['author_ids'] = array( (int) $form['author'] );
		}

		if ( 'repost' === $type && '' !== trim( (string) $form['repost_min_days'] ) ) {
			$days = (int) $form['repost_min_days'];
			if ( 1 !== preg_match( '/^\d+$/', trim( (string) $form['repost_min_days'] ) ) || $days < 1 || $days > 3650 ) {
				throw new DomainException( 'repost_days' );
			}
			$filters['minimum_days_since_prior_acceptance'] = $days;
		}

		$max = (int) $form['max_posts'];
		if ( $max < 1 || $max > BatchLimitService::MAX_CANDIDATE_LIMIT ) {
			throw new DomainException( 'max_posts' );
		}

		if ( '' !== trim( (string) $form['title_search'] ) ) {
			$ids = self::title_search_ids( $filters, trim( (string) $form['title_search'] ), $max );
			if ( array() === $ids ) {
				throw new DomainException( 'no_title_match' );
			}
			$filters['post_ids'] = $ids;
		}

		return array(
			'batch_type' => $type,
			'filters'    => $filters,
			'limits'     => array(
				'candidate_limit'        => $max,
				'destination_item_limit' => BatchLimitService::MAX_DESTINATION_ITEMS,
				'schedule'               => self::schedule_from_form( $form ),
				'schema_version'         => 1,
			),
		);
	}

	/**
	 * Build the schedule override document.
	 *
	 * @param array<string,mixed> $form Form values.
	 * @return array<string,mixed>
	 */
	private static function schedule_from_form( array $form ): array {
		$mode = (string) $form['timing'];
		if ( ! in_array( $mode, self::TIMINGS, true ) ) {
			throw new DomainException( 'timing' );
		}

		if ( 'staggered_explicit_time' !== $mode ) {
			return array(
				'mode'           => $mode,
				'schema_version' => 1,
			);
		}

		$date = (string) $form['start_date'];
		$time = (string) $form['start_time'];
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || 1 !== preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
			throw new DomainException( 'start_invalid' );
		}

		$local = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $date . ' ' . $time, self::site_timezone() );
		if ( false === $local || $local->format( 'Y-m-d H:i' ) !== $date . ' ' . $time ) {
			throw new DomainException( 'start_invalid' );
		}

		if ( $local->getTimestamp() < time() + ScheduleResolver::MINIMUM_LEAD_SECONDS ) {
			throw new DomainException( 'start_soon' );
		}

		$interval = (int) $form['interval'];
		if ( ! in_array( $interval, self::INTERVALS, true ) ) {
			throw new DomainException( 'interval' );
		}

		return array(
			'first_due_at_utc' => $local->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			'interval_minutes' => $interval,
			'mode'             => 'staggered_explicit_time',
			'schema_version'   => 1,
			'timezone'         => self::timezone_name(),
		);
	}

	/**
	 * Resolve comma-separated tag names to term IDs.
	 *
	 * @param string $names Tag names.
	 * @return array<int,int>
	 */
	private static function tag_ids( string $names ): array {
		$ids = array();

		foreach ( array_slice( array_unique( array_filter( array_map( 'trim', explode( ',', $names ) ) ) ), 0, self::MAX_TAGS ) as $name ) {
			$term = function_exists( 'get_term_by' ) ? get_term_by( 'name', $name, 'post_tag' ) : false;
			if ( ! $term instanceof \WP_Term && function_exists( 'get_term_by' ) ) {
				$term = get_term_by( 'slug', sanitize_title( $name ), 'post_tag' );
			}

			if ( ! $term instanceof \WP_Term ) {
				throw new DomainException( 'unknown_tag' );
			}

			$ids[] = (int) $term->term_id;
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Return tag names for term IDs.
	 *
	 * @param array<int,mixed> $term_ids Term IDs.
	 * @return string
	 */
	private static function tag_names( array $term_ids ): string {
		$names = array();

		foreach ( $term_ids as $term_id ) {
			$term = function_exists( 'get_term' ) ? get_term( (int) $term_id, 'post_tag' ) : null;
			if ( $term instanceof \WP_Term ) {
				$names[] = html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' );
			}
		}

		return implode( ', ', $names );
	}

	/**
	 * Find published posts whose title contains the search text.
	 *
	 * The same filters apply, so the post list matches every other choice.
	 *
	 * @param array<string,mixed> $filters Filters built so far.
	 * @param string              $search Search text.
	 * @param int                 $limit Maximum posts.
	 * @return array<int,int>
	 */
	private static function title_search_ids( array $filters, string $search, int $limit ): array {
		if ( ! class_exists( 'WP_Query' ) ) {
			return array();
		}

		$args = array(
			'fields'                 => 'ids',
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'orderby'                => array(
				'modified' => 'DESC',
				'ID'       => 'DESC',
			),
			'post_status'            => 'publish',
			'post_type'              => $filters['post_types'],
			'posts_per_page'         => $limit,
			's'                      => $search,
			'search_columns'         => array( 'post_title' ),
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- Match the batch candidate query, which bypasses unrelated frontend filters.
			'suppress_filters'       => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		if ( isset( $filters['author_ids'] ) ) {
			$args['author__in'] = $filters['author_ids'];
		}

		$date = array( 'relation' => 'AND' );
		if ( isset( $filters['published_from_utc'] ) || isset( $filters['published_to_utc'] ) ) {
			$clause = array(
				'column'    => 'post_date_gmt',
				'inclusive' => true,
			);
			if ( isset( $filters['published_from_utc'] ) ) {
				$clause['after'] = $filters['published_from_utc'];
			}
			if ( isset( $filters['published_to_utc'] ) ) {
				$clause['before'] = $filters['published_to_utc'];
			}
			$date[]             = $clause;
			$args['date_query'] = $date;
		}

		if ( isset( $filters['taxonomies'] ) ) {
			$tax = array( 'relation' => 'AND' );
			foreach ( $filters['taxonomies'] as $taxonomy ) {
				$tax[] = array(
					'field'            => 'term_id',
					'include_children' => false,
					'operator'         => 'IN',
					'taxonomy'         => $taxonomy['taxonomy'],
					'terms'            => $taxonomy['term_ids'],
				);
			}
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Bounded, operator-requested batch search.
			$args['tax_query'] = $tax;
		}

		$query = new \WP_Query( $args );

		return array_map( 'intval', is_array( $query->posts ) ? $query->posts : array() );
	}

	/**
	 * Remember the last submitted form for this user so the screen can show it again.
	 *
	 * @param array<string,mixed> $form Form values.
	 * @param string              $batch_public_id Batch created from it, or empty on error.
	 */
	private static function remember_form( array $form, string $batch_public_id ): void {
		if ( function_exists( 'set_transient' ) ) {
			set_transient(
				self::form_transient_key(),
				array(
					'batch_public_id' => $batch_public_id,
					'form'            => $form,
				),
				self::FORM_TTL_SECONDS
			);
		}
	}

	/**
	 * Return the remembered form.
	 *
	 * @return array{batch_public_id:string,form:array<string,mixed>}|null
	 */
	private static function remembered_form(): ?array {
		$value = function_exists( 'get_transient' ) ? get_transient( self::form_transient_key() ) : false;
		if ( ! is_array( $value ) || ! is_string( $value['batch_public_id'] ?? null ) || ! is_array( $value['form'] ?? null ) ) {
			return null;
		}

		$form = array_merge( self::form_defaults(), array_intersect_key( $value['form'], self::form_defaults() ) );

		return array(
			'batch_public_id' => $value['batch_public_id'],
			'form'            => $form,
		);
	}

	/**
	 * Return the per-user transient key.
	 *
	 * @return string
	 */
	private static function form_transient_key(): string {
		return 'pressqueue_batch_form_' . (string) self::current_user_id();
	}

	/*
	 * ---------------------------------------------------------------------
	 * Notices and redirects
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Render the result notice.
	 */
	private static function render_notice(): void {
		$message  = self::query_text( 'pressqueue_message', 64 );
		$messages = array(
			'batch_previewed' => __( 'Preview ready. Check the posts, pick the timing, then share.', 'pressqueue' ),
			'batch_confirmed' => __( 'Batch started. PressQueue is adding the shares now.', 'pressqueue' ),
			'batch_cancelled' => __( 'Batch cancelled. Shares from it that were not sent yet are cancelled.', 'pressqueue' ),
			'batch_recovered' => __( 'Restarted. PressQueue continues adding the shares.', 'pressqueue' ),
		);

		if ( isset( $messages[ $message ] ) ) {
			Ui::notice( 'success', $messages[ $message ] );
			return;
		}

		if ( 'batch_error' !== $message ) {
			return;
		}

		$code   = self::query_text( 'pressqueue_error', 32 );
		$errors = self::error_messages();
		$text   = $errors[ $code ] ?? $errors['unknown'];
		$detail = SafeOperatorError::notice_detail( self::query_text( 'pressqueue_detail', 191 ) );

		echo '<div class="notice notice-error"><p>' . esc_html( $text ) . '</p>';
		if ( '' !== $detail ) {
			echo '<details class="pq-more pq-small"><summary>' . esc_html__( 'Technical details', 'pressqueue' ) . '</summary><p class="pq-more-body pq-mono">' . esc_html( $detail ) . '</p></details>';
		}
		echo '</div>';
	}

	/**
	 * Return plain error messages by code.
	 *
	 * @return array<string,string>
	 */
	private static function error_messages(): array {
		return array(
			'busy'            => __( 'This batch changed at the same moment. Reload the page and try again.', 'pressqueue' ),
			'changed'         => __( 'A post, rule or channel changed after the preview. Update the preview to see the current result, then share.', 'pressqueue' ),
			'date_order'      => __( 'The Published after date must come before the Published before date.', 'pressqueue' ),
			'interval'        => __( 'Pick how often a share goes out from the list.', 'pressqueue' ),
			'max_posts'       => sprintf(
				/* translators: %d: maximum posts per batch. */
				__( 'At most must be a number between 1 and %d.', 'pressqueue' ),
				BatchLimitService::MAX_CANDIDATE_LIMIT
			),
			'missing'         => __( 'That batch no longer exists.', 'pressqueue' ),
			'no_rule'         => __( 'No rule takes part in this kind of batch yet. Set one up in Rules first.', 'pressqueue' ),
			'no_title_match'  => __( 'No published post has that text in its title. Check the spelling or clear Title contains.', 'pressqueue' ),
			'not_confirmable' => __( 'This preview can no longer be shared. It expired, was cancelled or was already shared. Preview again.', 'pressqueue' ),
			'permission'      => __( 'You are not allowed to share old posts.', 'pressqueue' ),
			'post_gone'       => __( 'One of the posts changed or is no longer published. Preview again.', 'pressqueue' ),
			'post_type'       => __( 'Pick a post type from the list.', 'pressqueue' ),
			'repost_days'     => __( 'Enter a number of days between 1 and 3650, or leave it empty.', 'pressqueue' ),
			'runtime'         => __( 'Background sharing is stopped, so batches cannot run right now. Open Settings, then Data and privacy, to see why.', 'pressqueue' ),
			'single_timing'   => __( 'Post right away and Next free queue slot only work when the batch has one share. Pick another timing and preview again.', 'pressqueue' ),
			'start_far'       => __( 'The last share would be more than a year away. Pick an earlier start or a shorter gap.', 'pressqueue' ),
			'start_invalid'   => __( 'Enter a valid date and time for the first share.', 'pressqueue' ),
			'start_soon'      => __( 'Pick a first share time at least 15 minutes from now.', 'pressqueue' ),
			'term'            => __( 'A category or tag you picked no longer exists. Pick another one and preview again.', 'pressqueue' ),
			'timing'          => __( 'Pick one of the timing options.', 'pressqueue' ),
			'too_many'        => sprintf(
				/* translators: %d: maximum shares per batch. */
				__( 'This batch would create more than %d shares. Lower At most or narrow your choices, then preview again.', 'pressqueue' ),
				BatchLimitService::MAX_DESTINATION_ITEMS
			),
			'unknown'         => __( 'PressQueue could not do that. Try again. If it keeps failing, check Activity for errors.', 'pressqueue' ),
			'unknown_tag'     => __( 'One of the tags does not exist. Check the spelling in Tags, or leave it empty.', 'pressqueue' ),
		);
	}

	/**
	 * Map a failure to a plain error code.
	 *
	 * Share now in the post editor maps the same failures to its own words.
	 *
	 * @param Throwable $throwable Failure.
	 * @return string
	 */
	public static function error_code( Throwable $throwable ): string {
		if ( $throwable instanceof DomainException ) {
			return isset( self::error_messages()[ $throwable->getMessage() ] ) ? $throwable->getMessage() : 'unknown';
		}

		if ( $throwable instanceof StaleRecordException ) {
			return 'busy';
		}

		if ( $throwable instanceof ValidationException && isset( self::DOMAIN_ERRORS[ $throwable->getMessage() ] ) ) {
			return self::DOMAIN_ERRORS[ $throwable->getMessage() ];
		}

		return 'unknown';
	}

	/**
	 * Redirect after a failure.
	 *
	 * @param Throwable   $throwable Failure.
	 * @param string|null $batch_public_id Batch to return to.
	 * @param bool        $keep_form Whether the remembered form should be shown.
	 */
	private static function redirect_error( Throwable $throwable, ?string $batch_public_id = null, bool $keep_form = false ): void {
		$args = array( 'pressqueue_error' => self::error_code( $throwable ) );

		if ( 'unknown' === $args['pressqueue_error'] ) {
			$args['pressqueue_detail'] = SafeOperatorError::redirect_detail( $throwable );
		}

		if ( null !== $batch_public_id && '' !== $batch_public_id && ! $keep_form ) {
			$args['batch_public_id'] = $batch_public_id;
		}

		self::redirect( 'batch_error', $args );
	}

	/**
	 * Redirect to the batch page.
	 *
	 * @param string               $message Message key.
	 * @param array<string,string> $args Extra query args.
	 */
	private static function redirect( string $message, array $args = array() ): void {
		$args = array_filter(
			array_merge(
				array(
					'page'               => self::SLUG,
					'pressqueue_message' => $message,
				),
				$args
			),
			static function ( string $value ): bool {
				return '' !== $value;
			}
		);

		wp_safe_redirect( add_query_arg( array_map( 'rawurlencode', $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Render the stopped-runtime callout.
	 */
	private static function render_runtime_callout(): void {
		echo '<div class="pq-callout pq-callout--bad pq-batch-runtime"><p><strong>' . esc_html__( 'Background sharing is stopped.', 'pressqueue' ) . '</strong> ' . esc_html__( 'Batches cannot be previewed or shared until it runs again.', 'pressqueue' );
		if ( current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			echo ' <a href="' . esc_url( Ui::url( Ui::PAGE_PRIVACY ) ) . '">' . esc_html__( 'See why in Data and privacy', 'pressqueue' ) . '</a>';
		}
		echo '</p></div>';
	}

	/*
	 * ---------------------------------------------------------------------
	 * Small helpers
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Return the two batch types in plain words.
	 *
	 * @return array<string,array{label:string,help:string,no_rule:string,rules_note:string,tick:string}>
	 */
	private static function batch_types(): array {
		return array(
			'bulk'   => array(
				'help'       => __( 'Posts that have no share yet on the channels your rules pick.', 'pressqueue' ),
				'label'      => __( 'Never shared before', 'pressqueue' ),
				'no_rule'    => __( 'No rule is set up for old posts yet.', 'pressqueue' ),
				'rules_note' => __( 'Your rules still decide the channels and the message. Only rules with "I pick old posts in Share old posts" ticked take part.', 'pressqueue' ),
				/* translators: %s: link to the Rules screen. */
				'tick'       => __( 'Tick "I pick old posts in Share old posts" in a rule under %s.', 'pressqueue' ),
			),
			'repost' => array(
				'help'       => __( 'Posts Buffer accepted before. Each goes again only to channels it was sent to, and only after the gap you choose.', 'pressqueue' ),
				'label'      => __( 'Share again', 'pressqueue' ),
				'no_rule'    => __( 'No rule is set up to share posts again yet.', 'pressqueue' ),
				'rules_note' => __( 'Your rules still decide the channels and the message. Only rules with "I pick shared posts to share again" ticked take part.', 'pressqueue' ),
				/* translators: %s: link to the Rules screen. */
				'tick'       => __( 'Tick "I pick shared posts to share again" in a rule under %s.', 'pressqueue' ),
			),
		);
	}

	/**
	 * Return timing choices in words.
	 *
	 * @param array<string,array<int,RuleRecord>> $rules Participating rules.
	 * @param string                              $type Current batch type.
	 * @return array<string,array{label:string,help:string}>
	 */
	private static function timing_choices( array $rules, string $type ): array {
		$rule_timing = array();
		foreach ( $rules[ $type ] ?? array() as $rule ) {
			$mode          = self::rule_schedule_mode( $rule );
			$rule_timing[] = sprintf(
				/* translators: 1: rule name, 2: timing such as "end of the Buffer queue". */
				__( '%1$s: %2$s', 'pressqueue' ),
				$rule->name,
				'' === $mode ? __( 'its own timing', 'pressqueue' ) : Labels::schedule_phrase( $mode )
			);
		}

		$inherit_help = __( 'Each share follows the timing set in its rule.', 'pressqueue' );
		if ( array() !== $rule_timing ) {
			$inherit_help .= ' ' . implode( '. ', $rule_timing ) . '.';
		}

		return array(
			'queue_end'               => array(
				'help'  => __( 'Each share goes to the end of your Buffer queue and goes out in your next free time slots. Recommended.', 'pressqueue' ),
				'label' => __( 'Spread across the Buffer queue', 'pressqueue' ),
			),
			'staggered_explicit_time' => array(
				'help'  => __( 'You pick when the first share goes out and how long to wait between shares.', 'pressqueue' ),
				'label' => __( 'One at a time from a start time', 'pressqueue' ),
			),
			'draft'                   => array(
				'help'  => __( 'Nothing goes public. Review and schedule each one in Buffer yourself.', 'pressqueue' ),
				'label' => __( 'Save all as Buffer drafts', 'pressqueue' ),
			),
			'inherit'                 => array(
				'help'  => $inherit_help,
				'label' => __( 'Use each rule\'s timing', 'pressqueue' ),
			),
			'queue_next'              => array(
				'help'  => __( 'Goes ahead of everything waiting in your Buffer queue. Only works when the batch has one share.', 'pressqueue' ),
				'label' => __( 'Next free queue slot', 'pressqueue' ),
			),
			'immediate'               => array(
				'help'  => __( 'Buffer publishes it within a few minutes. Only works when the batch has one share.', 'pressqueue' ),
				'label' => __( 'Post right away', 'pressqueue' ),
			),
		);
	}

	/**
	 * Return enabled include rules that take part, by batch type.
	 *
	 * @return array<string,array<int,RuleRecord>>
	 */
	private static function participating_rules(): array {
		$result = array(
			'bulk'   => array(),
			'repost' => array(),
		);

		try {
			$repository = new RuleRepository();
			foreach ( array_keys( $result ) as $trigger ) {
				foreach ( $repository->find_enabled_for_trigger( $trigger ) as $rule ) {
					if ( 'include' === $rule->effect ) {
						$result[ $trigger ][] = $rule;
					}
				}
			}
		} catch ( Throwable $throwable ) {
			unset( $throwable );
		}

		return $result;
	}

	/**
	 * Return a rule's schedule mode, or an empty string when unreadable.
	 *
	 * @param RuleRecord $rule Rule.
	 * @return string
	 */
	private static function rule_schedule_mode( RuleRecord $rule ): string {
		try {
			return (string) ( RuleDocuments::schedule_policy( $rule->schedule_policy_document )['mode'] ?? '' );
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			return '';
		}
	}

	/**
	 * Return a comma-separated list of rule names.
	 *
	 * @param array<int,RuleRecord> $rules Rules.
	 * @return string
	 */
	private static function rule_names( array $rules ): string {
		return implode(
			', ',
			array_map(
				static function ( RuleRecord $rule ): string {
					return $rule->name;
				},
				$rules
			)
		);
	}

	/**
	 * Return a rule name by public ID.
	 *
	 * @param string $public_id Rule public ID.
	 * @return string
	 */
	private static function rule_name( string $public_id ): string {
		try {
			return ( new ActivitySummary() )->rule_name( $public_id );
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			return '';
		}
	}

	/**
	 * Return a link to the Rules screen, or the plain word when the viewer cannot open it.
	 *
	 * @return string Escaped markup.
	 */
	private static function rules_link(): string {
		$label = esc_html__( 'Rules', 'pressqueue' );

		if ( ! current_user_can( Capabilities::MANAGE_RULES ) ) {
			return $label;
		}

		return '<a href="' . esc_url( Ui::url( Ui::PAGE_RULES ) ) . '">' . $label . '</a>';
	}

	/**
	 * Return the public post types a batch can pick from.
	 *
	 * @return array<string,string>
	 */
	private static function post_type_choices(): array {
		$choices = array();
		$objects = function_exists( 'get_post_types' ) ? get_post_types( array( 'public' => true ), 'objects' ) : array();

		foreach ( $objects as $slug => $object ) {
			if ( 'attachment' === $slug || ! is_object( $object ) ) {
				continue;
			}
			$label            = isset( $object->labels->name ) && is_string( $object->labels->name ) ? $object->labels->name : (string) $slug;
			$choices[ $slug ] = $label;
		}

		if ( ! isset( $choices['post'] ) ) {
			$choices = array( 'post' => __( 'Posts', 'pressqueue' ) ) + $choices;
		}

		return $choices;
	}

	/**
	 * Return the numbered step marker.
	 *
	 * @param int $number Step number.
	 * @return string
	 */
	private static function step_dot( int $number ): string {
		return '<span class="pq-step-dot" aria-hidden="true">' . esc_html( (string) $number ) . '</span><span class="screen-reader-text">' . esc_html(
			sprintf(
				/* translators: %d: step number. */
				__( 'Step %d:', 'pressqueue' ),
				$number
			)
		) . ' </span>';
	}

	/**
	 * Return a channel badge and name.
	 *
	 * @param array{service:string,name:string} $channel Channel facts.
	 * @return string
	 */
	private static function channel_markup( array $channel ): string {
		if ( '' === $channel['service'] ) {
			return '<span class="pq-channel-name">' . esc_html( $channel['name'] ) . '</span>';
		}

		return Ui::channel_name( $channel['service'], $channel['name'] );
	}

	/**
	 * Return "6 posts, 15 shares".
	 *
	 * @param int $posts Posts.
	 * @param int $shares Shares.
	 * @return string
	 */
	private static function posts_and_shares( int $posts, int $shares ): string {
		return sprintf(
			/* translators: %d: number of posts. */
			_n( '%d post', '%d posts', $posts, 'pressqueue' ),
			$posts
		) . ', ' . sprintf(
			/* translators: %d: number of shares. */
			_n( '%d share', '%d shares', $shares, 'pressqueue' ),
			$shares
		);
	}

	/**
	 * Return a readable gap such as "day" or "2 hours".
	 *
	 * @param int $minutes Minutes.
	 * @return string
	 */
	private static function interval_label( int $minutes ): string {
		if ( 1440 === $minutes ) {
			return __( 'day', 'pressqueue' );
		}

		if ( 60 === $minutes ) {
			return __( 'hour', 'pressqueue' );
		}

		if ( 0 === $minutes % 60 ) {
			$hours = intdiv( $minutes, 60 );
			/* translators: %d: number of hours. */
			return sprintf( _n( '%d hour', '%d hours', $hours, 'pressqueue' ), $hours );
		}

		/* translators: %d: number of minutes. */
		return sprintf( _n( '%d minute', '%d minutes', $minutes, 'pressqueue' ), $minutes );
	}

	/**
	 * Return a user display name.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private static function user_name( int $user_id ): string {
		$user = $user_id > 0 && function_exists( 'get_userdata' ) ? get_userdata( $user_id ) : false;
		if ( $user instanceof \WP_User && '' !== (string) $user->display_name ) {
			return (string) $user->display_name;
		}

		/* translators: %d: user ID. */
		return sprintf( __( 'User #%d', 'pressqueue' ), $user_id );
	}

	/**
	 * Return the current sharing mode.
	 *
	 * @return string
	 */
	private static function current_mode(): string {
		try {
			return Ui::mode();
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			return '';
		}
	}

	/**
	 * Check whether a batch recorded test runs.
	 *
	 * @param BatchRecord $batch Batch.
	 * @return bool
	 */
	private static function batch_is_test( BatchRecord $batch ): bool {
		foreach ( self::load_items( $batch ) as $item ) {
			$mode = $item->preview_item['delivery']['delivery_mode'] ?? null;
			if ( is_string( $mode ) ) {
				return 'dry_run' === $mode;
			}
		}

		return false;
	}

	/**
	 * Load a batch and expire it when due.
	 *
	 * @param string $public_id Batch public ID.
	 * @return BatchRecord|null
	 */
	private static function load_batch( string $public_id ): ?BatchRecord {
		try {
			$repository = new BatchRepository();
			$batch      = $repository->find_by_public_id( $public_id );

			return null === $batch ? null : $repository->expire_if_due( $batch );
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			return null;
		}
	}

	/**
	 * Load a batch's items.
	 *
	 * @param BatchRecord $batch Batch.
	 * @return array<int,BatchItemRecord>
	 */
	private static function load_items( BatchRecord $batch ): array {
		try {
			return ( new BatchItemRepository() )->find_by_batch( $batch->public_id );
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			return array();
		}
	}

	/**
	 * Return a batch detail URL.
	 *
	 * @param string $public_id Batch public ID.
	 * @return string
	 */
	private static function batch_url( string $public_id ): string {
		return Ui::url( self::SLUG, array( 'batch_public_id' => $public_id ) );
	}

	/**
	 * Return the site timezone.
	 *
	 * @return DateTimeZone
	 */
	private static function site_timezone(): DateTimeZone {
		return function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	}

	/**
	 * Return the site timezone name.
	 *
	 * @return string
	 */
	private static function timezone_name(): string {
		$name = self::site_timezone()->getName();

		return '' === $name ? 'UTC' : $name;
	}

	/**
	 * Convert a local calendar day to the UTC start or end of that day.
	 *
	 * @param string $date Local date as Y-m-d.
	 * @param bool   $end_of_day Whether to return the last second of the day.
	 * @return string UTC timestamp.
	 */
	private static function local_day_to_utc( string $date, bool $end_of_day ): string {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			throw new DomainException( 'date_order' );
		}

		$local = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $date . ( $end_of_day ? ' 23:59:59' : ' 00:00:00' ), self::site_timezone() );
		if ( false === $local || $local->format( 'Y-m-d' ) !== $date ) {
			throw new DomainException( 'date_order' );
		}

		return $local->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Format a UTC timestamp in the site timezone.
	 *
	 * @param string $utc UTC timestamp.
	 * @param string $format Optional PHP date format. Defaults to the site date format.
	 * @return string
	 */
	private static function local_date( string $utc, string $format = '' ): string {
		$timestamp = strtotime( $utc . ' UTC' );
		if ( false === $timestamp ) {
			return '';
		}

		if ( '' === $format ) {
			$format = function_exists( 'get_option' ) ? (string) get_option( 'date_format', 'M j, Y' ) : 'M j, Y';
		}

		if ( function_exists( 'wp_date' ) ) {
			$date = wp_date( $format, $timestamp );
			return is_string( $date ) ? $date : '';
		}

		return gmdate( $format, $timestamp );
	}

	/**
	 * Format a UTC timestamp as a local date and time.
	 *
	 * @param string $utc UTC timestamp.
	 * @return string
	 */
	private static function local_datetime_text( string $utc ): string {
		$format = function_exists( 'get_option' ) ? trim( get_option( 'date_format', 'M j, Y' ) . ' ' . get_option( 'time_format', 'H:i' ) ) : 'M j, Y H:i';

		return self::local_date( $utc, $format );
	}

	/**
	 * Decode a POST JSON object for the older raw-document form.
	 *
	 * @param string $key Key.
	 * @return array<string,mixed>
	 */
	private static function post_json_object( string $key ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked by caller and JSON is decoded structurally.
		$raw = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
		if ( ! is_string( $raw ) ) {
			throw new ValidationException( 'JSON field is invalid.' );
		}

		$value = json_decode( $raw, true, 512, JSON_THROW_ON_ERROR );
		if ( ! is_array( $value ) || ( array() !== $value && array_is_list( $value ) ) ) {
			throw new ValidationException( 'JSON field must contain an object.' );
		}

		return $value;
	}

	/**
	 * Return POST text.
	 *
	 * @param string $key Key.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	private static function post_text( string $key, string $fallback = '' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers check nonces before reading mutations.
		$value = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : $fallback;

		return is_string( $value ) ? $value : $fallback;
	}

	/**
	 * Return bounded query text.
	 *
	 * @param string $key Key.
	 * @param int    $maximum Maximum length.
	 * @return string
	 */
	private static function query_text( string $key, int $maximum ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display query args.
		$value = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';

		return strlen( $value ) > $maximum ? substr( $value, 0, $maximum ) : $value;
	}

	/**
	 * Return a sanitized query key.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private static function query_key( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing query arg.
		return isset( $_GET[ $key ] ) ? sanitize_key( wp_unslash( $_GET[ $key ] ) ) : '';
	}

	/**
	 * Return current user ID.
	 *
	 * @return int
	 */
	private static function current_user_id(): int {
		return function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	}

	/**
	 * Require batch operation capability.
	 */
	private static function require_capability(): void {
		if ( ! current_user_can( Capabilities::OPERATE_BATCHES ) ) {
			wp_die( esc_html__( 'You are not allowed to operate PressQueue batches.', 'pressqueue' ) );
		}
	}
}
