<?php
/**
 * Administration mutation boundary tests.
 *
 * @package PressQueue\Tests\Unit
 */

declare(strict_types=1);

namespace PressQueue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PressQueue\Admin\ChannelController;
use PressQueue\Admin\ConnectionController;
use PressQueue\Admin\DeliveryActionController;
use PressQueue\Security\Capabilities;
use ReflectionClass;
use RuntimeException;

use function pressqueue_test_reset_wordpress_stubs;

/**
 * @covers \PressQueue\Admin\ChannelController
 * @covers \PressQueue\Admin\ConnectionController
 * @covers \PressQueue\Admin\DeliveryActionController
 */
final class AdminMutationBoundaryTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		pressqueue_test_reset_wordpress_stubs();
		$_POST    = array();
		$_REQUEST = array();
		unset( $_SERVER['REQUEST_METHOD'] );
	}

	protected function tearDown(): void {
		$_POST    = array();
		$_REQUEST = array();
		unset( $_SERVER['REQUEST_METHOD'] );

		parent::tearDown();
	}

	/**
	 * @dataProvider mutationBoundaryProvider
	 *
	 * @param class-string $controller Controller class.
	 * @param string       $nonce_action Nonce action.
	 * @param string       $capability Capability.
	 * @param string       $message Message.
	 */
	public function test_admin_mutations_require_capability( string $controller, string $nonce_action, string $capability, string $message ): void {
		unset( $capability );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_REQUEST['_wpnonce']      = 'valid-nonce';
		$GLOBALS['pressqueue_test_valid_nonces'][ $nonce_action ] = 'valid-nonce';

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( $message );

		$this->invokeMutationBoundary( $controller, $nonce_action );
	}

	/**
	 * @dataProvider mutationBoundaryProvider
	 *
	 * @param class-string $controller Controller class.
	 * @param string       $nonce_action Nonce action.
	 * @param string       $capability Capability.
	 * @param string       $message Message.
	 */
	public function test_admin_mutations_require_post_requests( string $controller, string $nonce_action, string $capability, string $message ): void {
		unset( $message );
		$GLOBALS['pressqueue_test_current_user_caps'][ $capability ] = true;
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_REQUEST['_wpnonce']      = 'valid-nonce';
		$GLOBALS['pressqueue_test_valid_nonces'][ $nonce_action ] = 'valid-nonce';

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'PressQueue changes require POST.' );

		$this->invokeMutationBoundary( $controller, $nonce_action );
	}

	/**
	 * @dataProvider mutationBoundaryProvider
	 *
	 * @param class-string $controller Controller class.
	 * @param string       $nonce_action Nonce action.
	 * @param string       $capability Capability.
	 * @param string       $message Message.
	 */
	public function test_admin_mutations_require_valid_nonces( string $controller, string $nonce_action, string $capability, string $message ): void {
		unset( $message );
		$GLOBALS['pressqueue_test_current_user_caps'][ $capability ] = true;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_REQUEST['_wpnonce']      = 'stale-nonce';
		$GLOBALS['pressqueue_test_valid_nonces'][ $nonce_action ] = 'valid-nonce';

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Nonce check failed.' );

		$this->invokeMutationBoundary( $controller, $nonce_action );
	}

	/**
	 * @dataProvider mutationBoundaryProvider
	 *
	 * @param class-string $controller Controller class.
	 * @param string       $nonce_action Nonce action.
	 * @param string       $capability Capability.
	 * @param string       $message Message.
	 */
	public function test_admin_mutations_accept_valid_post_nonces( string $controller, string $nonce_action, string $capability, string $message ): void {
		unset( $message );
		$GLOBALS['pressqueue_test_current_user_caps'][ $capability ] = true;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_REQUEST['_wpnonce']      = 'valid-nonce';
		$GLOBALS['pressqueue_test_valid_nonces'][ $nonce_action ] = 'valid-nonce';

		$this->invokeMutationBoundary( $controller, $nonce_action );

		self::assertTrue( true );
	}

	/**
	 * @dataProvider deliveryHandlerBoundaryProvider
	 *
	 * @param callable $handler Handler.
	 * @param string   $nonce_action Nonce action.
	 * @param array<string,string> $post POST fields.
	 */
	public function test_delivery_handlers_require_operator_capability( callable $handler, string $nonce_action, array $post ): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array_merge( array( '_wpnonce' => 'valid-nonce' ), $post );
		$_REQUEST                  = $_POST;
		$GLOBALS['pressqueue_test_valid_nonces'][ $nonce_action ] = 'valid-nonce';

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'operate PressQueue deliveries' );

		$handler();
	}

	/**
	 * @dataProvider deliveryHandlerBoundaryProvider
	 *
	 * @param callable $handler Handler.
	 * @param string   $nonce_action Nonce action.
	 * @param array<string,string> $post POST fields.
	 */
	public function test_delivery_handlers_require_post_requests( callable $handler, string $nonce_action, array $post ): void {
		$GLOBALS['pressqueue_test_current_user_caps'][ Capabilities::OPERATE_DELIVERIES ] = true;
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = array_merge( array( '_wpnonce' => 'valid-nonce' ), $post );
		$_REQUEST                  = $_POST;
		$GLOBALS['pressqueue_test_valid_nonces'][ $nonce_action ] = 'valid-nonce';

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'PressQueue changes require POST.' );

		$handler();
	}

	/**
	 * @dataProvider deliveryHandlerBoundaryProvider
	 *
	 * @param callable $handler Handler.
	 * @param string   $nonce_action Nonce action.
	 * @param array<string,string> $post POST fields.
	 */
	public function test_delivery_handlers_require_valid_nonces( callable $handler, string $nonce_action, array $post ): void {
		$GLOBALS['pressqueue_test_current_user_caps'][ Capabilities::OPERATE_DELIVERIES ] = true;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array_merge( array( '_wpnonce' => 'stale-nonce' ), $post );
		$_REQUEST                  = $_POST;
		$GLOBALS['pressqueue_test_valid_nonces'][ $nonce_action ] = 'valid-nonce';

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Nonce check failed.' );

		$handler();
	}

	/**
	 * @dataProvider connectionHandlerBoundaryProvider
	 *
	 * @param callable             $handler Handler.
	 * @param string               $nonce_action Nonce action.
	 * @param array<string,string> $post POST fields.
	 */
	public function test_connection_handlers_require_capability_before_reading_input( callable $handler, string $nonce_action, array $post ): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array_merge( array( '_wpnonce' => 'valid-nonce' ), $post );
		$_REQUEST                  = $_POST;
		$GLOBALS['pressqueue_test_valid_nonces'][ $nonce_action ] = 'valid-nonce';

		try {
			$handler();
			self::fail( 'The handler ran without the connection capability.' );
		} catch ( RuntimeException $exception ) {
			self::assertStringContainsString( 'manage PressQueue', $exception->getMessage() );
		}

		self::assertSame( array(), $GLOBALS['pressqueue_test_http_requests'] );
		self::assertSame( array(), $GLOBALS['pressqueue_test_redirects'] ?? array() );
	}

	/**
	 * @dataProvider connectionHandlerBoundaryProvider
	 *
	 * @param callable             $handler Handler.
	 * @param string               $nonce_action Nonce action.
	 * @param array<string,string> $post POST fields.
	 */
	public function test_connection_handlers_require_post_requests( callable $handler, string $nonce_action, array $post ): void {
		$GLOBALS['pressqueue_test_current_user_caps'][ Capabilities::MANAGE_CONNECTIONS ] = true;
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = array_merge( array( '_wpnonce' => 'valid-nonce' ), $post );
		$_REQUEST                  = $_POST;
		$GLOBALS['pressqueue_test_valid_nonces'][ $nonce_action ] = 'valid-nonce';

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'PressQueue changes require POST.' );

		$handler();
	}

	/**
	 * @dataProvider connectionHandlerBoundaryProvider
	 *
	 * @param callable             $handler Handler.
	 * @param string               $nonce_action Nonce action.
	 * @param array<string,string> $post POST fields.
	 */
	public function test_connection_handlers_require_valid_nonces( callable $handler, string $nonce_action, array $post ): void {
		$GLOBALS['pressqueue_test_current_user_caps'][ Capabilities::MANAGE_CONNECTIONS ] = true;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array_merge( array( '_wpnonce' => 'stale-nonce' ), $post );
		$_REQUEST                  = $_POST;
		$GLOBALS['pressqueue_test_valid_nonces'][ $nonce_action ] = 'valid-nonce';

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Nonce check failed.' );

		$handler();
	}

	public function test_provider_failures_map_to_plain_error_codes_without_raw_messages(): void {
		$cases = array(
			'invalid_credential' => 'key_rejected',
			'forbidden'          => 'key_forbidden',
			'network_failure'    => 'network',
			'rate_limited'       => 'rate_limited',
			'malformed_response' => 'provider_failure',
		);

		foreach ( $cases as $classification => $code ) {
			$result = ConnectionController::error_result( new \PressQueue\Provider\ProviderException( $classification, 'Raw provider text with secret-ish detail.' ) );

			self::assertSame( $code, $result['error'] );
			self::assertSame( $classification, $result['reason'] );
			self::assertStringNotContainsString( 'secret-ish', implode( ' ', $result ) );
		}

		$unknown = ConnectionController::error_result( new \PressQueue\Provider\ProviderException( 'something_new', 'x' ) );
		self::assertSame( 'unexpected_provider_failure', $unknown['reason'] );

		$storage = ConnectionController::error_result( new \PressQueue\Security\CredentialEnvelopeException( 'The PHP Sodium extension is required for provider credentials.' ) );
		self::assertSame( 'storage_sodium', $storage['error'] );

		$salts = ConnectionController::error_result( new \PressQueue\Security\CredentialEnvelopeException( 'WordPress secret key material is not configured.' ) );
		self::assertSame( 'storage_salts', $salts['error'] );

		$unreadable = ConnectionController::error_result( new \PressQueue\Security\CredentialEnvelopeException( 'Credential envelope could not be decrypted.' ) );
		self::assertSame( 'key_unreadable', $unreadable['error'] );

		$generic = ConnectionController::error_result( new RuntimeException( 'Database password is hunter2' ) );
		self::assertSame( 'failed', $generic['error'] );
		self::assertStringNotContainsString( 'hunter2', $generic['detail'] );
	}

	public function test_cancel_handler_requires_confirmation(): void {
		$GLOBALS['pressqueue_test_current_user_caps'][ Capabilities::OPERATE_DELIVERIES ] = true;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'_wpnonce'           => 'valid-nonce',
			'delivery_public_id' => '01H00000000000000000000000',
		);
		$_REQUEST                  = $_POST;
		$GLOBALS['pressqueue_test_valid_nonces']['pressqueue_cancel_delivery'] = 'valid-nonce';

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'confirmation is required' );

		DeliveryActionController::handle_cancel_delivery();
	}

	public function test_cancel_held_handler_requires_confirmation(): void {
		$GLOBALS['pressqueue_test_current_user_caps'][ Capabilities::OPERATE_DELIVERIES ] = true;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array( '_wpnonce' => 'valid-nonce' );
		$_REQUEST                  = $_POST;
		$GLOBALS['pressqueue_test_valid_nonces']['pressqueue_cancel_held_deliveries'] = 'valid-nonce';

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'confirmation is required' );

		DeliveryActionController::handle_cancel_held_deliveries();
	}

	public function test_reconcile_handler_requires_confirmation(): void {
		$GLOBALS['pressqueue_test_current_user_caps'][ Capabilities::OPERATE_DELIVERIES ] = true;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'_wpnonce'           => 'valid-nonce',
			'delivery_public_id' => '01H00000000000000000000000',
			'outcome'            => 'confirmed_not_accepted',
		);
		$_REQUEST                  = $_POST;
		$GLOBALS['pressqueue_test_valid_nonces']['pressqueue_reconcile_delivery_confirmed_not_accepted'] = 'valid-nonce';

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'confirmation is required' );

		DeliveryActionController::handle_reconcile_delivery();
	}

	public function test_delivery_public_id_post_value_is_not_truncated(): void {
		$_POST = array(
			'delivery_public_id' => '01H00000000000000000000000EXTRA',
		);

		$this->expectException( \PressQueue\Storage\ValidationException::class );

		$this->invokePrivateStatic( DeliveryActionController::class, 'post_delivery_public_id' );
	}

	public function test_raw_reconciliation_post_value_is_not_trimmed_or_truncated(): void {
		$_POST = array(
			'remote_id' => "\n" . str_repeat( 'A', 200 ),
		);

		self::assertSame( $_POST['remote_id'], $this->invokePrivateStatic( DeliveryActionController::class, 'post_optional_raw_text', array( 'remote_id' ) ) );
	}

	/**
	 * @return array<string,array{0:class-string,1:string,2:string,3:string}>
	 */
	public static function mutationBoundaryProvider(): array {
		return array(
			'connection'      => array( ConnectionController::class, 'pressqueue_test_connection', Capabilities::MANAGE_CONNECTIONS, 'manage PressQueue' ),
			'connect buffer'  => array( ConnectionController::class, 'pressqueue_connect_buffer', Capabilities::MANAGE_CONNECTIONS, 'manage PressQueue' ),
			'finish setup'    => array( ConnectionController::class, 'pressqueue_finish_connection', Capabilities::MANAGE_CONNECTIONS, 'manage PressQueue' ),
			'channel'         => array( ChannelController::class, 'pressqueue_enable_channel', Capabilities::MANAGE_CONNECTIONS, 'manage PressQueue' ),
			'delivery cancel' => array( DeliveryActionController::class, 'pressqueue_cancel_delivery', Capabilities::OPERATE_DELIVERIES, 'operate PressQueue deliveries' ),
		);
	}

	/**
	 * @return array<string,array{0:callable,1:string,2:array<string,string>}>
	 */
	public static function connectionHandlerBoundaryProvider(): array {
		$connection = array( 'connection_public_id' => '01H00000000000000000000000' );
		$channel    = array(
			'channel_public_id' => '01H00000000000000000000000',
			'updated_at'        => '2026-10-09 10:00:00',
		);

		return array(
			'connect buffer'    => array( array( ConnectionController::class, 'handle_connect_buffer' ), 'pressqueue_connect_buffer', array( 'api_key' => 'pqfake_unit_key' ) ),
			'finish connection' => array( array( ConnectionController::class, 'handle_finish_connection' ), 'pressqueue_finish_connection', array_merge( $connection, array( 'remote_organization_id' => 'org' ) ) ),
			'create connection' => array( array( ConnectionController::class, 'handle_create_connection' ), 'pressqueue_create_connection', array( 'api_key' => 'pqfake_unit_key' ) ),
			'test connection'   => array( array( ConnectionController::class, 'handle_test_connection' ), 'pressqueue_test_connection', $connection ),
			'sync connection'   => array( array( ConnectionController::class, 'handle_sync_connection' ), 'pressqueue_sync_connection', $connection ),
			'rotate connection' => array( array( ConnectionController::class, 'handle_rotate_connection' ), 'pressqueue_rotate_connection', array_merge( $connection, array( 'api_key' => 'pqfake_unit_key' ) ) ),
			'enable connection' => array( array( ConnectionController::class, 'handle_enable_connection' ), 'pressqueue_enable_connection', $connection ),
			'disable connection' => array( array( ConnectionController::class, 'handle_disable_connection' ), 'pressqueue_disable_connection', $connection ),
			'disconnect'        => array( array( ConnectionController::class, 'handle_disconnect_connection' ), 'pressqueue_disconnect_connection', $connection ),
			'enable channel'    => array( array( ChannelController::class, 'handle_enable_channel' ), 'pressqueue_enable_channel', $channel ),
			'disable channel'   => array( array( ChannelController::class, 'handle_disable_channel' ), 'pressqueue_disable_channel', $channel ),
		);
	}

	/**
	 * @return array<string,array{0:callable,1:string,2:array<string,string>}>
	 */
	public static function deliveryHandlerBoundaryProvider(): array {
		return array(
			'cancel'    => array(
				array( DeliveryActionController::class, 'handle_cancel_delivery' ),
				'pressqueue_cancel_delivery',
				array(
					'confirm_local_effect' => '1',
					'delivery_public_id'   => '01H00000000000000000000000',
				),
			),
			'resume'    => array(
				array( DeliveryActionController::class, 'handle_resume_delivery' ),
				'pressqueue_resume_delivery',
				array( 'delivery_public_id' => '01H00000000000000000000000' ),
			),
			'retry'     => array(
				array( DeliveryActionController::class, 'handle_retry_delivery' ),
				'pressqueue_retry_delivery',
				array( 'delivery_public_id' => '01H00000000000000000000000' ),
			),
			'reconcile' => array(
				array( DeliveryActionController::class, 'handle_reconcile_delivery' ),
				'pressqueue_reconcile_delivery_confirmed_not_accepted',
				array(
					'confirm_local_effect' => '1',
					'delivery_public_id'   => '01H00000000000000000000000',
					'outcome'              => 'confirmed_not_accepted',
				),
			),
			'resume held' => array(
				array( DeliveryActionController::class, 'handle_resume_held_deliveries' ),
				'pressqueue_resume_held_deliveries',
				array(),
			),
			'cancel held' => array(
				array( DeliveryActionController::class, 'handle_cancel_held_deliveries' ),
				'pressqueue_cancel_held_deliveries',
				array( 'confirm_local_effect' => '1' ),
			),
		);
	}

	/**
	 * Invoke one controller mutation boundary.
	 *
	 * @param class-string $controller Controller class.
	 * @param string       $nonce_action Nonce action.
	 */
	private function invokeMutationBoundary( string $controller, string $nonce_action ): void {
		$method = ( new ReflectionClass( $controller ) )->getMethod( 'mutation_boundary' );
		$method->setAccessible( true );
		$method->invoke( null, $nonce_action );
	}

	/**
	 * Invoke one private static helper.
	 *
	 * @param class-string $controller Controller class.
	 * @param string       $method_name Method name.
	 * @param array<int,mixed> $args Arguments.
	 * @return mixed
	 */
	private function invokePrivateStatic( string $controller, string $method_name, array $args = array() ) {
		$method = ( new ReflectionClass( $controller ) )->getMethod( $method_name );
		$method->setAccessible( true );

		return $method->invokeArgs( null, $args );
	}
}
