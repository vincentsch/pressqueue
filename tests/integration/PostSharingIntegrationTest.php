<?php
/**
 * Post editor sharing integration tests.
 *
 * @package PressQueue\Tests\Integration
 */

declare(strict_types=1);

namespace PressQueue\Tests\Integration;

use PressQueue\Admin\DeliveryExplainer;
use PressQueue\Admin\PostControlsController;
use PressQueue\Admin\PostSharingSummary;
use PressQueue\Delivery\DeliveryQueryRepository;
use PressQueue\Editorial\NewPostDefaults;
use PressQueue\Editorial\PostControlMetadata;
use PressQueue\Planning\EventCollector;
use PressQueue\Runtime\RuntimeDisabledLatch;
use PressQueue\Security\Capabilities;
use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\Database;
use PressQueue\Storage\Record\RuleRecord;
use PressQueue\Storage\Repository\ChannelRepository;
use PressQueue\Storage\Repository\ConnectionRepository;
use PressQueue\Storage\Repository\RuleRepository;
use PressQueue\Storage\Schema;
use PressQueue\Storage\SchemaMigrator;
use PressQueue\Storage\SettingsRepository;
use PressQueue\Tests\Support\PressQueueIntegrationTestCase as WP_UnitTestCase;
use WP_REST_Request;

/**
 * @covers \PressQueue\Admin\PostControlsController
 * @covers \PressQueue\Admin\PostPreviewController
 * @covers \PressQueue\Admin\PostSharingSummary
 * @covers \PressQueue\Editorial\NewPostDefaults
 */
final class PostSharingIntegrationTest extends WP_UnitTestCase {
	/**
	 * Administrator ID.
	 *
	 * @var int
	 */
	private int $admin_id = 0;

	protected function setUp(): void {
		parent::setUp();

		$this->resetStorage();
		( new SchemaMigrator( Database::from_globals() ) )->migrate();
		RuntimeDisabledLatch::open();
		SettingsRepository::ensure_defaults_for_current_site();
		Capabilities::grant_to_administrators( false );
		$this->admin_id = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	protected function tearDown(): void {
		$this->resetStorage();

		parent::tearDown();
	}

	public function test_hook_is_registered_before_the_event_collector(): void {
		self::assertSame( NewPostDefaults::PRIORITY, has_action( 'transition_post_status', array( NewPostDefaults::class, 'handle_transition' ) ) );
		self::assertSame( 10, has_action( 'transition_post_status', array( EventCollector::class, 'capture_status_transition' ) ) );
	}

	public function test_opt_in_marks_new_posts_from_any_writer(): void {
		update_option( SettingsRepository::NEW_POST_SHARING_OPTION, 'opt_in' );
		wp_set_current_user( 0 );

		$draft      = $this->post( array( 'post_status' => 'draft' ) );
		$auto_draft = $this->post( array( 'post_status' => 'auto-draft' ) );
		$published  = $this->post( array( 'post_status' => 'publish' ) );
		$page       = $this->post(
			array(
				'post_status' => 'draft',
				'post_type'   => 'page',
			)
		);
		$attachment = (int) self::factory()->attachment->create_object( 'image.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );

		foreach ( array( $draft, $auto_draft, $published, $page ) as $post_id ) {
			self::assertSame( array( 'exclude' ), get_post_meta( $post_id, PostControlMetadata::LEGACY_META_KEY ) );
			self::assertFalse( metadata_exists( 'post', $post_id, PostControlMetadata::META_KEY ) );
			self::assertSame( 'exclude', PostControlMetadata::read_for_post( $post_id )['policy'] );
		}

		self::assertFalse( metadata_exists( 'post', $attachment, PostControlMetadata::LEGACY_META_KEY ) );
	}

	public function test_automatic_mode_and_existing_posts_are_left_alone(): void {
		$automatic = $this->post( array( 'post_status' => 'draft' ) );
		self::assertFalse( metadata_exists( 'post', $automatic, PostControlMetadata::LEGACY_META_KEY ) );

		update_option( SettingsRepository::NEW_POST_SHARING_OPTION, 'opt_in' );
		wp_update_post(
			array(
				'ID'         => $automatic,
				'post_title' => 'Edited after switching to opt-in',
			)
		);
		wp_publish_post( $automatic );

		self::assertFalse( metadata_exists( 'post', $automatic, PostControlMetadata::LEGACY_META_KEY ) );
		self::assertSame( 'inherit', PostControlMetadata::read_for_post( $automatic )['policy'] );
	}

	public function test_choices_stored_at_creation_are_never_overwritten(): void {
		update_option( SettingsRepository::NEW_POST_SHARING_OPTION, 'opt_in' );

		$with_controls = $this->post(
			array(
				'meta_input'  => array( PostControlMetadata::META_KEY => wp_slash( PostControlMetadata::encoded( PostControlMetadata::default_bundle() ) ) ),
				'post_status' => 'draft',
			)
		);
		$with_marker   = $this->post(
			array(
				'meta_input'  => array( PostControlMetadata::LEGACY_META_KEY => 'include' ),
				'post_status' => 'draft',
			)
		);

		self::assertFalse( metadata_exists( 'post', $with_controls, PostControlMetadata::LEGACY_META_KEY ) );
		self::assertSame( 'inherit', PostControlMetadata::read_for_post( $with_controls )['policy'] );
		self::assertSame( array( 'include' ), get_post_meta( $with_marker, PostControlMetadata::LEGACY_META_KEY ) );
	}

	public function test_a_site_mu_plugin_writing_the_same_marker_coexists(): void {
		update_option( SettingsRepository::NEW_POST_SHARING_OPTION, 'opt_in' );
		$writes = 0;
		$site   = static function ( $new_status, $old_status, $post ) use ( &$writes ): void {
			unset( $new_status );
			if ( 'new' !== $old_status || ! $post instanceof \WP_Post ) {
				return;
			}
			if ( metadata_exists( 'post', $post->ID, PostControlMetadata::META_KEY ) || metadata_exists( 'post', $post->ID, PostControlMetadata::LEGACY_META_KEY ) ) {
				return;
			}
			++$writes;
			update_post_meta( $post->ID, PostControlMetadata::LEGACY_META_KEY, 'exclude' );
		};
		add_action( 'transition_post_status', $site, -1000, 3 );

		$post_id = $this->post( array( 'post_status' => 'draft' ) );
		remove_action( 'transition_post_status', $site, -1000 );

		self::assertSame( array( 'exclude' ), get_post_meta( $post_id, PostControlMetadata::LEGACY_META_KEY ) );
		self::assertLessThanOrEqual( 1, $writes );
	}

	public function test_switching_sharing_on_wins_over_the_marker_and_the_post_is_shared(): void {
		$destination = $this->enabledDestination();
		$this->createEnabledRule( $this->includeRuleInput( $destination ) );
		update_option( SettingsRepository::NEW_POST_SHARING_OPTION, 'opt_in' );

		$on  = $this->post( array( 'post_status' => 'draft' ) );
		$off = $this->post( array( 'post_status' => 'draft' ) );

		$response = $this->rest(
			'PUT',
			'/pressqueue/v1/posts/' . $on . '/controls',
			array(
				'base_control_bundle_hash' => PostControlMetadata::bundle_hash( PostControlMetadata::read_for_post( $on ) ),
				'controls'                 => PostControlMetadata::default_bundle(),
			)
		);
		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'inherit', $response->get_data()['controls']['policy'] );
		self::assertSame( 'opt_in', $response->get_data()['editor']['new_post_sharing'] );

		wp_publish_post( $on );
		wp_publish_post( $off );
		EventCollector::finalize();

		$deliveries = new DeliveryQueryRepository();
		self::assertSame( 1, $deliveries->query( array( 'post_id' => $on ) )['total'] );
		self::assertSame( 0, $deliveries->query( array( 'post_id' => $off ) )['total'] );

		$shared = $this->rest( 'GET', '/pressqueue/v1/posts/' . $on . '/activity' )->get_data()['activity'];
		self::assertCount( 1, $shared['items'] );
		self::assertSame( 'Test run', $shared['items'][0]['label'] );
		self::assertSame( 'Example Social', $shared['items'][0]['name'] );
		self::assertStringContainsString( 'page=pressqueue-deliveries', $shared['url'] );
		self::assertStringContainsString( 'post_id=' . $on, $shared['url'] );

		$skipped = $this->rest( 'GET', '/pressqueue/v1/posts/' . $off . '/activity' )->get_data()['activity'];
		self::assertSame( array(), $skipped['items'] );
		self::assertSame( 'Not shared when it was published.', $skipped['not_shared_title'] );
		self::assertSame( 'Sharing is switched off for this post.', $skipped['not_shared'] );
	}

	public function test_preview_route_returns_ready_made_display_strings(): void {
		$destination = $this->enabledDestination();
		$this->createEnabledRule( $this->includeRuleInput( $destination ) );
		$post_id = $this->post(
			array(
				'post_status' => 'draft',
				'post_title'  => 'Weekend baker wanted',
			)
		);

		$snapshot = PostSharingSummary::saved_post_snapshot( get_post( $post_id ) );
		self::assertSame( 'publish', $snapshot['post_status'] );

		$display = $this->rest(
			'POST',
			'/pressqueue/v1/posts/' . $post_id . '/preview',
			array(
				'controls'        => PostControlMetadata::default_bundle(),
				'editor_snapshot' => $snapshot,
			)
		)->get_data()['display'];

		self::assertTrue( $display['will_share'] );
		self::assertSame( 'When you publish, PressQueue would send it to:', $display['heading'] );
		self::assertSame( '', $display['kept_off'] );
		self::assertSame( 'Example Social', $display['channels'][0]['name'] );
		self::assertSame( 'Mastodon', $display['channels'][0]['service_name'] );
		self::assertSame( 'Right away · without an image', $display['details'] );
		self::assertSame( 280, $display['message']['limit'] );
		self::assertStringStartsWith( 'Weekend baker wanted', $display['message']['rule_message'] );

		$snapshot['published_at_gmt'] = '2020-01-01 12:00:00';
		$backdated                    = $this->rest(
			'POST',
			'/pressqueue/v1/posts/' . $post_id . '/preview',
			array(
				'controls'        => PostControlMetadata::default_bundle(),
				'editor_snapshot' => $snapshot,
			)
		)->get_data()['display'];

		self::assertFalse( $backdated['will_share'] );
		self::assertStringContainsString( 'more than a day in the past', $backdated['reason'] );
	}

	public function test_classic_box_shows_the_simple_layout_and_the_opt_in_default(): void {
		$destination = $this->enabledDestination();
		$this->createEnabledRule( $this->includeRuleInput( $destination ) );
		update_option( SettingsRepository::NEW_POST_SHARING_OPTION, 'opt_in' );
		$post_id = $this->post( array( 'post_status' => 'auto-draft' ) );

		ob_start();
		PostControlsController::render_meta_box( get_post( $post_id ) );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Share this post', $html );
		self::assertDoesNotMatchRegularExpression( '/id="pressqueue-share"[^>]*checked/', $html );
		self::assertStringContainsString( 'On this site, new posts start with sharing off.', $html );
		self::assertStringContainsString( 'This post will not be shared. You can switch it on any time before publishing.', $html );
		self::assertStringContainsString( 'When you publish, PressQueue would send it to:', $html );
		self::assertStringContainsString( 'Example Social', $html );
		self::assertStringContainsString( 'Customize for this post', $html );
		self::assertStringContainsString( 'Test mode is on. Nothing is sent to Buffer yet.', $html );
		self::assertStringNotContainsString( 'Attachment IDs', $html );
		self::assertStringNotContainsString( 'UTC offset', $html );
	}

	public function test_heading_says_what_would_happen_in_each_sharing_mode(): void {
		self::assertSame( 'When you publish, PressQueue sends it to:', PostSharingSummary::heading( 'first_publish', 'live' ) );
		self::assertSame( 'When you publish, PressQueue would send it to:', PostSharingSummary::heading( 'first_publish', 'test' ) );
		self::assertSame( 'When you update this post, PressQueue would send it to:', PostSharingSummary::heading( 'update', 'test' ) );
		self::assertSame( 'When you publish, PressQueue holds it for these channels until sharing resumes:', PostSharingSummary::heading( 'first_publish', 'paused' ) );
		self::assertSame( 'When the post goes live as scheduled, PressQueue holds it for these channels until sharing resumes:', PostSharingSummary::heading( 'scheduled_publication', 'paused' ) );

		$destination = $this->enabledDestination();
		$this->createEnabledRule( $this->includeRuleInput( $destination ) );
		$post_id  = $this->post( array( 'post_status' => 'draft' ) );
		$settings = new SettingsRepository();
		$settings->set_dry_run_enabled( false );
		$settings->set_kill_switch_enabled( false );
		self::assertSame( 'When you publish, PressQueue sends it to:', $this->previewDisplay( $post_id )['heading'] );

		$settings->set_kill_switch_enabled( true );
		self::assertSame( 'paused', $settings->sharing_mode() );
		self::assertSame( 'When you publish, PressQueue holds it for these channels until sharing resumes:', $this->previewDisplay( $post_id )['heading'] );
	}

	public function test_a_matching_never_share_rule_keeps_the_switch_off_without_changing_the_stored_choice(): void {
		$destination = $this->enabledDestination();
		$journal     = (int) self::factory()->category->create( array( 'name' => 'Private Journal' ) );
		$this->createEnabledRule( $this->includeRuleInput( $destination ) );
		$this->createEnabledRule( $this->excludeRuleInput( $journal ) );
		$post_id           = $this->post(
			array(
				'post_category' => array( $journal ),
				'post_status'   => 'draft',
			)
		);
		$stored            = PostControlMetadata::default_bundle();
		$stored['message'] = array(
			'mode'  => 'override',
			'value' => 'A line of my own',
		);
		PostControlMetadata::replace_for_post( $post_id, PostControlMetadata::normalize_bundle( $stored ), PostControlMetadata::bundle_hash( PostControlMetadata::read_for_post( $post_id ) ) );
		$before = PostControlMetadata::read_for_post( $post_id );

		$display = $this->previewDisplay( $post_id );
		self::assertFalse( $display['will_share'] );
		self::assertSame( 'Kept off social media by the "never share" rule "Keep the journal private".', $display['kept_off'] );
		self::assertSame( 'The "never share" rule "Keep the journal private" matches this post.', $display['reason'] );

		ob_start();
		PostControlsController::render_meta_box( get_post( $post_id ) );
		$html = (string) ob_get_clean();

		self::assertMatchesRegularExpression( '/<input type="checkbox" id="pressqueue-share" disabled/', $html );
		self::assertDoesNotMatchRegularExpression( '/id="pressqueue-share"[^>]*checked/', $html );
		self::assertStringContainsString( '<input type="hidden" name="pressqueue_share" value="1" />', $html );
		self::assertStringContainsString( 'Kept off social media by the &quot;never share&quot; rule &quot;Keep the journal private&quot;.', $html );
		self::assertMatchesRegularExpression( '/<details class="pq-ed-more" open hidden>/', $html );
		self::assertStringContainsString( 'A line of my own', $html );
		self::assertStringNotContainsString( 'PressQueue would send it to', $html );
		self::assertStringNotContainsString( 'This post will not be shared.', $html );
		self::assertSame( $before, PostControlMetadata::read_for_post( $post_id ), 'rendering never changes what is stored' );

		// Once the post leaves the category, the switch is back to normal.
		wp_set_post_categories( $post_id, array() );
		ob_start();
		PostControlsController::render_meta_box( get_post( $post_id ) );
		$html = (string) ob_get_clean();
		self::assertMatchesRegularExpression( '/id="pressqueue-share" name="pressqueue_share" value="1" data-pq-share\s+checked/', $html );
		self::assertStringNotContainsString( 'Kept off social media', $html );
		self::assertMatchesRegularExpression( '/<details class="pq-ed-more" open>/', $html );
	}

	public function test_an_unfinished_date_and_time_never_blocks_saving_while_sharing_is_off(): void {
		$post_id  = $this->post( array( 'post_status' => 'draft' ) );
		$controls = PostControlMetadata::default_bundle();

		$controls['policy']   = 'exclude';
		$controls['schedule'] = array(
			'local_date' => '2026-10-20',
			'local_time' => '',
			'mode'       => 'explicit_time',
			'timezone'   => 'Europe/Berlin',
		);
		$response             = $this->rest(
			'PUT',
			'/pressqueue/v1/posts/' . $post_id . '/controls',
			array(
				'base_control_bundle_hash' => PostControlMetadata::bundle_hash( PostControlMetadata::read_for_post( $post_id ) ),
				'controls'                 => $controls,
			)
		);
		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'exclude', $response->get_data()['controls']['policy'] );
		self::assertSame( array( 'mode' => 'inherit' ), $response->get_data()['controls']['schedule'] );

		$controls['policy'] = 'inherit';
		$rejected           = $this->rest(
			'PUT',
			'/pressqueue/v1/posts/' . $post_id . '/controls',
			array(
				'base_control_bundle_hash' => PostControlMetadata::bundle_hash( PostControlMetadata::read_for_post( $post_id ) ),
				'controls'                 => $controls,
			)
		);
		self::assertSame( 400, $rejected->get_status() );
		self::assertSame( 'exclude', PostControlMetadata::read_for_post( $post_id )['policy'] );

		$form = array(
			'pressqueue_schedule_local_date' => '',
			'pressqueue_schedule_local_time' => '09:00',
			'pressqueue_schedule_mode'       => 'explicit_time',
			'pressqueue_schedule_timezone'   => 'Europe/Berlin',
		);
		self::assertSame( array( 'mode' => 'inherit' ), PostControlsController::bundle_from_share_form( $form, PostControlMetadata::default_bundle() )['schedule'] );

		try {
			PostControlsController::bundle_from_share_form( array_merge( $form, array( 'pressqueue_share' => '1' ) ), PostControlMetadata::default_bundle() );
			self::fail( 'An unfinished date and time must stop the save while sharing is on.' );
		} catch ( \PressQueue\Storage\ValidationException $exception ) {
			self::assertStringContainsString( 'explicit schedule', $exception->getMessage() );
		}
	}

	public function test_a_failed_share_explains_itself_in_the_editor(): void {
		$destination = $this->enabledDestination();
		$this->createEnabledRule( $this->includeRuleInput( $destination ) );
		$post_id = $this->post( array( 'post_status' => 'draft' ) );
		wp_publish_post( $post_id );
		EventCollector::finalize();

		$database = Database::from_globals();
		$database->query( 'UPDATE `' . $database->table( Schema::DELIVERIES ) . "` SET state = 'failed', error_class = 'invalid_credential' WHERE channel_public_id = %s", array( $destination ) );
		$delivery = ( new DeliveryQueryRepository() )->query( array( 'post_id' => $post_id ) )['rows'][0];

		$items = $this->rest( 'GET', '/pressqueue/v1/posts/' . $post_id . '/activity' )->get_data()['activity']['items'];
		self::assertCount( 1, $items );
		self::assertSame( 'failed', $items[0]['state'] );
		self::assertNotSame( '', $items[0]['reason'] );
		self::assertSame( DeliveryExplainer::problem_sentence( $delivery ), $items[0]['reason'] );

		ob_start();
		PostControlsController::render_meta_box( get_post( $post_id ) );
		$html = (string) ob_get_clean();
		self::assertStringContainsString( '<span class="pq-ed-small pq-ed-activity-reason">' . esc_html( $items[0]['reason'] ) . '</span>', $html );
	}

	public function test_site_time_zone_is_preselected_as_a_zone_the_schedule_accepts(): void {
		$timezone = get_option( 'timezone_string' );
		$offset   = get_option( 'gmt_offset' );

		update_option( 'timezone_string', 'Europe/Berlin' );
		self::assertSame( 'Europe/Berlin', PostSharingSummary::site_timezone() );
		self::assertSame( 'Europe/Berlin', PostSharingSummary::site_timezone_label() );

		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', 2 );
		self::assertSame( 'Etc/GMT-2', PostSharingSummary::site_timezone() );
		self::assertSame( 'UTC+02:00', PostSharingSummary::site_timezone_label() );

		update_option( 'gmt_offset', 0 );
		self::assertSame( 'UTC', PostSharingSummary::site_timezone() );

		update_option( 'gmt_offset', 5.5 );
		self::assertNotFalse( timezone_open( PostSharingSummary::site_timezone() ) );

		update_option( 'timezone_string', $timezone );
		update_option( 'gmt_offset', $offset );
	}

	/**
	 * Return the editor preview display for a post's saved state.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>
	 */
	private function previewDisplay( int $post_id ): array {
		$response = $this->rest(
			'POST',
			'/pressqueue/v1/posts/' . $post_id . '/preview',
			array(
				'controls'        => PostControlMetadata::default_bundle(),
				'editor_snapshot' => PostSharingSummary::saved_post_snapshot( get_post( $post_id ) ),
			)
		);
		self::assertSame( 200, $response->get_status() );

		return $response->get_data()['display'];
	}

	/**
	 * Return a "never share" rule for one category.
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
			'name'                   => 'Keep the journal private',
			'priority'               => 5,
			'schedule_policy'        => 'immediate',
			'triggers'               => array( 'first_publish' ),
		);
	}

	/**
	 * Create a post.
	 *
	 * @param array<string,mixed> $args Post arguments.
	 * @return int
	 */
	private function post( array $args ): int {
		$post_id = wp_insert_post(
			array_merge(
				array(
					'post_content' => 'Body text',
					'post_title'   => 'Sharing test',
					'post_type'    => 'post',
				),
				$args
			)
		);

		self::assertIsInt( $post_id );
		self::assertGreaterThan( 0, $post_id );

		return $post_id;
	}

	/**
	 * Dispatch a REST request.
	 *
	 * @param string              $method Method.
	 * @param string              $route Route.
	 * @param array<string,mixed> $body JSON body.
	 * @return \WP_REST_Response
	 */
	private function rest( string $method, string $route, array $body = array() ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		if ( array() !== $body ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}

		$response = rest_do_request( $request );
		self::assertInstanceOf( \WP_REST_Response::class, $response );

		return $response;
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
	 * Create an enabled channel and return its public ID.
	 *
	 * @return string
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
		$channel    = $channels->create( $connection->public_id, 'buffer', 'remote-channel-1', 'mastodon', array( 'name' => 'Example Social' ), $this->channelCapabilities(), false, '2026-08-24 12:00:00', 'org_1', 'available' );
		$channel    = $channels->set_enabled_if_current( $channel->public_id, true, $channel->updated_at->format( 'Y-m-d H:i:s' ) );

		return $channel->public_id;
	}

	/**
	 * Return include rule input.
	 *
	 * @param string $channel_public_id Channel public ID.
	 * @return array<string,mixed>
	 */
	private function includeRuleInput( string $channel_public_id ): array {
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
				'message'                => '{{ title }}',
				'schema_version'         => 1,
			),
			'name'                   => 'Share new posts',
			'priority'               => 100,
			'schedule_policy'        => 'immediate',
			'triggers'               => array( 'first_publish' ),
		);
	}

	/**
	 * Return a schema 2 text and image channel capability document.
	 *
	 * @return array<string,mixed>
	 */
	private function channelCapabilities(): array {
		return array(
			'catalog_version' => '2026-08-25.1',
			'delivery'        => array(
				'image'               => array(
					'alt_text_required' => false,
					'max_bytes'         => 5 * 1024 * 1024,
					'max_height'        => 4096,
					'max_items'         => 1,
					'max_width'         => 4096,
					'mime_types'        => array( 'image/gif', 'image/jpeg', 'image/png' ),
					'min_height'        => 1,
					'min_width'         => 1,
				),
				'max_text_characters' => 280,
				'schedule_slots'      => array( 'immediate', 'queue_next', 'queue_end', 'explicit_time' ),
				'text'                => true,
			),
			'schema_version'  => 2,
			'service'         => 'mastodon',
			'supported'       => true,
		);
	}

	/**
	 * Drop plugin tables and options.
	 */
	private function resetStorage(): void {
		global $wpdb;

		$database = Database::from_globals();

		foreach ( array_reverse( Schema::table_suffixes() ) as $suffix ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . $database->table( $suffix ) . '`' );
		}

		foreach ( array( Schema::VERSION_OPTION, Schema::MIGRATION_LOCK_OPTION, Schema::MIGRATION_FAILED_OPTION, Capabilities::VERSION_OPTION, SettingsRepository::KILL_SWITCH_OPTION, SettingsRepository::DRY_RUN_OPTION, SettingsRepository::NEW_POST_SHARING_OPTION ) as $option ) {
			delete_option( $option );
		}

		foreach ( Capabilities::names() as $capability ) {
			get_role( 'administrator' )->remove_cap( $capability );
			get_role( 'editor' )->remove_cap( $capability );
		}
	}
}
