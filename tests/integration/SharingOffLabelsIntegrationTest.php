<?php
/**
 * "Sharing off" wording for posts whose own switch is off.
 *
 * @package PressQueue\Tests\Integration
 */

declare(strict_types=1);

namespace PressQueue\Tests\Integration;

use PressQueue\Admin\ActivitySummary;
use PressQueue\Admin\DecisionsController;
use PressQueue\Admin\DeliveryListController;
use PressQueue\Admin\OverviewController;
use PressQueue\Admin\PostListColumn;
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

/**
 * @covers \PressQueue\Admin\ActivitySummary
 * @covers \PressQueue\Admin\DecisionsController
 * @covers \PressQueue\Admin\Labels
 * @covers \PressQueue\Admin\OverviewController
 * @covers \PressQueue\Admin\PostListColumn
 */
final class SharingOffLabelsIntegrationTest extends WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();

		$this->resetStorage();
		( new SchemaMigrator( Database::from_globals() ) )->migrate();
		RuntimeDisabledLatch::open();
		SettingsRepository::ensure_defaults_for_current_site();
		Capabilities::grant_to_administrators( false );
		$user_id = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( 0 );
		wp_set_current_user( $user_id );
		$_GET = array();
	}

	protected function tearDown(): void {
		$_GET = array();
		$this->resetStorage();

		parent::tearDown();
	}

	public function test_a_switched_off_post_reads_sharing_off_while_a_never_share_rule_keeps_its_wording(): void {
		$destination = $this->enabledDestination();
		$journal     = (int) self::factory()->category->create( array( 'name' => 'Private Journal' ) );
		$this->createEnabledRule( $this->includeRuleInput( $destination ) );
		$this->createEnabledRule( $this->excludeRuleInput( $journal ) );

		update_option( SettingsRepository::NEW_POST_SHARING_OPTION, 'opt_in' );
		$off = $this->post( 'Kitchen renovation diary' );
		update_option( SettingsRepository::NEW_POST_SHARING_OPTION, 'automatic' );
		$never = $this->post( 'Notes from the journal', array( $journal ) );
		self::assertSame( 'exclude', PostControlMetadata::read_for_post( $off )['policy'] );

		wp_publish_post( $off );
		wp_publish_post( $never );
		EventCollector::finalize();

		$feed = array();
		foreach ( ( new ActivitySummary() )->recent_feed() as $entry ) {
			$feed[ (int) $entry['post_id'] ] = $entry;
		}
		self::assertSame( 'switched_off', $feed[ $off ]['blocked_kind'] );
		self::assertSame( '"Share this post" was switched off for this post.', $feed[ $off ]['not_shared'] );
		self::assertSame( 'excluded', $feed[ $never ]['blocked_kind'] );
		self::assertSame( 'Matches the "never share" rule "Keep the journal private".', $feed[ $never ]['not_shared'] );

		ob_start();
		OverviewController::render_page();
		$overview = (string) ob_get_clean();
		$off_row  = $this->row( $overview, 'Kitchen renovation diary' );
		self::assertStringContainsString( '<span class="pq-pill pq-pill--idle" title="&quot;Share this post&quot; was switched off for this post.">Sharing off</span>', $off_row );
		self::assertStringContainsString( '&quot;Share this post&quot; was switched off for this post.', $off_row );
		self::assertStringNotContainsString( 'Never share', $off_row );
		self::assertStringNotContainsString( 'pq-pill--bad', $off_row );
		self::assertStringContainsString( 'pq-pill--bad', $this->row( $overview, 'Notes from the journal' ) );
		self::assertStringContainsString( '>Never share</span>', $this->row( $overview, 'Notes from the journal' ) );

		$decisions = $this->renderDecisions();
		$off_row   = $this->row( $decisions, 'Kitchen renovation diary', '<li class="pq-decision ' );
		self::assertStringContainsString( 'pq-decision--switched_off', $off_row );
		self::assertStringContainsString( '>Sharing off</span>', $off_row );
		self::assertStringContainsString( 'pq-pill--idle', $off_row );
		self::assertStringContainsString( '<span>&quot;Share this post&quot; was switched off for this post.</span>', $off_row );
		self::assertStringNotContainsString( 'Never share', $off_row );
		self::assertStringNotContainsString( 'pq-pill--bad', $off_row );
		$never_row = $this->row( $decisions, 'Notes from the journal', '<li class="pq-decision ' );
		self::assertStringContainsString( 'Never share: in a category you never share.', $never_row );
		self::assertStringContainsString( 'pq-pill--bad', $never_row );
		self::assertStringContainsString( 'Rule: Keep the journal private', $never_row );

		$column = $this->renderColumns( array( $off, $never ) );
		self::assertSame( '<span class="pq-col-off">Sharing off</span>', $column[ $off ] );
		self::assertSame( '<span class="pq-col-off">Never share</span>', $column[ $never ] );
	}

	public function test_the_post_list_forgets_the_switch_once_it_is_on_again(): void {
		$destination = $this->enabledDestination();
		$this->createEnabledRule( $this->includeRuleInput( $destination ) );

		update_option( SettingsRepository::NEW_POST_SHARING_OPTION, 'opt_in' );
		$post_id = $this->post( 'Switched on later' );
		wp_publish_post( $post_id );
		EventCollector::finalize();

		self::assertSame( '<span class="pq-col-off">Sharing off</span>', $this->renderColumns( array( $post_id ) )[ $post_id ] );

		$bundle           = PostControlMetadata::read_for_post( $post_id );
		$next             = $bundle;
		$next['policy']   = 'inherit';
		PostControlMetadata::replace_for_post( $post_id, $next, PostControlMetadata::bundle_hash( $bundle ) );

		$cell = $this->renderColumns( array( $post_id ) )[ $post_id ];
		self::assertStringNotContainsString( 'Never share', $cell );
		self::assertStringNotContainsString( 'Sharing off', $cell );
	}

	/**
	 * Return the row that mentions a post title, up to the next row of the same list.
	 *
	 * @param string $html Screen HTML.
	 * @param string $title Post title.
	 * @param string $opener Opening tag of one row.
	 * @return string
	 */
	private function row( string $html, string $title, string $opener = '<li>' ): string {
		$position = strpos( $html, $title );
		self::assertNotFalse( $position, $title );
		$start = strrpos( substr( $html, 0, $position ), $opener );
		self::assertNotFalse( $start );
		$end = strpos( $html, $opener, $position );

		return false === $end ? substr( $html, (int) $start ) : substr( $html, (int) $start, $end - (int) $start );
	}

	/**
	 * Render the Decisions tab.
	 *
	 * @return string
	 */
	private function renderDecisions(): string {
		$_GET = array(
			'page' => DeliveryListController::SLUG,
			'tab'  => 'decisions',
		);
		ob_start();
		DecisionsController::render_tab();

		return (string) ob_get_clean();
	}

	/**
	 * Render the post list column for a page of posts.
	 *
	 * @param array<int,int> $post_ids Post IDs on the list page.
	 * @return array<int,string>
	 */
	private function renderColumns( array $post_ids ): array {
		global $wp_query;

		$wp_query        = new \WP_Query();
		$wp_query->posts = array_map( 'get_post', $post_ids );
		$this->resetColumnCache();

		$cells = array();
		foreach ( $post_ids as $post_id ) {
			ob_start();
			PostListColumn::render_column( 'pressqueue_sharing', $post_id );
			$cells[ $post_id ] = trim( (string) ob_get_clean() );
		}

		return $cells;
	}

	/**
	 * Forget the column's per-request cache so each render reads the current state.
	 */
	private function resetColumnCache(): void {
		foreach ( array( 'shares' => null, 'excluded' => array() ) as $name => $value ) {
			$property = new \ReflectionProperty( PostListColumn::class, $name );
			$property->setAccessible( true );
			$property->setValue( null, $value );
		}
	}

	/**
	 * Create a draft post.
	 *
	 * @param string         $title Title.
	 * @param array<int,int> $categories Category IDs.
	 * @return int
	 */
	private function post( string $title, array $categories = array() ): int {
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
		self::assertGreaterThan( 0, $post_id );

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
	 * Return a sharing rule for every post.
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
		$channel    = $channels->create(
			$connection->public_id,
			'buffer',
			'remote-channel-1',
			'mastodon',
			array( 'name' => 'Example Social' ),
			array(
				'catalog_version' => '2026-08-25.1',
				'delivery'        => array(
					'max_text_characters' => 280,
					'schedule_slots'      => array( 'immediate', 'queue_next', 'queue_end', 'explicit_time' ),
					'text'                => true,
				),
				'schema_version'  => 2,
				'service'         => 'mastodon',
				'supported'       => true,
			),
			false,
			'2026-08-24 12:00:00',
			'org_1',
			'available'
		);
		$channel    = $channels->set_enabled_if_current( $channel->public_id, true, $channel->updated_at->format( 'Y-m-d H:i:s' ) );

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
