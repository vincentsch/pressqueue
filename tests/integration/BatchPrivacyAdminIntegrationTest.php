<?php
/**
 * Share old posts and Data and privacy screen integration tests.
 *
 * @package PressQueue\Tests\Integration
 */

declare(strict_types=1);

namespace PressQueue\Tests\Integration;

use DomainException;
use PressQueue\Admin\BatchController;
use PressQueue\Admin\PrivacyController;
use PressQueue\Batch\BatchConfirmationService;
use PressQueue\Batch\BatchPreviewService;
use PressQueue\Batch\BatchQueueService;
use PressQueue\Privacy\PrivacySettings;
use PressQueue\Privacy\RetentionStateStore;
use PressQueue\Runtime\RuntimeDisabledLatch;
use PressQueue\Scheduler\ActionSchedulerQueue;
use PressQueue\Security\Capabilities;
use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\Database;
use PressQueue\Storage\Record\BatchRecord;
use PressQueue\Storage\Record\RuleRecord;
use PressQueue\Storage\Repository\BatchItemRepository;
use PressQueue\Storage\Repository\BatchRepository;
use PressQueue\Storage\Repository\ChannelRepository;
use PressQueue\Storage\Repository\ConnectionRepository;
use PressQueue\Storage\Repository\RuleRepository;
use PressQueue\Storage\Schema;
use PressQueue\Storage\SchemaMigrator;
use PressQueue\Storage\SettingsRepository;
use PressQueue\Tests\Support\PressQueueIntegrationTestCase as WP_UnitTestCase;
use ReflectionMethod;

/**
 * @covers \PressQueue\Admin\BatchController
 * @covers \PressQueue\Admin\PrivacyController
 */
final class BatchPrivacyAdminIntegrationTest extends WP_UnitTestCase {
	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private int $admin_id = 0;

	/**
	 * Posts created by the current test.
	 *
	 * @var array<int,int>
	 */
	private array $post_ids = array();

	protected function setUp(): void {
		parent::setUp();

		$this->resetStorage();
		( new SchemaMigrator( Database::from_globals() ) )->migrate();
		RuntimeDisabledLatch::open();
		Capabilities::grant_to_administrators( false );
		$this->admin_id = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
		( new SettingsRepository() )->set_dry_run_enabled( true );
		( new SettingsRepository() )->set_kill_switch_enabled( false );
		$_GET = array();
	}

	protected function tearDown(): void {
		$_GET = array();
		// Schema resets commit the open transaction, so remove this test's rows first.
		foreach ( $this->post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		$this->post_ids = array();
		delete_option( PrivacySettings::OPTION );
		delete_option( RetentionStateStore::OPTION );
		$this->resetStorage();

		parent::tearDown();
	}

	public function test_builder_shows_three_guided_steps_without_raw_documents(): void {
		$destination = $this->enabledDestination();
		$this->createEnabledRule( $this->includeRuleInput( $destination, 'Archive sharing' ) );

		$html = $this->renderBatches();

		self::assertStringContainsString( 'Pick posts', $html );
		self::assertStringContainsString( 'Review', $html );
		self::assertStringContainsString( 'Timing', $html );
		self::assertStringContainsString( 'Never shared before', $html );
		self::assertStringContainsString( 'Share again', $html );
		self::assertStringContainsString( 'Taking part: Archive sharing.', $html );
		self::assertStringContainsString( 'No rule is set up to share posts again yet.', $html );
		self::assertStringContainsString( 'Tick &quot;I pick shared posts to share again&quot; in a rule under <a href="' . esc_url( admin_url( 'admin.php?page=pressqueue-rules' ) ) . '">Rules</a>.', $html );
		self::assertStringContainsString( 'Only rules with &quot;I pick old posts in Share old posts&quot; ticked take part.', $html );
		self::assertStringNotContainsString( 'Old posts are picked', $html );
		self::assertStringNotContainsString( 'Shared posts are picked', $html );
		self::assertStringNotContainsString( 'never-share', $html );
		self::assertStringContainsString( 'aria-labelledby="pq-batch-max-label pq-batch-max-unit"', $html );
		self::assertStringContainsString( 'aria-labelledby="pq-batch-days-label pq-batch-days-unit"', $html );
		self::assertStringNotContainsString( '<span aria-hidden="true">posts</span>', $html );
		self::assertStringContainsString( 'Before you start', $html );
		self::assertStringContainsString( 'name="action" value="pressqueue_preview_batch"', $html );
		self::assertStringNotContainsString( 'Filters JSON', $html );
		self::assertStringNotContainsString( 'Limits JSON', $html );
		self::assertStringNotContainsString( 'schema_version', $html );
		self::assertStringNotContainsString( "\u{2014}", $html );
	}

	public function test_builder_explains_when_no_rule_takes_part(): void {
		$html = $this->renderBatches();

		self::assertStringContainsString( 'No rule takes part yet.', $html );
		self::assertStringContainsString( 'tick &quot;I pick old posts in Share old posts&quot; or &quot;I pick shared posts to share again&quot; under When', $html );
		self::assertStringContainsString( 'page=pressqueue-rules', $html );
		self::assertMatchesRegularExpression( '/<button type="submit" form="pq-batch-form" class="button button-primary" disabled="disabled">Preview posts</', $html );
	}

	public function test_guided_choices_pick_posts_by_date_category_tag_and_title(): void {
		$recipes = (int) self::factory()->category->create( array( 'name' => 'Recipes' ) );
		$june    = $this->post( 'Rye starter guide', '2001-06-10 10:00:00', array( $recipes ), array( 'sourdough' ) );
		$this->post( 'Croissant notes', '2001-06-12 10:00:00', array( $recipes ) );
		$this->post( 'August cookies', '2001-08-01 10:00:00', array( $recipes ), array( 'sourdough' ) );

		$documents = $this->documents(
			array(
				'category'         => $recipes,
				'published_after'  => '2001-06-01',
				'published_before' => '2001-06-30',
				'tags'             => 'Sourdough',
			)
		);
		self::assertSame( 'category', $documents['filters']['taxonomies'][0]['taxonomy'] );
		self::assertSame( 'post_tag', $documents['filters']['taxonomies'][1]['taxonomy'] );
		self::assertSame( array( $june ), $this->candidatePostIds( $documents ) );

		$by_title = $this->documents(
			array(
				'published_after'  => '2001-01-01',
				'published_before' => '2001-06-30',
				'title_search'     => 'rye starter',
			)
		);
		self::assertSame( array( $june ), $by_title['filters']['post_ids'] );

		foreach ( array(
			array( 'tags' => 'no such tag' ),
			array( 'title_search' => 'nothing has this title' ),
		) as $changes ) {
			try {
				$this->documents( $changes );
				self::fail( 'Unknown picks must be refused before a preview is stored.' );
			} catch ( DomainException $exception ) {
				self::assertContains( $exception->getMessage(), array( 'unknown_tag', 'no_title_match' ) );
			}
		}
	}

	public function test_preview_review_lists_shares_skips_and_carries_the_exact_confirmation(): void {
		$destination = $this->enabledDestination();
		$private     = (int) self::factory()->category->create( array( 'name' => 'Private Journal' ) );
		$this->createEnabledRule( $this->includeRuleInput( $destination, 'Archive sharing' ) );
		$this->createEnabledRule( $this->excludeRuleInput( $private ) );
		$shared   = $this->post( 'Old recipe worth sharing', '2001-06-10 10:00:00' );
		$excluded = $this->post( 'Private oven notes', '2001-06-11 10:00:00', array( $private ) );
		$this->post( 'Outside the dates', '2001-08-01 10:00:00' );

		$batch = $this->preview(
			array(
				'published_after'  => '2001-06-01',
				'published_before' => '2001-06-30',
			)
		);
		$html  = $this->renderBatches( array( 'batch_public_id' => $batch->public_id ) );

		self::assertStringContainsString( '1 post matches, 1 share in total, 1 post skipped', $html );
		self::assertStringContainsString( 'Old recipe worth sharing', $html );
		self::assertStringContainsString( 'Private oven notes', $html );
		self::assertStringContainsString( 'The &quot;never share&quot; rule &quot;Keep private notes private&quot; matches this post.', $html );
		self::assertStringNotContainsString( 'never-share', $html );
		self::assertStringNotContainsString( 'Outside the dates', $html );
		self::assertStringContainsString( '>Share 1 post</button>', $html );
		self::assertStringContainsString( 'name="action" value="pressqueue_confirm_batch"', $html );
		self::assertStringContainsString( 'name="batch_public_id" value="' . $batch->public_id . '"', $html );
		self::assertStringContainsString( 'name="preview_snapshot_hash" value="' . $batch->preview_snapshot_hash . '"', $html );
		self::assertStringContainsString( 'data-pq-confirm="Share 1 post in Test mode? PressQueue records 1 test run in Activity. Nothing is sent to Buffer."', $html );
		self::assertStringContainsString( 'This preview expires in', $html );
		self::assertStringContainsString( 'Example Social', $html );
		self::assertSame( 2, count( ( new BatchItemRepository() )->find_by_batch( $batch->public_id ) ) );
		unset( $shared, $excluded );
	}

	public function test_confirmed_batch_shows_progress_then_test_runs_with_activity_links(): void {
		$destination = $this->enabledDestination();
		$this->createEnabledRule( $this->includeRuleInput( $destination, 'Archive sharing' ) );
		$this->post( 'Old recipe worth sharing', '2001-06-10 10:00:00' );
		$batch     = $this->preview( array(
				'published_after'  => '2001-01-01',
				'published_before' => '2001-06-30',
			) );
		$scheduler = new BatchAdminRecordingScheduler();

		( new BatchConfirmationService( null, $scheduler ) )->confirm( $batch->public_id, $batch->preview_snapshot_hash, $this->admin_id );
		$busy = $this->renderBatches( array( 'batch_public_id' => $batch->public_id ) );

		self::assertStringContainsString( 'Adding shares', $busy );
		self::assertStringContainsString( 'Being added', $busy );
		self::assertStringContainsString( 'data-pq-refresh="6"', $busy );
		self::assertStringContainsString( 'name="action" value="pressqueue_recover_batch"', $busy );
		self::assertStringContainsString( 'name="action" value="pressqueue_cancel_batch"', $busy );
		self::assertStringNotContainsString( 'local handoff', $busy );

		( new BatchQueueService( null, $scheduler ) )->process( $batch->public_id, $batch->preview_snapshot_hash );
		$done = $this->renderBatches( array( 'batch_public_id' => $batch->public_id ) );

		self::assertStringContainsString( '>Done<', $done );
		self::assertStringContainsString( 'Every share was recorded as a test run or skipped. Nothing was sent to Buffer.', $done );
		self::assertStringContainsString( '>Test run<', $done );
		self::assertStringContainsString( 'page=pressqueue-delivery&#038;delivery_public_id=', $done );
		self::assertStringContainsString( 'View in Activity', $done );
		self::assertStringContainsString( '1 share added.', $done );
		self::assertStringNotContainsString( 'name="action" value="pressqueue_cancel_batch"', $done );
	}

	public function test_single_share_timings_explain_a_batch_with_several_shares(): void {
		$destination = $this->enabledDestination();
		$this->createEnabledRule( $this->includeRuleInput( $destination, 'Archive sharing' ) );
		$this->post( 'Old recipe worth sharing', '2001-06-10 10:00:00' );
		$this->post( 'Rye starter guide', '2001-06-12 10:00:00' );

		$batch = $this->preview(
			array(
				'published_after'  => '2001-01-01',
				'published_before' => '2001-06-30',
				'timing'           => 'immediate',
			)
		);
		$html  = $this->renderBatches( array( 'batch_public_id' => $batch->public_id ) );

		self::assertStringContainsString( 'Post right away only works when the batch has one share. This batch has 2 shares. Pick another timing below', $html );
		self::assertStringContainsString( '2 posts match, 2 shares in total', $html );
		self::assertStringNotContainsString( 'No posts match', $html );
		self::assertStringNotContainsString( 'This timing only works for a single share.', $html );
		self::assertStringNotContainsString( 'name="action" value="pressqueue_confirm_batch"', $html );
		self::assertStringContainsString( 'Fix the problem above, then update the preview.', $html );
		self::assertMatchesRegularExpression( '/value="queue_next"\s+disabled=\'disabled\' data-pq-single-timing/', $html );
		self::assertMatchesRegularExpression( '/value="immediate"\s+checked=\'checked\' data-pq-single-timing/', $html );
		self::assertStringContainsString( 'Not available: this preview has 2 shares.', $html );
		self::assertStringContainsString( 'Pick another timing: this preview has 2 shares.', $html );
		self::assertStringContainsString( 'Shared once you pick another timing', $html );

		$single = $this->preview(
			array(
				'published_after'  => '2001-06-11',
				'published_before' => '2001-06-30',
				'timing'           => 'immediate',
			)
		);
		$one    = $this->renderBatches( array( 'batch_public_id' => $single->public_id ) );
		self::assertStringContainsString( 'name="action" value="pressqueue_confirm_batch"', $one );
		self::assertStringNotContainsString( 'data-pq-single-timing', $one );
	}

	public function test_draft_batches_do_not_promise_new_posts_to_followers(): void {
		$destination = $this->enabledDestination();
		$this->createEnabledRule( $this->includeRuleInput( $destination, 'Archive sharing' ) );
		$this->post( 'Old recipe worth sharing', '2001-06-10 10:00:00' );
		( new SettingsRepository() )->set_dry_run_enabled( false );

		$drafts = $this->preview(
			array(
				'published_after'  => '2001-01-01',
				'published_before' => '2001-06-30',
				'timing'           => 'draft',
			)
		);
		$html   = $this->renderBatches( array( 'batch_public_id' => $drafts->public_id ) );
		self::assertStringContainsString( 'data-pq-confirm="Share 1 post? PressQueue sends 1 share to Buffer. They are saved as Buffer drafts. Nothing goes public until you schedule them in Buffer."', $html );
		self::assertStringNotContainsString( 'Your followers see them as new posts.', $html );

		$queue = $this->preview(
			array(
				'published_after'  => '2001-01-01',
				'published_before' => '2001-06-30',
				'timing'           => 'queue_end',
			)
		);
		self::assertStringContainsString( 'They go to the end of your Buffer queue. Your followers see them as new posts.', $this->renderBatches( array( 'batch_public_id' => $queue->public_id ) ) );
	}

	public function test_unfinished_batch_blocks_the_same_post_in_a_second_preview(): void {
		$destination = $this->enabledDestination();
		$this->createEnabledRule( $this->includeRuleInput( $destination, 'Archive sharing' ) );
		$this->post( 'Old recipe worth sharing', '2001-06-10 10:00:00' );
		$first = $this->preview( array(
				'published_after'  => '2001-01-01',
				'published_before' => '2001-06-30',
			) );
		( new BatchConfirmationService( null, new BatchAdminRecordingScheduler() ) )->confirm( $first->public_id, $first->preview_snapshot_hash, $this->admin_id );

		$second = $this->preview( array(
				'published_after'  => '2001-01-01',
				'published_before' => '2001-06-30',
			) );
		$html   = $this->renderBatches( array( 'batch_public_id' => $second->public_id ) );

		self::assertStringContainsString( 'Already part of another batch that is not finished.', $html );
		self::assertStringContainsString( '0 posts match, 0 shares in total, 1 post skipped', $html );
		self::assertStringNotContainsString( 'name="action" value="pressqueue_confirm_batch"', $html );
		self::assertStringContainsString( 'Nothing in this preview can be shared yet.', $html );
	}

	public function test_restricted_posts_hide_titles_and_messages_from_batch_operators(): void {
		$destination = $this->enabledDestination();
		$this->createEnabledRule( $this->includeRuleInput( $destination, 'Archive sharing' ) );
		$post_id = $this->post( 'Confidential launch plan', '2001-06-10 10:00:00' );
		$batch   = $this->preview( array(
				'published_after'  => '2001-01-01',
				'published_before' => '2001-06-30',
			) );

		$operator = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user     = get_userdata( $operator );
		self::assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( Capabilities::OPERATE_BATCHES );
		wp_set_current_user( 0 );
		wp_set_current_user( $operator );

		$html = $this->renderBatches( array( 'batch_public_id' => $batch->public_id ) );

		self::assertStringContainsString( 'Post #' . $post_id, $html );
		self::assertStringNotContainsString( 'Confidential launch plan', $html );
		self::assertStringNotContainsString( 'Show message', $html );
	}

	public function test_privacy_tab_renders_inside_settings_with_plain_words(): void {
		$counters                           = ( new RetentionStateStore() )->empty_counters();
		$counters['decisions']['deleted']   = 3;
		$counters['deliveries']['redacted'] = 2;
		update_option(
			RetentionStateStore::OPTION,
			array(
				'active_run'      => null,
				'last_completion' => array(
					'completed_at_utc' => gmdate( 'Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS ),
					'counters'         => $counters,
					'state'            => 'completed',
				),
				'schema_version'  => 1,
			),
			false
		);

		$html = $this->renderPrivacy();

		self::assertStringContainsString( '<h1>Settings</h1>', $html );
		self::assertStringContainsString( 'aria-current="page">Data and privacy</a>', $html );
		self::assertStringContainsString( 'Background sharing is running normally.', $html );
		self::assertStringContainsString( 'Keep history', $html );
		self::assertStringContainsString( 'duplicate protection keeps working', $html );
		self::assertStringContainsString( 'Removed 3 old records. Cleared the details of 2 records.', $html );
		self::assertStringContainsString( 'name="acknowledge_retention_effect"', $html );
		self::assertStringContainsString( 'name="acknowledge_uninstall_effect"', $html );
		self::assertStringContainsString( 'I understand this permanently deletes PressQueue&#039;s rules, history, settings and saved Buffer key when the plugin is deleted.', $html );
		self::assertStringContainsString( 'name="privacy_section" value="retention"', $html );
		self::assertStringContainsString( 'name="privacy_section" value="uninstall"', $html );
		self::assertStringContainsString( 'name="action" value="pressqueue_export_data"', $html );
		self::assertStringContainsString( 'name="export_cursor"', $html );
		self::assertStringContainsString( 'name="acknowledge_export_effect"', $html );
		self::assertStringNotContainsString( 'name="confirm_uninstall_effect"', $html );
		self::assertStringNotContainsString( PrivacySettings::UNINSTALL_CONFIRMATION, $html );
		self::assertStringNotContainsString( '"active_run"', $html );
		self::assertStringNotContainsString( '<code>open</code>', $html );
		self::assertStringNotContainsString( "\u{2014}", $html );
	}

	public function test_privacy_tab_explains_a_stopped_runtime(): void {
		update_option(
			PrivacySettings::OPTION,
			array(
				'delete_data_on_uninstall' => false,
				'retention_days'           => 5,
				'retention_enabled'        => false,
				'schema_version'           => 1,
			),
			false
		);
		RuntimeDisabledLatch::close();

		$html = $this->renderPrivacy();

		self::assertStringContainsString( 'Background sharing is stopped.', $html );
		self::assertStringContainsString( 'The saved data and privacy settings are damaged.', $html );
		self::assertStringContainsString( 'pq-callout--bad', $html );
		RuntimeDisabledLatch::open();
	}

	public function test_enabled_deletion_drops_the_acknowledgment_and_shows_a_warning(): void {
		( new PrivacySettings() )->save(
			array(
				'delete_data_on_uninstall' => true,
				'retention_days'           => 90,
				'retention_enabled'        => true,
				'schema_version'           => 1,
			)
		);

		$html = $this->renderPrivacy();

		self::assertStringContainsString( 'Deleting the plugin deletes all PressQueue data on this site.', $html );
		self::assertStringNotContainsString( 'name="acknowledge_uninstall_effect"', $html );
		self::assertStringNotContainsString( 'name="acknowledge_retention_effect"', $html );
		self::assertStringContainsString( 'name="action" value="pressqueue_run_retention"', $html );
	}

	/**
	 * Render the batch screen.
	 *
	 * @param array<string,string> $query Query args.
	 * @return string
	 */
	private function renderBatches( array $query = array() ): string {
		$_GET = $query;
		ob_start();
		BatchController::render_page();

		return (string) ob_get_clean();
	}

	/**
	 * Render the privacy tab.
	 *
	 * @return string
	 */
	private function renderPrivacy(): string {
		$_GET = array( 'page' => 'pressqueue-privacy' );
		ob_start();
		PrivacyController::render_page();

		return (string) ob_get_clean();
	}

	/**
	 * Build documents from guided form values.
	 *
	 * @param array<string,mixed> $changes Form changes.
	 * @return array{batch_type:string,filters:array<string,mixed>,limits:array<string,mixed>}
	 */
	private function documents( array $changes ): array {
		$method = new ReflectionMethod( BatchController::class, 'documents_from_form' );
		$method->setAccessible( true );

		return $method->invoke(
			null,
			array_merge(
				array(
					'author'           => 0,
					'batch_type'       => 'bulk',
					'category'         => 0,
					'interval'         => 1440,
					'max_posts'        => 10,
					'post_type'        => 'post',
					'published_after'  => '',
					'published_before' => '',
					'repost_min_days'  => '',
					'start_date'       => '',
					'start_time'       => '09:00',
					'tags'             => '',
					'timing'           => 'queue_end',
					'title_search'     => '',
				),
				$changes
			)
		);
	}

	/**
	 * Store a preview from guided form values.
	 *
	 * @param array<string,mixed> $changes Form changes.
	 * @return BatchRecord
	 */
	private function preview( array $changes ): BatchRecord {
		$documents = $this->documents( $changes );

		return ( new BatchPreviewService() )->preview( $documents['batch_type'], $documents['filters'], $documents['limits'], $this->admin_id );
	}

	/**
	 * Return the posts a guided selection would check.
	 *
	 * @param array{batch_type:string,filters:array<string,mixed>,limits:array<string,mixed>} $documents Documents.
	 * @return array<int,int>
	 */
	private function candidatePostIds( array $documents ): array {
		$assessment = ( new BatchPreviewService() )->assess( $documents['batch_type'], $documents['filters'], $documents['limits'], $this->admin_id );

		return array_values( array_unique( array_map( 'intval', array_column( $assessment['items'], 'post_id' ) ) ) );
	}

	/**
	 * Create a published, backdated post.
	 *
	 * @param string          $title Title.
	 * @param string          $date GMT date.
	 * @param array<int,int>  $categories Category IDs.
	 * @param array<int,string> $tags Tag names.
	 * @return int
	 */
	private function post( string $title, string $date, array $categories = array(), array $tags = array() ): int {
		$post_id = (int) self::factory()->post->create(
			array(
				'post_author'   => $this->admin_id,
				'post_category' => $categories,
				'post_content'  => 'Body text',
				'post_date'     => $date,
				'post_date_gmt' => $date,
				'post_status'   => 'publish',
				'post_title'    => $title,
			)
		);

		if ( array() !== $tags ) {
			wp_set_post_tags( $post_id, $tags );
		}
		$this->post_ids[] = $post_id;

		return $post_id;
	}

	/**
	 * Create and enable a rule.
	 *
	 * @param array<string,mixed> $input Rule input.
	 * @return RuleRecord
	 */
	private function createEnabledRule( array $input ): RuleRecord {
		$rules     = new RuleRepository();
		$rule      = $rules->create( $input );
		$previewed = $rules->mark_version_previewed( $rule->public_id, $rule->lock_version, $rule->version );

		return $rules->enable_after_preview( $previewed->public_id, $previewed->lock_version );
	}

	/**
	 * Return include rule input for old posts.
	 *
	 * @param string $channel_public_id Channel public ID.
	 * @param string $name Rule name.
	 * @return array<string,mixed>
	 */
	private function includeRuleInput( string $channel_public_id, string $name ): array {
		return array(
			'condition_tree'         => array(
				'children'       => array(
					array(
						'condition' => 'post_type',
						'operator'  => 'equals',
						'type'      => 'condition',
						'value'     => 'post',
					),
				),
				'operator'       => 'ALL',
				'schema_version' => 1,
				'type'           => 'group',
			),
			'custom_field_allowlist' => array(),
			'destination_set'        => array(
				'destinations'   => array(
					array( 'channel_public_id' => $channel_public_id ),
				),
				'schema_version' => 1,
			),
			'effect'                 => 'include',
			'inline_template'        => array(
				'custom_field_allowlist' => array(),
				'message'                => '{{ title }} {{ canonical_url }}',
				'schema_version'         => 1,
			),
			'name'                   => $name,
			'priority'               => 100,
			'schedule_policy'        => 'queue_end',
			'triggers'               => array( 'bulk' ),
		);
	}

	/**
	 * Return a never-share rule for one category.
	 *
	 * @param int $term_id Category ID.
	 * @return array<string,mixed>
	 */
	private function excludeRuleInput( int $term_id ): array {
		return array(
			'condition_tree'         => array(
				'children'       => array(
					array(
						'condition' => 'taxonomy',
						'operator'  => 'has_any',
						'type'      => 'condition',
						'value'     => array(
							'include_ancestors' => false,
							'taxonomy'          => 'category',
							'terms'             => array( $term_id ),
						),
					),
				),
				'operator'       => 'ALL',
				'schema_version' => 1,
				'type'           => 'group',
			),
			'custom_field_allowlist' => array(),
			'destination_set'        => array(
				'schema_version' => 1,
				'scope'          => 'all',
			),
			'effect'                 => 'exclude',
			'inline_template'        => array(
				'custom_field_allowlist' => array(),
				'message'                => '{{ title }}',
				'schema_version'         => 1,
			),
			'name'                   => 'Keep private notes private',
			'priority'               => 5,
			'schedule_policy'        => 'immediate',
			'triggers'               => array( 'first_publish' ),
		);
	}

	/**
	 * Create an enabled local destination.
	 *
	 * @return string Channel public ID.
	 */
	private function enabledDestination(): string {
		$connections = new ConnectionRepository();
		$channels    = new ChannelRepository();
		$envelope    = CanonicalJson::encode_object( array( 'fixture' => 'encrypted' ) );
		$account     = array(
			'display_label'     => 'Owner',
			'organizations'     => array(
				array(
					'channel_count'          => 1,
					'display_label'          => 'Editorial',
					'provider'               => 'buffer',
					'remote_organization_id' => 'org_1',
				),
			),
			'provider'          => 'buffer',
			'remote_account_id' => 'acct_1',
			'schema_version'    => 1,
		);
		$connection  = $connections->create_with_credential( 'buffer', 'Local account', $envelope, hash( 'sha256', $envelope ), array( 'profile' => 'Example' ) );
		$connection  = $connections->store_verified_account( $connection->public_id, 'acct_1', $account );
		$connection  = $connections->select_organization_unverified( $connection->public_id, 'org_1' );
		$lease       = $connections->acquire_sync_lease( $connection->public_id );
		self::assertNotNull( $lease );
		$connection = $connections->complete_sync_success( $connection->public_id, $lease, 'acct_1', 'org_1', $account );
		$connection = $connections->enable( $connection->public_id );
		$channel    = $channels->create(
			$connection->public_id,
			'buffer',
			'remote-channel-1',
			'mastodon',
			array( 'name' => 'Example Social' ),
			array(
				'catalog_version' => '2001-08-25.1',
				'delivery'        => array(
					'max_text_characters' => 500,
					'schedule_slots'      => array( 'immediate', 'queue_next', 'queue_end', 'explicit_time' ),
					'text'                => true,
				),
				'schema_version'  => 2,
				'service'         => 'mastodon',
				'supported'       => true,
			),
			false,
			'2001-08-24 12:00:00',
			'org_1',
			'available'
		);
		$channel    = $channels->set_enabled_if_current( $channel->public_id, true, $channel->updated_at->format( 'Y-m-d H:i:s' ) );

		return $channel->public_id;
	}

	/**
	 * Reset custom storage between tests.
	 */
	private function resetStorage(): void {
		global $wpdb;

		$database = Database::from_globals();
		foreach ( array_reverse( Schema::table_suffixes() ) as $suffix ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . $database->table( $suffix ) . '`' );
		}

		foreach ( array( Schema::VERSION_OPTION, Schema::MIGRATION_LOCK_OPTION, Schema::MIGRATION_FAILED_OPTION, Capabilities::VERSION_OPTION, SettingsRepository::KILL_SWITCH_OPTION, SettingsRepository::DRY_RUN_OPTION ) as $option ) {
			delete_option( $option );
		}

		foreach ( Capabilities::names() as $capability ) {
			get_role( 'administrator' )->remove_cap( $capability );
			get_role( 'editor' )->remove_cap( $capability );
		}
	}
}

/**
 * Scheduler double that records batch handoff requests without Action Scheduler.
 */
class BatchAdminRecordingScheduler extends ActionSchedulerQueue {
	/**
	 * Number of scheduling attempts.
	 *
	 * @var int
	 */
	public int $calls = 0;

	/**
	 * Record one batch handoff.
	 *
	 * @param string $batch_public_id Batch public ID.
	 * @param string $preview_snapshot_hash Snapshot hash.
	 * @return int
	 */
	public function schedule_batch_queue( string $batch_public_id, string $preview_snapshot_hash ): int {
		unset( $batch_public_id, $preview_snapshot_hash );
		++$this->calls;

		return $this->calls;
	}

	/**
	 * Record one batch continuation.
	 *
	 * @param string $batch_public_id Batch public ID.
	 * @param string $preview_snapshot_hash Snapshot hash.
	 * @return int
	 */
	public function schedule_batch_queue_continuation( string $batch_public_id, string $preview_snapshot_hash ): int {
		unset( $batch_public_id, $preview_snapshot_hash );
		++$this->calls;

		return $this->calls;
	}
}
