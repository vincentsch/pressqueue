<?php
/**
 * Social card integration tests.
 *
 * @package PressQueue\Tests\Integration
 */

declare(strict_types=1);

namespace PressQueue\Tests\Integration;

use PressQueue\Admin\PostSharingSummary;
use PressQueue\Planning\EventNormalizer;
use PressQueue\Planning\Planner;
use PressQueue\Runtime\RuntimeDisabledLatch;
use PressQueue\Security\Capabilities;
use PressQueue\SocialCard\CardStore;
use PressQueue\SocialCard\Hooks;
use PressQueue\SocialCard\LinkPreview;
use PressQueue\SocialCard\Renderer;
use PressQueue\SocialCard\Settings;
use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\Database;
use PressQueue\Storage\Repository\ChannelRepository;
use PressQueue\Storage\Repository\ConnectionRepository;
use PressQueue\Storage\Repository\RuleRepository;
use PressQueue\Storage\Schema;
use PressQueue\Storage\SchemaMigrator;
use PressQueue\Storage\SettingsRepository;
use PressQueue\Tests\Support\PressQueueIntegrationTestCase;

/**
 * @covers \PressQueue\SocialCard\CardStore
 * @covers \PressQueue\SocialCard\Hooks
 * @covers \PressQueue\SocialCard\LinkPreview
 * @covers \PressQueue\Planning\MediaResolver
 */
final class SocialCardIntegrationTest extends PressQueueIntegrationTestCase {
	protected function setUp(): void {
		parent::setUp();

		if ( ! Renderer::is_supported() ) {
			self::markTestSkipped( 'GD with FreeType is not available.' );
		}

		$this->resetStorage();
		( new SchemaMigrator( Database::from_globals() ) )->migrate();
		RuntimeDisabledLatch::open();
		Capabilities::grant_to_administrators( false );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		CardStore::flush_memo();
	}

	protected function tearDown(): void {
		CardStore::delete_all();
		delete_option( Settings::OPTION );
		$this->resetStorage();

		parent::tearDown();
	}

	public function test_a_card_is_drawn_once_and_reused(): void {
		$post_id = $this->publishedPost( 'Why small kitchens cook better' );

		$card = CardStore::for_post( $post_id );
		self::assertNotNull( $card );
		self::assertSame( $post_id, (int) get_post_field( 'post_parent', $card['attachment_id'] ) );
		self::assertTrue( CardStore::is_card( $card['attachment_id'] ) );
		self::assertFileExists( (string) get_attached_file( $card['attachment_id'] ) );

		$size = getimagesize( (string) get_attached_file( $card['attachment_id'] ) );
		self::assertIsArray( $size );
		self::assertSame( array( 1200, 630 ), array( $size[0], $size[1] ) );

		CardStore::flush_memo();
		self::assertSame( $card['attachment_id'], CardStore::for_post( $post_id )['attachment_id'] ?? null );
	}

	public function test_a_new_title_or_style_makes_a_new_card(): void {
		$post_id = $this->publishedPost( 'First title' );
		$first   = CardStore::for_post( $post_id );

		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Second title',
			)
		);
		CardStore::flush_memo();
		$second = CardStore::for_post( $post_id );

		Settings::save( array( 'font' => 'elegant-serif' ) );
		CardStore::flush_memo();
		$third = CardStore::for_post( $post_id );

		self::assertNotNull( $first );
		self::assertNotNull( $second );
		self::assertNotNull( $third );
		self::assertNotSame( $first['attachment_id'], $second['attachment_id'] );
		self::assertNotSame( $second['attachment_id'], $third['attachment_id'] );
	}

	public function test_cards_are_removed_with_their_post_and_hidden_from_the_media_picker(): void {
		$post_id = $this->publishedPost( 'Removed soon' );
		$card    = CardStore::for_post( $post_id );
		self::assertNotNull( $card );
		$file = (string) get_attached_file( $card['attachment_id'] );

		$query = Hooks::hide_from_media_modal( array( 'post_type' => 'attachment' ) );
		$ids   = get_posts(
			array_merge(
				$query,
				array(
					'fields'      => 'ids',
					'post_status' => 'inherit',
				)
			)
		);
		self::assertNotContains( $card['attachment_id'], array_map( 'intval', $ids ) );

		wp_delete_post( $post_id, true );

		self::assertNull( get_post( $card['attachment_id'] ) );
		self::assertFileDoesNotExist( $file );
	}

	public function test_rules_with_the_social_image_send_the_card(): void {
		$destination = $this->enabledDestination();
		$rule        = $this->createEnabledRule( $this->includeRuleInput( $destination['channel_public_id'] ) );
		$post_id     = $this->publishedPost( 'A post with a social image' );

		$normalizer = new EventNormalizer();
		$event      = $normalizer->from_post_id( $post_id, 'runtime', 'integration', 'first_publish', $normalizer->metadata_allowlist_for_rules( array( $rule ) ) );
		self::assertNotNull( $event );

		$result     = ( new Planner() )->plan_runtime( $event )->to_array();
		$deliveries = $result['deliveries'];
		self::assertCount( 1, $deliveries, (string) wp_json_encode( array_map( static fn( $d ) => array( $d['result'] ?? '', array_column( $d['reasons'] ?? array(), 'code' ) ), $result['decisions'] ) ) );

		$media = $deliveries[0]['payload_preview']['media_snapshot'];
		self::assertSame( 'social_card', $media['mode'] );
		self::assertCount( 1, $media['items'] );
		self::assertSame( 1200, $media['items'][0]['width'] );
		self::assertSame( 'image/jpeg', $media['items'][0]['mime_type'] );
		self::assertTrue( CardStore::is_card( (int) $media['items'][0]['attachment_id'] ) );
		self::assertStringContainsString( '/pressqueue/social/', $media['items'][0]['url'] );
	}

	public function test_unpublished_posts_get_no_public_card_until_they_go_live(): void {
		$destination = $this->enabledDestination();
		$rule        = $this->createEnabledRule( $this->includeRuleInput( $destination['channel_public_id'] ) );

		foreach ( array( 'draft', 'pending', 'private', 'future' ) as $status ) {
			$post_id = (int) self::factory()->post->create(
				array(
					'post_content' => 'Body text',
					'post_date'    => 'future' === $status ? gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) : '',
					'post_status'  => $status,
					'post_title'   => 'Secret plans for ' . $status,
				)
			);

			self::assertNull( CardStore::for_post( $post_id ), $status );
			self::assertSame( array(), get_children( array( 'post_parent' => $post_id, 'post_type' => 'attachment' ) ), $status );
			self::assertTrue( CardStore::will_draw_when_published( $post_id ), $status );

			$normalizer = new EventNormalizer();
			$event      = $normalizer->from_post_id( $post_id, 'preview', 'integration', 'first_publish', $normalizer->metadata_allowlist_for_rules( array( $rule ) ) );
			self::assertNotNull( $event, $status );
			$result = ( new Planner() )->preview_as_new_publication( $event, null, false )->to_array();
			self::assertNotSame( array(), $result['deliveries'], $status );
			foreach ( $result['deliveries'] as $delivery ) {
				self::assertSame( 'none', $delivery['payload_preview']['media_snapshot']['mode'] ?? null, $status );
			}
			self::assertSame( array(), get_children( array( 'post_parent' => $post_id, 'post_type' => 'attachment' ) ), $status . ' after preview' );

			$summary = PostSharingSummary::from_preview( $result );
			self::assertStringContainsString( 'with a social image of the title', (string) wp_json_encode( $summary ), $status );
		}

		$post_id = (int) self::factory()->post->create(
			array(
				'post_content' => 'Body text',
				'post_status'  => 'draft',
				'post_title'   => 'Published at last',
			)
		);
		self::assertNull( CardStore::for_post( $post_id ) );
		wp_publish_post( $post_id );
		CardStore::flush_memo();
		self::assertNotNull( CardStore::for_post( $post_id ) );
		self::assertFalse( CardStore::will_draw_when_published( $post_id ) );
	}

	public function test_link_previews_hand_the_card_to_the_seo_framework(): void {
		$post_id = $this->publishedPost( 'Previewed link' );
		$args    = array(
			'id'  => $post_id,
			'pta' => '',
			'tax' => '',
			'uid' => 0,
		);
		$params  = array(
			'cbs'      => array( 'featured' => 'strlen' ),
			'fallback' => array( 'settings' => 'strlen' ),
			'multi'    => true,
			'size'     => 'full',
		);

		self::assertSame( $params, LinkPreview::seo_framework_params( $params, $args, 'social' ), 'Off by default.' );

		Settings::save( array( 'link_preview' => 'missing' ) );
		$missing = LinkPreview::seo_framework_params( $params, $args, 'social' );
		self::assertSame( array( 'featured' ), array_keys( $missing['cbs'] ) );
		self::assertSame( array( 'pressqueue', 'settings' ), array_keys( $missing['fallback'] ) );

		Settings::save( array( 'link_preview' => 'always' ) );
		$always = LinkPreview::seo_framework_params( $params, $args, 'social' );
		self::assertSame( array( 'pressqueue' ), array_keys( $always['cbs'] ) );
		self::assertSame( $params, LinkPreview::seo_framework_params( $params, $args, 'organization' ) );

		$images = iterator_to_array( LinkPreview::seo_framework_image( $args, 'full' ) );
		self::assertCount( 1, $images );
		self::assertSame( 1200, $images[0]['width'] );
		self::assertStringContainsString( '/pressqueue/social/', $images[0]['url'] );
	}

	public function test_an_image_set_for_one_post_in_an_seo_plugin_wins(): void {
		Settings::save( array( 'link_preview' => 'always' ) );
		$post_id = $this->publishedPost( 'Has its own preview image' );
		self::assertNotNull( LinkPreview::card( $post_id ) );

		update_post_meta( $post_id, '_yoast_wpseo_opengraph-image', 'https://example.org/own.jpg' );
		self::assertNull( LinkPreview::card( $post_id ) );
	}

	/**
	 * Create a published post.
	 *
	 * @param string $title Title.
	 * @return int
	 */
	private function publishedPost( string $title ): int {
		return (int) self::factory()->post->create(
			array(
				'post_content' => 'Body text',
				'post_status'  => 'publish',
				'post_title'   => $title,
			)
		);
	}

	/**
	 * Create and enable a rule.
	 *
	 * @param array<string,mixed> $input Rule input.
	 * @return \PressQueue\Storage\Record\RuleRecord
	 */
	private function createEnabledRule( array $input ) {
		$rules     = new RuleRepository();
		$rule      = $rules->create( $input );
		$previewed = $rules->mark_version_previewed( $rule->public_id, $rule->lock_version, $rule->version );

		return $rules->enable_after_preview( $previewed->public_id, $previewed->lock_version );
	}

	/**
	 * Return a rule that shares posts with the social image.
	 *
	 * @param string $channel_public_id Channel.
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
				'message'                => '{{ title }} {{ canonical_url }}',
				'schema_version'         => 1,
			),
			'media_policy'           => array(
				'mode'           => 'social_card',
				'schema_version' => 1,
			),
			'name'                   => 'Share with social image',
			'priority'               => 100,
			'schedule_policy'        => 'immediate',
			'triggers'               => array( 'first_publish' ),
		);
	}

	/**
	 * Create an enabled destination that accepts images.
	 *
	 * @return array{connection_public_id:string,channel_public_id:string}
	 */
	private function enabledDestination(): array {
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
		$capability = array(
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
		$channel    = $channels->create( $connection->public_id, 'buffer', 'remote-channel-1', 'mastodon', array( 'name' => 'Example Social' ), $capability, false, '2026-08-24 12:00:00', 'org_1', 'available' );
		$channel    = $channels->set_enabled_if_current( $channel->public_id, true, $channel->updated_at->format( 'Y-m-d H:i:s' ) );

		return array(
			'channel_public_id'    => $channel->public_id,
			'connection_public_id' => $connection->public_id,
		);
	}

	/**
	 * Drop plugin tables and options.
	 */
	private function resetStorage(): void {
		global $wpdb;

		$database = Database::from_globals();
		foreach ( array_reverse( Schema::table_suffixes() ) as $suffix ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . $database->table( $suffix ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Test cleanup.
		}

		// Table changes commit implicitly, so options from earlier tests can survive the rollback.
		foreach ( array( Schema::VERSION_OPTION, Schema::MIGRATION_LOCK_OPTION, Schema::MIGRATION_FAILED_OPTION, Capabilities::VERSION_OPTION, SettingsRepository::KILL_SWITCH_OPTION, SettingsRepository::DRY_RUN_OPTION, SettingsRepository::NEW_POST_SHARING_OPTION, Settings::OPTION ) as $option ) {
			delete_option( $option );
		}
	}
}
