<?php
/**
 * Storage integration tests.
 *
 * @package PressQueue\Tests\Integration
 */

declare(strict_types=1);

namespace PressQueue\Tests\Integration;

use PressQueue\Provider\Dto\ProviderChannel;
use PressQueue\Security\Capabilities;
use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\Database;
use PressQueue\Storage\Record\ChannelRecord;
use PressQueue\Storage\Record\ConnectionRecord;
use PressQueue\Storage\Repository\ChannelRepository;
use PressQueue\Storage\Repository\ConnectionRepository;
use PressQueue\Storage\Repository\DecisionRepository;
use PressQueue\Storage\Repository\DeliveryRepository;
use PressQueue\Storage\Repository\RuleRepository;
use PressQueue\Storage\Schema;
use PressQueue\Storage\SchemaMigrator;
use PressQueue\Storage\SchemaStatus;
use PressQueue\Storage\SettingsRepository;
use PressQueue\Storage\StaleRecordException;
use PressQueue\Storage\StorageException;
use WP_UnitTestCase;

/**
 * @covers \PressQueue\Security\Capabilities
 * @covers \PressQueue\Storage\Database
 * @covers \PressQueue\Storage\Repository\AbstractRepository
 * @covers \PressQueue\Storage\Repository\ChannelRepository
 * @covers \PressQueue\Storage\Repository\ConnectionRepository
 * @covers \PressQueue\Storage\Repository\DecisionRepository
 * @covers \PressQueue\Storage\Repository\DeliveryRepository
 * @covers \PressQueue\Storage\Repository\RuleRepository
 * @covers \PressQueue\Storage\Schema
 * @covers \PressQueue\Storage\SchemaMigrator
 * @covers \PressQueue\Storage\SchemaStatus
 * @covers \PressQueue\Storage\SettingsRepository
 */
final class StorageIntegrationTest extends WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();

		$this->resetStorage();
	}

	protected function tearDown(): void {
		$this->resetStorage();

		parent::tearDown();
	}

	public function test_activation_creates_schema_settings_and_capabilities(): void {
		pressqueue_activate( false );

		$database = Database::from_globals();

		self::assertSame( Schema::TARGET_VERSION, (int) get_option( Schema::VERSION_OPTION, 0 ) );
		self::assertFalse( get_option( Schema::MIGRATION_LOCK_OPTION, false ) );
		self::assertFalse( get_option( Schema::MIGRATION_FAILED_OPTION, false ) );

		foreach ( Schema::table_suffixes() as $suffix ) {
			$table = $database->table( $suffix );
			self::assertSame( $table, $this->tableLike( $table ) );
		}

		$settings = ( new SettingsRepository() )->read();
		self::assertFalse( $settings->kill_switch_enabled );
		self::assertTrue( $settings->dry_run_enabled );
		self::assertSame( 'dry_run', $settings->delivery_mode );
		self::assertSame( 'dry_run', $settings->delivery_state );

		$this->assertOptionIsNotAutoloaded( Schema::VERSION_OPTION );
		$this->assertOptionIsNotAutoloaded( Capabilities::VERSION_OPTION );
		$this->assertOptionIsNotAutoloaded( SettingsRepository::KILL_SWITCH_OPTION );
		$this->assertOptionIsNotAutoloaded( SettingsRepository::DRY_RUN_OPTION );

		$administrator = get_role( 'administrator' );
		$editor        = get_role( 'editor' );

		self::assertNotNull( $administrator );
		self::assertNotNull( $editor );

		foreach ( Capabilities::names() as $capability ) {
			self::assertTrue( $administrator->has_cap( $capability ) );
			self::assertFalse( $editor->has_cap( $capability ) );
		}
	}

	public function test_migrations_are_rerunnable_and_preserve_data(): void {
		$migrator = new SchemaMigrator( Database::from_globals() );

		$migrator->migrate();

		$connections = new ConnectionRepository();
		$connection  = $connections->create( 'buffer', 'Local account', array( 'account' => 'example' ) );

		update_option( Schema::VERSION_OPTION, 0, false );

		$migrator->migrate();
		$migrator->migrate();

		self::assertSame( Schema::TARGET_VERSION, (int) get_option( Schema::VERSION_OPTION, 0 ) );
		self::assertNotNull( $connections->find_by_public_id( $connection->public_id ) );
		self::assertFalse( get_option( Schema::MIGRATION_LOCK_OPTION, false ) );
	}

	public function test_current_version_with_missing_tables_is_completed_on_retry(): void {
		$migrator = new SchemaMigrator( Database::from_globals() );
		$migrator->migrate();

		$database = Database::from_globals();
		$table    = $database->table( Schema::CHANNELS );

		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' );

		self::assertSame( Schema::TARGET_VERSION, (int) get_option( Schema::VERSION_OPTION, 0 ) );
		self::assertFalse( ( new SchemaStatus( $database ) )->is_ready_for_planning() );

		$migrator->migrate();

		self::assertSame( $table, $this->tableLike( $table ) );
		self::assertTrue( ( new SchemaStatus( $database ) )->is_ready_for_planning() );
	}

	public function test_stale_migration_locks_can_be_recovered_without_releasing_active_locks(): void {
		$migrator = new SchemaMigrator( Database::from_globals() );

		add_option(
			Schema::MIGRATION_LOCK_OPTION,
			array(
				'owner'      => 'active',
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			'',
			false
		);

		self::assertFalse( $migrator->recover_stale_lock() );
		self::assertNotFalse( get_option( Schema::MIGRATION_LOCK_OPTION, false ) );

		update_option(
			Schema::MIGRATION_LOCK_OPTION,
			array(
				'owner'      => 'stale',
				'created_at' => gmdate( 'Y-m-d H:i:s', time() - Schema::LOCK_TTL_SECONDS - 1 ),
			),
			false
		);

		self::assertTrue( $migrator->recover_stale_lock() );
		self::assertFalse( get_option( Schema::MIGRATION_LOCK_OPTION, false ) );
	}

	public function test_lock_delete_preserves_a_replaced_owner(): void {
		$database = Database::from_globals();
		$stale    = array(
			'owner'      => 'stale',
			'created_at' => gmdate( 'Y-m-d H:i:s', time() - Schema::LOCK_TTL_SECONDS - 1 ),
		);
		$active   = array(
			'owner'      => 'active',
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
		);

		add_option( Schema::MIGRATION_LOCK_OPTION, $active, '', false );

		self::assertFalse( $database->delete_option_if_value( Schema::MIGRATION_LOCK_OPTION, $stale ) );
		self::assertSame( $active, get_option( Schema::MIGRATION_LOCK_OPTION, false ) );

		self::assertTrue( $database->delete_option_if_value( Schema::MIGRATION_LOCK_OPTION, $active ) );
		self::assertFalse( get_option( Schema::MIGRATION_LOCK_OPTION, false ) );
	}

	public function test_table_shapes_match_the_storage_contract(): void {
		( new SchemaMigrator( Database::from_globals() ) )->migrate();

		$database = Database::from_globals();

		foreach ( Schema::expected_shape() as $suffix => $shape ) {
			$table   = $database->table( $suffix );
			$columns = $this->columns( $table );
			$indexes = $this->indexes( $table );

			self::assertSame( array_keys( $shape['columns'] ), array_keys( $columns ), $suffix . ' columns changed.' );

			foreach ( $shape['indexes'] as $index_name => $index ) {
				self::assertArrayHasKey( $index_name, $indexes, $suffix . ' is missing index ' . $index_name );
				self::assertSame( $index['columns'], $indexes[ $index_name ]['columns'], $suffix . ' index columns changed for ' . $index_name );
				self::assertSame( $index['unique'] ? 0 : 1, $indexes[ $index_name ]['non_unique'], $suffix . ' index uniqueness changed for ' . $index_name );
			}
		}

		$connection_indexes = $this->indexes( $database->table( Schema::CONNECTIONS ) );
		$decision_indexes   = $this->indexes( $database->table( Schema::DECISIONS ) );
		$delivery_indexes   = $this->indexes( $database->table( Schema::DELIVERIES ) );
		self::assertSame( 0, $connection_indexes['public_id']['non_unique'] );
		self::assertSame( 0, $decision_indexes['decision_key']['non_unique'] );
		self::assertSame( 0, $delivery_indexes['delivery_key']['non_unique'] );

		$connections = $this->columns( $database->table( Schema::CONNECTIONS ) );
		self::assertSame( 'NO', $connections['id']->Null );
		self::assertStringStartsWith( 'bigint unsigned', strtolower( $connections['id']->Type ) );
		self::assertSame( 'auto_increment', strtolower( $connections['id']->Extra ) );
		self::assertSame( '0', (string) $connections['enabled']->Default );

		$channels = $this->columns( $database->table( Schema::CHANNELS ) );
		self::assertSame( 'YES', $channels['last_synced_at']->Null );

		$rules = $this->columns( $database->table( Schema::RULES ) );
		self::assertSame( 'immediate', (string) $rules['schedule_policy']->Default );
		self::assertSame( '1', (string) $rules['version']->Default );
		self::assertSame( '1', (string) $rules['lock_version']->Default );

		$decisions = $this->columns( $database->table( Schema::DECISIONS ) );
		self::assertArrayNotHasKey( 'updated_at', $decisions );

		$this->assertAsciiBinaryColumn( $database->table( Schema::CONNECTIONS ), 'public_id' );
		$this->assertAsciiBinaryColumn( $database->table( Schema::DECISIONS ), 'decision_key' );
		$this->assertAsciiBinaryColumn( $database->table( Schema::DELIVERIES ), 'delivery_key' );
	}

	public function test_version_one_connection_and_channel_rows_upgrade_to_discovery_shape(): void {
		$migrator = new SchemaMigrator( Database::from_globals() );
		$migrator->migrate();

		$connections = new ConnectionRepository();
		$channels    = new ChannelRepository();
		$connection  = $connections->create( 'buffer', 'Legacy account', array(), true );
		$channel     = $channels->create( $connection->public_id, 'buffer', 'legacy-channel', 'mastodon', array( 'name' => 'Legacy' ), array(), true );
		$database    = Database::from_globals();

		$this->downgradeDiscoveryColumnsToVersionOne( $database );
		update_option( Schema::VERSION_OPTION, 1, false );

		$migrator->migrate();

		$upgraded_connection = $connections->find_by_public_id( $connection->public_id );
		$upgraded_channel    = $channels->find_by_public_id( $channel->public_id );

		self::assertNotNull( $upgraded_connection );
		self::assertNotNull( $upgraded_channel );
		self::assertFalse( $upgraded_connection->enabled );
		self::assertSame( 'disconnected', $upgraded_connection->connection_status );
		self::assertFalse( $upgraded_channel->enabled );
		self::assertSame( '', $upgraded_channel->remote_organization_id );
		self::assertSame( 'unverified', $upgraded_channel->availability );
		self::assertArrayNotHasKey( 'connection_remote_channel', $this->indexes( $database->table( Schema::CHANNELS ) ) );
		self::assertArrayHasKey( 'connection_org_remote_channel', $this->indexes( $database->table( Schema::CHANNELS ) ) );
	}

	public function test_partial_discovery_migration_retry_completes_remaining_steps(): void {
		$migrator = new SchemaMigrator( Database::from_globals() );
		$migrator->migrate();

		$connections = new ConnectionRepository();
		$channels    = new ChannelRepository();
		$connection  = $connections->create( 'buffer', 'Legacy account', array(), true );
		$channel     = $channels->create( $connection->public_id, 'buffer', 'legacy-channel', 'mastodon', array( 'name' => 'Legacy' ), array(), true );
		$database    = Database::from_globals();
		$conn_table  = $database->table( Schema::CONNECTIONS );
		$chan_table  = $database->table( Schema::CHANNELS );

		$this->downgradeDiscoveryColumnsToVersionOne( $database );
		$this->addColumnIfMissing( $conn_table, 'credential_envelope_json', 'credential_envelope_json LONGTEXT NULL AFTER account_json' );
		$this->addColumnIfMissing( $conn_table, 'credential_envelope_hash', 'credential_envelope_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER credential_envelope_json' );
		$this->addColumnIfMissing( $chan_table, 'remote_organization_id', "remote_organization_id VARCHAR(191) NOT NULL DEFAULT '' AFTER provider" );
		update_option( Schema::VERSION_OPTION, 1, false );

		$migrator->migrate();

		$upgraded_connection = $connections->find_by_public_id( $connection->public_id );
		$upgraded_channel    = $channels->find_by_public_id( $channel->public_id );

		self::assertSame( Schema::TARGET_VERSION, (int) get_option( Schema::VERSION_OPTION, 0 ) );
		self::assertFalse( get_option( Schema::MIGRATION_FAILED_OPTION, false ) );
		self::assertNotNull( $upgraded_connection );
		self::assertNotNull( $upgraded_channel );
		self::assertFalse( $upgraded_connection->enabled );
		self::assertSame( 'disconnected', $upgraded_connection->connection_status );
		self::assertFalse( $upgraded_channel->enabled );
		self::assertSame( 'unverified', $upgraded_channel->availability );
		self::assertArrayNotHasKey( 'connection_remote_channel', $this->indexes( $chan_table ) );
		self::assertArrayHasKey( 'sync_lease_expires_at', $this->indexes( $conn_table ) );
		self::assertArrayHasKey( 'connection_org_remote_channel', $this->indexes( $chan_table ) );
	}

	public function test_delivery_payload_validation_runs_before_queue_schema_expansion(): void {
		$migrator = new SchemaMigrator( Database::from_globals() );
		$migrator->migrate();

		$destination = $this->enabledDestination();
		$rule        = ( new RuleRepository() )->create( $this->ruleInput( $destination['channel']->public_id ) );
		$decision    = ( new DecisionRepository() )->create( $this->decisionInput( $rule->id, $rule->public_id, $rule->version, $destination['channel']->public_id ) );
		$delivery    = ( new DeliveryRepository() )->create(
			array(
				'channel_public_id'    => $destination['channel']->public_id,
				'connection_public_id' => $destination['connection']->public_id,
				'decision_public_id'   => $decision->public_id,
				'delivery_mode'        => 'dry_run',
				'payload_preview'      => array(
					'canonical_url' => 'https://example.test/post',
					'message'       => 'Legacy payload',
				),
				'provider'             => 'buffer',
				'state'                => 'dry_run',
			)
		);
		$database    = Database::from_globals();
		$table       = $database->table( Schema::DELIVERIES );

		global $wpdb;
		$wpdb->update(
			$table,
			array( 'payload_preview_json' => '{}' ),
			array( 'public_id' => $delivery->public_id )
		);

		$this->downgradeDeliveryColumnsToPreviewShape( $database );
		update_option( Schema::VERSION_OPTION, 1, false );

		try {
			$migrator->migrate();
			self::fail( 'Malformed legacy delivery payloads must stop migration before queue fields are added.' );
		} catch ( StorageException $exception ) {
			self::assertSame( 'Legacy delivery payload is not compatible with payload schema version 1.', $exception->getMessage() );
		}

		self::assertArrayNotHasKey( 'payload_schema_version', $this->columns( $table ) );
	}

	public function test_connection_channel_lifecycle_is_disabled_until_explicit_enable_and_disconnect_preserves_rows(): void {
		( new SchemaMigrator( Database::from_globals() ) )->migrate();

		$connections = new ConnectionRepository();
		$channels    = new ChannelRepository();
		$connection  = $connections->create( 'buffer', 'Buffer account' );
		$envelope    = CanonicalJson::encode_object( array( 'fixture' => 'encrypted' ) );
		$connection  = $connections->store_initial_credential( $connection->public_id, $envelope, hash( 'sha256', $envelope ) );

		self::assertFalse( $connection->enabled );
		self::assertTrue( $connection->has_credential );
		self::assertSame( 'unverified', $connection->connection_status );

		$account = array(
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
		$connection = $connections->store_verified_account( $connection->public_id, 'acct_1', $account );
		self::assertFalse( $connection->enabled );
		self::assertNull( $connection->verified_identity_hash );
		self::assertSame( 'unverified', $connection->connection_status );

		$connection = $connections->select_organization_unverified( $connection->public_id, 'org_1' );
		$lease      = $connections->acquire_sync_lease( $connection->public_id );

		self::assertNotNull( $lease );

		$leased = $connections->find_by_public_id( $connection->public_id );
		self::assertNotNull( $leased );

		$synced = $channels->synchronize_provider_channels(
			$leased,
			$lease,
			array(
				new ProviderChannel(
					'buffer',
					'org_1',
					'chan_1',
					'PressQueue',
					'PressQueue Social',
					'mastodon',
					null,
					array(
						'isDisconnected' => false,
						'isLocked'       => false,
						'isQueuePaused'  => false,
					),
					array( 'manageUpdates' ),
					'available',
					$this->channelCapabilities(),
					null
				),
			)
		);

		self::assertFalse( $synced[0]->enabled );
		self::assertSame( 'available', $synced[0]->availability );

		$connection = $connections->complete_sync_success( $connection->public_id, $lease, 'acct_1', 'org_1', $account );
		self::assertSame( 'verified', $connection->connection_status );
		self::assertFalse( $connection->enabled );

		$connection = $connections->enable( $connection->public_id );
		$channel    = $channels->set_enabled_if_current( $synced[0]->public_id, true, $synced[0]->updated_at->format( 'Y-m-d H:i:s' ) );

		self::assertTrue( $connection->enabled );
		self::assertTrue( $channel->enabled );

		$verified_identity_hash = $connection->verified_identity_hash;
		$connection             = $connections->store_verified_account( $connection->public_id, 'acct_1', $account );

		self::assertTrue( $connection->enabled );
		self::assertSame( 'verified', $connection->connection_status );
		self::assertSame( $verified_identity_hash, $connection->verified_identity_hash );

		$disconnected = $connections->disconnect( $connection->public_id );

		self::assertFalse( $disconnected->enabled );
		self::assertFalse( $disconnected->has_credential );
		self::assertNull( $disconnected->credential_envelope_hash );
		self::assertNull( $disconnected->verified_identity_hash );
		self::assertSame( 'disconnected', $disconnected->connection_status );
		self::assertNotNull( $channels->find_by_public_id( $channel->public_id ) );
	}

	public function test_credential_rotation_uses_envelope_hash_cas_and_preserves_one_winner(): void {
		( new SchemaMigrator( Database::from_globals() ) )->migrate();

		$connections = new ConnectionRepository();
		$destination = $this->enabledDestination();
		$connection  = $destination['connection'];
		$rotated_at  = $connection->credential_rotated_at;

		self::assertNotNull( $rotated_at );
		self::assertNotNull( $connection->credential_envelope_hash );

		$first_envelope = CanonicalJson::encode_object( array( 'fixture' => 'rotated-one' ) );
		$rotated        = $connections->replace_credential_if_current(
			$connection->public_id,
			$rotated_at->format( 'Y-m-d H:i:s' ),
			$connection->credential_envelope_hash,
			$first_envelope,
			hash( 'sha256', $first_envelope ),
			'acct_1',
			$this->accountDocument(),
			true
		);

		self::assertTrue( $rotated->enabled );
		self::assertSame( 'verified', $rotated->connection_status );

		$this->expectException( StaleRecordException::class );

		$second_envelope = CanonicalJson::encode_object( array( 'fixture' => 'rotated-two' ) );
		$connections->replace_credential_if_current(
			$connection->public_id,
			$rotated_at->format( 'Y-m-d H:i:s' ),
			$connection->credential_envelope_hash,
			$second_envelope,
			hash( 'sha256', $second_envelope ),
			'acct_1',
			$this->accountDocument(),
			true
		);
	}

	public function test_credential_rotation_cannot_reattach_after_disconnect(): void {
		( new SchemaMigrator( Database::from_globals() ) )->migrate();

		$connections = new ConnectionRepository();
		$destination = $this->enabledDestination();
		$connection  = $destination['connection'];
		$rotated_at  = $connection->credential_rotated_at;

		self::assertNotNull( $rotated_at );
		self::assertNotNull( $connection->credential_envelope_hash );

		$connections->disconnect( $connection->public_id );

		try {
			$envelope = CanonicalJson::encode_object( array( 'fixture' => 'reattach' ) );
			$connections->replace_credential_if_current(
				$connection->public_id,
				$rotated_at->format( 'Y-m-d H:i:s' ),
				$connection->credential_envelope_hash,
				$envelope,
				hash( 'sha256', $envelope ),
				'acct_1',
				$this->accountDocument(),
				true
			);
			self::fail( 'Rotation after disconnect must be stale.' );
		} catch ( StaleRecordException $exception ) {
			self::assertNotSame( '', $exception->getMessage() );
		}

		$disconnected = $connections->find_by_public_id( $connection->public_id );
		self::assertNotNull( $disconnected );
		self::assertFalse( $disconnected->has_credential );
		self::assertSame( 'disconnected', $disconnected->connection_status );
	}

	public function test_rotation_demotes_when_selected_organization_is_not_visible(): void {
		( new SchemaMigrator( Database::from_globals() ) )->migrate();

		$connections = new ConnectionRepository();
		$destination = $this->enabledDestination();
		$connection  = $destination['connection'];
		$rotated_at  = $connection->credential_rotated_at;

		self::assertNotNull( $rotated_at );
		self::assertNotNull( $connection->credential_envelope_hash );

		$envelope = CanonicalJson::encode_object( array( 'fixture' => 'org-missing' ) );
		$rotated  = $connections->replace_credential_if_current(
			$connection->public_id,
			$rotated_at->format( 'Y-m-d H:i:s' ),
			$connection->credential_envelope_hash,
			$envelope,
			hash( 'sha256', $envelope ),
			'acct_1',
			array_merge( $this->accountDocument(), array( 'organizations' => array() ) ),
			false
		);

		self::assertFalse( $rotated->enabled );
		self::assertSame( 'unverified', $rotated->connection_status );
		self::assertNull( $rotated->verified_identity_hash );
	}

	public function test_preserving_rotation_does_not_reenable_disabled_connection(): void {
		( new SchemaMigrator( Database::from_globals() ) )->migrate();

		$connections = new ConnectionRepository();
		$destination = $this->enabledDestination();
		$connection  = $destination['connection'];
		$rotated_at  = $connection->credential_rotated_at;

		self::assertNotNull( $rotated_at );
		self::assertNotNull( $connection->credential_envelope_hash );

		$connections->disable( $connection->public_id );

		$envelope = CanonicalJson::encode_object( array( 'fixture' => 'disabled-rotation' ) );
		$rotated  = $connections->replace_credential_if_current(
			$connection->public_id,
			$rotated_at->format( 'Y-m-d H:i:s' ),
			$connection->credential_envelope_hash,
			$envelope,
			hash( 'sha256', $envelope ),
			'acct_1',
			$this->accountDocument(),
			true
		);

		self::assertFalse( $rotated->enabled );
		self::assertSame( 'verified', $rotated->connection_status );
	}

	public function test_failed_credential_preparation_does_not_persist_connection_row(): void {
		( new SchemaMigrator( Database::from_globals() ) )->migrate();

		$connections = new ConnectionRepository();

		try {
			$connections->create_with_credential_factory(
				'buffer',
				'Local account',
				static function (): array {
					throw new StorageException( 'Credential could not be prepared.' );
				}
			);
			self::fail( 'Failed credential preparation must not create a connection.' );
		} catch ( StorageException $exception ) {
			self::assertSame( 'Credential could not be prepared.', $exception->getMessage() );
		}

		self::assertSame( 0, $this->rowCount( Schema::CONNECTIONS ) );
	}

	public function test_stale_sync_lease_cannot_write_channels(): void {
		( new SchemaMigrator( Database::from_globals() ) )->migrate();

		$connections = new ConnectionRepository();
		$channels    = new ChannelRepository();
		$envelope    = CanonicalJson::encode_object( array( 'fixture' => 'encrypted' ) );
		$account     = $this->accountDocument();
		$connection  = $connections->create_with_credential( 'buffer', 'Local account', $envelope, hash( 'sha256', $envelope ) );
		$connection  = $connections->store_verified_account( $connection->public_id, 'acct_1', $account );
		$connection  = $connections->select_organization_unverified( $connection->public_id, 'org_1' );
		$lease       = $connections->acquire_sync_lease( $connection->public_id );

		self::assertNotNull( $lease );

		$leased = $connections->find_by_public_id( $connection->public_id );
		self::assertNotNull( $leased );

		$connections->complete_sync_failure( $connection->public_id, $lease, array( 'classification' => 'test', 'schema_version' => 1 ) );

		try {
			$channels->synchronize_provider_channels( $leased, $lease, array( $this->providerChannel( 'chan_1' ) ) );
			self::fail( 'Stale sync lease must not write channels.' );
		} catch ( StaleRecordException $exception ) {
			self::assertNotSame( '', $exception->getMessage() );
		}

		self::assertNull( $channels->find_by_remote_identity( $connection->public_id, 'org_1', 'chan_1' ) );
	}

	public function test_preserving_sync_completion_does_not_reenable_disabled_connection(): void {
		( new SchemaMigrator( Database::from_globals() ) )->migrate();

		$connections = new ConnectionRepository();
		$destination = $this->enabledDestination();
		$connection  = $destination['connection'];
		$lease       = $connections->acquire_sync_lease( $connection->public_id );

		self::assertNotNull( $lease );

		$connections->disable( $connection->public_id );

		$completed = $connections->complete_sync_success( $connection->public_id, $lease, 'acct_1', 'org_1', $this->accountDocument(), true );

		self::assertFalse( $completed->enabled );
		self::assertSame( 'verified', $completed->connection_status );
	}

	public function test_available_resync_does_not_reenable_admin_disabled_channel(): void {
		( new SchemaMigrator( Database::from_globals() ) )->migrate();

		$connections = new ConnectionRepository();
		$channels    = new ChannelRepository();
		$destination = $this->enabledDestination();
		$connection  = $destination['connection'];
		$channel     = $channels->set_enabled_if_current( $destination['channel']->public_id, false, $destination['channel']->updated_at->format( 'Y-m-d H:i:s' ) );
		$lease       = $connections->acquire_sync_lease( $connection->public_id );

		self::assertNotNull( $lease );

		$leased = $connections->find_by_public_id( $connection->public_id );
		self::assertNotNull( $leased );

		$channels->synchronize_provider_channels( $leased, $lease, array( $this->providerChannel( 'remote-channel-1' ) ) );

		$resynced = $channels->find_by_public_id( $channel->public_id );
		self::assertNotNull( $resynced );
		self::assertFalse( $resynced->enabled );
	}

	public function test_repositories_persist_hydrate_and_enforce_unique_identities(): void {
		( new SchemaMigrator( Database::from_globals() ) )->migrate();

		$connections = new ConnectionRepository();
		$channels    = new ChannelRepository();
		$rules       = new RuleRepository();
		$decisions   = new DecisionRepository();
		$deliveries  = new DeliveryRepository();

		$connection = $connections->create( 'buffer', 'Local account', array( 'profile' => 'Example' ) );
		$channel    = $channels->create(
			$connection->public_id,
			'buffer',
			'remote-channel-1',
			'mastodon',
			array( 'name' => 'Example Social' ),
			array( 'text' => true )
		);

		self::assertFalse( $connection->enabled );
		self::assertFalse( $channel->enabled );
		self::assertFalse( $channels->destination_status( $channel->public_id )->channel_enabled );

		$rule = $rules->create( $this->ruleInput( $channel->public_id ) );

		self::assertFalse( $rule->enabled );
		self::assertSame( 1, $rule->version );
		self::assertSame( 1, $rule->lock_version );

		$enabled_rule = $rules->update( $rule->public_id, $rule->lock_version, array( 'enabled' => true ) );
		self::assertTrue( $enabled_rule->enabled );
		self::assertSame( 1, $enabled_rule->version );
		self::assertSame( 2, $enabled_rule->lock_version );

		$changed_rule = $rules->update( $enabled_rule->public_id, $enabled_rule->lock_version, array( 'priority' => 10 ) );
		self::assertSame( 2, $changed_rule->version );
		self::assertSame( 3, $changed_rule->lock_version );

		$this->expectException( StaleRecordException::class );
		$rules->update( $changed_rule->public_id, 1, array( 'enabled' => false ) );
	}

	public function test_decision_and_delivery_unique_keys_are_database_enforced(): void {
		( new SchemaMigrator( Database::from_globals() ) )->migrate();

		$channels    = new ChannelRepository();
		$rules       = new RuleRepository();
		$decisions   = new DecisionRepository();
		$deliveries  = new DeliveryRepository();

		$destination = $this->enabledDestination();
		$connection  = $destination['connection'];
		$channel     = $destination['channel'];
		$rule        = $rules->create( $this->ruleInput( $channel->public_id ) );

		$decision_input = $this->decisionInput( $rule->id, $rule->public_id, $rule->version, $channel->public_id );
		$decision       = $decisions->create( $decision_input );

		self::assertSame( $decision->decision_key, $decisions->find_by_decision_key( $decision->decision_key )->decision_key );

		try {
			$decisions->create( $decision_input );
			self::fail( 'Duplicate decision keys must be rejected.' );
		} catch ( StorageException $exception ) {
			self::assertStringContainsStringIgnoringCase( 'duplicate', $exception->getMessage() );
		}

		$delivery_input = array(
			'decision_public_id'   => $decision->public_id,
			'delivery_mode'        => 'dry_run',
			'state'                => 'dry_run',
			'provider'             => 'buffer',
			'connection_public_id' => $connection->public_id,
			'channel_public_id'    => $channel->public_id,
			'payload_preview'      => array(
				'canonical_url' => 'https://example.test/post',
				'message'       => 'Hello from storage',
			),
		);
		$delivery = $deliveries->create( $delivery_input );

		self::assertSame( $delivery->delivery_key, $deliveries->find_by_delivery_key( $delivery->delivery_key )->delivery_key );

		try {
			$deliveries->create( $delivery_input );
			self::fail( 'Duplicate delivery keys must be rejected.' );
		} catch ( StorageException $exception ) {
			self::assertStringContainsStringIgnoringCase( 'duplicate', $exception->getMessage() );
		}
	}

	public function test_deactivation_preserves_tables_and_options(): void {
		pressqueue_activate( false );

		$database = Database::from_globals();

		pressqueue_deactivate( false );

		foreach ( Schema::table_suffixes() as $suffix ) {
			$table = $database->table( $suffix );
			self::assertSame( $table, $this->tableLike( $table ) );
		}

		self::assertSame( Schema::TARGET_VERSION, (int) get_option( Schema::VERSION_OPTION, 0 ) );
		self::assertSame( '0', get_option( SettingsRepository::KILL_SWITCH_OPTION ) );
		self::assertSame( '1', get_option( SettingsRepository::DRY_RUN_OPTION ) );
	}

	public function test_historical_snapshots_survive_referenced_deletions(): void {
		( new SchemaMigrator( Database::from_globals() ) )->migrate();

		$author_id = self::factory()->user->create();
		$post_id   = self::factory()->post->create(
			array(
				'post_author' => $author_id,
				'post_status' => 'publish',
			)
		);
		$term_id   = self::factory()->term->create( array( 'taxonomy' => 'category' ) );

		wp_set_post_terms( $post_id, array( $term_id ), 'category' );

		$connections = new ConnectionRepository();
		$channels    = new ChannelRepository();
		$rules       = new RuleRepository();
		$decisions   = new DecisionRepository();
		$deliveries  = new DeliveryRepository();

		$destination = $this->enabledDestination();
		$connection  = $destination['connection'];
		$channel     = $destination['channel'];
		$rule        = $rules->create( $this->ruleInput( $channel->public_id ) );
		$decision   = $decisions->create( $this->decisionInput( $rule->id, $rule->public_id, $rule->version, $channel->public_id, $post_id, $author_id, $term_id ) );
		$delivery   = $deliveries->create(
			array(
				'decision_public_id'   => $decision->public_id,
				'delivery_mode'        => 'dry_run',
				'state'                => 'dry_run',
				'provider'             => 'buffer',
				'connection_public_id' => $connection->public_id,
				'channel_public_id'    => $channel->public_id,
				'payload_preview'      => array(
					'canonical_url' => 'https://example.test/post',
					'message'       => 'Historical payload',
				),
			)
		);

		wp_delete_post( $post_id, true );
		wp_delete_user( $author_id );
		wp_delete_term( $term_id, 'category' );

		global $wpdb;
		$wpdb->delete( Database::from_globals()->table( Schema::CHANNELS ), array( 'public_id' => $channel->public_id ) );
		$wpdb->delete( Database::from_globals()->table( Schema::CONNECTIONS ), array( 'public_id' => $connection->public_id ) );

		$stored_decision = $decisions->find_by_public_id( $decision->public_id );
		$stored_delivery = $deliveries->find_by_public_id( $delivery->public_id );

		self::assertNotNull( $stored_decision );
		self::assertNotNull( $stored_delivery );
		self::assertSame( $term_id, $stored_decision->snapshot['terms'][0]['term_id'] );
		self::assertSame( 'Historical payload', $stored_delivery->payload_preview['message'] );
		self::assertFalse( $channels->destination_status( $channel->public_id )->channel_exists );
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

	/**
	 * Return a valid include rule input.
	 *
	 * @param string $channel_public_id Channel public ID.
	 * @return array<string,mixed>
	 */
	private function ruleInput( string $channel_public_id ): array {
		return array(
			'name'                   => 'Publish posts',
			'effect'                 => 'include',
			'priority'               => 100,
			'triggers'               => array( 'first_publish' ),
			'condition_tree'         => array(
				'schema_version' => 1,
				'type'           => 'group',
				'operator'       => 'ALL',
				'children'       => array(
					array(
						'type'      => 'condition',
						'condition' => 'post_type',
						'operator'  => 'equals',
						'value'     => 'post',
					),
				),
			),
			'inline_template'        => array(
				'schema_version'          => 1,
				'message'                 => '{{ title }}',
				'custom_field_allowlist'  => array(),
			),
			'destination_set'        => array(
				'schema_version' => 1,
				'destinations'    => array(
					array( 'channel_public_id' => $channel_public_id ),
				),
			),
			'custom_field_allowlist' => array(),
			'schedule_policy'        => 'immediate',
		);
	}

	/**
	 * Create a verified and enabled destination fixture.
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
	 * Return a provider channel DTO fixture.
	 *
	 * @param string $remote_channel_id Remote channel ID.
	 * @return ProviderChannel
	 */
	private function providerChannel( string $remote_channel_id ): ProviderChannel {
		return new ProviderChannel(
			'buffer',
			'org_1',
			$remote_channel_id,
			'PressQueue',
			'PressQueue Social',
			'mastodon',
			null,
			array(
				'isDisconnected' => false,
				'isLocked'       => false,
				'isQueuePaused'  => false,
			),
			array( 'manageUpdates' ),
			'available',
			$this->channelCapabilities(),
			null
		);
	}

	/**
	 * Return a v1 text immediate channel capability document.
	 *
	 * @return array<string,mixed>
	 */
	private function channelCapabilities(): array {
		return array(
			'delivery'       => array(
				'schedule_slots' => array( 'immediate' ),
				'text'           => true,
			),
			'schema_version' => 1,
			'service'        => 'mastodon',
		);
	}

	/**
	 * Remove discovery columns and indexes to simulate a version 1 install.
	 *
	 * @param Database $database Database.
	 */
	private function downgradeDiscoveryColumnsToVersionOne( Database $database ): void {
		$connections = $database->table( Schema::CONNECTIONS );
		$channels    = $database->table( Schema::CHANNELS );

		foreach ( array( 'verified_identity_hash', 'connection_status', 'remote_account', 'remote_organization', 'sync_lease_expires_at' ) as $index ) {
			$this->dropIndexIfExists( $connections, $index );
		}

		foreach ( array( 'connection_org_remote_channel', 'remote_organization', 'availability', 'connection_availability' ) as $index ) {
			$this->dropIndexIfExists( $channels, $index );
		}

		$this->addIndexIfMissing( $channels, 'connection_remote_channel', 'UNIQUE KEY connection_remote_channel (connection_public_id, remote_channel_id)' );

		foreach ( array( 'credential_envelope_json', 'credential_envelope_hash', 'remote_account_id', 'remote_organization_id', 'verified_identity_hash', 'connection_status', 'sync_lease_owner', 'sync_lease_expires_at', 'credential_verified_at', 'credential_rotated_at', 'last_sync_started_at', 'last_sync_completed_at', 'last_error_json' ) as $column ) {
			$this->dropColumnIfExists( $connections, $column );
		}

		foreach ( array( 'remote_organization_id', 'availability', 'last_seen_at', 'availability_checked_at' ) as $column ) {
			$this->dropColumnIfExists( $channels, $column );
		}
	}

	/**
	 * Remove queue projection columns and indexes to simulate preview-only delivery storage.
	 *
	 * @param Database $database Database.
	 */
	private function downgradeDeliveryColumnsToPreviewShape( Database $database ): void {
		$deliveries = $database->table( Schema::DELIVERIES );

		foreach ( array( 'state_next_attempt', 'lease_expires_at', 'action_id', 'remote_id', 'remote_receipt_key', 'reconciliation_status', 'state_changed_id' ) as $index ) {
			$this->dropIndexIfExists( $deliveries, $index );
		}

		foreach ( array( 'bound_provider', 'bound_connection_public_id', 'bound_channel_public_id', 'bound_remote_account_id', 'bound_remote_organization_id', 'bound_remote_channel_id', 'bound_capability_schema_version', 'queued_at', 'last_attempt_at', 'attempt_count', 'automatic_attempt_count', 'next_attempt_at', 'lease_owner', 'lease_expires_at', 'action_id', 'provider_result', 'retry_safety', 'receipt_json', 'remote_id', 'remote_receipt_key', 'error_class', 'error_detail_json', 'reconciliation_status', 'reconciled_at', 'reconciled_by', 'reconciliation_note', 'payload_schema_version', 'adapter_encoding_version', 'state_changed_at' ) as $column ) {
			$this->dropColumnIfExists( $deliveries, $column );
		}
	}

	/**
	 * Drop an index when it exists.
	 *
	 * @param string $table Table.
	 * @param string $index Index.
	 */
	private function dropIndexIfExists( string $table, string $index ): void {
		global $wpdb;

		if ( isset( $this->indexes( $table )[ $index ] ) ) {
			$wpdb->query( 'ALTER TABLE `' . $table . '` DROP INDEX `' . $index . '`' );
		}
	}

	/**
	 * Add a column when it is missing.
	 *
	 * @param string $table Table.
	 * @param string $column Column.
	 * @param string $definition Definition.
	 */
	private function addColumnIfMissing( string $table, string $column, string $definition ): void {
		global $wpdb;

		if ( ! isset( $this->columns( $table )[ $column ] ) ) {
			$wpdb->query( 'ALTER TABLE `' . $table . '` ADD COLUMN ' . $definition );
		}
	}

	/**
	 * Add an index when it is missing.
	 *
	 * @param string $table Table.
	 * @param string $index Index.
	 * @param string $definition Definition.
	 */
	private function addIndexIfMissing( string $table, string $index, string $definition ): void {
		global $wpdb;

		if ( ! isset( $this->indexes( $table )[ $index ] ) ) {
			$wpdb->query( 'ALTER TABLE `' . $table . '` ADD ' . $definition );
		}
	}

	/**
	 * Drop a column when it exists.
	 *
	 * @param string $table Table.
	 * @param string $column Column.
	 */
	private function dropColumnIfExists( string $table, string $column ): void {
		global $wpdb;

		if ( isset( $this->columns( $table )[ $column ] ) ) {
			$wpdb->query( 'ALTER TABLE `' . $table . '` DROP COLUMN `' . $column . '`' );
		}
	}

	/**
	 * Return a valid included decision input.
	 *
	 * @param int         $rule_id Rule internal ID.
	 * @param string      $rule_public_id Rule public ID.
	 * @param int         $rule_version Rule version.
	 * @param string      $channel_public_id Channel public ID.
	 * @param int         $post_id Post ID.
	 * @param int         $author_id Author ID.
	 * @param int|null    $term_id Term ID.
	 * @return array<string,mixed>
	 */
	private function decisionInput(
		int $rule_id,
		string $rule_public_id,
		int $rule_version,
		string $channel_public_id,
		int $post_id = 42,
		int $author_id = 7,
		?int $term_id = null
	): array {
		return array(
			'scope_type'           => 'channel',
			'scope_public_id'      => $channel_public_id,
			'post_id'              => $post_id,
			'post_type'            => 'post',
			'post_status'          => 'publish',
			'author_id'            => $author_id,
			'revision_fingerprint' => str_repeat( 'a', 64 ),
			'trigger'              => 'first_publish',
			'event_mode'           => 'runtime',
			'rule_id'              => $rule_id,
			'rule_public_id'       => $rule_public_id,
			'rule_version'         => $rule_version,
			'result'               => 'included',
			'reasons'              => array(
				array(
					'code'             => 'matched_rule',
					'condition_result' => 'matched',
				),
			),
			'snapshot'             => array(
				'post'     => array(
					'id' => $post_id,
				),
				'settings' => array(
					'dry_run' => true,
				),
				'terms'    => null === $term_id ? array() : array(
					array(
						'taxonomy' => 'category',
						'term_id'  => $term_id,
					),
				),
			),
		);
	}

	/**
	 * Return a SHOW TABLES LIKE match.
	 *
	 * @param string $table Table name.
	 * @return string|null
	 */
	private function tableLike( string $table ): ?string {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Return DESCRIBE output keyed by column name.
	 *
	 * @param string $table Table name.
	 * @return array<string,object>
	 */
	private function columns( string $table ): array {
		global $wpdb;

		$rows    = $wpdb->get_results( 'DESCRIBE `' . $table . '`' );
		$columns = array();

		foreach ( $rows as $row ) {
			$columns[ $row->Field ] = $row;
		}

		return $columns;
	}

	/**
	 * Return SHOW INDEX output keyed by index name.
	 *
	 * @param string $table Table name.
	 * @return array<string,array{columns:array<int,string>,non_unique:int}>
	 */
	private function indexes( string $table ): array {
		global $wpdb;

		$rows    = $wpdb->get_results( 'SHOW INDEX FROM `' . $table . '`' );
		$indexes = array();

		foreach ( $rows as $row ) {
			$name = (string) $row->Key_name;

			$indexes[ $name ]['columns'][ (int) $row->Seq_in_index ] = (string) $row->Column_name;
			$indexes[ $name ]['non_unique']                         = (int) $row->Non_unique;
		}

		foreach ( $indexes as $name => $index ) {
			ksort( $index['columns'] );
			$indexes[ $name ]['columns'] = array_values( $index['columns'] );
		}

		return $indexes;
	}

	/**
	 * Assert that an option is not autoloaded.
	 *
	 * @param string $option Option name.
	 */
	private function assertOptionIsNotAutoloaded( string $option ): void {
		global $wpdb;

		$autoload = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT autoload FROM $wpdb->options WHERE option_name = %s",
				$option
			)
		);

		self::assertContains( $autoload, array( 'no', 'off', 'auto-off', '0' ) );
	}

	/**
	 * Assert a column uses ascii_bin collation.
	 *
	 * @param string $table Table name.
	 * @param string $column Column name.
	 */
	private function assertAsciiBinaryColumn( string $table, string $column ): void {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				$table,
				$column
			)
		);

		self::assertSame( 'ascii', $row->CHARACTER_SET_NAME );
		self::assertSame( 'ascii_bin', $row->COLLATION_NAME );
	}
}
