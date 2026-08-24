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
use PressQueue\Security\Capabilities;
use ReflectionClass;
use RuntimeException;

use function pressqueue_test_reset_wordpress_stubs;

/**
 * @covers \PressQueue\Admin\ChannelController
 * @covers \PressQueue\Admin\ConnectionController
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
	 */
	public function test_admin_mutations_require_connection_capability( string $controller, string $nonce_action ): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_REQUEST['_wpnonce']      = 'valid-nonce';
		$GLOBALS['pressqueue_test_valid_nonces'][ $nonce_action ] = 'valid-nonce';

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'manage PressQueue' );

		$this->invokeMutationBoundary( $controller, $nonce_action );
	}

	/**
	 * @dataProvider mutationBoundaryProvider
	 *
	 * @param class-string $controller Controller class.
	 * @param string       $nonce_action Nonce action.
	 */
	public function test_admin_mutations_require_post_requests( string $controller, string $nonce_action ): void {
		$GLOBALS['pressqueue_test_current_user_caps'][ Capabilities::MANAGE_CONNECTIONS ] = true;
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
	 */
	public function test_admin_mutations_require_valid_nonces( string $controller, string $nonce_action ): void {
		$GLOBALS['pressqueue_test_current_user_caps'][ Capabilities::MANAGE_CONNECTIONS ] = true;
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
	 */
	public function test_admin_mutations_accept_valid_post_nonces( string $controller, string $nonce_action ): void {
		$GLOBALS['pressqueue_test_current_user_caps'][ Capabilities::MANAGE_CONNECTIONS ] = true;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_REQUEST['_wpnonce']      = 'valid-nonce';
		$GLOBALS['pressqueue_test_valid_nonces'][ $nonce_action ] = 'valid-nonce';

		$this->invokeMutationBoundary( $controller, $nonce_action );

		self::assertTrue( true );
	}

	/**
	 * @return array<string,array{0:class-string,1:string}>
	 */
	public static function mutationBoundaryProvider(): array {
		return array(
			'connection' => array( ConnectionController::class, 'pressqueue_test_connection' ),
			'channel'    => array( ChannelController::class, 'pressqueue_enable_channel' ),
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
}
