<?php
/**
 * Delivery integration tests.
 *
 * @package PressQueue\Tests\Integration
 */

declare(strict_types=1);

namespace PressQueue\Tests\Integration;

use PressQueue\Delivery\DeliveryWorker;
use PressQueue\Delivery\QueueSweep;
use PressQueue\Provider\Capability\DeliveryCapability;
use PressQueue\Provider\DeliveryProvider;
use PressQueue\Provider\Dto\ProviderDeliveryRequest;
use PressQueue\Provider\Dto\ProviderDeliveryResult;
use PressQueue\Provider\Dto\ProviderReceipt;
use PressQueue\Provider\ProviderRegistry;
use PressQueue\Runtime\RuntimeDisabledLatch;
use PressQueue\Scheduler\ActionSchedulerQueue;
use PressQueue\Security\Capabilities;
use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\Database;
use PressQueue\Storage\Record\ChannelRecord;
use PressQueue\Storage\Record\ConnectionRecord;
use PressQueue\Storage\Repository\ChannelRepository;
use PressQueue\Storage\Repository\ConnectionRepository;
use PressQueue\Storage\Repository\DecisionRepository;
use PressQueue\Storage\Repository\DeliveryEventRepository;
use PressQueue\Storage\Repository\DeliveryRepository;
use PressQueue\Storage\Repository\RuleRepository;
use PressQueue\Storage\Schema;
use PressQueue\Storage\SchemaMigrator;
use PressQueue\Storage\SettingsRepository;
use PressQueue\Tests\Fixtures\Provider\FixtureDeliveryProvider;
use WP_UnitTestCase;

/**
 * @covers \PressQueue\Delivery\DeliveryWorker
 * @covers \PressQueue\Delivery\LeaseService
 * @covers \PressQueue\Delivery\QueueSweep
 * @covers \PressQueue\Delivery\RetryPolicy
 * @covers \PressQueue\Provider\DeliveryProvider
 * @covers \PressQueue\Provider\Dto\ProviderDeliveryRequest
 * @covers \PressQueue\Provider\Dto\ProviderDeliveryResult
 * @covers \PressQueue\Provider\Dto\ProviderReceipt
 * @covers \PressQueue\Scheduler\ActionSchedulerQueue
 * @covers \PressQueue\Storage\Repository\DeliveryEventRepository
 * @covers \PressQueue\Storage\Repository\DeliveryTransitionRepository
 */
final class DeliveryIntegrationTest extends WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();

		$this->resetStorage();
		( new SchemaMigrator( Database::from_globals() ) )->migrate();
		RuntimeDisabledLatch::open();
	}

	protected function tearDown(): void {
		$this->resetStorage();

		parent::tearDown();
	}

	public function test_sweep_and_worker_accept_delivery_once_with_event_history(): void {
		$delivery  = $this->liveDelivery();
		$scheduler = $this->fakeScheduler();
		$provider  = $this->acceptedProvider();
		$registry  = new ProviderRegistry( array(), array( 'buffer' => $provider ) );

		$sweep = new QueueSweep( Database::from_globals(), $scheduler, $registry );
		self::assertSame( 1, $sweep->run() );

		$queued = ( new DeliveryRepository() )->find_by_public_id( $delivery->public_id );
		self::assertNotNull( $queued );
		self::assertSame( 'queued', $queued->state );
		self::assertSame( 500, $queued->action_id );

		$worker = new DeliveryWorker(
			Database::from_globals(),
			$registry,
			$scheduler,
			static function (): string {
				return 'fixture credential';
			}
		);

		$worker->run( $delivery->public_id );
		$worker->run( $delivery->public_id );

		$accepted = ( new DeliveryRepository() )->find_by_public_id( $delivery->public_id );
		self::assertNotNull( $accepted );
		self::assertSame( 'accepted', $accepted->state );
		self::assertSame( 1, $accepted->attempt_count );
		self::assertSame( 1, $accepted->automatic_attempt_count );
		self::assertSame( 'remote-post-1', $accepted->remote_id );
		self::assertNotNull( $accepted->remote_receipt_key );
		self::assertSame( 'remote-post-1', $accepted->receipt['remote_id'] ?? null );
		self::assertCount( 1, $provider->requests );
		self::assertSame( "Hello from integration\n\nhttps://example.test/post", $provider->requests[0]->final_encoded_text );

		$events = ( new DeliveryEventRepository() )->find_by_delivery_public_id( $delivery->public_id );
		self::assertSame(
			array(
				'destination_bound',
				'queued',
				'scheduler_action_scheduled',
				'leased',
				'submission_started',
				'provider_result',
			),
			array_column( $events, 'event_type' )
		);
		self::assertStringNotContainsString( 'fixture credential', CanonicalJson::encode( array_map( static fn ( $event ) => $event->detail, $events ) ) );
	}

	public function test_kill_switch_holds_queued_delivery_without_attempt_or_provider_request(): void {
		$delivery  = $this->liveDelivery();
		$scheduler = $this->fakeScheduler();
		$provider  = $this->acceptedProvider();
		$registry  = new ProviderRegistry( array(), array( 'buffer' => $provider ) );

		( new QueueSweep( Database::from_globals(), $scheduler, $registry ) )->run();
		( new SettingsRepository() )->set_kill_switch_enabled( true );

		$worker = new DeliveryWorker(
			Database::from_globals(),
			$registry,
			$scheduler,
			static function (): string {
				return 'fixture credential';
			}
		);
		$worker->run( $delivery->public_id );

		$held = ( new DeliveryRepository() )->find_by_public_id( $delivery->public_id );
		self::assertNotNull( $held );
		self::assertSame( 'held', $held->state );
		self::assertSame( 0, $held->attempt_count );
		self::assertCount( 0, $provider->requests );

		$events = ( new DeliveryEventRepository() )->find_by_delivery_public_id( $delivery->public_id );
		self::assertContains( 'held', array_column( $events, 'event_type' ) );
	}

	public function test_due_retry_worker_action_submits_without_waiting_for_sweep(): void {
		$delivery  = $this->liveDelivery();
		$scheduler = $this->fakeScheduler();
		$provider  = $this->retryThenAcceptedProvider();
		$registry  = new ProviderRegistry( array(), array( 'buffer' => $provider ) );

		( new QueueSweep( Database::from_globals(), $scheduler, $registry ) )->run();

		$worker = new DeliveryWorker(
			Database::from_globals(),
			$registry,
			$scheduler,
			static function (): string {
				return 'fixture credential';
			}
		);
		$worker->run( $delivery->public_id );

		$retrying = ( new DeliveryRepository() )->find_by_public_id( $delivery->public_id );
		self::assertNotNull( $retrying );
		self::assertSame( 'retrying', $retrying->state );
		self::assertSame( 1, $retrying->attempt_count );

		$database = Database::from_globals();
		$database->update_rows(
			$database->table( Schema::DELIVERIES ),
			array( 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ),
			array( 'public_id' => $delivery->public_id ),
			array(
				'next_attempt_at' => '%s',
				'public_id'       => '%s',
			)
		);

		$worker->run( $delivery->public_id );

		$accepted = ( new DeliveryRepository() )->find_by_public_id( $delivery->public_id );
		self::assertNotNull( $accepted );
		self::assertSame( 'accepted', $accepted->state );
		self::assertSame( 2, $accepted->attempt_count );
		self::assertCount( 2, $provider->requests );
	}

	public function test_sweep_repairs_queued_delivery_with_stale_action_id(): void {
		$delivery  = $this->liveDelivery();
		$scheduler = $this->fakeScheduler();
		$registry  = new ProviderRegistry( array(), array( 'buffer' => $this->acceptedProvider() ) );
		$sweep     = new QueueSweep( Database::from_globals(), $scheduler, $registry );

		$sweep->run();

		$queued = ( new DeliveryRepository() )->find_by_public_id( $delivery->public_id );
		self::assertNotNull( $queued );
		self::assertSame( 500, $queued->action_id );

		$scheduler->scheduled = array();
		$sweep->run();

		$repaired = ( new DeliveryRepository() )->find_by_public_id( $delivery->public_id );
		self::assertNotNull( $repaired );
		self::assertSame( 'queued', $repaired->state );
		self::assertSame( 501, $repaired->action_id );

		$events = ( new DeliveryEventRepository() )->find_by_delivery_public_id( $delivery->public_id );
		self::assertContains( 'scheduler_race_repaired', array_column( $events, 'event_type' ) );
		self::assertContains( 'scheduler_action_scheduled', array_column( $events, 'event_type' ) );
	}

	public function test_fixture_provider_worker_path_accepts_delivery_without_live_http(): void {
		$delivery  = $this->fixtureDelivery();
		$scheduler = $this->fakeScheduler();
		$provider  = new FixtureDeliveryProvider();
		$registry  = new ProviderRegistry( array(), array( 'fixture' => $provider ) );

		self::assertSame( 1, ( new QueueSweep( Database::from_globals(), $scheduler, $registry ) )->run() );
		( new DeliveryWorker(
			Database::from_globals(),
			$registry,
			$scheduler,
			static function (): string {
				return 'fixture';
			}
		) )->run( $delivery->public_id );

		$accepted = ( new DeliveryRepository() )->find_by_public_id( $delivery->public_id );
		self::assertNotNull( $accepted );
		self::assertSame( 'accepted', $accepted->state );
		self::assertSame( 'remote_fixture_post', $accepted->remote_id );
		self::assertCount( 1, $provider->requests );
		self::assertSame( 'fixture', $provider->requests[0]->provider );
	}

	public function test_fixture_provider_worker_path_stops_before_provider_for_disabled_destination(): void {
		$delivery = $this->fixtureDelivery();
		( new ConnectionRepository() )->disable( $delivery->connection_public_id );
		$scheduler = $this->fakeScheduler();
		$provider  = new FixtureDeliveryProvider();
		$registry  = new ProviderRegistry( array(), array( 'fixture' => $provider ) );

		self::assertSame( 1, ( new QueueSweep( Database::from_globals(), $scheduler, $registry ) )->run() );

		$failed = ( new DeliveryRepository() )->find_by_public_id( $delivery->public_id );
		self::assertNotNull( $failed );
		self::assertSame( 'failed', $failed->state );
		self::assertSame( 'destination_binding_failed', $failed->error_class );
		self::assertSame( array(), $scheduler->scheduled );
		self::assertCount( 0, $provider->requests );
	}

	/**
	 * Create a live proposed delivery fixture.
	 *
	 * @return \PressQueue\Storage\Record\DeliveryRecord
	 */
	private function liveDelivery() {
		$destination = $this->enabledDestination();
		$rule        = ( new RuleRepository() )->create( $this->ruleInput( $destination['channel']->public_id ) );
		$decision    = ( new DecisionRepository() )->create( $this->decisionInput( $rule->id, $rule->public_id, $rule->version, $destination['channel']->public_id ) );
		$final_text  = "Hello from integration\n\nhttps://example.test/post";

		return ( new DeliveryRepository() )->create(
			array(
				'channel_public_id'    => $destination['channel']->public_id,
				'connection_public_id' => $destination['connection']->public_id,
				'decision_public_id'   => $decision->public_id,
				'delivery_mode'        => 'live',
				'payload_preview'      => array(
					'adapter_encoding_version'  => 2,
					'bound_capability_hash'     => DeliveryCapability::hash( $destination['channel']->capabilities ),
					'capability_schema_version' => 2,
					'canonical_url'             => 'https://example.test/post',
					'final_encoded_text'        => $final_text,
					'final_encoded_text_length' => strlen( $final_text ),
					'media_snapshot'            => array(
						'items'          => array(),
						'mode'           => 'none',
						'schema_version' => 1,
					),
					'message'                   => 'Hello from integration',
					'payload_schema_version'    => 2,
					'schedule_slot'             => 'immediate',
				),
				'provider'             => 'buffer',
				'remote_channel_id'    => $destination['channel']->remote_channel_id,
				'state'                => 'proposed',
			)
		);
	}

	/**
	 * Create a fixture-provider delivery.
	 *
	 * @return \PressQueue\Storage\Record\DeliveryRecord
	 */
	private function fixtureDelivery() {
		$destination = $this->fixtureDestination();
		$rule        = ( new RuleRepository() )->create( $this->ruleInput( $destination['channel']->public_id ) );
		$decision    = ( new DecisionRepository() )->create( $this->decisionInput( $rule->id, $rule->public_id, $rule->version, $destination['channel']->public_id ) );
		$final_text  = "Hello from fixture\n\nhttps://example.test/post";

		return ( new DeliveryRepository() )->create(
			array(
				'channel_public_id'    => $destination['channel']->public_id,
				'connection_public_id' => $destination['connection']->public_id,
				'decision_public_id'   => $decision->public_id,
				'delivery_mode'        => 'live',
				'payload_preview'      => array(
					'adapter_encoding_version'  => 2,
					'bound_capability_hash'     => DeliveryCapability::hash( $destination['channel']->capabilities ),
					'capability_schema_version' => 2,
					'canonical_url'             => 'https://example.test/post',
					'final_encoded_text'        => $final_text,
					'final_encoded_text_length' => strlen( $final_text ),
					'media_snapshot'            => array(
						'items'          => array(),
						'mode'           => 'none',
						'schema_version' => 1,
					),
					'message'                   => 'Hello from fixture',
					'payload_schema_version'    => 2,
					'schedule_slot'             => 'immediate',
				),
				'provider'             => 'fixture',
				'remote_channel_id'    => $destination['channel']->remote_channel_id,
				'state'                => 'proposed',
			)
		);
	}

	/**
	 * Create an enabled destination.
	 *
	 * @return array{connection:ConnectionRecord,channel:ChannelRecord}
	 */
	private function enabledDestination(): array {
		$connections = new ConnectionRepository();
		$channels    = new ChannelRepository();
		$envelope    = CanonicalJson::encode_object( array( 'fixture' => 'encrypted' ) );
		$account     = $this->accountDocument();
		$connection  = $connections->create_with_credential( 'buffer', 'Local account', $envelope, hash( 'sha256', $envelope ) );
		$connection  = $connections->store_verified_account( $connection->public_id, 'acct_1', $account );
		$connection  = $connections->select_organization_unverified( $connection->public_id, 'org_1' );
		$lease       = $connections->acquire_sync_lease( $connection->public_id );

		self::assertNotNull( $lease );

		$connection = $connections->complete_sync_success( $connection->public_id, $lease, 'acct_1', 'org_1', $account );
		$connection = $connections->enable( $connection->public_id );
		$channel    = $channels->create( $connection->public_id, 'buffer', 'remote-channel-1', 'mastodon', array( 'name' => 'Example Social' ), $this->channelCapabilities(), false, '2026-08-24 12:00:00', 'org_1', 'available' );
		$channel    = $channels->set_enabled_if_current( $channel->public_id, true, $channel->updated_at->format( 'Y-m-d H:i:s' ) );

		return array(
			'channel'    => $channel,
			'connection' => $connection,
		);
	}

	/**
	 * Create an enabled fixture-provider destination.
	 *
	 * @return array{connection:ConnectionRecord,channel:ChannelRecord}
	 */
	private function fixtureDestination(): array {
		$connections = new ConnectionRepository();
		$channels    = new ChannelRepository();
		$envelope    = CanonicalJson::encode_object( array( 'fixture' => 'encrypted' ) );
		$account     = $this->providerAccountDocument( 'fixture', 'acct_fixture', 'org_fixture' );
		$connection  = $connections->create_with_credential( 'fixture', 'Fixture account', $envelope, hash( 'sha256', $envelope ) );
		$connection  = $connections->store_verified_account( $connection->public_id, 'acct_fixture', $account );
		$connection  = $connections->select_organization_unverified( $connection->public_id, 'org_fixture' );
		$lease       = $connections->acquire_sync_lease( $connection->public_id );

		self::assertNotNull( $lease );

		$connection = $connections->complete_sync_success( $connection->public_id, $lease, 'acct_fixture', 'org_fixture', $account );
		$connection = $connections->enable( $connection->public_id );
		$channel    = $channels->create( $connection->public_id, 'fixture', 'chan_fixture', 'mastodon', array( 'name' => 'Fixture Social' ), $this->channelCapabilities(), false, '2026-08-24 12:00:00', 'org_fixture', 'available' );
		$channel    = $channels->set_enabled_if_current( $channel->public_id, true, $channel->updated_at->format( 'Y-m-d H:i:s' ) );

		return array(
			'channel'    => $channel,
			'connection' => $connection,
		);
	}

	/**
	 * Return a fake scheduler.
	 *
	 * @return ActionSchedulerQueue
	 */
	private function fakeScheduler(): ActionSchedulerQueue {
		return new class() extends ActionSchedulerQueue {
			/** @var array<int,array{public_id:string,timestamp:int|null}> */
			public array $scheduled     = array();
			private int $next_action_id = 500;

			public function is_ready(): bool {
				return true;
			}

			public function schedule_delivery( string $delivery_public_id, ?int $timestamp = null ): int {
				$this->scheduled[] = array(
					'public_id' => $delivery_public_id,
					'timestamp' => $timestamp,
				);

				return $this->next_action_id++;
			}

			public function has_delivery_action( string $delivery_public_id ): bool {
				foreach ( $this->scheduled as $action ) {
					if ( $action['public_id'] === $delivery_public_id ) {
						return true;
					}
				}

				return false;
			}

			public function unschedule_delivery( string $delivery_public_id ): void {
				unset( $delivery_public_id );
			}

			public function is_action_claimed( ?int $action_id ): bool {
				unset( $action_id );

				return false;
			}
		};
	}

	/**
	 * Return a fake accepting provider.
	 *
	 * @return DeliveryProvider&object{requests:array<int,ProviderDeliveryRequest>}
	 */
	private function acceptedProvider(): DeliveryProvider {
		return new class() implements DeliveryProvider {
			/** @var array<int,ProviderDeliveryRequest> */
			public array $requests = array();

			public function submit( ProviderDeliveryRequest $request, string $credential ): ProviderDeliveryResult {
				unset( $credential );

				$this->requests[] = $request;

				return ProviderDeliveryResult::accepted(
					$request,
					'remote-post-1',
					new ProviderReceipt(
						array(
							'provider'           => 'buffer',
							'remote_id'          => 'remote-post-1',
							'returned_text_hash' => hash( 'sha256', $request->message ),
							'schema_version'     => 1,
						)
					)
				);
			}
		};
	}

	/**
	 * Return a fake provider that retries once, then accepts.
	 *
	 * @return DeliveryProvider&object{requests:array<int,ProviderDeliveryRequest>}
	 */
	private function retryThenAcceptedProvider(): DeliveryProvider {
		return new class() implements DeliveryProvider {
			/** @var array<int,ProviderDeliveryRequest> */
			public array $requests = array();

			public function submit( ProviderDeliveryRequest $request, string $credential ): ProviderDeliveryResult {
				unset( $credential );

				$this->requests[] = $request;

				if ( 1 === count( $this->requests ) ) {
					return ProviderDeliveryResult::failure(
						$request,
						'retryable_failure',
						'automatic_allowed',
						'unexpected_provider_failure',
						array(
							'classification' => 'unexpected_provider_failure',
							'schema_version' => 1,
						),
						null,
						true,
						false
					);
				}

				return ProviderDeliveryResult::accepted(
					$request,
					'remote-post-2',
					new ProviderReceipt(
						array(
							'provider'           => 'buffer',
							'remote_id'          => 'remote-post-2',
							'returned_text_hash' => hash( 'sha256', $request->message ),
							'schema_version'     => 1,
						)
					)
				);
			}
		};
	}

	/**
	 * Return a valid include rule input.
	 *
	 * @param string $channel_public_id Channel public ID.
	 * @return array<string,mixed>
	 */
	private function ruleInput( string $channel_public_id ): array {
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
			'name'                   => 'Publish posts',
			'priority'               => 100,
			'schedule_policy'        => 'immediate',
			'triggers'               => array( 'first_publish' ),
		);
	}

	/**
	 * Return an included decision input.
	 *
	 * @param int    $rule_id Rule ID.
	 * @param string $rule_public_id Rule public ID.
	 * @param int    $rule_version Rule version.
	 * @param string $channel_public_id Channel public ID.
	 * @return array<string,mixed>
	 */
	private function decisionInput( int $rule_id, string $rule_public_id, int $rule_version, string $channel_public_id ): array {
		return array(
			'author_id'            => 7,
			'event_key'            => str_repeat( 'a', 64 ),
			'event_mode'           => 'runtime',
			'post_id'              => 42,
			'post_status'          => 'publish',
			'post_type'            => 'post',
			'reasons'              => array(
				array(
					'code'             => 'matched_rule',
					'condition_result' => 'matched',
				),
			),
			'result'               => 'included',
			'revision_fingerprint' => str_repeat( 'b', 64 ),
			'rule_id'              => $rule_id,
			'rule_public_id'       => $rule_public_id,
			'rule_version'         => $rule_version,
			'scope_public_id'      => $channel_public_id,
			'scope_type'           => 'channel',
			'snapshot'             => array(
				'post'     => array( 'id' => 42 ),
				'settings' => array( 'dry_run' => false ),
			),
			'trigger'              => 'first_publish',
		);
	}

	/**
	 * Return a verified account document.
	 *
	 * @return array<string,mixed>
	 */
	private function accountDocument(): array {
		return $this->providerAccountDocument( 'buffer', 'acct_1', 'org_1' );
	}

	/**
	 * Return a verified account document.
	 *
	 * @param string $provider Provider.
	 * @param string $account_id Account ID.
	 * @param string $organization_id Organization ID.
	 * @return array<string,mixed>
	 */
	private function providerAccountDocument( string $provider, string $account_id, string $organization_id ): array {
		return array(
			'display_label'     => 'Owner',
			'organizations'     => array(
				array(
					'channel_count'          => 1,
					'display_label'          => 'Editorial',
					'provider'               => $provider,
					'remote_organization_id' => $organization_id,
				),
			),
			'provider'          => $provider,
			'remote_account_id' => $account_id,
			'schema_version'    => 1,
		);
	}

	/**
	 * Return channel capabilities.
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
	}
}
