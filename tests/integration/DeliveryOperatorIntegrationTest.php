<?php
/**
 * Delivery operator integration tests.
 *
 * @package PressQueue\Tests\Integration
 */

declare(strict_types=1);

namespace PressQueue\Tests\Integration;

use PressQueue\Admin\AdminScreens;
use PressQueue\Admin\DeliveryDetailController;
use PressQueue\Delivery\DeliveryQueryRepository;
use PressQueue\Delivery\DeliveryWorker;
use PressQueue\Delivery\OperatorActionService;
use PressQueue\Delivery\ReconciliationService;
use PressQueue\Provider\Capability\DeliveryCapability;
use PressQueue\Provider\DeliveryProvider;
use PressQueue\Provider\Dto\ProviderDeliveryRequest;
use PressQueue\Provider\Dto\ProviderDeliveryResult;
use PressQueue\Provider\Dto\ProviderReceipt;
use PressQueue\Provider\ProviderRegistry;
use PressQueue\Scheduler\ActionSchedulerQueue;
use PressQueue\Security\Capabilities;
use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\Database;
use PressQueue\Storage\HashKeys;
use PressQueue\Storage\Record\ChannelRecord;
use PressQueue\Storage\Record\ConnectionRecord;
use PressQueue\Storage\Record\DeliveryRecord;
use PressQueue\Storage\Repository\ChannelRepository;
use PressQueue\Storage\Repository\ConnectionRepository;
use PressQueue\Storage\Repository\DecisionRepository;
use PressQueue\Storage\Repository\DeliveryEventRepository;
use PressQueue\Storage\Repository\DeliveryRepository;
use PressQueue\Storage\Repository\RuleRepository;
use PressQueue\Storage\Schema;
use PressQueue\Storage\SchemaMigrator;
use PressQueue\Storage\SettingsRepository;
use PressQueue\Storage\ValidationException;
use WP_UnitTestCase;

/**
 * @covers \PressQueue\Admin\AdminScreens
 * @covers \PressQueue\Admin\DeliveryDetailController
 * @covers \PressQueue\Admin\DeliveryListController
 * @covers \PressQueue\Delivery\DeliveryDisplaySnapshot
 * @covers \PressQueue\Delivery\DeliveryQueryRepository
 * @covers \PressQueue\Delivery\OperatorActionService
 * @covers \PressQueue\Delivery\ReconciliationService
 * @covers \PressQueue\Storage\Repository\DeliveryEventRepository
 * @covers \PressQueue\Storage\Repository\DeliveryTransitionRepository
 */
final class DeliveryOperatorIntegrationTest extends WP_UnitTestCase {
	/**
	 * Destination fixture sequence.
	 *
	 * @var int
	 */
	private int $destination_sequence = 0;

	protected function setUp(): void {
		parent::setUp();

		$_GET                       = array();
		$_POST                      = array();
		$this->destination_sequence = 0;
		$this->resetStorage();
		( new SchemaMigrator( Database::from_globals() ) )->migrate();
	}

	protected function tearDown(): void {
		$_GET  = array();
		$_POST = array();
		wp_set_current_user( 0 );
		update_option( 'timezone_string', '', true );
		update_option( 'gmt_offset', 0, true );
		$this->resetStorage();

		parent::tearDown();
	}

	public function test_cancel_changes_state_first_unschedules_and_appends_event(): void {
		$delivery = $this->liveDelivery();
		$this->updateDelivery(
			$delivery->public_id,
			array(
				'action_id'        => 700,
				'queued_at'        => '2026-08-24 12:00:00',
				'state'            => 'queued',
				'state_changed_at' => '2026-08-24 12:00:00',
			)
		);

		$scheduler              = $this->fakeScheduler();
		$scheduler->scheduled[] = array(
			'public_id' => $delivery->public_id,
			'timestamp' => null,
		);

		$cancelled = ( new OperatorActionService( Database::from_globals(), $scheduler, static fn (): bool => true ) )->cancel( $delivery->public_id, 123 );

		self::assertSame( 'cancelled', $cancelled->state );
		self::assertNull( $cancelled->action_id );
		self::assertSame( array( $delivery->public_id ), $scheduler->unscheduled );

		$events     = ( new DeliveryEventRepository() )->find_by_delivery_id( $delivery->id );
		$last_event = $events[ count( $events ) - 1 ];
		self::assertSame( 'cancelled', $last_event->event_type );
		self::assertSame( 123, $last_event->actor_user_id );
	}

	public function test_resume_refuses_when_kill_switch_is_enabled(): void {
		$delivery = $this->liveDelivery();
		$this->updateDelivery(
			$delivery->public_id,
			array(
				'state'            => 'held',
				'state_changed_at' => '2026-08-24 12:00:00',
			)
		);
		( new SettingsRepository() )->set_kill_switch_enabled( true );

		$this->expectException( ValidationException::class );

		try {
			( new OperatorActionService( Database::from_globals(), $this->fakeScheduler(), static fn (): bool => true ) )->resume( $delivery->public_id, 123 );
		} finally {
			$stored = ( new DeliveryRepository() )->find_by_public_id( $delivery->public_id );
			self::assertNotNull( $stored );
			self::assertSame( 'held', $stored->state );
			self::assertContains( 'refused_transition', array_column( ( new DeliveryEventRepository() )->find_by_delivery_id( $delivery->id ), 'event_type' ) );
		}
	}

	public function test_manual_retry_reuses_delivery_row_and_preserves_attempt_history(): void {
		$delivery = $this->liveDelivery();
		$this->updateDelivery(
			$delivery->public_id,
			array(
				'attempt_count'           => 5,
				'automatic_attempt_count' => 5,
				'error_class'             => 'automatic_budget_exhausted',
				'provider_result'         => 'retryable_failure',
				'retry_safety'            => 'manual_allowed',
				'state'                   => 'failed',
				'state_changed_at'        => '2026-08-24 12:00:00',
			)
		);

		$scheduler = $this->fakeScheduler();
		$retried   = ( new OperatorActionService( Database::from_globals(), $scheduler, static fn (): bool => true ) )->retry( $delivery->public_id, 123 );

		self::assertSame( $delivery->id, $retried->id );
		self::assertSame( $delivery->public_id, $retried->public_id );
		self::assertSame( $delivery->delivery_key, $retried->delivery_key );
		self::assertSame( $delivery->payload_hash, $retried->payload_hash );
		self::assertSame( 5, $retried->attempt_count );
		self::assertSame( 5, $retried->automatic_attempt_count );
		self::assertSame( 2, $retried->payload_schema_version );
		self::assertSame( 2, $retried->adapter_encoding_version );
		self::assertSame( 'queued', $retried->state );
		self::assertSame( array( $delivery->public_id ), array_column( $scheduler->scheduled, 'public_id' ) );

		$events = ( new DeliveryEventRepository() )->find_by_delivery_id( $delivery->id );
		self::assertContains( 'manual_retry', array_column( $events, 'event_type' ) );
	}

	public function test_manual_retry_refuses_retired_legacy_encoder_rows(): void {
		$delivery = $this->liveDelivery();
		$this->updateDelivery(
			$delivery->public_id,
			array(
				'adapter_encoding_version'        => 1,
				'bound_capability_schema_version' => 1,
				'error_class'                     => 'automatic_budget_exhausted',
				'payload_preview_json'            => CanonicalJson::encode_object(
					array(
						'canonical_url' => 'https://example.test/post',
						'message'       => 'Legacy payload',
					)
				),
				'payload_schema_version'          => 1,
				'provider_result'                 => 'retryable_failure',
				'retry_safety'                    => 'manual_allowed',
				'state'                           => 'failed',
				'state_changed_at'                => '2026-08-24 12:00:00',
			)
		);

		$this->expectException( ValidationException::class );

		try {
			( new OperatorActionService( Database::from_globals(), $this->fakeScheduler(), static fn (): bool => true ) )->retry( $delivery->public_id, 123 );
		} finally {
			$events = ( new DeliveryEventRepository() )->find_by_delivery_id( $delivery->id );
			self::assertSame( 'legacy_encoder_retired', $events[ count( $events ) - 1 ]->detail['classification'] ?? null );
		}
	}

	public function test_worker_counts_operator_retry_as_manual_attempt(): void {
		$delivery = $this->liveDelivery();
		$this->updateDelivery(
			$delivery->public_id,
			array(
				'attempt_count'           => 5,
				'automatic_attempt_count' => 5,
				'error_class'             => 'automatic_budget_exhausted',
				'provider_result'         => 'retryable_failure',
				'retry_safety'            => 'manual_allowed',
				'state'                   => 'failed',
				'state_changed_at'        => '2026-08-24 12:00:00',
			)
		);

		$scheduler = $this->fakeScheduler();
		$provider  = $this->acceptedProvider();
		( new OperatorActionService( Database::from_globals(), $scheduler, static fn (): bool => true ) )->retry( $delivery->public_id, 123 );

		( new DeliveryWorker(
			Database::from_globals(),
			new ProviderRegistry( array(), array( 'buffer' => $provider ) ),
			$scheduler,
			static function (): string {
				return 'fixture credential';
			}
		) )->run( $delivery->public_id );

		$accepted = ( new DeliveryRepository() )->find_by_public_id( $delivery->public_id );
		self::assertNotNull( $accepted );
		self::assertSame( 'accepted', $accepted->state );
		self::assertSame( 6, $accepted->attempt_count );
		self::assertSame( 5, $accepted->automatic_attempt_count );
		self::assertCount( 1, $provider->requests );

		$events             = ( new DeliveryEventRepository() )->find_by_delivery_id( $delivery->id );
		$submission_started = array_values(
			array_filter(
				$events,
				static fn ( $event ): bool => 'submission_started' === $event->event_type
			)
		);

		self::assertCount( 1, $submission_started );
		self::assertSame( 6, $submission_started[0]->attempt_number );
		self::assertFalse( $submission_started[0]->detail['automatic'] ?? true );
	}

	public function test_manual_retry_after_confirmed_not_accepted_clears_reconciliation_projection(): void {
		$delivery    = $this->liveDelivery();
		$receipt_key = HashKeys::remote_receipt_key( 'buffer', $delivery->connection_public_id, 'remote-ambiguous' );
		$this->updateDelivery(
			$delivery->public_id,
			array(
				'action_id'             => 700,
				'error_detail_json'     => CanonicalJson::encode_object( array( 'classification' => 'ambiguous' ) ),
				'receipt_json'          => CanonicalJson::encode_object( array( 'remote_id' => 'remote-ambiguous' ) ),
				'reconciled_at'         => '2026-08-24 12:05:00',
				'reconciled_by'         => 123,
				'reconciliation_note'   => 'Not visible in Buffer',
				'reconciliation_status' => 'confirmed_not_accepted',
				'remote_id'             => 'remote-ambiguous',
				'remote_receipt_key'    => $receipt_key,
				'retry_safety'          => 'manual_allowed',
				'state'                 => 'ambiguous',
				'state_changed_at'      => '2026-08-24 12:00:00',
			)
		);

		$retried = ( new OperatorActionService( Database::from_globals(), $this->fakeScheduler(), static fn (): bool => true ) )->retry( $delivery->public_id, 123 );

		self::assertSame( 'queued', $retried->state );
		self::assertSame( 'none', $retried->reconciliation_status );
		self::assertNull( $retried->reconciled_at );
		self::assertNull( $retried->reconciled_by );
		self::assertNull( $retried->reconciliation_note );
		self::assertNull( $retried->remote_id );
		self::assertNull( $retried->remote_receipt_key );
		self::assertNull( $retried->receipt );
		self::assertNull( $retried->error_detail );
	}

	public function test_unreconciled_ambiguous_delivery_cannot_retry(): void {
		$delivery = $this->liveDelivery();
		$this->updateDelivery(
			$delivery->public_id,
			array(
				'provider_result'  => 'ambiguous',
				'retry_safety'     => 'reconcile_first',
				'state'            => 'ambiguous',
				'state_changed_at' => '2026-08-24 12:00:00',
			)
		);

		$this->expectException( ValidationException::class );
		( new OperatorActionService( Database::from_globals(), $this->fakeScheduler(), static fn (): bool => true ) )->retry( $delivery->public_id, 123 );
	}

	public function test_reconciliation_outcomes_and_duplicate_remote_id_refusal(): void {
		$destination = $this->enabledDestination();
		$first       = $this->ambiguousDelivery( 42, $destination );
		$accepted    = ( new ReconciliationService() )->reconcile( $first->public_id, 'confirmed_remote_id', 'remote-post-1', 'Buffer dashboard check', 123 );

		self::assertSame( 'accepted', $accepted->state );
		self::assertSame( 'ambiguous', $accepted->provider_result );
		self::assertSame( 'confirmed_remote_id', $accepted->reconciliation_status );
		self::assertSame( 'remote-post-1', $accepted->remote_id );
		self::assertNotNull( $accepted->remote_receipt_key );

		$second = $this->ambiguousDelivery( 43, $destination );

		try {
			( new ReconciliationService() )->reconcile( $second->public_id, 'confirmed_remote_id', 'remote-post-1', null, 123 );
			self::fail( 'Duplicate remote receipt IDs must be refused.' );
		} catch ( ValidationException $exception ) {
			self::assertStringContainsString( 'refused', $exception->getMessage() );
		}

		$stored_second = ( new DeliveryRepository() )->find_by_public_id( $second->public_id );
		self::assertNotNull( $stored_second );
		self::assertSame( 'ambiguous', $stored_second->state );
		self::assertContains( 'refused_transition', array_column( ( new DeliveryEventRepository() )->find_by_delivery_id( $second->id ), 'event_type' ) );

		$third        = $this->ambiguousDelivery( 44 );
		$not_accepted = ( new ReconciliationService() )->reconcile( $third->public_id, 'confirmed_not_accepted', null, str_repeat( 'a', 700 ), 123 );
		self::assertSame( 'ambiguous', $not_accepted->state );
		self::assertSame( 'confirmed_not_accepted', $not_accepted->reconciliation_status );
		self::assertSame( 'manual_allowed', $not_accepted->retry_safety );
		self::assertSame( 500, strlen( (string) $not_accepted->reconciliation_note ) );

		$fourth    = $this->ambiguousDelivery( 45 );
		$cancelled = ( new ReconciliationService() )->reconcile( $fourth->public_id, 'cancelled', null, 'No remote post exists', 123 );
		self::assertSame( 'cancelled', $cancelled->state );
		self::assertSame( 'cancelled', $cancelled->reconciliation_status );
	}

	public function test_delivery_query_filters_due_retry_identifier_timezone_and_pagination(): void {
		update_option( 'timezone_string', 'Europe/Berlin', true );
		update_option( 'gmt_offset', 2, true );

		$first  = $this->liveDelivery( 42 );
		$second = $this->liveDelivery( 43 );
		$this->updateDelivery(
			$first->public_id,
			array(
				'next_attempt_at'  => gmdate( 'Y-m-d H:i:s', time() - 60 ),
				'remote_id'        => 'Remote-Filter-1',
				'retry_safety'     => 'automatic_allowed',
				'state'            => 'retrying',
				'state_changed_at' => '2026-08-23 22:30:00',
			)
		);
		$this->updateDelivery(
			$second->public_id,
			array(
				'remote_id'        => 'remote-filter-1',
				'state'            => 'queued',
				'state_changed_at' => '2026-08-24 22:30:00',
			)
		);

		$query = new DeliveryQueryRepository();
		self::assertSame( $first->public_id, $query->query( array( 'due_retry' => '1' ) )['rows'][0]->public_id );
		self::assertSame( $first->public_id, $query->query( array( 'identifier' => 'Remote-Filter-1' ) )['rows'][0]->public_id );
		$lowercase_remote = $query->query( array( 'identifier' => 'remote-filter-1' ) );
		self::assertSame( 1, $lowercase_remote['total'] );
		self::assertSame( $second->public_id, $lowercase_remote['rows'][0]->public_id );
		self::assertSame(
			$first->public_id,
			$query->query(
				array(
					'state_changed_from' => '2026-08-24',
					'state_changed_to'   => '2026-08-24',
				)
			)['rows'][0]->public_id
		);

		$page_one = $query->query( array(), 1, 1 );
		$page_two = $query->query( array(), 2, 1 );

		self::assertSame( 2, $page_one['total'] );
		self::assertSame( $second->public_id, $page_one['rows'][0]->public_id );
		self::assertSame( $first->public_id, $page_two['rows'][0]->public_id );

		$this->expectException( ValidationException::class );
		$query->query( array( 'post_id' => '42abc' ) );
	}

	public function test_detail_rendering_redacts_diagnostics_hides_decision_link_and_shows_deleted_actor(): void {
		$delivery = $this->ambiguousDelivery( 42 );
		$this->updateDelivery(
			$delivery->public_id,
			array(
				'error_detail_json' => CanonicalJson::encode_object(
					array(
						'Authorization'     => 'Bearer secret-token',
						'classification'    => 'ambiguous',
						'raw_response'      => 'raw body secret',
						'request_variables' => array( 'api_key' => 'secret-key' ),
					)
				),
				'error_class'       => 'automatic_budget_exhausted',
			)
		);
		( new DeliveryEventRepository() )->append(
			array(
				'actor_type'         => 'operator',
				'actor_user_id'      => 999999,
				'delivery_id'        => $delivery->id,
				'delivery_public_id' => $delivery->public_id,
				'detail'             => array( 'request_variables' => array( 'token' => 'secret-token' ) ),
				'event_type'         => 'refused_transition',
				'from_state'         => 'ambiguous',
				'to_state'           => 'ambiguous',
			)
		);

		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		( new DeliveryEventRepository() )->append(
			array(
				'actor_type'         => 'operator',
				'actor_user_id'      => $user_id,
				'delivery_id'        => $delivery->id,
				'delivery_public_id' => $delivery->public_id,
				'detail'             => array( 'classification' => 'operator_checked_delivery' ),
				'event_type'         => 'refused_transition',
				'from_state'         => 'ambiguous',
				'to_state'           => 'ambiguous',
			)
		);
		$user = get_userdata( $user_id );
		self::assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( Capabilities::VIEW_DELIVERIES );
		$user->add_cap( Capabilities::OPERATE_DELIVERIES );

		$_GET['delivery_public_id'] = $delivery->public_id;

		ob_start();
		DeliveryDetailController::render_page();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Record local evidence only', $html );
		self::assertStringContainsString( 'User #999999 (deleted)', $html );
		self::assertStringContainsString( 'User #' . (string) $user_id, $html );
		self::assertStringContainsString( 'Automatic Budget Exhausted (automatic_budget_exhausted)', $html );
		self::assertStringNotContainsString( 'Open Decisions', $html );
		self::assertStringNotContainsString( 'secret-token', $html );
		self::assertStringNotContainsString( 'secret-key', $html );
		self::assertStringNotContainsString( 'raw body secret', $html );
	}

	public function test_default_page_routes_delivery_only_user_to_delivery_log(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$user = get_userdata( $user_id );
		self::assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( Capabilities::VIEW_DELIVERIES );

		ob_start();
		AdminScreens::render_default_page();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'PressQueue Delivery Log', $html );
		self::assertStringNotContainsString( 'PressQueue Connections', $html );
		self::assertStringNotContainsString( 'PressQueue Decisions', $html );
	}

	/**
	 * @param array{connection:ConnectionRecord,channel:ChannelRecord}|null $destination Destination.
	 */
	private function ambiguousDelivery( int $post_id, ?array $destination = null ): DeliveryRecord {
		$delivery = $this->liveDelivery( $post_id, $destination );
		$this->updateDelivery(
			$delivery->public_id,
			array(
				'error_class'      => 'ambiguous_provider_result',
				'provider_result'  => 'ambiguous',
				'retry_safety'     => 'reconcile_first',
				'state'            => 'ambiguous',
				'state_changed_at' => '2026-08-24 12:00:00',
			)
		);

		$stored = ( new DeliveryRepository() )->find_by_public_id( $delivery->public_id );
		self::assertNotNull( $stored );

		return $stored;
	}

	/**
	 * @param array{connection:ConnectionRecord,channel:ChannelRecord}|null $destination Destination.
	 */
	private function liveDelivery( int $post_id = 42, ?array $destination = null ): DeliveryRecord {
		$destination = $destination ?? $this->enabledDestination();
		$rule        = ( new RuleRepository() )->create( $this->ruleInput( $destination['channel']->public_id ) );
		$decision    = ( new DecisionRepository() )->create( $this->decisionInput( $rule->id, $rule->public_id, $rule->version, $destination, $post_id ) );
		$final_text  = "Hello from integration\n\nhttps://example.test/post-" . (string) $post_id;

		return ( new DeliveryRepository() )->create(
			array(
				'channel_public_id'    => $destination['channel']->public_id,
				'connection_public_id' => $destination['connection']->public_id,
				'decision_public_id'   => $decision->public_id,
				'delivery_mode'        => 'live',
				'payload_preview'      => array(
					'adapter_encoding_version'         => 2,
					'bound_capability_catalog_version' => '2026-08-25.1',
					'bound_capability_hash'            => DeliveryCapability::hash( $destination['channel']->capabilities ),
					'capability_schema_version'        => 2,
					'canonical_url'                    => 'https://example.test/post-' . (string) $post_id,
					'channel_label'                    => 'Example Social',
					'channel_public_id'                => $destination['channel']->public_id,
					'connection_label'                 => 'Local account',
					'connection_public_id'             => $destination['connection']->public_id,
					'final_encoded_text'               => $final_text,
					'final_encoded_text_length'        => strlen( $final_text ),
					'media_snapshot'                   => array(
						'items'          => array(),
						'mode'           => 'none',
						'schema_version' => 1,
					),
					'message'                          => 'Hello from integration',
					'payload_schema_version'           => 2,
					'provider'                         => 'buffer',
					'remote_channel_id'                => $destination['channel']->remote_channel_id,
					'schedule_slot'                    => 'immediate',
					'service'                          => 'mastodon',
				),
				'provider'             => 'buffer',
				'remote_channel_id'    => $destination['channel']->remote_channel_id,
				'state'                => 'proposed',
			)
		);
	}

	/**
	 * @return array{connection:ConnectionRecord,channel:ChannelRecord}
	 */
	private function enabledDestination(): array {
		++$this->destination_sequence;
		$index = $this->destination_sequence;

		$connections = new ConnectionRepository();
		$channels    = new ChannelRepository();
		$envelope    = CanonicalJson::encode_object( array( 'fixture' => 'encrypted' ) );
		$account     = $this->accountDocument( $index );
		$connection  = $connections->create_with_credential( 'buffer', 'Local account', $envelope, hash( 'sha256', $envelope ) );
		$connection  = $connections->store_verified_account( $connection->public_id, 'acct_' . (string) $index, $account );
		$connection  = $connections->select_organization_unverified( $connection->public_id, 'org_' . (string) $index );
		$lease       = $connections->acquire_sync_lease( $connection->public_id );

		self::assertNotNull( $lease );

		$connection = $connections->complete_sync_success( $connection->public_id, $lease, 'acct_' . (string) $index, 'org_' . (string) $index, $account );
		$connection = $connections->enable( $connection->public_id );
		$channel    = $channels->create( $connection->public_id, 'buffer', 'remote-channel-' . (string) $index, 'mastodon', array( 'name' => 'Example Social' ), $this->channelCapabilities(), false, '2026-08-24 12:00:00', 'org_' . (string) $index, 'available' );
		$channel    = $channels->set_enabled_if_current( $channel->public_id, true, $channel->updated_at->format( 'Y-m-d H:i:s' ) );

		return array(
			'channel'    => $channel,
			'connection' => $connection,
		);
	}

	/**
	 * @param array{connection:ConnectionRecord,channel:ChannelRecord} $destination Destination.
	 * @return array<string,mixed>
	 */
	private function decisionInput( int $rule_id, string $rule_public_id, int $rule_version, array $destination, int $post_id ): array {
		return array(
			'author_id'            => 7,
			'event_key'            => hash( 'sha256', 'event-' . (string) $post_id ),
			'event_mode'           => 'runtime',
			'post_id'              => $post_id,
			'post_status'          => 'publish',
			'post_type'            => 'post',
			'reasons'              => array(
				array(
					'code'             => 'matched_rule',
					'condition_result' => 'matched',
				),
			),
			'result'               => 'included',
			'revision_fingerprint' => hash( 'sha256', 'revision-' . (string) $post_id ),
			'rule_id'              => $rule_id,
			'rule_public_id'       => $rule_public_id,
			'rule_version'         => $rule_version,
			'scope_public_id'      => $destination['channel']->public_id,
			'scope_type'           => 'channel',
			'snapshot'             => array(
				'destination' => array(
					'channel_label'    => 'Example Social',
					'connection_label' => 'Local account',
					'service'          => 'mastodon',
				),
				'post'        => array( 'id' => $post_id ),
				'settings'    => array( 'dry_run' => false ),
			),
			'trigger'              => 'first_publish',
		);
	}

	/**
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
	 * @return array<string,mixed>
	 */
	private function accountDocument( int $index ): array {
		return array(
			'display_label'     => 'Owner',
			'organizations'     => array(
				array(
					'channel_count'          => 1,
					'display_label'          => 'Editorial',
					'provider'               => 'buffer',
					'remote_organization_id' => 'org_' . (string) $index,
				),
			),
			'provider'          => 'buffer',
			'remote_account_id' => 'acct_' . (string) $index,
			'schema_version'    => 1,
		);
	}

	/**
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
	 * @param array<string,mixed> $values Values.
	 */
	private function updateDelivery( string $delivery_public_id, array $values ): void {
		$values['updated_at'] = $values['updated_at'] ?? '2026-08-24 12:00:00';

		Database::from_globals()->update_rows(
			Database::from_globals()->table( Schema::DELIVERIES ),
			$values,
			array( 'public_id' => $delivery_public_id ),
			array(
				'action_id'                       => '%d',
				'adapter_encoding_version'        => '%d',
				'attempt_count'                   => '%d',
				'automatic_attempt_count'         => '%d',
				'bound_capability_schema_version' => '%d',
				'error_class'                     => '%s',
				'error_detail_json'               => '%s',
				'next_attempt_at'                 => '%s',
				'payload_preview_json'            => '%s',
				'payload_schema_version'          => '%d',
				'provider_result'                 => '%s',
				'public_id'                       => '%s',
				'queued_at'                       => '%s',
				'receipt_json'                    => '%s',
				'reconciled_at'                   => '%s',
				'reconciled_by'                   => '%d',
				'reconciliation_note'             => '%s',
				'reconciliation_status'           => '%s',
				'remote_id'                       => '%s',
				'remote_receipt_key'              => '%s',
				'retry_safety'                    => '%s',
				'state'                           => '%s',
				'state_changed_at'                => '%s',
				'updated_at'                      => '%s',
			)
		);
	}

	/**
	 * @return ActionSchedulerQueue&object{scheduled:array<int,array{public_id:string,timestamp:int|null}>,unscheduled:array<int,string>}
	 */
	private function fakeScheduler(): ActionSchedulerQueue {
		return new class() extends ActionSchedulerQueue {
			/** @var array<int,array{public_id:string,timestamp:int|null}> */
			public array $scheduled = array();
			/** @var array<int,string> */
			public array $unscheduled   = array();
			private int $next_action_id = 900;

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
				$this->unscheduled[] = $delivery_public_id;
			}

			public function has_recurring_sweep(): bool {
				return true;
			}
		};
	}

	/**
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
					'remote-operator-retry',
					new ProviderReceipt(
						array(
							'provider'           => 'buffer',
							'remote_id'          => 'remote-operator-retry',
							'returned_text_hash' => hash( 'sha256', $request->message ),
							'schema_version'     => 1,
						)
					)
				);
			}
		};
	}

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
