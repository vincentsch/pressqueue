<?php
/**
 * Provider connection integration tests.
 *
 * @package PressQueue\Tests\Integration
 */

declare(strict_types=1);

namespace PressQueue\Tests\Integration;

use PressQueue\Admin\ChannelController;
use PressQueue\Admin\ConnectionController;
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
use PressQueue\Tests\Fixtures\Provider\FixtureBufferDiscoveryProvider;
use PressQueue\Tests\Fixtures\Provider\FixtureDiscoveryProvider;
use PressQueue\Tests\Support\PressQueueIntegrationTestCase as WP_UnitTestCase;

/**
 * @covers \PressQueue\Admin\ChannelController
 * @covers \PressQueue\Admin\ChannelsPage
 * @covers \PressQueue\Admin\ChannelStatus
 * @covers \PressQueue\Admin\ConnectionController
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
		ConnectionController::set_registry( null );
		ConnectionController::set_credential_envelope( null );
		FixtureBufferDiscoveryProvider::$reject_all       = false;
		FixtureBufferDiscoveryProvider::$account_requests = 0;
		remove_all_filters( 'wp_redirect' );
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
		unset( $_SERVER['REQUEST_METHOD'] );
		$this->resetStorage();

		parent::tearDown();
	}

	public function test_connect_with_one_organization_turns_connection_on_and_keeps_channels_off(): void {
		$this->useBufferFixture();

		$location = $this->submit( array( ConnectionController::class, 'handle_connect_buffer' ), 'pressqueue_connect_buffer', array( 'api_key' => 'pq-good-key-123' ) );

		self::assertStringContainsString( 'pressqueue_message=buffer_connected', $location );
		self::assertStringNotContainsString( 'pq-good-key-123', $location );

		$connections = ( new ConnectionRepository() )->find_all();
		self::assertCount( 1, $connections );
		self::assertSame( 'verified', $connections[0]->connection_status );
		self::assertTrue( $connections[0]->enabled );
		self::assertSame( 'Buffer', $connections[0]->label );
		self::assertSame( FixtureBufferDiscoveryProvider::ORG_ONE, $connections[0]->remote_organization_id );
		self::assertStringNotContainsString( 'pq-good-key-123', (string) ( new ConnectionRepository() )->credential_envelope_json( $connections[0]->public_id ) );
		self::assertSame( 1, FixtureBufferDiscoveryProvider::$account_requests );

		$channels = ( new ChannelRepository() )->find_by_connection( $connections[0]->public_id );
		self::assertCount( 2, $channels );
		foreach ( $channels as $channel ) {
			self::assertFalse( $channel->enabled );
		}

		$_GET = array(
			'page'                  => ConnectionController::SLUG,
			'pressqueue_message'    => 'buffer_connected',
			'pressqueue_connection' => $connections[0]->public_id,
		);
		$html = $this->renderPage();

		self::assertStringContainsString( 'Buffer connected. Switch on the channels you want to use.', $html );
		self::assertStringContainsString( 'Larkfield Bakery', $html );
		self::assertStringContainsString( 'Your channels', $html );
		self::assertStringContainsString( 'Needs image-first posts, which PressQueue does not send yet.', $html );
		self::assertStringContainsString( 'Post to Larkfield Bakery on LinkedIn', $html );
		self::assertStringNotContainsString( 'Connect your Buffer account', $html );
	}

	public function test_rejected_key_stores_nothing_and_is_never_echoed(): void {
		$this->useBufferFixture();

		$location = $this->submit( array( ConnectionController::class, 'handle_connect_buffer' ), 'pressqueue_connect_buffer', array( 'api_key' => 'pq-invalid-secret' ) );

		self::assertStringContainsString( 'pressqueue_error=key_rejected', $location );
		self::assertStringContainsString( 'pressqueue_reason=invalid_credential', $location );
		self::assertStringNotContainsString( 'pq-invalid-secret', $location );
		self::assertSame( array(), ( new ConnectionRepository() )->find_all() );

		wp_parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
		$_GET = $query;
		$_POST['api_key'] = 'pq-invalid-secret';
		$html = $this->renderPage();

		self::assertStringContainsString( 'Buffer did not accept this key. Copy it again from Buffer&#039;s API settings and paste the whole key.', $html );
		self::assertStringContainsString( 'Technical details', $html );
		self::assertStringContainsString( 'Connect your Buffer account', $html );
		self::assertStringNotContainsString( 'pq-invalid-secret', $html );
		self::assertMatchesRegularExpression( '/<input type="password" id="pq-buffer-key" name="api_key" class="pq-field" autocomplete="off" spellcheck="false"[^>]*>/', $html );
		self::assertDoesNotMatchRegularExpression( '/<input type="password"[^>]*value=/', $html );
		self::assertStringContainsString( 'href="https://publish.buffer.com/settings/api" target="_blank" rel="noopener noreferrer"', $html );
	}

	public function test_network_failure_after_saving_hides_the_attempt(): void {
		$this->useBufferFixture();

		$location = $this->submit( array( ConnectionController::class, 'handle_connect_buffer' ), 'pressqueue_connect_buffer', array( 'api_key' => 'pq-network-key' ) );

		self::assertStringContainsString( 'pressqueue_error=network', $location );
		self::assertSame( array(), ( new ConnectionRepository() )->find_all() );
	}

	public function test_several_organizations_wait_for_a_choice_then_connect(): void {
		$this->useBufferFixture();

		$location = $this->submit( array( ConnectionController::class, 'handle_connect_buffer' ), 'pressqueue_connect_buffer', array( 'api_key' => 'pq-multi-key' ) );
		self::assertStringContainsString( 'pressqueue_message=choose_organization', $location );

		$connections = ( new ConnectionRepository() )->find_all();
		self::assertCount( 1, $connections );
		$waiting = $connections[0];
		self::assertSame( 'unverified', $waiting->connection_status );
		self::assertFalse( $waiting->enabled );
		self::assertNull( $waiting->remote_organization_id );
		self::assertTrue( $waiting->has_credential );

		$_GET = array( 'page' => ConnectionController::SLUG );
		$html = $this->renderPage();
		self::assertStringContainsString( 'Which Buffer organization should PressQueue use?', $html );
		self::assertStringContainsString( 'Mara (personal)', $html );
		self::assertStringContainsString( '2 channels', $html );
		self::assertStringNotContainsString( 'Connect your Buffer account', $html );

		$location = $this->submit(
			array( ConnectionController::class, 'handle_finish_connection' ),
			'pressqueue_finish_connection',
			array(
				'connection_public_id'   => $waiting->public_id,
				'remote_organization_id' => FixtureBufferDiscoveryProvider::ORG_TWO,
			)
		);
		self::assertStringContainsString( 'pressqueue_message=buffer_connected', $location );

		$connected = ( new ConnectionRepository() )->find_by_public_id( $waiting->public_id );
		self::assertNotNull( $connected );
		self::assertSame( 'verified', $connected->connection_status );
		self::assertTrue( $connected->enabled );
		self::assertSame( FixtureBufferDiscoveryProvider::ORG_TWO, $connected->remote_organization_id );
	}

	public function test_switching_organization_keeps_the_connection_on_and_retires_old_channels(): void {
		$this->useBufferFixture();
		$this->submit( array( ConnectionController::class, 'handle_connect_buffer' ), 'pressqueue_connect_buffer', array( 'api_key' => 'pq-multi-key' ) );
		$connection = ( new ConnectionRepository() )->find_all()[0];
		$this->submit(
			array( ConnectionController::class, 'handle_finish_connection' ),
			'pressqueue_finish_connection',
			array(
				'connection_public_id'   => $connection->public_id,
				'remote_organization_id' => FixtureBufferDiscoveryProvider::ORG_TWO,
			)
		);

		$_GET = array( 'page' => ConnectionController::SLUG );
		self::assertStringContainsString( 'Switch organization', $this->renderPage() );

		$location = $this->submit(
			array( ConnectionController::class, 'handle_finish_connection' ),
			'pressqueue_finish_connection',
			array(
				'connection_public_id'   => $connection->public_id,
				'remote_organization_id' => FixtureBufferDiscoveryProvider::ORG_ONE,
			)
		);
		self::assertStringContainsString( 'pressqueue_message=organization_switched', $location );

		$switched = ( new ConnectionRepository() )->find_by_public_id( $connection->public_id );
		self::assertNotNull( $switched );
		self::assertSame( FixtureBufferDiscoveryProvider::ORG_ONE, $switched->remote_organization_id );
		self::assertTrue( $switched->enabled );

		$personal = ( new ChannelRepository() )->find_by_remote_identity( $connection->public_id, FixtureBufferDiscoveryProvider::ORG_TWO, FixtureBufferDiscoveryProvider::PERSONAL );
		self::assertNotNull( $personal );
		self::assertSame( 'missing', $personal->availability );
		self::assertFalse( $personal->enabled );

		$_GET = array(
			'page'                  => ConnectionController::SLUG,
			'pressqueue_message'    => 'organization_switched',
			'pressqueue_connection' => $connection->public_id,
		);
		$html = $this->renderPage();
		self::assertStringContainsString( 'Now using Larkfield Bakery.', $html );
		self::assertStringNotContainsString( 'Mara on LinkedIn', substr( $html, 0, (int) strpos( $html, 'Technical details' ) ) );
	}

	public function test_reconnecting_a_disconnected_account_keeps_its_channels(): void {
		$this->useBufferFixture();
		$this->submit( array( ConnectionController::class, 'handle_connect_buffer' ), 'pressqueue_connect_buffer', array( 'api_key' => 'pq-good-key' ) );
		$connection = ( new ConnectionRepository() )->find_all()[0];
		$linkedin   = ( new ChannelRepository() )->find_by_remote_identity( $connection->public_id, FixtureBufferDiscoveryProvider::ORG_ONE, FixtureBufferDiscoveryProvider::LINKEDIN );
		self::assertNotNull( $linkedin );

		$location = $this->submit(
			array( ChannelController::class, 'handle_enable_channel' ),
			'pressqueue_enable_channel',
			array(
				'channel_public_id' => $linkedin->public_id,
				'updated_at'        => $linkedin->updated_at->format( 'Y-m-d H:i:s' ),
			)
		);
		self::assertStringContainsString( 'pressqueue_message=channel_enabled', $location );
		self::assertStringContainsString( 'pressqueue_channel=' . $linkedin->public_id, $location );

		$location = $this->submit( array( ConnectionController::class, 'handle_disconnect_connection' ), 'pressqueue_disconnect_connection', array( 'connection_public_id' => $connection->public_id ) );
		self::assertStringContainsString( 'pressqueue_message=connection_disconnected', $location );

		$_GET = array( 'page' => ConnectionController::SLUG );
		self::assertStringContainsString( 'Connecting a Buffer account you used before brings back its channels', $this->renderPage() );

		$location = $this->submit( array( ConnectionController::class, 'handle_connect_buffer' ), 'pressqueue_connect_buffer', array( 'api_key' => 'pq-good-key-new' ) );
		self::assertStringContainsString( 'pressqueue_message=buffer_reconnected', $location );
		self::assertStringContainsString( 'pressqueue_connection=' . $connection->public_id, $location );

		$connections = ( new ConnectionRepository() )->find_all();
		self::assertCount( 1, $connections );
		self::assertTrue( $connections[0]->enabled );
		$linkedin = ( new ChannelRepository() )->find_by_public_id( $linkedin->public_id );
		self::assertNotNull( $linkedin );
		self::assertTrue( $linkedin->enabled );
	}

	public function test_same_organization_is_not_connected_twice(): void {
		$this->useBufferFixture();
		$this->submit( array( ConnectionController::class, 'handle_connect_buffer' ), 'pressqueue_connect_buffer', array( 'api_key' => 'pq-good-key' ) );

		$location = $this->submit( array( ConnectionController::class, 'handle_connect_buffer' ), 'pressqueue_connect_buffer', array( 'api_key' => 'pq-good-key-second' ) );

		self::assertStringContainsString( 'pressqueue_error=already_connected', $location );
		self::assertCount( 1, ( new ConnectionRepository() )->find_all() );
	}

	public function test_missing_credential_storage_is_explained_before_anyone_pastes_a_key(): void {
		$this->useBufferFixture();
		ConnectionController::set_credential_envelope( new CredentialEnvelope( $this->keyMaterial(), false ) );
		$_GET = array( 'page' => ConnectionController::SLUG );

		$html = $this->renderPage();
		self::assertStringContainsString( 'PressQueue cannot store a Buffer key on this server yet.', $html );
		self::assertStringContainsString( 'sodium extension', $html );
		self::assertStringNotContainsString( 'name="api_key"', $html );

		$placeholders = array_fill_keys( $this->constantNames(), 'put your unique phrase here' );
		ConnectionController::set_credential_envelope( new CredentialEnvelope( $placeholders ) );
		self::assertSame( 'salts', ConnectionController::credential_storage_problem() );
		self::assertStringContainsString( 'security keys and salts in wp-config.php', $this->renderPage() );

		$location = $this->submit( array( ConnectionController::class, 'handle_connect_buffer' ), 'pressqueue_connect_buffer', array( 'api_key' => 'pq-good-key' ) );
		self::assertStringContainsString( 'pressqueue_error=storage_salts', $location );
		self::assertSame( 0, FixtureBufferDiscoveryProvider::$account_requests );
		self::assertSame( array(), ( new ConnectionRepository() )->find_all() );
	}

	public function test_rejected_saved_key_shows_a_fix_and_replacing_it_reconnects(): void {
		$this->useBufferFixture();
		$this->submit( array( ConnectionController::class, 'handle_connect_buffer' ), 'pressqueue_connect_buffer', array( 'api_key' => 'pq-good-key' ) );
		$connection = ( new ConnectionRepository() )->find_all()[0];

		FixtureBufferDiscoveryProvider::$reject_all = true;
		$location = $this->submit(
			array( ConnectionController::class, 'handle_sync_connection' ),
			'pressqueue_sync_connection',
			array(
				'connection_public_id'   => $connection->public_id,
				'remote_organization_id' => (string) $connection->remote_organization_id,
			)
		);
		self::assertStringContainsString( 'pressqueue_error=key_rejected', $location );

		$connection = ( new ConnectionRepository() )->find_by_public_id( $connection->public_id );
		self::assertNotNull( $connection );
		self::assertSame( 'credential_invalid', $connection->connection_status );
		self::assertFalse( $connection->enabled );

		$_GET = array( 'page' => ConnectionController::SLUG );
		$html = $this->renderPage();
		self::assertStringContainsString( 'Key no longer works', $html );
		self::assertStringContainsString( 'Replace key and reconnect', $html );

		FixtureBufferDiscoveryProvider::$reject_all = false;
		$location = $this->submit(
			array( ConnectionController::class, 'handle_rotate_connection' ),
			'pressqueue_rotate_connection',
			array(
				'connection_public_id'  => $connection->public_id,
				'credential_rotated_at' => null === $connection->credential_rotated_at ? '' : $connection->credential_rotated_at->format( 'Y-m-d H:i:s' ),
				'api_key'               => 'pq-good-key-replacement',
				'reconnect'             => '1',
			)
		);
		self::assertStringContainsString( 'pressqueue_message=credential_reconnected', $location );

		$connection = ( new ConnectionRepository() )->find_by_public_id( $connection->public_id );
		self::assertNotNull( $connection );
		self::assertSame( 'verified', $connection->connection_status );
		self::assertTrue( $connection->enabled );
	}

	public function test_replacing_a_key_with_one_from_another_account_is_refused(): void {
		$this->useBufferFixture();
		$this->submit( array( ConnectionController::class, 'handle_connect_buffer' ), 'pressqueue_connect_buffer', array( 'api_key' => 'pq-good-key' ) );
		$connection = ( new ConnectionRepository() )->find_all()[0];

		$location = $this->submit(
			array( ConnectionController::class, 'handle_rotate_connection' ),
			'pressqueue_rotate_connection',
			array(
				'connection_public_id'  => $connection->public_id,
				'credential_rotated_at' => null === $connection->credential_rotated_at ? '' : $connection->credential_rotated_at->format( 'Y-m-d H:i:s' ),
				'api_key'               => 'pq-other-account-key',
			)
		);

		self::assertStringContainsString( 'pressqueue_error=other_account', $location );
		self::assertStringContainsString( 'pressqueue_form=replace', $location );
		$after = ( new ConnectionRepository() )->find_by_public_id( $connection->public_id );
		self::assertNotNull( $after );
		self::assertSame( $connection->credential_envelope_hash, $after->credential_envelope_hash );
	}

	public function test_existing_step_by_step_actions_keep_working(): void {
		$this->useBufferFixture();

		$location = $this->submit(
			array( ConnectionController::class, 'handle_create_connection' ),
			'pressqueue_create_connection',
			array(
				'label'   => 'Legacy Buffer',
				'api_key' => 'pq-good-key',
			)
		);
		self::assertStringContainsString( 'pressqueue_message=connection_created', $location );
		$connection = ( new ConnectionRepository() )->find_all()[0];
		self::assertSame( 'unverified', $connection->connection_status );

		$_GET = array( 'page' => ConnectionController::SLUG );
		self::assertStringContainsString( 'Finish setup', $this->renderPage() );

		$location = $this->submit( array( ConnectionController::class, 'handle_test_connection' ), 'pressqueue_test_connection', array( 'connection_public_id' => $connection->public_id ) );
		self::assertStringContainsString( 'pressqueue_message=credential_verified', $location );

		$location = $this->submit(
			array( ConnectionController::class, 'handle_sync_connection' ),
			'pressqueue_sync_connection',
			array(
				'connection_public_id'   => $connection->public_id,
				'remote_organization_id' => FixtureBufferDiscoveryProvider::ORG_ONE,
			)
		);
		self::assertStringContainsString( 'pressqueue_message=channels_synced', $location );

		$location = $this->submit( array( ConnectionController::class, 'handle_enable_connection' ), 'pressqueue_enable_connection', array( 'connection_public_id' => $connection->public_id ) );
		self::assertStringContainsString( 'pressqueue_message=connection_enabled', $location );
		self::assertTrue( ( new ConnectionRepository() )->is_enabled( $connection->public_id ) );

		$linkedin = ( new ChannelRepository() )->find_by_remote_identity( $connection->public_id, FixtureBufferDiscoveryProvider::ORG_ONE, FixtureBufferDiscoveryProvider::LINKEDIN );
		self::assertNotNull( $linkedin );
		$this->submit(
			array( ChannelController::class, 'handle_enable_channel' ),
			'pressqueue_enable_channel',
			array(
				'channel_public_id' => $linkedin->public_id,
				'updated_at'        => $linkedin->updated_at->format( 'Y-m-d H:i:s' ),
			)
		);
		$linkedin = ( new ChannelRepository() )->find_by_public_id( $linkedin->public_id );
		self::assertNotNull( $linkedin );
		self::assertTrue( $linkedin->enabled );

		$location = $this->submit(
			array( ChannelController::class, 'handle_disable_channel' ),
			'pressqueue_disable_channel',
			array(
				'channel_public_id' => $linkedin->public_id,
				'updated_at'        => $linkedin->updated_at->format( 'Y-m-d H:i:s' ),
			)
		);
		self::assertStringContainsString( 'pressqueue_message=channel_disabled', $location );

		$instagram = ( new ChannelRepository() )->find_by_remote_identity( $connection->public_id, FixtureBufferDiscoveryProvider::ORG_ONE, FixtureBufferDiscoveryProvider::INSTAGRAM );
		self::assertNotNull( $instagram );
		$location = $this->submit(
			array( ChannelController::class, 'handle_enable_channel' ),
			'pressqueue_enable_channel',
			array(
				'channel_public_id' => $instagram->public_id,
				'updated_at'        => $instagram->updated_at->format( 'Y-m-d H:i:s' ),
			)
		);
		self::assertStringContainsString( 'pressqueue_error=channel_unavailable', $location );

		$location = $this->submit( array( ConnectionController::class, 'handle_disable_connection' ), 'pressqueue_disable_connection', array( 'connection_public_id' => $connection->public_id ) );
		self::assertStringContainsString( 'pressqueue_message=connection_disabled', $location );

		$location = $this->submit( array( ConnectionController::class, 'handle_disconnect_connection' ), 'pressqueue_disconnect_connection', array( 'connection_public_id' => $connection->public_id ) );
		self::assertStringContainsString( 'pressqueue_message=connection_disconnected', $location );
		self::assertNull( ( new ConnectionRepository() )->credential_envelope_json( $connection->public_id ) );
	}

	public function test_connect_requires_the_connection_capability(): void {
		$this->useBufferFixture();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		try {
			$this->submit( array( ConnectionController::class, 'handle_connect_buffer' ), 'pressqueue_connect_buffer', array( 'api_key' => 'pq-good-key' ), false );
			self::fail( 'The connect action ran without the connection capability.' );
		} catch ( \WPDieException $exception ) {
			self::assertStringContainsString( 'not allowed to manage PressQueue connections', $exception->getMessage() );
		}

		self::assertSame( 0, FixtureBufferDiscoveryProvider::$account_requests );
		self::assertSame( array(), ( new ConnectionRepository() )->find_all() );
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
	 * Point the controller at the offline Buffer fixture and sign in someone who manages connections.
	 */
	private function useBufferFixture(): void {
		ConnectionController::set_registry(
			new ProviderRegistry(
				array(
					new ProviderDescriptor(
						'buffer',
						array( 'personal_api_key' ),
						static function (): FixtureBufferDiscoveryProvider {
							return new FixtureBufferDiscoveryProvider();
						},
						null
					),
				)
			)
		);
		ConnectionController::set_credential_envelope( new CredentialEnvelope( $this->keyMaterial() ) );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = get_user_by( 'id', $user_id );
		self::assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( Capabilities::MANAGE_CONNECTIONS );
		$user->add_cap( Capabilities::MANAGE_SETTINGS );
		wp_set_current_user( $user_id );
	}

	/**
	 * Submit one admin-post handler and return where it redirected.
	 *
	 * @param callable             $handler Handler.
	 * @param string               $nonce_action Nonce action.
	 * @param array<string,string> $post POST fields.
	 * @param bool                 $expect_redirect Whether the handler must redirect.
	 * @return string
	 */
	private function submit( callable $handler, string $nonce_action, array $post, bool $expect_redirect = true ): string {
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
			$_POST    = array();
			$_REQUEST = array();
		}

		if ( $expect_redirect ) {
			self::fail( 'The handler did not redirect.' );
		}

		return '';
	}

	/**
	 * Render the Channels page.
	 *
	 * @return string
	 */
	private function renderPage(): string {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		ob_start();
		ConnectionController::render_connections_page();

		return (string) ob_get_clean();
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
