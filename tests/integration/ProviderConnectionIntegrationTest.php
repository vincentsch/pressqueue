<?php
/**
 * Provider connection integration tests.
 *
 * @package PressQueue\Tests\Integration
 */

declare(strict_types=1);

namespace PressQueue\Tests\Integration;

use PressQueue\Provider\ProviderConnectionService;
use PressQueue\Provider\ProviderDescriptor;
use PressQueue\Provider\ProviderRegistry;
use PressQueue\Security\Capabilities;
use PressQueue\Security\CredentialEnvelope;
use PressQueue\Storage\Database;
use PressQueue\Storage\Repository\ChannelRepository;
use PressQueue\Storage\Repository\ConnectionRepository;
use PressQueue\Storage\Schema;
use PressQueue\Storage\SchemaMigrator;
use PressQueue\Storage\SettingsRepository;
use PressQueue\Tests\Fixtures\Provider\FixtureDiscoveryProvider;
use WP_UnitTestCase;

/**
 * @covers \PressQueue\Provider\ProviderConnectionService
 * @covers \PressQueue\Provider\ProviderDescriptor
 * @covers \PressQueue\Provider\ProviderRegistry
 * @covers \PressQueue\Storage\Repository\ChannelRepository
 * @covers \PressQueue\Storage\Repository\ConnectionRepository
 */
final class ProviderConnectionIntegrationTest extends WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();

		$this->resetStorage();
		( new SchemaMigrator( Database::from_globals() ) )->migrate();
	}

	protected function tearDown(): void {
		$this->resetStorage();

		parent::tearDown();
	}

	public function test_service_creates_disabled_connection_and_syncs_disabled_channels(): void {
		$service     = $this->service();
		$connections = new ConnectionRepository();
		$channels    = new ChannelRepository();
		$connection  = $service->create_disabled_connection( 'fixture', 'Fixture account', 'fixture credential', 'fixture_key' );
		$stored_json = $connections->credential_envelope_json( $connection->public_id );

		self::assertFalse( $connection->enabled );
		self::assertSame( 'unverified', $connection->connection_status );
		self::assertTrue( $connection->has_credential );
		self::assertIsString( $stored_json );
		self::assertStringNotContainsString( 'fixture credential', $stored_json );

		$account = $service->fetch_account( $connection, 'fixture credential' );
		$synced  = $service->sync_channels( $connection, 'fixture credential', $account, 'org_fixture', true );
		$rows    = $channels->find_by_connection( $connection->public_id );

		self::assertSame( 'verified', $synced->connection_status );
		self::assertFalse( $synced->enabled );
		self::assertSame( 'acct_fixture', $synced->remote_account_id );
		self::assertSame( 'org_fixture', $synced->remote_organization_id );
		self::assertCount( 1, $rows );
		self::assertFalse( $rows[0]->enabled );
		self::assertSame( 'available', $rows[0]->availability );
		self::assertSame( 'chan_fixture', $rows[0]->remote_channel_id );
	}

	public function test_service_rejects_undeclared_authentication_mode(): void {
		$this->expectException( \PressQueue\Storage\ValidationException::class );

		$this->service()->create_disabled_connection( 'fixture', 'Fixture account', 'fixture credential', 'oauth' );
	}

	/**
	 * Return a provider connection service with deterministic key material.
	 *
	 * @return ProviderConnectionService
	 */
	private function service(): ProviderConnectionService {
		$registry = new ProviderRegistry(
			array(
				new ProviderDescriptor(
					'fixture',
					array( 'fixture_key' ),
					static function (): FixtureDiscoveryProvider {
						return new FixtureDiscoveryProvider();
					},
					null
				),
			)
		);

		return new ProviderConnectionService( Database::from_globals(), $registry, new CredentialEnvelope( $this->keyMaterial() ) );
	}

	/**
	 * Return deterministic test key material.
	 *
	 * @return array<string,string>
	 */
	private function keyMaterial(): array {
		$material = array();
		foreach ( $this->constantNames() as $index => $name ) {
			$material[ $name ] = 'pressqueue provider key ' . (string) $index;
		}

		return $material;
	}

	/**
	 * Return WordPress secret constant names.
	 *
	 * @return array<int,string>
	 */
	private function constantNames(): array {
		return array(
			'AUTH_KEY',
			'SECURE_AUTH_KEY',
			'LOGGED_IN_KEY',
			'NONCE_KEY',
			'AUTH_SALT',
			'SECURE_AUTH_SALT',
			'LOGGED_IN_SALT',
			'NONCE_SALT',
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
