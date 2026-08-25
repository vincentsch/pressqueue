<?php
/**
 * Planning integration tests.
 *
 * @package PressQueue\Tests\Integration
 */

declare(strict_types=1);

namespace PressQueue\Tests\Integration;

use PressQueue\Editorial\PostControlMetadata;
use PressQueue\Planning\EventCollector;
use PressQueue\Planning\EventNormalizer;
use PressQueue\Planning\Planner;
use PressQueue\Security\Capabilities;
use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\Database;
use PressQueue\Storage\Record\RuleRecord;
use PressQueue\Storage\Repository\ChannelRepository;
use PressQueue\Storage\Repository\ConnectionRepository;
use PressQueue\Storage\Repository\DecisionRepository;
use PressQueue\Storage\Repository\RuleRepository;
use PressQueue\Storage\Schema;
use PressQueue\Storage\SchemaMigrator;
use PressQueue\Storage\SettingsRepository;
use PressQueue\Storage\StaleRecordException;
use WP_UnitTestCase;

/**
 * @covers \PressQueue\Planning\ConditionEvaluation
 * @covers \PressQueue\Planning\ConditionEvaluator
 * @covers \PressQueue\Planning\EventNormalizer
 * @covers \PressQueue\Planning\NormalizedEvent
 * @covers \PressQueue\Planning\Planner
 * @covers \PressQueue\Planning\PlanningResult
 * @covers \PressQueue\Planning\ReasonFactory
 * @covers \PressQueue\Planning\TemplateRenderer
 */
final class PlanningIntegrationTest extends WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();

		$this->resetStorage();
		( new SchemaMigrator( Database::from_globals() ) )->migrate();
	}

	protected function tearDown(): void {
		$this->resetStorage();

		parent::tearDown();
	}

	public function test_fixture_exclusion_rule_records_excluded_decision_with_taxonomy_and_metadata_reasons(): void {
		$parent_id = self::factory()->term->create(
			array(
				'name'     => 'Guides',
				'taxonomy' => 'category',
			)
		);
		$child_id  = self::factory()->term->create(
			array(
				'name'     => 'Deep Guides',
				'parent'   => $parent_id,
				'taxonomy' => 'category',
			)
		);
		$post_id   = $this->publishedPost();
		wp_set_post_terms( $post_id, array( $child_id ), 'category' );
		update_post_meta( $post_id, '_pressqueue_fixture_lane', 'evergreen' );

		$rule = $this->createEnabledRule( $this->excludeRuleInput( (int) $parent_id ) );

		$result = $this->planPost( $post_id, array( $rule ) );
		$fields = array_column( $result->decisions[0]['reasons'], 'field' );

		self::assertSame( 'excluded', $result->decisions[0]['result'] );
		self::assertSame( array(), $result->deliveries );
		self::assertContains( 'taxonomy:category', $fields );
		self::assertContains( 'metadata:_pressqueue_fixture_lane', $fields );
		self::assertSame( 1, $this->rowCount( Schema::DECISIONS ) );
		self::assertSame( 0, $this->rowCount( Schema::DELIVERIES ) );
	}

	public function test_matching_preview_includes_skipped_nonmatching_rule_explanation(): void {
		$matching   = $this->createEnabledRule( $this->excludePostTypeRuleInput( 'post', 5, 'Exclude posts' ) );
		$skipped    = $this->createEnabledRule( $this->excludePostTypeRuleInput( 'page', 10, 'Exclude pages' ) );
		$post_id    = $this->publishedPost();
		$rules      = array( $matching, $skipped );
		$normalizer = new EventNormalizer();
		$event      = $normalizer->from_post_id( $post_id, 'preview', 'integration', 'first_publish', $normalizer->metadata_allowlist_for_rules( $rules ) );

		self::assertNotNull( $event );

		$result = ( new Planner() )->preview( $event, $rules );

		self::assertSame( array( 'excluded', 'skipped' ), array_column( $result->decisions, 'result' ) );
		self::assertSame( $skipped->public_id, $result->decisions[1]['rule_public_id'] );
		self::assertSame( 'not_matched', $result->decisions[1]['reasons'][0]['result'] );
		self::assertSame( 0, $this->rowCount( Schema::DECISIONS ) );
		self::assertSame( 0, $this->rowCount( Schema::DELIVERIES ) );
	}

	public function test_include_planning_creates_idempotent_dry_run_delivery_and_live_identity_separately(): void {
		$destination = $this->enabledDestination();
		$rule        = $this->createEnabledRule( $this->includeRuleInput( $destination['channel_public_id'] ) );
		$post_id     = $this->publishedPost( 'Launch Post' );
		$result      = $this->planPost( $post_id, array( $rule ) );

		self::assertSame( 'included', $result->decisions[0]['result'] );
		self::assertSame( 'dry_run', $result->deliveries[0]['delivery_mode'] );
		self::assertSame( 'dry_run', $result->deliveries[0]['state'] );
		self::assertStringContainsString( 'Launch Post', $result->deliveries[0]['payload_preview']['message'] );
		self::assertSame( 1, $this->rowCount( Schema::DECISIONS ) );
		self::assertSame( 1, $this->rowCount( Schema::DELIVERIES ) );

		$this->planPost( $post_id, array( $rule ) );
		self::assertSame( 1, $this->rowCount( Schema::DECISIONS ) );
		self::assertSame( 1, $this->rowCount( Schema::DELIVERIES ) );

		$settings = new SettingsRepository();
		$settings->set_dry_run_enabled( false );
		$settings->set_kill_switch_enabled( false );
		$live = $this->planPost( $post_id, array( $rule ) );

		self::assertSame( 'live', $live->deliveries[0]['delivery_mode'] );
		self::assertSame( 'proposed', $live->deliveries[0]['state'] );
		self::assertSame( 1, $this->rowCount( Schema::DECISIONS ) );
		self::assertSame( 2, $this->rowCount( Schema::DELIVERIES ) );
	}

	public function test_kill_switch_creates_held_live_rows_when_dry_run_is_off(): void {
		$destination = $this->enabledDestination();
		$rule        = $this->createEnabledRule( $this->includeRuleInput( $destination['channel_public_id'] ) );
		$settings    = new SettingsRepository();
		$settings->set_dry_run_enabled( false );
		$settings->set_kill_switch_enabled( true );

		$result = $this->planPost( $this->publishedPost(), array( $rule ) );

		self::assertSame( 'live', $result->deliveries[0]['delivery_mode'] );
		self::assertSame( 'held', $result->deliveries[0]['state'] );
		self::assertSame( 1, $this->rowCount( Schema::DELIVERIES ) );
	}

	public function test_inclusion_policy_error_blocks_same_destination_delivery(): void {
		$destination = $this->enabledDestination();
		$error_rule  = $this->createEnabledRule(
			array_merge(
				$this->includeRuleInput( $destination['channel_public_id'], 5 ),
				array(
					'condition_tree'         => $this->metadataExistsCondition( '_pressqueue_non_scalar' ),
					'custom_field_allowlist' => array( '_pressqueue_non_scalar' ),
					'name'                   => 'Broken include',
				)
			)
		);
		$valid_rule  = $this->createEnabledRule( $this->includeRuleInput( $destination['channel_public_id'], 10 ) );
		$post_id     = $this->publishedPost();
		update_post_meta( $post_id, '_pressqueue_non_scalar', array( 'bad' ) );

		$result  = $this->planPost( $post_id, array( $error_rule, $valid_rule ) );
		$results = array_column( $result->decisions, 'result' );
		$reasons = array_merge( $result->decisions[0]['reasons'], $result->decisions[1]['reasons'] );
		$codes   = array_column( $reasons, 'code' );

		sort( $results, SORT_STRING );

		self::assertSame( array( 'policy_error', 'skipped' ), $results );
		self::assertContains( 'metadata_non_scalar', $codes );
		self::assertContains( 'blocked_by_destination_policy_error', $codes );
		self::assertSame( array(), $result->deliveries );
		self::assertSame( 0, $this->rowCount( Schema::DELIVERIES ) );
	}

	public function test_explicit_post_exclusion_wins_before_invalid_template_rendering(): void {
		$destination = $this->enabledDestination();
		$rule        = $this->createEnabledRule( $this->includeRuleInput( $destination['channel_public_id'], 100, '{{ unknown_tag }}' ) );
		$post_id     = $this->publishedPost();
		update_post_meta( $post_id, '_pressqueue_policy', 'exclude' );

		$result = $this->planPost( $post_id, array( $rule ) );

		self::assertCount( 1, $result->decisions );
		self::assertSame( 'excluded', $result->decisions[0]['result'] );
		self::assertSame( array(), $result->deliveries );
		self::assertSame( 1, $this->rowCount( Schema::DECISIONS ) );
		self::assertSame( 0, $this->rowCount( Schema::DELIVERIES ) );
	}

	public function test_explicit_post_exclusion_wins_before_malformed_stored_rule(): void {
		$destination = $this->enabledDestination();
		$rule        = $this->createEnabledRule( $this->includeRuleInput( $destination['channel_public_id'] ) );
		$post_id     = $this->publishedPost();
		update_post_meta( $post_id, '_pressqueue_policy', 'exclude' );
		$this->corruptRuleDocument( $rule->public_id, 'condition_tree_json', '{bad json' );

		$result = $this->planPost( $post_id, array( $rule ) );

		self::assertCount( 1, $result->decisions );
		self::assertSame( 'excluded', $result->decisions[0]['result'] );
		self::assertSame( array(), $result->deliveries );
		self::assertSame( 1, $this->rowCount( Schema::DECISIONS ) );
		self::assertSame( 0, $this->rowCount( Schema::DELIVERIES ) );
	}

	public function test_malformed_stored_rule_records_policy_error_and_first_publish_metadata(): void {
		$destination = $this->enabledDestination();
		$rule        = $this->createEnabledRule( $this->includeRuleInput( $destination['channel_public_id'] ) );
		$post_id     = $this->publishedPost();
		$this->corruptRuleDocument( $rule->public_id, 'condition_tree_json', '{bad json' );

		EventCollector::capture_status_transition( 'publish', 'draft', get_post( $post_id ) );
		EventCollector::finalize();

		$decisions = ( new DecisionRepository() )->find_recent();

		self::assertSame( 1, $this->rowCount( Schema::DECISIONS ) );
		self::assertSame( 0, $this->rowCount( Schema::DELIVERIES ) );
		self::assertSame( 'policy_error', $decisions[0]->result );
		self::assertSame( 'malformed_rule', $decisions[0]->reasons[0]['code'] );
		self::assertNotSame( '', get_post_meta( $post_id, EventNormalizer::META_FIRST_PUBLISH_SEEN_AT, true ) );
	}

	public function test_matching_include_rules_select_one_winner_per_destination(): void {
		$destination = $this->enabledDestination();
		$slow_rule   = $this->createEnabledRule( $this->includeRuleInput( $destination['channel_public_id'], 20, 'Slow {{ title }}' ) );
		$fast_rule   = $this->createEnabledRule( $this->includeRuleInput( $destination['channel_public_id'], 10, 'Fast {{ title }}' ) );

		$result  = $this->planPost( $this->publishedPost(), array( $slow_rule, $fast_rule ) );
		$results = array_column( $result->decisions, 'result' );
		$reasons = array_merge( $result->decisions[0]['reasons'], $result->decisions[1]['reasons'] );
		$codes   = array_column( $reasons, 'code' );

		sort( $results, SORT_STRING );

		self::assertSame( array( 'included', 'skipped' ), $results );
		self::assertContains( 'superseded_by_rule', $codes );
		self::assertSame( 'Fast Test Post', $result->deliveries[0]['payload_preview']['message'] );
		self::assertSame( 1, $this->rowCount( Schema::DELIVERIES ) );
	}

	public function test_disabled_channel_is_skipped_and_disabled_connection_is_policy_error(): void {
		$connections = new ConnectionRepository();
		$channels    = new ChannelRepository();

		$enabled_connection = $connections->create( 'buffer', 'Enabled account', array(), true );
		$disabled_channel   = $channels->create( $enabled_connection->public_id, 'buffer', 'disabled-channel', 'mastodon', array( 'name' => 'Disabled' ), $this->channelCapabilities(), false, '2026-08-24 12:00:00', 'org_1', 'available' );
		$disabled_rule      = $this->createEnabledRule( $this->includeRuleInput( $disabled_channel->public_id ) );

		$disabled_result = $this->planPost( $this->publishedPost(), array( $disabled_rule ) );
		self::assertSame( 'skipped', $disabled_result->decisions[0]['result'] );
		self::assertSame( 'disabled_channel', $disabled_result->decisions[0]['reasons'][ count( $disabled_result->decisions[0]['reasons'] ) - 1 ]['code'] );

		$disabled_connection = $connections->create( 'buffer', 'Disabled account', array(), false );
		$enabled_channel     = $channels->create( $disabled_connection->public_id, 'buffer', 'enabled-channel', 'mastodon', array( 'name' => 'Enabled' ), $this->channelCapabilities(), true, '2026-08-24 12:00:00', 'org_1', 'available' );
		$connection_rule     = $this->createEnabledRule( $this->includeRuleInput( $enabled_channel->public_id ) );

		$connection_result = $this->planPost( $this->publishedPost(), array( $connection_rule ) );
		self::assertSame( 'policy_error', $connection_result->decisions[0]['result'] );
		self::assertSame( 'disabled_connection', $connection_result->decisions[0]['reasons'][ count( $connection_result->decisions[0]['reasons'] ) - 1 ]['code'] );
	}

	public function test_unsupported_channel_capability_is_explainable_policy_error(): void {
		$destination = $this->enabledDestination();
		$channels    = new ChannelRepository();
		$channel     = $channels->create(
			$destination['connection_public_id'],
			'buffer',
			'unsupported-channel',
			'mastodon',
			array( 'name' => 'Unsupported' ),
			array(
				'delivery'       => array(
					'schedule_slots' => array(),
					'text'           => false,
				),
				'schema_version' => 1,
				'service'        => 'mastodon',
			),
			true,
			'2026-08-24 12:00:00',
			'org_1',
			'available'
		);
		$rule        = $this->createEnabledRule( $this->includeRuleInput( $channel->public_id ) );

		$result = $this->planPost( $this->publishedPost(), array( $rule ) );
		$reason = $result->decisions[0]['reasons'][ count( $result->decisions[0]['reasons'] ) - 1 ];
		$record = $result->decisions[0]['stored_record'];

		self::assertSame( 'policy_error', $result->decisions[0]['result'] );
		self::assertSame( 'unsupported_channel_capability', $reason['code'] );
		self::assertSame( 'available', $record->snapshot['destination']['channel_availability'] );
		self::assertFalse( $record->snapshot['destination']['channel_capability_decision']['compatible'] );
		self::assertSame( 0, $record->snapshot['destination']['channel_capability_decision']['text_limit'] );
		self::assertSame( 0, $this->rowCount( Schema::DELIVERIES ) );
	}

	public function test_invalid_taxonomy_configuration_is_policy_error(): void {
		$destination = $this->enabledDestination();
		$rule        = $this->createEnabledRule(
			array_merge(
				$this->includeRuleInput( $destination['channel_public_id'] ),
				array(
					'condition_tree' => $this->taxonomyCondition( 'pressqueue_missing_taxonomy', array( 'missing-term' ) ),
				)
			)
		);

		$result = $this->planPost( $this->publishedPost(), array( $rule ) );

		self::assertSame( 'policy_error', $result->decisions[0]['result'] );
		self::assertSame( 'invalid_taxonomy_configuration', $result->decisions[0]['reasons'][0]['code'] );
		self::assertSame( array(), $result->deliveries );
		self::assertSame( 0, $this->rowCount( Schema::DELIVERIES ) );
	}

	public function test_different_sources_with_same_facts_share_revision_fingerprint(): void {
		$post_id    = $this->publishedPost();
		$normalizer = new EventNormalizer();
		$editor     = $normalizer->from_post_id( $post_id, 'preview', 'editor', 'first_publish', array() );
		$rest       = $normalizer->from_post_id( $post_id, 'preview', 'rest', 'first_publish', array() );

		self::assertNotNull( $editor );
		self::assertNotNull( $rest );
		self::assertSame( $editor->revision_fingerprint, $rest->revision_fingerprint );
	}

	public function test_irrelevant_publish_update_is_ignored_after_fingerprint_recorded(): void {
		$destination = $this->enabledDestination();
		$rule        = $this->createEnabledRule( $this->includeRuleInput( $destination['channel_public_id'] ) );
		$post_id     = $this->publishedPost();

		EventCollector::capture_status_transition( 'publish', 'draft', get_post( $post_id ) );
		EventCollector::finalize();

		self::assertSame( 1, $this->rowCount( Schema::DECISIONS ) );
		self::assertSame( 1, $this->rowCount( Schema::DELIVERIES ) );

		$this->touchOnlyModifiedTime( $post_id );
		EventCollector::capture_save( $post_id, get_post( $post_id ), true );
		EventCollector::finalize();

		self::assertSame( 1, $this->rowCount( Schema::DECISIONS ) );
		self::assertSame( 1, $this->rowCount( Schema::DELIVERIES ) );
	}

	public function test_upgraded_control_only_update_initializes_split_fingerprints_without_planning(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		Capabilities::grant_to_administrators( false );

		$destination = $this->enabledDestination();
		$rule        = $this->createEnabledRule( $this->includeRuleInput( $destination['channel_public_id'] ) );
		$post_id     = $this->publishedPost();
		$normalizer  = new EventNormalizer();
		$before      = $normalizer->from_post_id( $post_id, 'runtime', 'integration', 'update', $normalizer->metadata_allowlist_for_rules( array( $rule ) ) );

		self::assertNotNull( $before );

		update_post_meta( $post_id, EventNormalizer::META_LAST_RUNTIME_FINGERPRINT, $before->legacy_runtime_fingerprint );
		update_post_meta(
			$post_id,
			PostControlMetadata::META_KEY,
			PostControlMetadata::encoded(
				array(
					'channels'       => array(
						'channel_public_ids' => array( $destination['channel_public_id'] ),
						'mode'               => 'override',
					),
					'policy'         => 'inherit',
					'schema_version' => 1,
				)
			)
		);

		EventCollector::capture_save( $post_id, get_post( $post_id ), true );
		EventCollector::finalize();

		self::assertSame( 0, $this->rowCount( Schema::DECISIONS ) );
		self::assertSame( 0, $this->rowCount( Schema::DELIVERIES ) );
		self::assertNotSame( '', get_post_meta( $post_id, EventNormalizer::META_LAST_TRIGGER_FINGERPRINT, true ) );
		self::assertNotSame( '', get_post_meta( $post_id, EventNormalizer::META_LAST_DELIVERY_REVISION_FINGERPRINT, true ) );
	}

	public function test_channel_override_restricts_matching_rule_destinations(): void {
		$allowed  = $this->enabledDestination( 'remote-channel-allowed' );
		$channels = new ChannelRepository();
		$omitted  = $channels->create( $allowed['connection_public_id'], 'buffer', 'remote-channel-omitted', 'mastodon', array( 'name' => 'Omitted Social' ), $this->channelCapabilities(), false, '2026-08-24 12:00:00', 'org_1', 'available' );
		$omitted  = $channels->set_enabled_if_current( $omitted->public_id, true, $omitted->updated_at->format( 'Y-m-d H:i:s' ) );
		$rule     = $this->createEnabledRule( $this->includeRuleInput( $omitted->public_id ) );
		$post_id  = $this->publishedPost();

		update_post_meta(
			$post_id,
			PostControlMetadata::META_KEY,
			PostControlMetadata::encoded(
				array(
					'channels'       => array(
						'channel_public_ids' => array( $allowed['channel_public_id'] ),
						'mode'               => 'override',
					),
					'policy'         => 'inherit',
					'schema_version' => 1,
				)
			)
		);

		$result = $this->planPost( $post_id, array( $rule ) );
		$codes  = array_merge( $result->decisions[0]['reasons'], $result->decisions[1]['reasons'] );
		$codes  = array_column( $codes, 'code' );

		self::assertSame( array(), $result->deliveries );
		self::assertSame( array( 'skipped', 'skipped' ), array_column( $result->decisions, 'result' ) );
		self::assertContains( 'omitted_by_channel_override', $codes );
		self::assertContains( 'requested_channel_not_selected_by_rules', $codes );
	}

	public function test_preview_confirmation_rejects_stale_rule_version(): void {
		$destination = $this->enabledDestination();
		$repository  = new RuleRepository();
		$loaded      = $repository->create( $this->includeRuleInput( $destination['channel_public_id'] ) );

		$repository->update( $loaded->public_id, $loaded->lock_version, array( 'priority' => 25 ) );

		$this->expectException( StaleRecordException::class );

		$repository->mark_version_previewed( $loaded->public_id, $loaded->lock_version, $loaded->version );
	}

	/**
	 * Run planning for a post and specific rules.
	 *
	 * @param int                                              $post_id Post ID.
	 * @param array<int,\PressQueue\Storage\Record\RuleRecord> $rules Rules.
	 * @return \PressQueue\Planning\PlanningResult
	 */
	private function planPost( int $post_id, array $rules ) {
		$normalizer = new EventNormalizer();
		$event      = $normalizer->from_post_id( $post_id, 'runtime', 'integration', 'first_publish', $normalizer->metadata_allowlist_for_rules( $rules ) );

		self::assertNotNull( $event );

		return ( new Planner() )->plan_runtime( $event );
	}

	/**
	 * Create a rule and enable the exact previewed version.
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
	 * Create an enabled local destination.
	 *
	 * @return array{connection_public_id:string,channel_public_id:string}
	 */
	private function enabledDestination( string $remote_channel_id = 'remote-channel-1' ): array {
		$connections = new ConnectionRepository();
		$channels    = new ChannelRepository();
		$envelope    = CanonicalJson::encode_object( array( 'fixture' => 'encrypted' ) );
		$account     = $this->accountDocument();
		$connection  = $connections->create_with_credential( 'buffer', 'Local account', $envelope, hash( 'sha256', $envelope ), array( 'profile' => 'Example' ) );
		$connection  = $connections->store_verified_account( $connection->public_id, 'acct_1', $account );
		$connection  = $connections->select_organization_unverified( $connection->public_id, 'org_1' );
		$lease       = $connections->acquire_sync_lease( $connection->public_id );

		self::assertNotNull( $lease );

		$connection = $connections->complete_sync_success( $connection->public_id, $lease, 'acct_1', 'org_1', $account );
		$connection = $connections->enable( $connection->public_id );
		$channel    = $channels->create( $connection->public_id, 'buffer', $remote_channel_id, 'mastodon', array( 'name' => 'Example Social' ), $this->channelCapabilities(), false, '2026-08-24 12:00:00', 'org_1', 'available' );
		$channel    = $channels->set_enabled_if_current( $channel->public_id, true, $channel->updated_at->format( 'Y-m-d H:i:s' ) );

		return array(
			'channel_public_id'    => $channel->public_id,
			'connection_public_id' => $connection->public_id,
		);
	}

	/**
	 * Return a verified account document.
	 *
	 * @return array<string,mixed>
	 */
	private function accountDocument(): array {
		return array(
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
	}

	/**
	 * Create a published post.
	 *
	 * @param string $title Title.
	 * @return int
	 */
	private function publishedPost( string $title = 'Test Post' ): int {
		return (int) self::factory()->post->create(
			array(
				'post_content' => 'Body text',
				'post_status'  => 'publish',
				'post_title'   => $title,
			)
		);
	}

	/**
	 * Return include rule input.
	 *
	 * @param string $channel_public_id Channel public ID.
	 * @param int    $priority Priority.
	 * @param string $message Message.
	 * @return array<string,mixed>
	 */
	private function includeRuleInput( string $channel_public_id, int $priority = 100, string $message = '{{ title }} {{ canonical_url }}' ): array {
		return array(
			'condition_tree'         => $this->postTypeCondition(),
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
				'message'                => $message,
				'schema_version'         => 1,
			),
			'name'                   => 'Include posts',
			'priority'               => $priority,
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
	 * Return metadata exists condition tree.
	 *
	 * @param string $key Metadata key.
	 * @return array<string,mixed>
	 */
	private function metadataExistsCondition( string $key ): array {
		return array(
			'children'       => array(
				array(
					'condition' => 'metadata',
					'operator'  => 'exists',
					'type'      => 'condition',
					'value'     => array(
						'key' => $key,
					),
				),
			),
			'operator'       => 'ALL',
			'schema_version' => 1,
			'type'           => 'group',
		);
	}

	/**
	 * Return taxonomy condition tree.
	 *
	 * @param string           $taxonomy Taxonomy.
	 * @param array<int,mixed> $terms Terms.
	 * @return array<string,mixed>
	 */
	private function taxonomyCondition( string $taxonomy, array $terms ): array {
		return array(
			'children'       => array(
				array(
					'condition' => 'taxonomy',
					'operator'  => 'has_any',
					'type'      => 'condition',
					'value'     => array(
						'include_ancestors' => false,
						'taxonomy'          => $taxonomy,
						'terms'             => $terms,
					),
				),
			),
			'operator'       => 'ALL',
			'schema_version' => 1,
			'type'           => 'group',
		);
	}

	/**
	 * Return exclusion rule input.
	 *
	 * @param int $guides_term_id Guides term ID.
	 * @return array<string,mixed>
	 */
	private function excludeRuleInput( int $guides_term_id ): array {
		return array(
			'condition_tree'         => array(
				'children'       => array(
					array(
						'condition' => 'taxonomy',
						'operator'  => 'has_any',
						'type'      => 'condition',
						'value'     => array(
							'include_ancestors' => true,
							'taxonomy'          => 'category',
							'terms'             => array( $guides_term_id ),
						),
					),
					array(
						'condition' => 'metadata',
						'operator'  => 'equals',
						'type'      => 'condition',
						'value'     => array(
							'key'   => '_pressqueue_fixture_lane',
							'value' => 'evergreen',
						),
					),
				),
				'operator'       => 'ANY',
				'schema_version' => 1,
				'type'           => 'group',
			),
			'custom_field_allowlist' => array( '_pressqueue_fixture_lane' ),
			'destination_set'        => array(
				'schema_version' => 1,
				'scope'          => 'all',
			),
			'effect'                 => 'exclude',
			'inline_template'        => array(
				'custom_field_allowlist' => array( '_pressqueue_fixture_lane' ),
				'message'                => '{{ title }}',
				'schema_version'         => 1,
			),
			'name'                   => 'Exclude guides and evergreen',
			'priority'               => 5,
			'schedule_policy'        => 'immediate',
			'triggers'               => array( 'first_publish' ),
		);
	}

	/**
	 * Return a post-type exclusion rule input.
	 *
	 * @param string $post_type Post type.
	 * @param int    $priority Priority.
	 * @param string $name Rule name.
	 * @return array<string,mixed>
	 */
	private function excludePostTypeRuleInput( string $post_type, int $priority, string $name ): array {
		return array(
			'condition_tree'         => $this->postTypeCondition( $post_type ),
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
			'name'                   => $name,
			'priority'               => $priority,
			'schedule_policy'        => 'immediate',
			'triggers'               => array( 'first_publish' ),
		);
	}

	/**
	 * Return post type condition tree.
	 *
	 * @param string $post_type Post type.
	 * @return array<string,mixed>
	 */
	private function postTypeCondition( string $post_type = 'post' ): array {
		return array(
			'children'       => array(
				array(
					'condition' => 'post_type',
					'operator'  => 'equals',
					'type'      => 'condition',
					'value'     => $post_type,
				),
			),
			'operator'       => 'ALL',
			'schema_version' => 1,
			'type'           => 'group',
		);
	}

	/**
	 * Count rows in a PressQueue table.
	 *
	 * @param string $suffix Table suffix.
	 * @return int
	 */
	private function rowCount( string $suffix ): int {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . Database::from_globals()->table( $suffix ) . '`' );
	}

	/**
	 * Corrupt one stored rule JSON document.
	 *
	 * @param string $public_id Rule public ID.
	 * @param string $column Column name.
	 * @param string $value Stored value.
	 */
	private function corruptRuleDocument( string $public_id, string $column, string $value ): void {
		global $wpdb;

		$wpdb->update(
			Database::from_globals()->table( Schema::RULES ),
			array( $column => $value ),
			array( 'public_id' => $public_id )
		);
	}

	/**
	 * Change only WordPress modified timestamps.
	 *
	 * @param int $post_id Post ID.
	 */
	private function touchOnlyModifiedTime( int $post_id ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => '2026-08-24 13:00:00',
				'post_modified_gmt' => '2026-08-24 13:00:00',
			),
			array( 'ID' => $post_id )
		);
		clean_post_cache( $post_id );
	}

	/**
	 * Reset custom storage between tests.
	 */
	private function resetStorage(): void {
		global $wpdb;

		$database = Database::from_globals();

		foreach ( array_reverse( Schema::table_suffixes() ) as $suffix ) {
			$table = $database->table( $suffix );
			$wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' );
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
