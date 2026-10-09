<?php
/**
 * Privacy and security contract tests.
 *
 * @package PressQueue\Tests\Unit
 */

declare(strict_types=1);

namespace PressQueue\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use PressQueue\Delivery\DeliveryDisplaySnapshot;
use PressQueue\Privacy\ExportCursor;
use PressQueue\Privacy\ExportRedactor;
use PressQueue\Privacy\ExportService;
use PressQueue\Privacy\PersonalDataExporter;
use PressQueue\Privacy\PrivacySettings;
use PressQueue\Privacy\RetentionStateStore;
use PressQueue\Privacy\UninstallPolicy;
use PressQueue\Runtime\RuntimeDisabledLatch;
use PressQueue\Scheduler\ActionSchedulerQueue;
use PressQueue\Security\Capabilities;
use PressQueue\Security\SafeOperatorError;
use PressQueue\Storage\Schema;
use PressQueue\Storage\StaleRecordException;
use PressQueue\Storage\ValidationException;
use RuntimeException;

use function pressqueue_test_reset_wordpress_stubs;

/**
 * @covers \PressQueue\Privacy\ExportCursor
 * @covers \PressQueue\Privacy\ExportRedactor
 * @covers \PressQueue\Privacy\ExportService
 * @covers \PressQueue\Privacy\PersonalDataExporter
 * @covers \PressQueue\Privacy\PrivacySettings
 * @covers \PressQueue\Privacy\RetentionStateStore
 * @covers \PressQueue\Privacy\UninstallPolicy
 * @covers \PressQueue\Runtime\RuntimeDisabledLatch
 * @covers \PressQueue\Delivery\DeliveryDisplaySnapshot
 * @covers \PressQueue\Security\Capabilities
 * @covers \PressQueue\Security\SafeOperatorError
 */
final class PrivacySecurityContractsTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		pressqueue_test_reset_wordpress_stubs();
	}

	public function test_malformed_kill_switch_is_enabled(): void {
		update_option( \PressQueue\Storage\SettingsRepository::KILL_SWITCH_OPTION, 'invalid', false );
		self::assertTrue( ( new \PressQueue\Storage\SettingsRepository() )->is_kill_switch_enabled() );
	}

	public function test_runtime_latch_is_strict_and_missing_values_are_closed(): void {
		self::assertTrue( RuntimeDisabledLatch::is_closed() );

		RuntimeDisabledLatch::open();
		self::assertSame( '0', get_option( RuntimeDisabledLatch::OPTION ) );
		self::assertTrue( RuntimeDisabledLatch::is_open() );

		RuntimeDisabledLatch::close();
		self::assertSame( '1', get_option( RuntimeDisabledLatch::OPTION ) );
		self::assertTrue( RuntimeDisabledLatch::is_closed() );

		update_option( RuntimeDisabledLatch::OPTION, 'yes', false );
		self::assertTrue( RuntimeDisabledLatch::is_malformed() );
		self::assertTrue( RuntimeDisabledLatch::is_closed() );

		$this->expectException( ValidationException::class );
		RuntimeDisabledLatch::assert_open_for( 'testing' );
	}

	public function test_privacy_settings_defaults_are_non_autoloaded_and_strict(): void {
		PrivacySettings::ensure_defaults_for_current_site();

		self::assertSame( PrivacySettings::defaults(), get_option( PrivacySettings::OPTION ) );
		self::assertFalse( $GLOBALS['pressqueue_test_option_autoloads'][ PrivacySettings::OPTION ] );

		update_option(
			PrivacySettings::OPTION,
			array(
				'delete_data_on_uninstall' => false,
				'retention_days'           => 365,
				'retention_enabled'        => 'yes',
				'schema_version'           => 1,
			),
			false
		);

		$this->expectException( ValidationException::class );
		( new PrivacySettings() )->read();
	}

	public function test_retention_state_failures_store_classification_without_raw_message(): void {
		$settings                      = PrivacySettings::defaults();
		$settings['retention_enabled'] = true;
		$store                         = new RetentionStateStore();
		$run                           = $store->acquire( $settings );

		self::assertIsArray( $run );

		$store->fail( $run, new RuntimeException( 'secret token ABCDEFGHIJKLMNOPQRSTUVWXYZ123456' ) );

		$state = $store->read();

		self::assertNull( $state['active_run'] );
		self::assertSame( 'failed', $state['last_completion']['state'] );
		self::assertSame( 'operator_error', $state['last_completion']['summary']['classification'] );
		self::assertArrayNotHasKey( 'message', $state['last_completion']['summary'] );
		self::assertStringNotContainsString( 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', wp_json_encode( $state ) );
	}

	public function test_retention_progress_requires_current_lease(): void {
		$settings                      = PrivacySettings::defaults();
		$settings['retention_enabled'] = true;
		$store                         = new RetentionStateStore();
		$run                           = $store->acquire( $settings );

		self::assertIsArray( $run );

		$state                           = $store->read();
		$state['active_run']['lease_id'] = str_repeat( 'a', 32 );
		update_option( RetentionStateStore::OPTION, $state, false );

		$this->expectException( StaleRecordException::class );
		$store->progress(
			$run,
			array(
				'family'        => 'delivery_events',
				'public_id'     => '01K8Z8D0PKH2N8J7S3K5A9C6DE',
				'timestamp_utc' => '2026-08-25 12:00:00',
			),
			$store->empty_counters()
		);
	}

	public function test_malformed_retention_state_is_rejected(): void {
		update_option(
			RetentionStateStore::OPTION,
			array(
				'active_run'     => array(
					'cursor' => array(
						'family' => 'unknown',
					),
				),
				'schema_version' => 1,
			),
			false
		);

		$this->expectException( ValidationException::class );
		( new RetentionStateStore() )->read();
	}

	public function test_export_redactor_excludes_secrets_and_rendered_payloads_by_default(): void {
		$redactor = new ExportRedactor();
		$value    = array(
			'credential_envelope_json' => array( 'ciphertext' => 'secret' ),
			'credential_envelope_hash' => str_repeat( 'a', 64 ),
			'payload'                  => array(
				'final_encoded_text' => 'Rendered text',
				'media_snapshot'     => array(
					'signed_url' => 'https://example.test/private.jpg',
				),
				'payload_hash'       => str_repeat( 'b', 64 ),
			),
		);

		$default = $redactor->redact_value( $value, false );
		self::assertTrue( $default['credential_envelope_json']['pressqueue_redacted'] );
		self::assertSame( str_repeat( 'a', 64 ), $default['credential_envelope_hash'] );
		self::assertTrue( $default['payload']['final_encoded_text']['pressqueue_redacted'] );
		self::assertTrue( $default['payload']['media_snapshot']['signed_url']['pressqueue_redacted'] );
		self::assertSame( str_repeat( 'b', 64 ), $default['payload']['payload_hash'] );

		$expanded = $redactor->redact_value( $value, true );
		self::assertSame( 'Rendered text', $expanded['payload']['final_encoded_text'] );
		self::assertSame( 'https://example.test/private.jpg', $expanded['payload']['media_snapshot']['signed_url'] );
	}

	public function test_export_cursor_round_trips_stable_values(): void {
		$cursor = new ExportCursor( 'deliveries', '2026-08-25 12:00:00', '01K8Z8D0PKH2N8J7S3K5A9C6DE' );
		$parsed = ExportCursor::from_string( $cursor->to_string() );

		self::assertInstanceOf( ExportCursor::class, $parsed );
		self::assertSame( $cursor->to_array(), $parsed->to_array() );
		self::assertSame( '2026-08-25T12:00:00Z', $parsed->to_array()['timestamp_utc'] );
		self::assertArrayNotHasKey( 'timestamp', $parsed->to_array() );
		self::assertSame( $cursor->to_array(), ExportCursor::from_string( wp_json_encode( $cursor->to_array() ) )->to_array() );
	}

	public function test_administrator_export_uses_required_schema_and_redaction(): void {
		PrivacySettings::ensure_defaults_for_current_site();
		RetentionStateStore::ensure_defaults_for_current_site();
		update_option( Schema::VERSION_OPTION, Schema::TARGET_VERSION, false );
		update_option( Capabilities::VERSION_OPTION, Capabilities::TARGET_VERSION, false );
		$GLOBALS['wpdb'] = new PrivacyExportWpdb();

		$service = new ExportService();
		$page    = $service->page( null, false, 10, 100000 );

		self::assertSame( 1, $page['metadata']['schema_version'] );
		self::assertArrayHasKey( 'redaction', $page );
		self::assertTrue( $page['redaction']['credentials_excluded'] );
		self::assertContains( 'actor_user', $page['redaction']['classes_applied'] );
		self::assertArrayHasKey( 'settings', $page['tables'] );
		self::assertArrayNotHasKey( 'settings', $page );
		self::assertArrayNotHasKey( 'credential_envelope_hash', $page['tables']['connections'][0] );
		self::assertSame( '2026-08-20T10:00:00Z', $page['tables']['deliveries'][0]['lease_expires_at'] );
		self::assertSame( 'actor_user', $page['tables']['delivery_events'][0]['actor_user_id']['reason'] );
		self::assertStringContainsString( '`updated_at` <=', implode( "\n", $GLOBALS['wpdb']->queries ) );

		$truncated = $service->page( null, false, 1, 100000 );
		self::assertTrue( $truncated['truncated'] );
		self::assertIsArray( $truncated['next_cursor'] );
		self::assertArrayHasKey( 'timestamp_utc', $truncated['next_cursor'] );

		$stream = $service->page_stream( null, false, 10, 100000 );
		self::assertIsResource( $stream );
		$decoded = json_decode( stream_get_contents( $stream ), true );
		fclose( $stream );
		self::assertSame( 1, $decoded['metadata']['schema_version'] );
	}

	public function test_capability_schema_includes_dedicated_export_capability(): void {
		self::assertSame( 5, Capabilities::TARGET_VERSION );
		self::assertContains( Capabilities::EXPORT_DATA, Capabilities::names() );

		Capabilities::grant_to_administrators();

		self::assertTrue( get_role( 'administrator' )->has_cap( Capabilities::EXPORT_DATA ) );
	}

	public function test_personal_data_exporter_uses_required_groups_and_total_page_size(): void {
		$GLOBALS['wpdb'] = new PersonalDataExportWpdb();

		$first = PersonalDataExporter::export( 'user@example.test', 1 );
		self::assertCount( 100, $first['data'] );
		self::assertFalse( $first['done'] );
		self::assertSame( array( 'pressqueue-decisions' ), array_values( array_unique( array_column( $first['data'], 'group_id' ) ) ) );

		$second = PersonalDataExporter::export( 'user@example.test', 2 );
		self::assertCount( 40, $second['data'] );
		self::assertTrue( $second['done'] );
		self::assertSame(
			array( 'pressqueue-decisions', 'pressqueue-deliveries', 'pressqueue-batches' ),
			array_values( array_unique( array_column( $second['data'], 'group_id' ) ) )
		);
		self::assertContains( 'PressQueue delivery history', array_column( $second['data'], 'group_label' ) );
	}

	public function test_safe_operator_error_does_not_expose_arbitrary_throwable_text(): void {
		$message = SafeOperatorError::message( new RuntimeException( 'database password abcdefghijklmnopqrstuvwxyz123456' ) );

		self::assertStringNotContainsString( 'password', $message );
		self::assertSame( 'operator_error', SafeOperatorError::classification( new RuntimeException( 'boom' ) ) );
		self::assertSame( 'REST request body must be a JSON object.', SafeOperatorError::message( new ValidationException( 'REST request body must be a JSON object.' ) ) );
		self::assertStringNotContainsString( 'token', SafeOperatorError::message( new ValidationException( 'token leaked in validation' ) ) );
		self::assertSame( '', SafeOperatorError::notice_detail( 'Re-enter your key at an attacker site.' ) );
		self::assertSame( '', SafeOperatorError::notice_detail( 'Failure classification: attacker.' ) );
		self::assertSame( 'Rule version is stale.', SafeOperatorError::notice_detail( 'Rule version is stale.' ) );
	}

	public function test_capability_upgrades_preserve_revocations_and_only_add_new_capabilities(): void {
		Capabilities::grant_to_administrators();
		$role = get_role( 'administrator' );
		$role->remove_cap( Capabilities::MANAGE_CONNECTIONS );
		Capabilities::grant_to_administrators();
		self::assertFalse( $role->has_cap( Capabilities::MANAGE_CONNECTIONS ) );
		update_option( Capabilities::VERSION_OPTION, 4, false );
		$role->remove_cap( Capabilities::EXPORT_DATA );
		Capabilities::grant_to_administrators();
		self::assertFalse( $role->has_cap( Capabilities::MANAGE_CONNECTIONS ) );
		self::assertTrue( $role->has_cap( Capabilities::EXPORT_DATA ) );
	}

	public function test_scheduler_repairs_daily_retention_action(): void {
		RuntimeDisabledLatch::open();

		ActionSchedulerQueue::ensure_recurring_actions();

		self::assertNotFalse( as_next_scheduled_action( ActionSchedulerQueue::SWEEP_HOOK, array(), ActionSchedulerQueue::GROUP ) );
		self::assertNotFalse( as_next_scheduled_action( ActionSchedulerQueue::RETENTION_HOOK, array(), ActionSchedulerQueue::GROUP ) );
	}

	public function test_admin_delivery_timestamps_use_site_timezone(): void {
		$GLOBALS['pressqueue_test_timezone'] = 'Europe/Berlin';

		self::assertSame(
			'2026-01-01 01:00:00 CET',
			DeliveryDisplaySnapshot::format_timestamp( new DateTimeImmutable( '2026-01-01 00:00:00 UTC' ) )
		);
	}

	public function test_uninstall_preserves_data_by_default_but_unschedules_actions(): void {
		$GLOBALS['wpdb'] = new \wpdb();
		PrivacySettings::ensure_defaults_for_current_site();
		Capabilities::grant_to_administrators();
		as_schedule_single_action( time(), ActionSchedulerQueue::WORKER_HOOK, array( 'delivery-1' ), ActionSchedulerQueue::GROUP );

		UninstallPolicy::uninstall();

		self::assertSame( array(), $GLOBALS['pressqueue_test_as_actions'] );
		self::assertSame( array(), $GLOBALS['wpdb']->queries );
		self::assertSame( PrivacySettings::defaults(), get_option( PrivacySettings::OPTION ) );
		self::assertFalse( get_role( 'administrator' )->has_cap( Capabilities::EXPORT_DATA ) );
	}

	public function test_uninstall_deletes_owned_data_when_enabled(): void {
		$GLOBALS['wpdb']                      = new \wpdb();
		$settings                             = PrivacySettings::defaults();
		$settings['delete_data_on_uninstall'] = true;
		update_option( PrivacySettings::OPTION, $settings, false );
		Capabilities::grant_to_administrators();

		UninstallPolicy::uninstall();

		$queries = implode( "\n", $GLOBALS['wpdb']->queries );

		self::assertStringContainsString( 'DELETE FROM `wp_postmeta`', $queries );
		self::assertStringContainsString( '_pressqueue_controls', $queries );
		self::assertStringContainsString( '_pressqueue_policy', $queries );
		self::assertStringContainsString( '_pressqueue_first_publish_seen_at', $queries );
		self::assertStringContainsString( '_pressqueue_last_runtime_fingerprint', $queries );
		self::assertStringContainsString( '_pressqueue_last_runtime_trigger_fingerprint', $queries );
		self::assertStringContainsString( '_pressqueue_last_delivery_revision_fingerprint', $queries );
		self::assertStringContainsString( 'DELETE FROM `wp_options`', $queries );
		self::assertStringContainsString( 'DROP TABLE IF EXISTS `wp_pressqueue_deliveries`', $queries );
		self::assertFalse( get_role( 'administrator' )->has_cap( Capabilities::EXPORT_DATA ) );
	}
}

/**
 * Fake database for administrator export contract tests.
 */
final class PrivacyExportWpdb extends \wpdb {
	/**
	 * Return associative rows for export queries.
	 *
	 * @param string $query Query.
	 * @param mixed  $output Output format.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_results( $query, $output = null ): array {
		unset( $output );

		$this->queries[] = (string) $query;

		if ( false !== strpos( (string) $query, 'FROM `wp_pressqueue_connections`' ) ) {
			return array(
				array(
					'account_json'           => '{"token":"secret","name":"Example"}',
					'connection_status'      => 'verified',
					'created_at'             => '2026-08-20 09:00:00',
					'credential_rotated_at'  => null,
					'credential_verified_at' => '2026-08-20 09:30:00',
					'enabled'                => '1',
					'label'                  => 'Primary',
					'last_error_json'        => '{"message":"provider secret"}',
					'last_sync_completed_at' => null,
					'last_sync_started_at'   => null,
					'provider'               => 'buffer',
					'public_id'              => '01K8Z8D0PKH2N8J7S3K5A9C6DE',
					'remote_account_id'      => 'acct_1',
					'remote_organization_id' => '',
					'updated_at'             => '2026-08-20 09:30:00',
					'verified_identity_hash' => str_repeat( 'a', 64 ),
				),
			);
		}

		if ( false !== strpos( (string) $query, 'FROM `wp_pressqueue_deliveries`' ) ) {
			return array(
				array(
					'action_id'                        => '44',
					'adapter_encoding_version'         => '1',
					'attempt_count'                    => '0',
					'automatic_attempt_count'          => '0',
					'bound_capability_catalog_version' => 'catalog',
					'bound_capability_hash'            => str_repeat( 'b', 64 ),
					'bound_capability_schema_version'  => '2',
					'bound_channel_public_id'          => '01K8Z8D0PKH2N8J7S3K5A9C6DF',
					'bound_connection_public_id'       => '01K8Z8D0PKH2N8J7S3K5A9C6DG',
					'bound_provider'                   => 'buffer',
					'bound_remote_account_id'          => 'acct_1',
					'bound_remote_channel_id'          => 'chan_1',
					'bound_remote_organization_id'     => '',
					'channel_public_id'                => '01K8Z8D0PKH2N8J7S3K5A9C6DF',
					'connection_public_id'             => '01K8Z8D0PKH2N8J7S3K5A9C6DG',
					'created_at'                       => '2026-08-20 09:00:00',
					'decision_public_id'               => '01K8Z8D0PKH2N8J7S3K5A9C6DH',
					'delivery_key'                     => 'delivery-key',
					'delivery_mode'                    => 'dry_run',
					'error_class'                      => null,
					'error_detail_json'                => '{"message":"secret diagnostic"}',
					'event_key'                        => 'event-key',
					'intent_occurrence_key'            => 'intent-key',
					'last_attempt_at'                  => null,
					'lease_expires_at'                 => '2026-08-20 10:00:00',
					'media_identity_hash'              => str_repeat( 'c', 64 ),
					'media_snapshot_json'              => '{"signed_url":"https://example.test/private.jpg"}',
					'next_attempt_at'                  => null,
					'payload_hash'                     => str_repeat( 'd', 64 ),
					'payload_preview_json'             => '{"final_encoded_text":"Rendered"}',
					'payload_schema_version'           => '1',
					'provider'                         => 'buffer',
					'provider_result'                  => 'dry_run',
					'public_id'                        => '01K8Z8D0PKH2N8J7S3K5A9C6DI',
					'queued_at'                        => null,
					'receipt_json'                     => '{"token":"secret"}',
					'reconciliation_note'              => null,
					'reconciliation_status'            => 'none',
					'reconciled_at'                    => null,
					'reconciled_by'                    => null,
					'remote_channel_id'                => 'chan_1',
					'remote_id'                        => null,
					'remote_receipt_key'               => null,
					'retry_safety'                     => 'not_retryable',
					'rule_public_id'                   => '01K8Z8D0PKH2N8J7S3K5A9C6DJ',
					'rule_version'                     => '1',
					'schedule_slot'                    => 'immediate',
					'schedule_timezone'                => 'UTC',
					'scheduled_for_utc'                => null,
					'state'                            => 'dry_run',
					'state_changed_at'                 => '2026-08-20 09:00:00',
					'updated_at'                       => '2026-08-20 09:00:00',
				),
			);
		}

		if ( false !== strpos( (string) $query, 'FROM `wp_pressqueue_delivery_events`' ) ) {
			return array(
				array(
					'action_id'          => null,
					'actor_type'         => 'user',
					'actor_user_id'      => '1',
					'attempt_number'     => null,
					'created_at'         => '2026-08-20 09:00:00',
					'delivery_public_id' => '01K8Z8D0PKH2N8J7S3K5A9C6DI',
					'detail_json'        => '{"token":"secret"}',
					'event_type'         => 'created',
					'from_state'         => null,
					'provider_result'    => null,
					'public_id'          => '01K8Z8D0PKH2N8J7S3K5A9C6DK',
					'retry_safety'       => null,
					'to_state'           => 'dry_run',
				),
			);
		}

		return array();
	}
}

/**
 * Fake database for personal-data exporter pagination tests.
 */
final class PersonalDataExportWpdb extends \wpdb {
	/**
	 * Return count values for exporter family queries.
	 *
	 * @param string $query Query.
	 * @return string|null
	 */
	public function get_var( $query ): ?string {
		$this->queries[] = (string) $query;

		if ( false !== strpos( (string) $query, 'FROM `wp_pressqueue_deliveries` v' ) ) {
			return '10';
		}

		if ( false !== strpos( (string) $query, 'FROM `wp_pressqueue_batches`' ) ) {
			return '10';
		}

		if ( false !== strpos( (string) $query, 'FROM `wp_pressqueue_decisions`' ) ) {
			return '120';
		}

		return '0';
	}

	/**
	 * Return associative rows for exporter family queries.
	 *
	 * @param string $query Query.
	 * @param mixed  $output Output format.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_results( $query, $output = null ): array {
		unset( $output );

		$this->queries[] = (string) $query;
		$limit           = $this->query_number( (string) $query, 'LIMIT', 100 );
		$offset          = $this->query_number( (string) $query, 'OFFSET', 0 );

		if ( false !== strpos( (string) $query, 'FROM `wp_pressqueue_deliveries` v' ) ) {
			return array_slice( $this->delivery_rows(), $offset, $limit );
		}

		if ( false !== strpos( (string) $query, 'FROM `wp_pressqueue_batches`' ) ) {
			return array_slice( $this->batch_rows(), $offset, $limit );
		}

		if ( false !== strpos( (string) $query, 'FROM `wp_pressqueue_decisions`' ) ) {
			return array_slice( $this->decision_rows(), $offset, $limit );
		}

		return array();
	}

	/**
	 * Extract a numeric SQL clause value from a prepared query.
	 *
	 * @param string $query Query.
	 * @param string $clause Clause name.
	 * @param int    $fallback Fallback.
	 * @return int
	 */
	private function query_number( string $query, string $clause, int $fallback ): int {
		if ( 1 !== preg_match( '/' . preg_quote( $clause, '/' ) . ' ([0-9]+)/', $query, $matches ) ) {
			return $fallback;
		}

		return (int) $matches[1];
	}

	/**
	 * Return decision rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function decision_rows(): array {
		$rows = array();
		for ( $index = 1; $index <= 120; ++$index ) {
			$rows[] = array(
				'created_at'    => '2026-08-20 09:00:00',
				'event_mode'    => 'dry_run',
				'post_id'       => (string) $index,
				'post_status'   => 'publish',
				'post_type'     => 'post',
				'public_id'     => sprintf( 'decision-%03d', $index ),
				'reasons_json'  => '[]',
				'result'        => 'included',
				'snapshot_hash' => str_repeat( 'a', 64 ),
				'trigger'       => 'manual',
			);
		}

		return $rows;
	}

	/**
	 * Return delivery rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function delivery_rows(): array {
		$rows = array();
		for ( $index = 1; $index <= 10; ++$index ) {
			$rows[] = array(
				'created_at'            => '2026-08-20 10:00:00',
				'decision_public_id'    => sprintf( 'decision-%03d', $index ),
				'delivery_mode'         => 'dry_run',
				'payload_hash'          => str_repeat( 'b', 64 ),
				'provider'              => 'buffer',
				'public_id'             => sprintf( 'delivery-%03d', $index ),
				'reconciliation_status' => 'none',
				'remote_id'             => null,
				'schedule_slot'         => 'immediate',
				'state'                 => 'dry_run',
				'updated_at'            => '2026-08-20 10:00:00',
			);
		}

		return $rows;
	}

	/**
	 * Return batch rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function batch_rows(): array {
		$rows = array();
		for ( $index = 1; $index <= 10; ++$index ) {
			$rows[] = array(
				'batch_type'            => 'bulk',
				'completed_at'          => null,
				'confirmed_at'          => null,
				'confirmed_by'          => '1',
				'created_at'            => '2026-08-20 11:00:00',
				'created_by'            => '1',
				'preview_snapshot_hash' => str_repeat( 'c', 64 ),
				'public_id'             => sprintf( 'batch-%03d', $index ),
				'started_at'            => null,
				'status'                => 'draft',
				'updated_at'            => '2026-08-20 11:00:00',
			);
		}

		return $rows;
	}
}
