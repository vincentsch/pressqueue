<?php
/**
 * "Share now" in the post editor.
 *
 * @package PressQueue\Tests\Integration
 */

declare(strict_types=1);

namespace PressQueue\Tests\Integration;

use PressQueue\Admin\PostControlsController;
use PressQueue\Admin\PostSharingSummary;
use PressQueue\Delivery\DeliveryQueryRepository;
use PressQueue\Editorial\PostControlMetadata;
use PressQueue\Planning\EventCollector;
use PressQueue\Runtime\RuntimeDisabledLatch;
use PressQueue\Security\Capabilities;
use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\Database;
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
use WP_REST_Request;

/**
 * @covers \PressQueue\Admin\PostShareNowController
 * @covers \PressQueue\Batch\PostShareNowService
 */
final class PostShareNowIntegrationTest extends WP_UnitTestCase {
	/**
	 * Administrator ID.
	 *
	 * @var int
	 */
	private int $admin_id = 0;

	/**
	 * Connection public ID for extra channels.
	 *
	 * @var string
	 */
	private string $connection = '';

	protected function setUp(): void {
		parent::setUp();

		$this->resetStorage();
		( new SchemaMigrator( Database::from_globals() ) )->migrate();
		RuntimeDisabledLatch::open();
		SettingsRepository::ensure_defaults_for_current_site();
		Capabilities::grant_to_administrators( false );
		$this->admin_id = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->login( $this->admin_id );
	}

	protected function tearDown(): void {
		$this->resetStorage();

		parent::tearDown();
	}

	public function test_share_now_shows_what_would_be_sent_and_sends_only_after_confirming(): void {
		$channel = $this->channel( 'remote-1', 'mastodon', 'Example Social' );
		$this->createEnabledRule( $this->includeRuleInput( array( $channel ), 'immediate' ) );
		$post_id = $this->publishedPost( 'Weekend baker wanted' );
		self::assertSame( 0, $this->deliveryCount( $post_id ), 'the rule only takes part in Share old posts, so publishing shared nothing' );

		self::assertTrue( PostSharingSummary::editor_context( $post_id )['can_share_now'] );
		$box = $this->metaBox( $post_id );
		self::assertStringContainsString( 'data-pq-share-now data-post-id="' . $post_id . '" data-saved-on="1" hidden', $box );
		self::assertStringContainsString( '>Share now</button>', $box );

		$response = $this->rest( 'POST', '/pressqueue/v1/posts/' . $post_id . '/share-now/preview' );
		self::assertSame( 200, $response->get_status() );
		$preview = $response->get_data()['share_now'];
		self::assertTrue( $preview['can_confirm'] );
		self::assertSame( 'Test mode is on, so nothing goes to Buffer. PressQueue would record this as a test run:', $preview['heading'] );
		self::assertCount( 1, $preview['shares'] );
		self::assertSame( 'Example Social', $preview['shares'][0]['name'] );
		self::assertSame( 'Mastodon', $preview['shares'][0]['service_name'] );
		self::assertSame( 'Right away', $preview['shares'][0]['when'] );
		self::assertStringStartsWith( 'Weekend baker wanted', $preview['shares'][0]['text'] );
		self::assertSame( '', $preview['problem'] );
		self::assertSame( 0, $this->deliveryCount( $post_id ), 'a preview sends nothing' );

		$batch = ( new BatchRepository() )->find_by_public_id( $preview['batch_public_id'] );
		self::assertNotNull( $batch );
		self::assertSame( 'bulk', $batch->batch_type, 'Share now is a one-post Share old posts batch' );
		self::assertSame( array( $post_id ), $batch->filters['post_ids'] );
		self::assertSame( 'previewed', $batch->status );
		self::assertSame( $this->admin_id, $batch->created_by );

		$confirm = $this->rest(
			'POST',
			'/pressqueue/v1/posts/' . $post_id . '/share-now',
			array(
				'batch_public_id'       => $preview['batch_public_id'],
				'preview_snapshot_hash' => $preview['preview_snapshot_hash'],
			)
		);
		self::assertSame( 200, $confirm->get_status() );
		$items = $confirm->get_data()['activity']['items'];
		self::assertCount( 1, $items );
		self::assertSame( 'Test run', $items[0]['label'] );
		self::assertSame( 'Example Social', $items[0]['name'] );
		self::assertSame( 1, $this->deliveryCount( $post_id ) );
		self::assertSame( 'handed_off', ( new BatchRepository() )->find_by_public_id( $preview['batch_public_id'] )->status );

		$again = $this->rest(
			'POST',
			'/pressqueue/v1/posts/' . $post_id . '/share-now',
			array(
				'batch_public_id'       => $preview['batch_public_id'],
				'preview_snapshot_hash' => $preview['preview_snapshot_hash'],
			)
		);
		self::assertSame( 409, $again->get_status() );
		self::assertSame( 'not_confirmable', $again->get_data()['code'] );
		self::assertSame( 1, $this->deliveryCount( $post_id ), 'confirming twice never sends twice' );

		self::assertStringNotContainsString( 'data-pq-share-now', $this->metaBox( $post_id ), 'a shared post shows its activity instead' );
	}

	public function test_switched_off_posts_cannot_be_shared_now(): void {
		$channel = $this->channel( 'remote-1', 'mastodon', 'Example Social' );
		$this->createEnabledRule( $this->includeRuleInput( array( $channel ), 'queue_end' ) );
		update_option( SettingsRepository::NEW_POST_SHARING_OPTION, 'opt_in' );
		$post_id = $this->publishedPost( 'Private kitchen notes' );
		self::assertSame( 'exclude', PostControlMetadata::read_for_post( $post_id )['policy'] );

		$box = $this->metaBox( $post_id );
		self::assertStringContainsString( 'data-saved-on="0"', $box );
		self::assertStringContainsString( '<div class="pq-ed-share-now-start" data-pq-when="on" hidden>', $box );
		self::assertStringContainsString( '<p class="pq-ed-help" data-pq-when="off">Switch sharing on and update the post to share it now.</p>', $box );

		$response = $this->rest( 'POST', '/pressqueue/v1/posts/' . $post_id . '/share-now/preview' );
		self::assertSame( 409, $response->get_status() );
		self::assertSame( 'sharing_off', $response->get_data()['code'] );
		self::assertSame( 'Sharing is switched off for this post, so it cannot be shared now. Switch it on and update the post first.', $response->get_data()['message'] );
		self::assertSame( 0, ( new BatchRepository() )->count_all() );
	}

	public function test_switching_sharing_off_after_the_preview_stops_the_send(): void {
		$channel = $this->channel( 'remote-1', 'mastodon', 'Example Social' );
		$this->createEnabledRule( $this->includeRuleInput( array( $channel ), 'queue_end' ) );
		$post_id = $this->publishedPost( 'Changed my mind' );
		$preview = $this->rest( 'POST', '/pressqueue/v1/posts/' . $post_id . '/share-now/preview' )->get_data()['share_now'];
		self::assertTrue( $preview['can_confirm'] );

		$bundle         = PostControlMetadata::read_for_post( $post_id );
		$next           = $bundle;
		$next['policy'] = 'exclude';
		PostControlMetadata::replace_for_post( $post_id, $next, PostControlMetadata::bundle_hash( $bundle ) );

		$confirm = $this->rest(
			'POST',
			'/pressqueue/v1/posts/' . $post_id . '/share-now',
			array(
				'batch_public_id'       => $preview['batch_public_id'],
				'preview_snapshot_hash' => $preview['preview_snapshot_hash'],
			)
		);
		self::assertSame( 409, $confirm->get_status() );
		self::assertSame( 'sharing_off', $confirm->get_data()['code'] );
		self::assertSame( 0, $this->deliveryCount( $post_id ) );
	}

	public function test_a_never_share_rule_stops_share_now_and_says_which_rule(): void {
		$channel = $this->channel( 'remote-1', 'mastodon', 'Example Social' );
		$journal = (int) self::factory()->category->create( array( 'name' => 'Private Journal' ) );
		$this->createEnabledRule( $this->includeRuleInput( array( $channel ), 'queue_end' ) );
		$this->createEnabledRule( $this->excludeRuleInput( $journal ) );
		$post_id = $this->publishedPost( 'Journal entry', array( $journal ) );

		$preview = $this->rest( 'POST', '/pressqueue/v1/posts/' . $post_id . '/share-now/preview' )->get_data()['share_now'];
		self::assertFalse( $preview['can_confirm'] );
		self::assertSame( array(), $preview['shares'] );
		self::assertSame( 'The "never share" rule "Keep the journal private" matches this post.', $preview['problem'] );

		// Even a hand-made confirm request cannot send it.
		$this->rest(
			'POST',
			'/pressqueue/v1/posts/' . $post_id . '/share-now',
			array(
				'batch_public_id'       => $preview['batch_public_id'],
				'preview_snapshot_hash' => $preview['preview_snapshot_hash'],
			)
		);
		self::assertSame( 0, $this->deliveryCount( $post_id ) );
	}

	public function test_no_rule_for_old_posts_explains_how_to_set_one_up(): void {
		$channel = $this->channel( 'remote-1', 'mastodon', 'Example Social' );
		$input   = $this->includeRuleInput( array( $channel ), 'queue_end' );
		$input['triggers'] = array( 'update' );
		$this->createEnabledRule( $input );
		$post_id = $this->publishedPost( 'Old favourite' );

		$preview = $this->rest( 'POST', '/pressqueue/v1/posts/' . $post_id . '/share-now/preview' )->get_data()['share_now'];
		self::assertFalse( $preview['can_confirm'] );
		self::assertSame( 'No rule takes part in Share old posts yet. Tick "I pick old posts in Share old posts" in a rule, then try again.', $preview['problem'] );
	}

	public function test_several_channels_with_a_right_away_rule_go_to_the_end_of_the_queue(): void {
		$first  = $this->channel( 'remote-1', 'mastodon', 'Example Social' );
		$second = $this->channel( 'remote-2', 'bluesky', 'Bakery Sky' );
		$this->createEnabledRule( $this->includeRuleInput( array( $first, $second ), 'immediate' ) );
		$post_id = $this->publishedPost( 'Two channels' );

		$preview = $this->rest( 'POST', '/pressqueue/v1/posts/' . $post_id . '/share-now/preview' )->get_data()['share_now'];
		self::assertTrue( $preview['can_confirm'] );
		self::assertCount( 2, $preview['shares'] );
		self::assertSame( array( 'End of the Buffer queue', 'End of the Buffer queue' ), array_column( $preview['shares'], 'when' ) );
		self::assertSame( '"Post right away" and "Next free queue slot" only work for one channel at a time. So each share goes to the end of its Buffer queue.', $preview['note'] );
		self::assertSame( 'PressQueue sends this to Buffer for 2 channels:', $this->liveHeading( $post_id ) );
	}

	public function test_share_now_needs_the_share_old_posts_permission(): void {
		$channel = $this->channel( 'remote-1', 'mastodon', 'Example Social' );
		$this->createEnabledRule( $this->includeRuleInput( array( $channel ), 'queue_end' ) );
		$post_id = $this->publishedPost( 'Editors only' );

		$editor_id = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
		$editor    = get_userdata( $editor_id );
		self::assertInstanceOf( \WP_User::class, $editor );
		$editor->add_cap( Capabilities::EDIT_POST_CONTROLS );
		$this->login( $editor_id );

		self::assertFalse( PostSharingSummary::editor_context( $post_id )['can_share_now'] );
		$box = $this->metaBox( $post_id );
		self::assertStringContainsString( 'Share this post', $box, 'the editor still sees the sharing box' );
		self::assertStringNotContainsString( 'data-pq-share-now', $box );
		self::assertSame( 403, $this->rest( 'POST', '/pressqueue/v1/posts/' . $post_id . '/share-now/preview' )->get_status() );

		$editor->add_cap( Capabilities::OPERATE_BATCHES );
		$this->login( $editor_id );
		self::assertTrue( PostSharingSummary::editor_context( $post_id )['can_share_now'] );
		self::assertSame( 200, $this->rest( 'POST', '/pressqueue/v1/posts/' . $post_id . '/share-now/preview' )->get_status() );
	}

	public function test_a_preview_only_works_for_its_own_post_and_person_and_can_be_cancelled(): void {
		$channel = $this->channel( 'remote-1', 'mastodon', 'Example Social' );
		$this->createEnabledRule( $this->includeRuleInput( array( $channel ), 'queue_end' ) );
		$post_id = $this->publishedPost( 'Mine' );
		$other   = $this->publishedPost( 'Someone else' );
		$preview = $this->rest( 'POST', '/pressqueue/v1/posts/' . $post_id . '/share-now/preview' )->get_data()['share_now'];
		$body    = array(
			'batch_public_id'       => $preview['batch_public_id'],
			'preview_snapshot_hash' => $preview['preview_snapshot_hash'],
		);

		$wrong_post = $this->rest( 'POST', '/pressqueue/v1/posts/' . $other . '/share-now', $body );
		self::assertSame( 409, $wrong_post->get_status() );
		self::assertSame( 'missing', $wrong_post->get_data()['code'] );

		$this->login( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		self::assertSame( 'missing', $this->rest( 'POST', '/pressqueue/v1/posts/' . $post_id . '/share-now', $body )->get_data()['code'] );
		self::assertSame( 0, $this->deliveryCount( $post_id ) + $this->deliveryCount( $other ) );

		$this->login( $this->admin_id );
		$cancel = $this->rest( 'POST', '/pressqueue/v1/posts/' . $post_id . '/share-now/cancel', array( 'batch_public_id' => $preview['batch_public_id'] ) );
		self::assertSame( 200, $cancel->get_status() );
		self::assertSame( 'cancelled', ( new BatchRepository() )->find_by_public_id( $preview['batch_public_id'] )->status );
		self::assertSame( array( 'cancelled' ), array_values( array_unique( array_map( static fn ( $item ): string => $item->state, ( new BatchItemRepository() )->find_by_batch( $preview['batch_public_id'] ) ) ) ) );
		self::assertSame( 'not_confirmable', $this->rest( 'POST', '/pressqueue/v1/posts/' . $post_id . '/share-now', $body )->get_data()['code'] );
		self::assertSame( 0, $this->deliveryCount( $post_id ) );
	}

	/**
	 * Return the preview heading in live mode.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function liveHeading( int $post_id ): string {
		( new SettingsRepository() )->set_dry_run_enabled( false );
		$heading = $this->rest( 'POST', '/pressqueue/v1/posts/' . $post_id . '/share-now/preview' )->get_data()['share_now']['heading'];
		( new SettingsRepository() )->set_dry_run_enabled( true );

		return (string) $heading;
	}

	/**
	 * Count shares of a post.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	private function deliveryCount( int $post_id ): int {
		return ( new DeliveryQueryRepository() )->query( array( 'post_id' => $post_id ) )['total'];
	}

	/**
	 * Render the classic editor box.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function metaBox( int $post_id ): string {
		ob_start();
		PostControlsController::render_meta_box( get_post( $post_id ) );

		return (string) ob_get_clean();
	}

	/**
	 * Log a user in for capability checks.
	 *
	 * @param int $user_id User ID.
	 */
	private function login( int $user_id ): void {
		wp_set_current_user( 0 );
		wp_set_current_user( $user_id );
	}

	/**
	 * Create and publish a post, and record its publish event.
	 *
	 * @param string         $title Title.
	 * @param array<int,int> $categories Category IDs.
	 * @return int
	 */
	private function publishedPost( string $title, array $categories = array() ): int {
		$post_id = wp_insert_post(
			array(
				'post_category' => $categories,
				'post_content'  => 'Body text',
				'post_status'   => 'draft',
				'post_title'    => $title,
				'post_type'     => 'post',
			)
		);
		self::assertIsInt( $post_id );
		wp_publish_post( $post_id );
		EventCollector::finalize();

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
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array() === $body ? new \stdClass() : $body ) );

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
	 * Return a sharing rule that only takes part in Share old posts.
	 *
	 * @param array<int,string> $channel_public_ids Channel public IDs.
	 * @param string            $schedule Rule timing.
	 * @return array<string,mixed>
	 */
	private function includeRuleInput( array $channel_public_ids, string $schedule ): array {
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
				'destinations'   => array_map(
					static fn ( string $id ): array => array( 'channel_public_id' => $id ),
					$channel_public_ids
				),
				'schema_version' => 1,
			),
			'effect'                 => 'include',
			'inline_template'        => array(
				'custom_field_allowlist' => array(),
				'message'                => '{{ title }}',
				'schema_version'         => 1,
			),
			'name'                   => 'Share old posts',
			'priority'               => 100,
			'schedule_policy'        => $schedule,
			'triggers'               => array( 'bulk' ),
		);
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
			'triggers'               => array( 'first_publish', 'bulk' ),
		);
	}

	/**
	 * Create an enabled channel and return its public ID.
	 *
	 * @param string $remote_id Remote channel ID.
	 * @param string $service Service.
	 * @param string $name Display name.
	 * @return string
	 */
	private function channel( string $remote_id, string $service, string $name ): string {
		$channels = new ChannelRepository();

		if ( '' === $this->connection ) {
			$connections = new ConnectionRepository();
			$envelope    = CanonicalJson::encode_object( array( 'fixture' => 'encrypted' ) );
			$account     = array(
				'display_label'     => 'Owner',
				'organizations'     => array(
					array(
						'channel_count'          => 2,
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
			$connection       = $connections->complete_sync_success( $connection->public_id, $lease, 'acct_1', 'org_1', $account );
			$connection       = $connections->enable( $connection->public_id );
			$this->connection = $connection->public_id;
		}

		$channel = $channels->create(
			$this->connection,
			'buffer',
			$remote_id,
			$service,
			array( 'name' => $name ),
			array(
				'catalog_version' => '2026-08-25.1',
				'delivery'        => array(
					'max_text_characters' => 280,
					'schedule_slots'      => array( 'immediate', 'queue_next', 'queue_end', 'explicit_time' ),
					'text'                => true,
				),
				'schema_version'  => 2,
				'service'         => $service,
				'supported'       => true,
			),
			false,
			'2026-08-24 12:00:00',
			'org_1',
			'available'
		);
		$channel = $channels->set_enabled_if_current( $channel->public_id, true, $channel->updated_at->format( 'Y-m-d H:i:s' ) );

		return $channel->public_id;
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
