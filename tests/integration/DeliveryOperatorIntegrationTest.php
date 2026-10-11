<?php
/**
 * Delivery operator integration tests.
 *
 * @package PressQueue\Tests\Integration
 */

declare(strict_types=1);

namespace PressQueue\Tests\Integration;

use PressQueue\Admin\AdminScreens;
use PressQueue\Admin\DecisionsController;
use PressQueue\Admin\DeliveryActionController;
use PressQueue\Admin\DeliveryDetailController;
use PressQueue\Admin\DeliveryListController;
use PressQueue\Delivery\DeliveryQueryRepository;
use PressQueue\Delivery\DeliveryWorker;
use PressQueue\Delivery\OperatorActionAssessmentService;
use PressQueue\Delivery\OperatorActionService;
use PressQueue\Delivery\ReconciliationService;
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
use PressQueue\Storage\HashKeys;
use PressQueue\Storage\Record\ChannelRecord;
use PressQueue\Storage\Record\ConnectionRecord;
use PressQueue\Storage\Record\DeliveryRecord;
use PressQueue\Storage\Record\RuleRecord;
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
use PressQueue\WpCli\DeliveryCommand;
use PressQueue\WpCli\ExitCodes;
use PressQueue\Tests\Support\PressQueueIntegrationTestCase as WP_UnitTestCase;

/**
 * @covers \PressQueue\Admin\AdminScreens
 * @covers \PressQueue\Admin\DecisionsController
 * @covers \PressQueue\Admin\DeliveryActionController
 * @covers \PressQueue\Admin\DeliveryDetailController
 * @covers \PressQueue\Admin\DeliveryExplainer
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
		RuntimeDisabledLatch::open();
	}

	protected function tearDown(): void {
		DeliveryActionController::set_services( null, null );
		DeliveryActionController::set_assessment_service( null );
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

	public function test_retry_assessment_is_zero_write_and_requires_decryptable_credential(): void {
		$delivery = $this->liveDelivery();
		$this->updateDelivery(
			$delivery->public_id,
			array(
				'error_class'      => 'automatic_budget_exhausted',
				'provider_result'  => 'retryable_failure',
				'retry_safety'     => 'manual_allowed',
				'state'            => 'failed',
				'state_changed_at' => '2026-08-24 12:00:00',
			)
		);
		$before_events = ( new DeliveryEventRepository() )->find_by_delivery_id( $delivery->id );

		$blocked = ( new OperatorActionAssessmentService( Database::from_globals(), static fn (): bool => false ) )->assess_retry( $delivery->public_id );
		$allowed = ( new OperatorActionAssessmentService( Database::from_globals(), static fn (): bool => true ) )->assess_retry( $delivery->public_id );
		$stored  = ( new DeliveryRepository() )->find_by_public_id( $delivery->public_id );

		self::assertFalse( $blocked->would_mutate );
		self::assertContains( 'manual_retry_credential_unavailable', $blocked->blocked_reasons );
		self::assertTrue( $allowed->would_mutate );
		self::assertNotNull( $stored );
		self::assertSame( 'failed', $stored->state );
		self::assertCount( count( $before_events ), ( new DeliveryEventRepository() )->find_by_delivery_id( $delivery->id ) );
	}

	public function test_cli_delivery_cancel_defaults_to_zero_write_assessment(): void {
		$user_id  = $this->commandUser( Capabilities::OPERATE_DELIVERIES );
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
		$before_events = ( new DeliveryEventRepository() )->find_by_delivery_id( $delivery->id );

		list( $status, $line ) = $this->captureCommand(
			static function () use ( $delivery ): int {
				return ( new DeliveryCommand() )->cancel( array( $delivery->public_id ), array() );
			}
		);
		$stored = ( new DeliveryRepository() )->find_by_public_id( $delivery->public_id );

		self::assertSame( $user_id, get_current_user_id() );
		self::assertSame( ExitCodes::SUCCESS, $status );
		self::assertFalse( $line['data']['applied'] );
		self::assertTrue( $line['data']['would_mutate'] );
		self::assertNotNull( $stored );
		self::assertSame( 'queued', $stored->state );
		self::assertCount( count( $before_events ), ( new DeliveryEventRepository() )->find_by_delivery_id( $delivery->id ) );
	}

	public function test_cli_delivery_cancel_apply_uses_existing_service(): void {
		$this->commandUser( Capabilities::OPERATE_DELIVERIES );
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

		list( $status, $line ) = $this->captureCommand(
			static function () use ( $delivery ): int {
				return ( new DeliveryCommand() )->cancel( array( $delivery->public_id ), array( 'apply' => true ) );
			}
		);
		$stored = ( new DeliveryRepository() )->find_by_public_id( $delivery->public_id );

		self::assertSame( ExitCodes::SUCCESS, $status );
		self::assertTrue( $line['data']['applied'] );
		self::assertSame( $delivery->public_id, $line['data']['record_public_id'] );
		self::assertSame( 'queued', $line['data']['old_state'] );
		self::assertSame( 'cancelled', $line['data']['new_state'] );
		self::assertNotNull( $line['data']['event_public_id'] );
		self::assertNotNull( $stored );
		self::assertSame( 'cancelled', $stored->state );
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
		wp_set_current_user( 0 );
		wp_set_current_user( $user_id );

		$_GET['delivery_public_id'] = $delivery->public_id;

		ob_start();
		DeliveryDetailController::render_page();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'I looked for this post in Buffer', $html );
		self::assertStringNotContainsString( 'Record local evidence only', $html );
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
		wp_set_current_user( 0 );
		wp_set_current_user( $user_id );

		ob_start();
		AdminScreens::render_default_page();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '<h1>Activity</h1>', $html );
		self::assertStringContainsString( 'Filter shares by status', $html );
		self::assertStringNotContainsString( 'Why posts were shared or skipped', $html );
		self::assertStringNotContainsString( 'PressQueue Connections', $html );
		self::assertStringNotContainsString( 'PressQueue Decisions', $html );
	}

	public function test_non_ascii_identifier_filter_is_safe(): void {
		$this->liveDelivery( 42 );
		$result = ( new DeliveryQueryRepository() )->query( array( 'identifier' => 'Müller' ) );
		self::assertSame( 0, $result['total'] );
	}

	public function test_retention_scrubs_accepted_content_and_keeps_duplicate_identity(): void {
		$delivery = $this->liveDelivery( 42 );
		$this->updateDelivery( $delivery->public_id, array( 'state' => 'accepted', 'state_changed_at' => '2020-01-01 00:00:00' ) );
		$service = new \PressQueue\Privacy\RetentionService();
		$method = new \ReflectionMethod( $service, 'delete_deliveries' );
		$method->setAccessible( true );
		$result = $method->invoke( $service, '2021-01-01 00:00:00', array() );
		self::assertSame( 1, $result['counters']['redacted'] );
		$stored = ( new DeliveryRepository() )->find_by_public_id( $delivery->public_id );
		self::assertNotNull( $stored );
		self::assertSame( 'accepted', $stored->state );
		self::assertSame( $delivery->delivery_key, $stored->delivery_key );
		self::assertSame( 'retention', $stored->payload_preview['redacted'] );
		self::assertNotNull( ( new \PressQueue\Batch\DuplicateHistoryService() )->blocking_bulk_duplicate( 42, $delivery->channel_public_id, null, 'live' ) );
	}

	public function test_repost_gap_uses_latest_acceptance_instead_of_an_older_match(): void {
		$destination = $this->enabledDestination();
		$older = $this->liveDelivery( 42, $destination );
		$recent = $this->liveDelivery( 42, $destination );
		$this->updateDelivery( $older->public_id, array( 'state' => 'accepted', 'state_changed_at' => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
		$this->updateDelivery( $recent->public_id, array( 'state' => 'accepted', 'state_changed_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
		self::assertNull( ( new \PressQueue\Batch\DuplicateHistoryService() )->prior_accepted_delivery( 42, $destination['channel']->public_id, array( 'minimum_days_since_prior_acceptance' => 7 ) ) );
	}

	public function test_activity_list_explains_problems_and_offers_one_action_per_row(): void {
		$this->activityAdmin();
		$invalid   = $this->liveDelivery( $this->titledPost( 61, 'Spring menu' ) );
		$not_found = $this->liveDelivery( $this->titledPost( 62, 'Hiring a weekend baker' ) );
		$ambiguous = $this->liveDelivery( $this->titledPost( 63, 'Rye starter guide' ) );
		$held      = $this->liveDelivery( $this->titledPost( 64, 'Holiday hours' ) );
		$retrying  = $this->liveDelivery( $this->titledPost( 65, 'Sourdough at 4 a.m.' ) );

		$this->updateDelivery( $invalid->public_id, array( 'error_class' => 'invalid_credential', 'provider_result' => 'permanent_failure', 'retry_safety' => 'not_retryable', 'state' => 'failed' ) );
		$this->updateDelivery( $not_found->public_id, array( 'error_class' => 'not_found', 'provider_result' => 'permanent_failure', 'retry_safety' => 'manual_allowed', 'state' => 'failed' ) );
		$this->updateDelivery( $ambiguous->public_id, array( 'error_class' => 'malformed_response', 'provider_result' => 'ambiguous', 'retry_safety' => 'reconcile_first', 'state' => 'ambiguous' ) );
		$this->updateDelivery( $held->public_id, array( 'state' => 'held' ) );
		$this->updateDelivery( $retrying->public_id, array( 'error_class' => 'rate_limited', 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ), 'provider_result' => 'rate_limited', 'retry_safety' => 'automatic_allowed', 'state' => 'retrying' ) );

		$html = $this->renderActivity( array() );

		self::assertStringContainsString( 'Buffer said the API key is no longer valid.', $html );
		self::assertStringContainsString( 'Buffer could not find this channel.', $html );
		self::assertStringContainsString( 'Buffer may have received this, but the reply was cut off.', $html );
		self::assertStringContainsString( 'Buffer limits requests per day. Next try: ', $html );
		self::assertStringContainsString( 'Held because PressQueue was paused when the post was published.', $html );
		self::assertStringContainsString( 'Spring menu', $html );
		self::assertStringContainsString( 'Needs attention <b>3</b>', $html );
		self::assertSame( 3, substr_count( $html, '<tr class="pq-attention">' ) );
		self::assertSame( 1, substr_count( $html, 'value="pressqueue_retry_delivery"' ) );
		self::assertSame( 1, substr_count( $html, 'value="pressqueue_resume_delivery"' ) );
		self::assertStringContainsString( 'delivery_public_id=' . $ambiguous->public_id . '#resolve', $html );
		self::assertStringContainsString( 'Resume all on hold', $html );
		self::assertStringNotContainsString( $invalid->delivery_key, $html );
		self::assertStringNotContainsString( $invalid->payload_hash, $html );
		self::assertStringNotContainsString( 'Lease', $html );

		$attention = $this->renderActivity( array( 'group' => 'attention' ) );
		self::assertStringContainsString( $ambiguous->public_id, $attention );
		self::assertStringNotContainsString( $held->public_id, $attention );
		self::assertStringNotContainsString( $retrying->public_id, $attention );

		$overview_link = $this->renderActivity( array( 'state' => 'held' ) );
		self::assertStringContainsString( 'group=held" aria-current="true"', $overview_link );
		self::assertStringContainsString( $held->public_id, $overview_link );
		self::assertStringNotContainsString( $ambiguous->public_id, $overview_link );

		( new SettingsRepository() )->set_dry_run_enabled( true );
		$test_mode = $this->renderActivity( array( 'group' => 'held' ) );
		self::assertStringNotContainsString( 'value="pressqueue_resume_delivery"', $test_mode );
		self::assertStringNotContainsString( 'Resume all on hold', $test_mode );
		self::assertStringContainsString( 'PressQueue is in test mode, so these shares cannot be sent.', $test_mode );
		self::assertStringContainsString( '>Go live</button>', $test_mode );
		self::assertStringContainsString( 'name="sharing_mode" value="live"', $test_mode );
		self::assertStringContainsString( 'name="redirect_page" value="pressqueue-deliveries"', $test_mode );
		self::assertStringNotContainsString( 'Open Settings', $test_mode );
		self::assertStringContainsString( 'A share ID or Buffer post ID.', $test_mode );
		self::assertStringNotContainsString( 'delivery key', $test_mode );

		( new SettingsRepository() )->set_dry_run_enabled( false );
		( new SettingsRepository() )->set_kill_switch_enabled( true );
		$paused = $this->renderActivity( array( 'group' => 'held' ) );
		self::assertStringContainsString( '>Resume sharing</button>', $paused );
		self::assertStringNotContainsString( 'value="pressqueue_resume_delivery"', $paused );
		( new SettingsRepository() )->set_kill_switch_enabled( false );
	}

	public function test_failed_share_gives_one_consistent_next_step(): void {
		$this->activityAdmin();
		$invalid = $this->liveDelivery( $this->titledPost( 121, 'Spring menu' ) );
		$this->updateDelivery( $invalid->public_id, array( 'attempt_count' => 1, 'error_class' => 'invalid_credential', 'provider_result' => 'permanent_failure', 'retry_safety' => 'not_retryable', 'state' => 'failed' ) );

		$html = $this->renderDetail( $invalid->public_id );

		self::assertStringContainsString( 'Buffer said the API key is no longer valid.', $html );
		self::assertStringContainsString( 'Add a new Buffer API key under <a href="' . esc_url( admin_url( 'admin.php?page=pressqueue-connections' ) ) . '">Channels</a>.', $html );
		self::assertStringContainsString( 'This share cannot be sent again. Cancel it, then share the post from <a href="' . esc_url( admin_url( 'admin.php?page=pressqueue-batches' ) ) . '">Share old posts</a>.', $html );
		self::assertStringContainsString( 'value="pressqueue_cancel_delivery"', $html );
		self::assertStringNotContainsString( 'value="pressqueue_retry_delivery"', $html );
		self::assertStringNotContainsString( 'then retry', $html );
		self::assertStringNotContainsString( 'cannot retry this share', $html );
		self::assertStringNotContainsString( 'clear it from the list', $html );
		self::assertStringContainsString( 'Message PressQueue tried to send', $html );
		self::assertStringNotContainsString( 'Message sent to Buffer', $html );

		$missing = $this->liveDelivery( $this->titledPost( 126, 'Hiring a weekend baker' ) );
		$this->updateDelivery( $missing->public_id, array( 'attempt_count' => 1, 'error_class' => 'not_found', 'provider_result' => 'permanent_failure', 'retry_safety' => 'manual_allowed', 'state' => 'failed' ) );

		$retry = $this->renderDetail( $missing->public_id );

		self::assertStringContainsString( 'Buffer could not find this channel.', $retry );
		self::assertStringContainsString( 'refresh the channels under <a href=', $retry );
		self::assertStringContainsString( 'Then retry this share.', $retry );
		self::assertStringContainsString( '>Retry</button>', $retry );
		self::assertStringNotContainsString( 'cannot be sent again', $retry );

		$this->commandUser( Capabilities::VIEW_DELIVERIES );
		$viewer = $this->renderDetail( $invalid->public_id );
		self::assertStringContainsString( 'Add a new Buffer API key under Channels.', $viewer );
		self::assertStringNotContainsString( 'page=pressqueue-connections', $viewer );
	}

	public function test_missing_confirmation_returns_to_the_share_with_a_notice(): void {
		$this->activityAdmin();
		$ambiguous = $this->ambiguousDelivery( $this->titledPost( 131, 'Rye starter guide' ) );
		$held      = $this->liveDelivery( $this->titledPost( 136, 'Holiday hours' ) );
		$this->updateDelivery( $held->public_id, array( 'state' => 'held' ) );

		$location = $this->submit(
			array( DeliveryActionController::class, 'handle_reconcile_delivery' ),
			'pressqueue_reconcile_delivery_cancelled',
			array(
				'delivery_public_id' => $ambiguous->public_id,
				'outcome'            => 'cancelled',
			)
		);
		self::assertStringContainsString( 'page=pressqueue-delivery', $location );
		self::assertStringContainsString( 'delivery_public_id=' . $ambiguous->public_id, $location );
		self::assertStringContainsString( 'pressqueue_message=delivery_confirm_reconcile', $location );
		self::assertSame( 'ambiguous', $this->storedState( $ambiguous->public_id ) );

		$_GET = $this->queryArgs( $location );
		ob_start();
		DeliveryDetailController::render_page();
		$notice = (string) ob_get_clean();
		self::assertStringContainsString( 'notice-warning', $notice );
		self::assertStringContainsString( 'Nothing was saved. Tick &quot;I looked for this post in Buffer&quot;, then click the button again.', $notice );

		$location = $this->submit( array( DeliveryActionController::class, 'handle_cancel_delivery' ), 'pressqueue_cancel_delivery', array( 'delivery_public_id' => $held->public_id ) );
		self::assertStringContainsString( 'pressqueue_message=delivery_confirm_cancel', $location );
		self::assertSame( 'held', $this->storedState( $held->public_id ) );

		$location = $this->submit( array( DeliveryActionController::class, 'handle_cancel_held_deliveries' ), 'pressqueue_cancel_held_deliveries', array() );
		self::assertStringContainsString( 'page=pressqueue-deliveries', $location );
		self::assertStringContainsString( 'group=held', $location );
		self::assertStringContainsString( 'pressqueue_message=held_confirm_cancel', $location );
		self::assertSame( 'held', $this->storedState( $held->public_id ) );
		self::assertStringContainsString( 'Nothing was cancelled. Tick the box to confirm, then click Cancel shares on hold again.', $this->renderActivity( $this->queryArgs( $location ) ) );

		// With the tick the choice is saved. The handler redirects from inside its try block,
		// so the test redirect interception only proves the state change here.
		$this->submit(
			array( DeliveryActionController::class, 'handle_reconcile_delivery' ),
			'pressqueue_reconcile_delivery_cancelled',
			array(
				'confirm_local_effect' => '1',
				'delivery_public_id'   => $ambiguous->public_id,
				'outcome'              => 'cancelled',
			)
		);
		self::assertSame( 'cancelled', $this->storedState( $ambiguous->public_id ) );
	}

	public function test_activity_hides_titles_and_actions_from_viewers_without_rights(): void {
		$delivery = $this->liveDelivery( $this->titledPost( 66, 'Private draft title' ) );
		$this->updateDelivery( $delivery->public_id, array( 'error_class' => 'not_found', 'provider_result' => 'permanent_failure', 'retry_safety' => 'manual_allowed', 'state' => 'failed' ) );
		$this->commandUser( Capabilities::VIEW_DELIVERIES );

		$html = $this->renderActivity( array() );

		self::assertStringContainsString( 'Post #66', $html );
		self::assertStringNotContainsString( 'Private draft title', $html );
		self::assertStringNotContainsString( 'value="pressqueue_retry_delivery"', $html );

		$search = $this->renderActivity( array( 'search' => 'Private draft' ) );
		self::assertStringNotContainsString( $delivery->public_id, $search );
		self::assertStringContainsString( 'No shares match these filters', $search );
	}

	public function test_activity_search_channel_and_query_filters(): void {
		$this->activityAdmin();
		$focaccia = $this->liveDelivery( $this->titledPost( 71, 'Pumpkin focaccia' ) );
		$oven     = $this->liveDelivery( $this->titledPost( 72, 'The new oven' ) );

		$search = $this->renderActivity( array( 'search' => 'focaccia' ) );
		self::assertStringContainsString( $focaccia->public_id, $search );
		self::assertStringNotContainsString( $oven->public_id, $search );

		$channel = $this->renderActivity( array( 'channel' => $oven->channel_public_id ) );
		self::assertStringContainsString( $oven->public_id, $channel );
		self::assertStringNotContainsString( $focaccia->public_id, $channel );

		$this->updateDelivery( $oven->public_id, array( 'state' => 'held' ) );
		$query = new DeliveryQueryRepository();
		self::assertSame( array( $oven->public_id ), array_map( static fn ( DeliveryRecord $row ): string => $row->public_id, $query->query( array( 'states' => array( 'held', 'failed' ) ) )['rows'] ) );
		self::assertSame( array( $focaccia->public_id ), array_map( static fn ( DeliveryRecord $row ): string => $row->public_id, $query->query( array( 'post_ids' => array( 71 ) ) )['rows'] ) );
		self::assertSame( 0, $query->query( array( 'post_ids' => array() ) )['total'] );
		self::assertSame( array( 'held' => 1, 'proposed' => 1 ), $this->sortedCounts( $query->count_by_state() ) );
		self::assertSame( array( 'proposed' => 1 ), $query->count_by_state( array( 'post_ids' => array( 71 ) ) ) );

		try {
			$query->query( array( 'states' => array( 'held', 'unknown' ) ) );
			self::fail( 'Unknown states must be refused.' );
		} catch ( ValidationException $exception ) {
			self::assertStringContainsString( 'unsupported', $exception->getMessage() );
		}

		$this->expectException( ValidationException::class );
		$query->query( array( 'post_ids' => array( '7 OR 1=1' ) ) );
	}

	public function test_share_details_tell_what_happened_and_keep_message_redaction(): void {
		$user_id  = $this->activityAdmin();
		$delivery = $this->liveDelivery( $this->titledPost( 81, 'Autumn bread classes' ) );
		$this->updateDelivery( $delivery->public_id, array( 'state' => 'held' ) );
		$service = new OperatorActionService( Database::from_globals(), $this->fakeScheduler(), static fn (): bool => true );
		$service->resume( $delivery->public_id, $user_id );
		$service->cancel( $delivery->public_id, $user_id );

		$html = $this->renderDetail( $delivery->public_id );

		self::assertStringContainsString( '<h1>Autumn bread classes</h1>', $html );
		self::assertStringContainsString( 'What happened', $html );
		self::assertStringContainsString( 'Planned when the post was published.', $html );
		self::assertStringContainsString( 'Held because PressQueue was paused when the post was published.', $html );
		self::assertStringContainsString( 'Resumed by Mara Lindqvist.', $html );
		self::assertStringContainsString( 'Cancelled by Mara Lindqvist.', $html );
		self::assertStringContainsString( 'Hello from integration', $html );
		self::assertStringContainsString( 'Technical details', $html );
		self::assertStringNotContainsString( 'value="pressqueue_cancel_delivery"', $html );

		$this->commandUser( Capabilities::VIEW_DELIVERIES );
		$viewer = $this->renderDetail( $delivery->public_id );
		self::assertStringContainsString( 'Message and media details are hidden because you cannot edit the source post.', $viewer );
		self::assertStringNotContainsString( 'Hello from integration', $viewer );
		self::assertStringNotContainsString( 'Autumn bread classes', $viewer );
		self::assertStringContainsString( 'post_facts_redacted', $viewer );

		$this->activityAdmin();
		$accepted = $this->liveDelivery( 82 );
		$this->updateDelivery(
			$accepted->public_id,
			array(
				'payload_preview_json' => CanonicalJson::encode_object(
					array(
						'redacted'       => 'retention',
						'schema_version' => 1,
					)
				),
				'state'                => 'accepted',
			)
		);
		self::assertStringContainsString( 'The message was removed by your data retention settings.', $this->renderDetail( $accepted->public_id ) );
	}

	public function test_ambiguous_share_offers_three_reconciliation_choices(): void {
		$this->activityAdmin();
		$delivery = $this->ambiguousDelivery( 42 );

		$html = $this->renderDetail( $delivery->public_id );

		self::assertStringContainsString( 'id="resolve"', $html );
		self::assertStringContainsString( 'If PressQueue sends it again and Buffer already has it, the post appears twice.', $html );
		self::assertStringContainsString( 'I found it in Buffer', $html );
		self::assertStringContainsString( 'It is not in Buffer', $html );
		self::assertStringContainsString( 'Cancel it', $html );
		self::assertSame( 3, substr_count( $html, 'I looked for this post in Buffer' ) );
		self::assertSame( 3, substr_count( $html, 'name="confirm_local_effect" value="1" required' ) );
		self::assertStringNotContainsString( 'Record local evidence only', $html );
		self::assertStringContainsString( 'Message PressQueue tried to send', $html );
		self::assertStringNotContainsString( 'Message sent to Buffer', $html );
		foreach ( array( 'confirmed_remote_id', 'confirmed_not_accepted', 'cancelled' ) as $outcome ) {
			self::assertStringContainsString( 'name="outcome" value="' . $outcome . '"', $html );
		}

		$this->updateDelivery(
			$delivery->public_id,
			array(
				'error_class'       => 'draft_not_confirmed',
				'error_detail_json' => CanonicalJson::encode_object(
					array(
						'classification' => 'draft_not_confirmed',
						'remote_id'      => 'returned-remote-1',
					)
				),
			)
		);
		$draft = $this->renderDetail( $delivery->public_id );
		self::assertStringContainsString( 'Sharing was paused to be safe.', $draft );
		self::assertStringContainsString( 'value="returned-remote-1"', $draft );
		self::assertStringNotContainsString( 'name="outcome" value="confirmed_not_accepted"', $draft );
	}

	public function test_bulk_resume_respects_mode_and_zero_write_assessment(): void {
		$first  = $this->liveDelivery( 91 );
		$second = $this->liveDelivery( 92 );
		$this->updateDelivery( $first->public_id, array( 'state' => 'held' ) );
		$this->updateDelivery( $second->public_id, array( 'state' => 'held' ) );
		DeliveryActionController::set_services( new OperatorActionService( Database::from_globals(), $this->fakeScheduler(), static fn (): bool => true ), null );
		DeliveryActionController::set_assessment_service( new OperatorActionAssessmentService( Database::from_globals(), static fn (): bool => true ) );

		( new SettingsRepository() )->set_dry_run_enabled( true );
		$blocked = DeliveryActionController::resume_held_batch( 7 );
		self::assertSame( 'held_blocked', $blocked['pressqueue_message'] );
		self::assertSame( 'dry_run_enabled', $blocked['pq_reason'] );
		self::assertSame( 'held', $this->storedState( $first->public_id ) );
		self::assertNotContains( 'refused_transition', array_column( ( new DeliveryEventRepository() )->find_by_delivery_id( $first->id ), 'event_type' ) );

		( new SettingsRepository() )->set_dry_run_enabled( false );
		wp_update_post( array( 'ID' => 92, 'post_status' => 'draft' ) );
		$result = DeliveryActionController::resume_held_batch( 7 );

		self::assertSame( 'held_resumed', $result['pressqueue_message'] );
		self::assertSame( 1, $result['pq_done'] );
		self::assertSame( 1, $result['pq_skipped'] );
		self::assertSame( 'post_not_public', $result['pq_reason'] );
		self::assertSame( 0, $result['pq_left'] );
		self::assertSame( 'queued', $this->storedState( $first->public_id ) );
		self::assertSame( 'held', $this->storedState( $second->public_id ) );
		self::assertNotContains( 'refused_transition', array_column( ( new DeliveryEventRepository() )->find_by_delivery_id( $second->id ), 'event_type' ) );

		$cancelled = DeliveryActionController::cancel_held_batch( 7 );
		self::assertSame( 'held_cancelled', $cancelled['pressqueue_message'] );
		self::assertSame( 1, $cancelled['pq_done'] );
		self::assertSame( 'cancelled', $this->storedState( $second->public_id ) );
		self::assertSame( 'queued', $this->storedState( $first->public_id ) );
	}

	public function test_decisions_tab_groups_events_and_hides_reasons_from_non_editors(): void {
		$destination = $this->enabledDestination();
		$rules       = new RuleRepository();
		$include     = $rules->create( $this->ruleInput( $destination['channel']->public_id ) );
		$this->titledPost( 101, 'Rye starter guide' );
		$this->titledPost( 102, 'Journal entry' );
		$this->titledPost( 103, 'Market stall news' );
		$this->titledPost( 104, 'Previewed only' );

		$this->recordDecision( 101, 'skipped', null, null, array( array( 'code' => 'no_matching_rules', 'label' => 'Enabled rules exist but none matched this event.' ) ) );
		$this->recordDecision(
			102,
			'excluded',
			$include,
			null,
			array(
				array(
					'code'             => 'taxonomy_category_matched',
					'condition_result' => 'matched',
					'field'            => 'taxonomy:category',
					'label'            => 'Taxonomy condition evaluated.',
				),
			)
		);
		$this->recordDecision( 103, 'included', $include, $destination['channel']->public_id, array( array( 'code' => 'included_destination', 'label' => 'Destination is included by the winning rule.' ) ) );
		$this->recordDecision( 103, 'skipped', null, null, array( array( 'code' => 'superseded_by_rule', 'label' => 'Superseded.' ) ), 'runtime', 'global-103' );
		$this->recordDecision( 104, 'skipped', null, null, array( array( 'code' => 'no_matching_rules' ) ), 'preview' );

		$this->activityAdmin();
		$html = $this->renderDecisions( array() );

		self::assertStringContainsString( 'Not shared: No sharing rule matches this post.', $html );
		self::assertStringContainsString( 'Never share: in a category you never share.', $html );
		self::assertStringContainsString( 'Matched for Mastodon.', $html );
		self::assertStringNotContainsString( 'Shared to', $html );
		self::assertStringContainsString( 'Matched <b>1</b>', $html );
		self::assertStringContainsString( 'All <b>3</b>', $html );
		self::assertStringContainsString( 'Showing 1 to 3 of 3', $html );
		self::assertStringNotContainsString( 'post event', $html );
		self::assertStringNotContainsString( '<h4', $html );
		self::assertStringContainsString( '<h3 class="pq-why-heading">Rules checked</h3>', $html );
		self::assertSame( 1, substr_count( $html, 'Market stall news' ) );
		self::assertStringNotContainsString( 'Previewed only', $html );
		self::assertStringContainsString( 'Publish posts matched.', $html );

		$not_shared = $this->renderDecisions( array( 'outcome' => 'not_shared' ) );
		self::assertStringContainsString( 'Rye starter guide', $not_shared );
		self::assertStringContainsString( 'Journal entry', $not_shared );
		self::assertStringNotContainsString( 'Market stall news', $not_shared );

		$this->commandUser( Capabilities::VIEW_DECISIONS );
		$viewer = $this->renderDecisions( array() );
		self::assertStringNotContainsString( 'No sharing rule matches this post.', $viewer );
		self::assertStringNotContainsString( 'in a category you never share', $viewer );
		self::assertStringNotContainsString( 'Rye starter guide', $viewer );
		self::assertStringContainsString( 'Details are hidden because you cannot edit this post.', $viewer );
	}

	public function test_decisions_tab_shows_one_row_per_post_and_batch_with_the_real_outcome(): void {
		$first   = $this->enabledDestination();
		$second  = $this->enabledDestination();
		$include = ( new RuleRepository() )->create( $this->ruleInput( $first['channel']->public_id ) );
		$rye     = (int) self::factory()->post->create( array( 'post_title' => 'Rye starter feeding schedule' ) );
		$weekend = (int) self::factory()->post->create( array( 'post_title' => 'Weekend opening times' ) );
		$this->recordBatchDecision( $rye, $include, $first['channel']->public_id, '01J00000000000000000BATCH1', 1 );
		$this->recordBatchDecision( $rye, $include, $second['channel']->public_id, '01J00000000000000000BATCH1', 2 );
		$this->recordBatchDecision( $rye, $include, $first['channel']->public_id, '01J00000000000000000BATCH2', 1 );

		$sent   = $this->liveDelivery( $weekend, $first );
		$failed = $this->liveDelivery( $weekend, $second );
		$this->updateDelivery( $sent->public_id, array( 'state' => 'accepted' ) );
		$this->updateDelivery( $failed->public_id, array( 'error_class' => 'not_found', 'provider_result' => 'permanent_failure', 'retry_safety' => 'manual_allowed', 'state' => 'failed' ) );

		$this->activityAdmin();
		$html = $this->renderDecisions( array() );

		self::assertSame( 2, substr_count( $html, 'Rye starter feeding schedule' ) );
		self::assertSame( 1, substr_count( $html, 'Weekend opening times' ) );
		self::assertStringContainsString( 'Matched for Example Social and Example Social.', $html );
		self::assertStringContainsString( '1 sent, 1 failed', $html );
		self::assertStringContainsString( 'pq-decision-status--bad', $html );
		self::assertStringContainsString( 'All <b>3</b>', $html );
		self::assertStringContainsString( 'Matched <b>3</b>', $html );

		$matched = $this->renderDecisions( array( 'outcome' => 'shared' ) );
		self::assertStringContainsString( 'aria-current="true">Matched <b>3</b>', $matched );
	}

	/**
	 * Record one channel decision for an item of a Share old posts batch.
	 *
	 * @param int        $post_id Post ID.
	 * @param RuleRecord $rule Rule.
	 * @param string     $channel_public_id Channel public ID.
	 * @param string     $batch_public_id Batch public ID.
	 * @param int        $position Item position.
	 */
	private function recordBatchDecision( int $post_id, RuleRecord $rule, string $channel_public_id, string $batch_public_id, int $position ): void {
		global $wpdb;

		$intent = hash( 'sha256', 'intent-' . $batch_public_id . '-' . (string) $post_id . '-' . $channel_public_id );
		( new DecisionRepository() )->create(
			array(
				'author_id'             => 1,
				'decision_key'          => hash( 'sha256', 'decision-' . $intent ),
				'event_key'             => hash( 'sha256', 'event-' . $intent ),
				'event_mode'            => 'runtime',
				'intent_occurrence_key' => $intent,
				'post_id'               => $post_id,
				'post_status'           => 'publish',
				'post_type'             => 'post',
				'reasons'               => array( array( 'code' => 'included_destination' ) ),
				'result'                => 'included',
				'revision_fingerprint'  => hash( 'sha256', 'revision-' . (string) $post_id ),
				'rule_id'               => $rule->id,
				'rule_public_id'        => $rule->public_id,
				'rule_version'          => $rule->version,
				'scope_public_id'       => $channel_public_id,
				'scope_type'            => 'channel',
				'snapshot'              => array( 'post' => array( 'id' => $post_id ) ),
				'trigger'               => 'bulk',
			)
		);

		$wpdb->insert(
			Database::from_globals()->table( Schema::BATCH_ITEMS ),
			array(
				'batch_id'              => 1,
				'batch_public_id'       => $batch_public_id,
				'channel_public_id'     => $channel_public_id,
				'created_at'            => '2026-08-24 12:00:00',
				'intent_occurrence_key' => $intent,
				'item_key'              => hash( 'sha256', 'item-' . $intent ),
				'position'              => $position,
				'post_id'               => $post_id,
				'preview_item_json'     => '{}',
				'public_id'             => strtoupper( substr( '01J' . hash( 'sha256', 'public-' . $intent ), 0, 26 ) ),
				'reason_json'           => '{}',
				'semantic_preview_hash' => hash( 'sha256', 'semantic-' . $intent ),
				'state'                 => 'queued',
				'updated_at'            => '2026-08-24 12:00:00',
			)
		);
	}

	/**
	 * Submit one admin-post handler and return where it redirected.
	 *
	 * @param callable             $handler Handler.
	 * @param string               $nonce_action Nonce action.
	 * @param array<string,string> $post POST fields.
	 * @return string
	 */
	private function submit( callable $handler, string $nonce_action, array $post ): string {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_GET                      = array();
		$_POST                     = array_merge( $post, array( '_wpnonce' => wp_create_nonce( $nonce_action ) ) );
		$_REQUEST                  = $_POST;

		remove_all_filters( 'wp_redirect' );
		add_filter(
			'wp_redirect',
			static function ( $location ): void {
				throw new \RuntimeException( 'pressqueue-test-redirect:' . (string) $location );
			}
		);

		try {
			$handler();
		} catch ( \RuntimeException $exception ) {
			if ( ! str_starts_with( $exception->getMessage(), 'pressqueue-test-redirect:' ) ) {
				throw $exception;
			}

			return substr( $exception->getMessage(), strlen( 'pressqueue-test-redirect:' ) );
		} finally {
			remove_all_filters( 'wp_redirect' );
			$_POST                     = array();
			$_REQUEST                  = array();
			$_SERVER['REQUEST_METHOD'] = 'GET';
		}

		self::fail( 'The handler did not redirect.' );
	}

	/**
	 * Return the query arguments of a redirect location.
	 *
	 * @param string $location Location.
	 * @return array<string,string>
	 */
	private function queryArgs( string $location ): array {
		$query = (string) wp_parse_url( $location, PHP_URL_QUERY );
		$args  = array();
		parse_str( $query, $args );

		return array_map( 'strval', $args );
	}

	/**
	 * Create an authenticated command user with one capability.
	 *
	 * @param string $capability Capability.
	 * @return int
	 */
	private function commandUser( string $capability ): int {
		$user_id = (int) self::factory()->user->create();
		$user = get_userdata( $user_id );
		self::assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( $capability );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * Capture one JSON command response.
	 *
	 * @param callable():int $callback Callback.
	 * @return array{0:int,1:array<string,mixed>}
	 */
	private function captureCommand( callable $callback ): array {
		ob_start();
		$status = $callback();
		$output = ob_get_clean();

		self::assertIsString( $output );
		$decoded = json_decode( trim( $output ), true, 512, JSON_THROW_ON_ERROR );
		self::assertIsArray( $decoded );

		return array( $status, $decoded );
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
		if ( null === get_post( $post_id ) ) {
			wp_insert_post( array( 'import_id' => $post_id, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Fixture post' ) );
		}
		$destination = $destination ?? $this->enabledDestination();
		$rule        = ( new RuleRepository() )->create( $this->ruleInput( $destination['channel']->public_id ) );
		$rules = new RuleRepository();
		$rule = $rules->mark_version_previewed( $rule->public_id, $rule->lock_version, $rule->version );
		$rule = $rules->update( $rule->public_id, $rule->lock_version, array( 'enabled' => true ) );
		( new SettingsRepository() )->set_dry_run_enabled( false );
		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish', 'post_password' => '' ) );
		delete_post_meta( $post_id, '_pressqueue_policy' );
		delete_post_meta( $post_id, '_pressqueue_controls' );
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

	/**
	 * Create an administrator with every PressQueue capability.
	 *
	 * @return int
	 */
	private function activityAdmin(): int {
		$user_id = (int) self::factory()->user->create(
			array(
				'display_name' => 'Mara Lindqvist',
				'role'         => 'administrator',
			)
		);
		$user    = get_userdata( $user_id );
		self::assertInstanceOf( \WP_User::class, $user );
		foreach ( Capabilities::names() as $capability ) {
			$user->add_cap( $capability );
		}
		wp_set_current_user( 0 );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * Create a published post with a known ID and title.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $title Title.
	 * @return int
	 */
	private function titledPost( int $post_id, string $title ): int {
		wp_insert_post(
			array(
				'import_id'   => $post_id,
				'post_status' => 'publish',
				'post_title'  => $title,
				'post_type'   => 'post',
			)
		);

		return $post_id;
	}

	/**
	 * Render the Activity page.
	 *
	 * @param array<string,string> $get Query args.
	 * @return string
	 */
	private function renderActivity( array $get ): string {
		$_GET = array_merge( array( 'page' => DeliveryListController::SLUG ), $get );
		ob_start();
		DeliveryListController::render_page();

		return (string) ob_get_clean();
	}

	/**
	 * Render the decisions tab.
	 *
	 * @param array<string,string> $get Query args.
	 * @return string
	 */
	private function renderDecisions( array $get ): string {
		$_GET = array_merge(
			array(
				'page' => DeliveryListController::SLUG,
				'tab'  => 'decisions',
			),
			$get
		);
		ob_start();
		DecisionsController::render_tab();

		return (string) ob_get_clean();
	}

	/**
	 * Render share details.
	 *
	 * @param string $delivery_public_id Delivery public ID.
	 * @return string
	 */
	private function renderDetail( string $delivery_public_id ): string {
		$_GET = array(
			'delivery_public_id' => $delivery_public_id,
			'page'               => DeliveryDetailController::SLUG,
		);
		ob_start();
		DeliveryDetailController::render_page();

		return (string) ob_get_clean();
	}

	/**
	 * Return the stored state of a delivery.
	 *
	 * @param string $delivery_public_id Delivery public ID.
	 * @return string
	 */
	private function storedState( string $delivery_public_id ): string {
		$stored = ( new DeliveryRepository() )->find_by_public_id( $delivery_public_id );
		self::assertNotNull( $stored );

		return $stored->state;
	}

	/**
	 * Sort state counts for stable comparison.
	 *
	 * @param array<string,int> $counts Counts.
	 * @return array<string,int>
	 */
	private function sortedCounts( array $counts ): array {
		ksort( $counts );

		return $counts;
	}

	/**
	 * Record one decision for the decisions tab.
	 *
	 * @param int                            $post_id Post ID.
	 * @param string                         $result Result.
	 * @param RuleRecord|null                $rule Rule.
	 * @param string|null                    $channel_public_id Channel public ID for channel scope.
	 * @param array<int,array<string,mixed>> $reasons Reasons.
	 * @param string                         $mode Event mode.
	 * @param string                         $salt Decision key salt.
	 */
	private function recordDecision( int $post_id, string $result, ?RuleRecord $rule, ?string $channel_public_id, array $reasons, string $mode = 'runtime', string $salt = '' ): void {
		( new DecisionRepository() )->create(
			array(
				'author_id'            => 1,
				'decision_key'         => hash( 'sha256', 'decision-' . (string) $post_id . '-' . $result . '-' . $salt . '-' . $mode ),
				'event_key'            => hash( 'sha256', 'activity-event-' . (string) $post_id . '-' . $mode ),
				'event_mode'           => $mode,
				'post_id'              => $post_id,
				'post_status'          => 'publish',
				'post_type'            => 'post',
				'reasons'              => $reasons,
				'result'               => $result,
				'revision_fingerprint' => hash( 'sha256', 'revision-' . (string) $post_id ),
				'rule_id'              => null === $rule ? null : $rule->id,
				'rule_public_id'       => null === $rule ? null : $rule->public_id,
				'rule_version'         => null === $rule ? null : $rule->version,
				'scope_public_id'      => $channel_public_id,
				'scope_type'           => null === $channel_public_id ? 'global' : 'channel',
				'snapshot'             => array( 'post' => array( 'id' => $post_id ) ),
				'trigger'              => 'first_publish',
			)
		);
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
