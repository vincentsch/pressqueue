<?php
/**
 * WP-CLI contract tests.
 *
 * @package PressQueue\Tests\Unit
 */

declare(strict_types=1);

namespace PressQueue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PressQueue\Security\Capabilities;
use PressQueue\Storage\StaleRecordException;
use PressQueue\WpCli\AbstractCommand;
use PressQueue\WpCli\BatchCommand;
use PressQueue\WpCli\ChannelCommand;
use PressQueue\WpCli\CommandException;
use PressQueue\WpCli\CommandRegistrar;
use PressQueue\WpCli\ExitCodes;
use PressQueue\WpCli\JsonResponse;
use PressQueue\WpCli\Query\RecordFormatter;
use PressQueue\WpCli\StatusCommand;

use function pressqueue_test_reset_wordpress_stubs;

/**
 * @covers \PressQueue\WpCli\AbstractCommand
 * @covers \PressQueue\WpCli\BatchCommand
 * @covers \PressQueue\WpCli\ChannelCommand
 * @covers \PressQueue\WpCli\CommandException
 * @covers \PressQueue\WpCli\CommandRegistrar
 * @covers \PressQueue\WpCli\ExitCodes
 * @covers \PressQueue\WpCli\JsonResponse
 * @covers \PressQueue\WpCli\Query\RecordFormatter
 * @covers \PressQueue\WpCli\StatusCommand
 */
final class WpCliContractsTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		pressqueue_test_reset_wordpress_stubs();
	}

	public function test_success_envelope_shape_is_stable(): void {
		self::assertSame(
			array(
				'command'        => 'status',
				'data'           => array( 'ok' => true ),
				'pagination'     => array(
					'page'        => 1,
					'per_page'    => 50,
					'total_items' => 1,
					'total_pages' => 1,
				),
				'schema_version' => 1,
			),
			JsonResponse::success_envelope(
				'status',
				array( 'ok' => true ),
				array(
					'page'        => 1,
					'per_page'    => 50,
					'total_items' => 1,
					'total_pages' => 1,
				)
			)
		);
	}

	public function test_error_response_uses_exit_status_and_json_line(): void {
		list( $status, $line ) = $this->capture_cli_document(
			static function (): int {
				return JsonResponse::error(
					'connections.list',
					new CommandException(
						'invalid_arguments',
						'Invalid option.',
						ExitCodes::INVALID,
						array(
							array(
								'path'    => '--format',
								'message' => 'Only JSON is supported.',
							),
						)
					)
				);
			}
		);

		self::assertSame( ExitCodes::INVALID, $status );
		self::assertSame( 'connections.list', $line['command'] );
		self::assertSame( 'invalid_arguments', $line['error']['code'] );
		self::assertSame( ExitCodes::INVALID, $line['error']['status'] );
		self::assertSame( '--format', $line['error']['field_errors'][0]['path'] );
	}

	public function test_registrar_skips_registration_when_cli_runtime_is_inactive(): void {
		CommandRegistrar::register();

		self::assertSame( array(), $GLOBALS['pressqueue_test_wp_cli_commands'] );
	}

	public function test_command_rejects_non_json_format_before_work_runs(): void {
		$command = new class() extends AbstractCommand {
			/**
			 * Dispatch a test command.
			 *
			 * @param array<string,mixed> $assoc_args Args.
			 * @param callable            $callback Callback.
			 * @return int
			 */
			public function dispatch( array $assoc_args, callable $callback ): int {
				return $this->run_json( 'test.command', $assoc_args, $callback );
			}
		};
		$called  = false;

		list( $status, $line ) = $this->capture_cli_document(
			static function () use ( $command, &$called ): int {
				return $command->dispatch(
					array( 'format' => 'table' ),
					static function () use ( &$called ): array {
						$called = true;

						return array( 'ok' => true );
					}
				);
			}
		);

		self::assertFalse( $called );
		self::assertSame( ExitCodes::INVALID, $status );
		self::assertSame( 'invalid_arguments', $line['error']['code'] );
	}

	public function test_status_command_requires_authenticated_capability_before_storage(): void {
		$GLOBALS['pressqueue_test_logged_in'] = false;

		list( $status, $line ) = $this->capture_cli_document(
			static function (): int {
				return ( new StatusCommand() )->__invoke( array(), array() );
			}
		);

		self::assertSame( ExitCodes::UNAUTHORIZED, $status );
		self::assertSame( 'not_authenticated', $line['error']['code'] );
	}

	public function test_status_command_matches_schema_version_contract(): void {
		$GLOBALS['pressqueue_test_current_user_caps'][ Capabilities::MANAGE_SETTINGS ] = true;

		list( $status, $line ) = $this->capture_cli_document(
			static function (): int {
				return ( new StatusCommand() )->__invoke( array(), array() );
			}
		);

		self::assertSame( ExitCodes::SUCCESS, $status );
		self::assertSame( 1, $line['data']['schema_version'] );
		self::assertArrayHasKey( 'runtime_disabled', $line['data'] );
		self::assertArrayHasKey( 'retention', $line['data'] );
	}

	public function test_stale_record_exceptions_exit_as_refused_state_changes(): void {
		$command = new class() extends AbstractCommand {
			/**
			 * Dispatch a test command.
			 *
			 * @param array<string,mixed> $assoc_args Args.
			 * @return int
			 */
			public function dispatch( array $assoc_args ): int {
				return $this->run_json(
					'test.stale',
					$assoc_args,
					static function (): array {
						throw new StaleRecordException( 'changed' );
					}
				);
			}
		};

		list( $status, $line ) = $this->capture_cli_document(
			static function () use ( $command ): int {
				return $command->dispatch( array() );
			}
		);

		self::assertSame( ExitCodes::REFUSED, $status );
		self::assertSame( 'stale_state', $line['error']['code'] );
	}

	public function test_channels_list_requires_connection_before_database_read(): void {
		$GLOBALS['pressqueue_test_current_user_caps'][ Capabilities::MANAGE_CONNECTIONS ] = true;

		list( $status, $line ) = $this->capture_cli_document(
			static function (): int {
				return ( new ChannelCommand() )->list_( array(), array() );
			}
		);

		self::assertSame( ExitCodes::INVALID, $status );
		self::assertSame( 'invalid_arguments', $line['error']['code'] );
		self::assertSame( '--connection is required.', $line['error']['message'] );
	}

	public function test_batch_preview_rejects_empty_filters_document_before_database_read(): void {
		$GLOBALS['pressqueue_test_current_user_caps'][ Capabilities::OPERATE_BATCHES ] = true;

		list( $status, $line ) = $this->capture_cli_document(
			static function (): int {
				return ( new BatchCommand() )->preview(
					array(),
					array(
						'filters-json' => '{}',
						'type'         => 'bulk',
					)
				);
			}
		);

		self::assertSame( ExitCodes::INVALID, $status );
		self::assertSame( 'schema_validation_failed', $line['error']['code'] );
		self::assertSame( '--filters-json must not be empty.', $line['error']['message'] );
	}

	public function test_batch_preview_requires_schema_version_for_limits_document(): void {
		$GLOBALS['pressqueue_test_current_user_caps'][ Capabilities::OPERATE_BATCHES ] = true;

		list( $status, $line ) = $this->capture_cli_document(
			static function (): int {
				return ( new BatchCommand() )->preview(
					array(),
					array(
						'limits-json' => '{"candidate_limit":1}',
						'post-ids'    => '42',
						'type'        => 'bulk',
					)
				);
			}
		);

		self::assertSame( ExitCodes::INVALID, $status );
		self::assertSame( 'schema_validation_failed', $line['error']['code'] );
		self::assertSame( '--limits-json schema_version must be 1.', $line['error']['message'] );
	}

	public function test_batch_preview_rejects_repost_only_filters_for_bulk_preview(): void {
		$GLOBALS['pressqueue_test_current_user_caps'][ Capabilities::OPERATE_BATCHES ] = true;

		list( $status, $line ) = $this->capture_cli_document(
			static function (): int {
				return ( new BatchCommand() )->preview(
					array(),
					array(
						'filters-json' => '{"schema_version":1,"prior_delivery_states":["accepted"]}',
						'type'         => 'bulk',
					)
				);
			}
		);

		self::assertSame( ExitCodes::INVALID, $status );
		self::assertSame( 'schema_validation_failed', $line['error']['code'] );
		self::assertSame( '--filters-json contains a repost-only key for a bulk preview.', $line['error']['message'] );
	}

	public function test_record_formatter_redacts_secret_shaped_documents(): void {
		$redacted = RecordFormatter::redacted_document(
			array(
				'body'   => str_repeat( 'x', 1001 ),
				'nested' => array(
					'api_key'    => 'secret-value',
					'credential' => 'another-secret',
					'note'       => 'Bearer abc123',
				),
				'public' => 'visible',
			)
		);

		self::assertSame( '[redacted]', $redacted['nested']['api_key'] );
		self::assertSame( '[redacted]', $redacted['nested']['credential'] );
		self::assertSame( '[redacted]', $redacted['nested']['note'] );
		self::assertSame( 'visible', $redacted['public'] );
		self::assertSame( 1003, strlen( $redacted['body'] ) );
	}

	/**
	 * Capture one emitted command document.
	 *
	 * @param callable():int $callback Callback.
	 * @return array{0:int,1:array<string,mixed>}
	 */
	private function capture_cli_document( callable $callback ): array {
		ob_start();
		$status = $callback();
		$output = ob_get_clean();

		self::assertIsString( $output );

		return array( $status, $this->decode_cli_line( $output ) );
	}

	/**
	 * Decode one JSON line.
	 *
	 * @param string $output Output.
	 * @return array<string,mixed>
	 */
	private function decode_cli_line( string $output ): array {
		$decoded = json_decode( trim( $output ), true );

		self::assertIsArray( $decoded );

		return $decoded;
	}
}
