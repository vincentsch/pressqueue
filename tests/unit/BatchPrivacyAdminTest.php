<?php
/**
 * Share old posts and Data and privacy controller tests.
 *
 * @package PressQueue\Tests\Unit
 */

declare(strict_types=1);

namespace PressQueue\Tests\Unit;

use DomainException;
use PHPUnit\Framework\TestCase;
use PressQueue\Admin\BatchController;
use PressQueue\Admin\PrivacyController;
use PressQueue\Batch\BatchLimitService;
use PressQueue\Batch\RepostCriteriaService;
use PressQueue\Privacy\PrivacySettings;
use PressQueue\Security\Capabilities;
use PressQueue\Storage\StaleRecordException;
use PressQueue\Storage\ValidationException;
use ReflectionMethod;
use RuntimeException;

use function pressqueue_test_reset_wordpress_stubs;

/**
 * @covers \PressQueue\Admin\BatchController
 * @covers \PressQueue\Admin\PrivacyController
 */
final class BatchPrivacyAdminTest extends TestCase {
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

	public function test_guided_form_builds_documents_the_batch_domain_accepts(): void {
		$GLOBALS['pressqueue_test_timezone'] = 'Europe/Berlin';

		$documents = $this->invoke(
			BatchController::class,
			'documents_from_form',
			array(
				$this->form(
					array(
						'category'         => 12,
						'max_posts'        => 15,
						'published_after'  => '2026-06-01',
						'published_before' => '2026-06-30',
					)
				),
			)
		);

		self::assertSame( 'bulk', $documents['batch_type'] );
		self::assertSame(
			array(
				'post_statuses'      => array( 'publish' ),
				'post_types'         => array( 'post' ),
				'schema_version'     => 1,
				'taxonomies'         => array(
					array(
						'operator' => 'has_any',
						'taxonomy' => 'category',
						'term_ids' => array( 12 ),
					),
				),
				'published_from_utc' => '2026-05-31 22:00:00',
				'published_to_utc'   => '2026-06-30 21:59:59',
			),
			$documents['filters']
		);
		self::assertSame( 15, $documents['limits']['candidate_limit'] );
		self::assertSame( BatchLimitService::MAX_DESTINATION_ITEMS, $documents['limits']['destination_item_limit'] );

		$normalized = ( new BatchLimitService() )->normalize( $documents['limits'] );
		self::assertSame( 'queue_end', $normalized['schedule']['mode'] );
	}

	public function test_repost_gap_only_applies_to_share_again(): void {
		$bulk = $this->invoke( BatchController::class, 'documents_from_form', array( $this->form( array( 'repost_min_days' => '45' ) ) ) );
		self::assertArrayNotHasKey( 'minimum_days_since_prior_acceptance', $bulk['filters'] );

		$repost = $this->invoke(
			BatchController::class,
			'documents_from_form',
			array(
				$this->form(
					array(
						'batch_type'      => 'repost',
						'repost_min_days' => '45',
					)
				),
			)
		);
		self::assertSame( 'repost', $repost['batch_type'] );
		self::assertSame( 45, $repost['filters']['minimum_days_since_prior_acceptance'] );
		self::assertSame( 45, ( new RepostCriteriaService() )->normalize( $repost['filters'] )['minimum_days_since_prior_acceptance'] );

		$any_gap = $this->invoke(
			BatchController::class,
			'documents_from_form',
			array(
				$this->form(
					array(
						'batch_type'      => 'repost',
						'repost_min_days' => '',
					)
				),
			)
		);
		self::assertArrayNotHasKey( 'minimum_days_since_prior_acceptance', $any_gap['filters'] );
	}

	/**
	 * @dataProvider invalidFormProvider
	 *
	 * @param array<string,mixed> $changes Form changes.
	 * @param string              $code Expected error code.
	 */
	public function test_invalid_guided_form_values_fail_with_plain_codes( array $changes, string $code ): void {
		try {
			$this->invoke( BatchController::class, 'documents_from_form', array( $this->form( $changes ) ) );
			self::fail( 'Invalid form values must be refused.' );
		} catch ( DomainException $exception ) {
			self::assertSame( $code, $exception->getMessage() );
			self::assertArrayHasKey( $code, $this->invoke( BatchController::class, 'error_messages' ) );
		}
	}

	/**
	 * @return array<string,array{0:array<string,mixed>,1:string}>
	 */
	public static function invalidFormProvider(): array {
		return array(
			'dates out of order'  => array(
				array(
					'published_after'  => '2026-07-01',
					'published_before' => '2026-06-01',
				),
				'date_order',
			),
			'impossible date'     => array( array( 'published_after' => '2026-02-31' ), 'date_order' ),
			'too many posts'      => array( array( 'max_posts' => BatchLimitService::MAX_CANDIDATE_LIMIT + 1 ), 'max_posts' ),
			'zero posts'          => array( array( 'max_posts' => 0 ), 'max_posts' ),
			'unknown post type'   => array( array( 'post_type' => 'nope' ), 'post_type' ),
			'bad repost gap'      => array(
				array(
					'batch_type'      => 'repost',
					'repost_min_days' => '0',
				),
				'repost_days',
			),
			'unknown timing'      => array( array( 'timing' => 'whenever' ), 'timing' ),
			'start in the past'   => array(
				array(
					'start_date' => '2020-01-01',
					'start_time' => '09:00',
					'timing'     => 'staggered_explicit_time',
				),
				'start_soon',
			),
			'malformed start'     => array(
				array(
					'start_date' => 'tomorrow',
					'timing'     => 'staggered_explicit_time',
				),
				'start_invalid',
			),
			'gap outside choices' => array(
				array(
					'interval'   => 7,
					'start_date' => gmdate( 'Y-m-d', time() + 3 * 86400 ),
					'timing'     => 'staggered_explicit_time',
				),
				'interval',
			),
		);
	}

	public function test_one_at_a_time_converts_the_local_start_to_utc(): void {
		$GLOBALS['pressqueue_test_timezone'] = 'Europe/Berlin';
		$start                               = ( new \DateTimeImmutable( '+3 days', new \DateTimeZone( 'Europe/Berlin' ) ) )->format( 'Y-m-d' );

		$schedule = $this->invoke(
			BatchController::class,
			'schedule_from_form',
			array(
				$this->form(
					array(
						'interval'   => 360,
						'start_date' => $start,
						'start_time' => '09:30',
						'timing'     => 'staggered_explicit_time',
					)
				),
			)
		);

		$expected = ( new \DateTimeImmutable( $start . ' 09:30:00', new \DateTimeZone( 'Europe/Berlin' ) ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		self::assertSame( 'staggered_explicit_time', $schedule['mode'] );
		self::assertSame( $expected, $schedule['first_due_at_utc'] );
		self::assertSame( 360, $schedule['interval_minutes'] );
		self::assertSame( 'Europe/Berlin', $schedule['timezone'] );

		$normalized = ( new BatchLimitService() )->normalize(
			array(
				'candidate_limit' => 5,
				'schedule'        => $schedule,
				'schema_version'  => 1,
			)
		);
		self::assertSame( $expected, $normalized['schedule']['first_due_at_utc'] );
	}

	public function test_domain_failures_map_to_plain_messages_without_leaking_raw_text(): void {
		$messages = $this->invoke( BatchController::class, 'error_messages' );

		self::assertSame( 'changed', $this->invoke( BatchController::class, 'error_code', array( new ValidationException( 'Batch preview changed. Create a fresh preview before confirming.' ) ) ) );
		self::assertSame( 'too_many', $this->invoke( BatchController::class, 'error_code', array( new ValidationException( 'Batch preview is truncated. Narrow the filters before confirming.' ) ) ) );
		self::assertSame( 'runtime', $this->invoke( BatchController::class, 'error_code', array( new ValidationException( 'PressQueue runtime is disabled.' ) ) ) );
		self::assertSame( 'busy', $this->invoke( BatchController::class, 'error_code', array( new StaleRecordException( 'Batch confirmation lost the status race.' ) ) ) );
		self::assertSame( 'unknown', $this->invoke( BatchController::class, 'error_code', array( new ValidationException( 'secret internal detail' ) ) ) );
		self::assertSame( 'unknown', $this->invoke( BatchController::class, 'error_code', array( new DomainException( 'not-a-code' ) ) ) );
		self::assertSame( 'unknown_tag', $this->invoke( BatchController::class, 'error_code', array( new DomainException( 'unknown_tag' ) ) ) );

		foreach ( $messages as $message ) {
			self::assertStringNotContainsString( "\u{2014}", $message );
		}
	}

	public function test_skipped_channels_with_the_same_reason_are_merged(): void {
		$merged = $this->invoke(
			BatchController::class,
			'merge_skips',
			array(
				array(
					array(
						'channel' => array(
							'name'    => 'A',
							'service' => 'x',
						),
						'reason'  => 'Same',
					),
					array(
						'channel' => array(
							'name'    => 'B',
							'service' => 'bluesky',
						),
						'reason'  => 'Same',
					),
					array(
						'channel' => null,
						'reason'  => 'Whole post',
					),
				),
			)
		);

		self::assertCount( 2, $merged );
		self::assertSame( 'Same', $merged[0]['reason'] );
		self::assertCount( 2, $merged[0]['channels'] );
		self::assertSame( array(), $merged[1]['channels'] );
	}

	/**
	 * @dataProvider batchHandlerProvider
	 *
	 * @param string $handler Handler method.
	 * @param string $nonce_action Nonce action.
	 */
	public function test_batch_handlers_require_capability_post_and_nonce( string $handler, string $nonce_action ): void {
		$this->assertBoundary( array( BatchController::class, $handler ), $nonce_action, Capabilities::OPERATE_BATCHES );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function batchHandlerProvider(): array {
		return array(
			'preview' => array( 'handle_preview', 'pressqueue_preview_batch' ),
			'confirm' => array( 'handle_confirm', 'pressqueue_confirm_batch' ),
			'cancel'  => array( 'handle_cancel', 'pressqueue_cancel_batch' ),
			'recover' => array( 'handle_recover', 'pressqueue_recover_batch' ),
		);
	}

	/**
	 * @dataProvider privacyHandlerProvider
	 *
	 * @param string $handler Handler method.
	 * @param string $nonce_action Nonce action.
	 * @param string $capability Capability.
	 */
	public function test_privacy_handlers_require_capability_post_and_nonce( string $handler, string $nonce_action, string $capability ): void {
		$this->assertBoundary( array( PrivacyController::class, $handler ), $nonce_action, $capability );
	}

	/**
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public static function privacyHandlerProvider(): array {
		return array(
			'save'      => array( 'handle_save_settings', 'pressqueue_save_privacy_settings', Capabilities::MANAGE_SETTINGS ),
			'export'    => array( 'handle_export', 'pressqueue_export_data', Capabilities::EXPORT_DATA ),
			'retention' => array( 'handle_run_retention', 'pressqueue_run_retention', Capabilities::MANAGE_SETTINGS ),
		);
	}

	public function test_section_saves_only_change_their_own_fields(): void {
		$current = array(
			'delete_data_on_uninstall' => true,
			'retention_days'           => 365,
			'retention_enabled'        => false,
			'schema_version'           => 1,
		);

		$retention = $this->invoke(
			PrivacyController::class,
			'settings_from_request',
			array(
				$current,
				'retention',
				array(
					'retention_days'    => '90',
					'retention_enabled' => '1',
				),
			)
		);
		self::assertTrue( $retention['delete_data_on_uninstall'] );
		self::assertTrue( $retention['retention_enabled'] );
		self::assertSame( 90, PrivacySettings::normalize( $retention )['retention_days'] );

		$uninstall = $this->invoke( PrivacyController::class, 'settings_from_request', array( $current, 'uninstall', array() ) );
		self::assertFalse( $uninstall['delete_data_on_uninstall'] );
		self::assertFalse( $uninstall['retention_enabled'] );
		self::assertSame( 365, $uninstall['retention_days'] );

		// Older forms post every field at once: a missing box still means off.
		$legacy = $this->invoke( PrivacyController::class, 'settings_from_request', array( $current, '', array( 'retention_days' => '400' ) ) );
		self::assertFalse( $legacy['delete_data_on_uninstall'] );
		self::assertFalse( $legacy['retention_enabled'] );
		self::assertSame( '400', $legacy['retention_days'] );
	}

	public function test_turning_on_deletion_needs_the_box_or_the_exact_sentence(): void {
		$_POST = array( 'acknowledge_uninstall_effect' => '1' );
		$this->invoke( PrivacyController::class, 'require_acknowledgment', array( 'acknowledge_uninstall_effect', 'confirm_uninstall_effect', PrivacySettings::UNINSTALL_CONFIRMATION, 'uninstall_ack' ) );

		$_POST = array( 'confirm_uninstall_effect' => PrivacySettings::UNINSTALL_CONFIRMATION );
		$this->invoke( PrivacyController::class, 'require_acknowledgment', array( 'acknowledge_uninstall_effect', 'confirm_uninstall_effect', PrivacySettings::UNINSTALL_CONFIRMATION, 'uninstall_ack' ) );

		foreach ( array( array(), array( 'acknowledge_uninstall_effect' => 'yes' ), array( 'confirm_uninstall_effect' => 'I understand' ) ) as $post ) {
			$_POST = $post;
			try {
				$this->invoke( PrivacyController::class, 'require_acknowledgment', array( 'acknowledge_uninstall_effect', 'confirm_uninstall_effect', PrivacySettings::UNINSTALL_CONFIRMATION, 'uninstall_ack' ) );
				self::fail( 'Deletion on uninstall must stay an explicit opt-in.' );
			} catch ( DomainException $exception ) {
				self::assertSame( 'uninstall_ack', $exception->getMessage() );
			}
		}

		self::assertFalse( PrivacySettings::defaults()['delete_data_on_uninstall'] );
		self::assertFalse( PrivacySettings::defaults()['retention_enabled'] );
	}

	public function test_privacy_failures_map_to_plain_messages(): void {
		self::assertSame( 'days', $this->invoke( PrivacyController::class, 'error_code', array( new ValidationException( 'Retention days must be between 30 and 3650.' ) ) ) );
		self::assertSame( 'export_cursor', $this->invoke( PrivacyController::class, 'error_code', array( new ValidationException( 'Export cursor is invalid.' ) ) ) );
		self::assertSame( 'retention_ack', $this->invoke( PrivacyController::class, 'error_code', array( new DomainException( 'retention_ack' ) ) ) );
		self::assertSame( 'unknown', $this->invoke( PrivacyController::class, 'error_code', array( new RuntimeException( 'raw' ) ) ) );
	}

	/**
	 * Assert that a handler refuses missing capability, GET requests and bad nonces.
	 *
	 * @param callable $handler Handler.
	 * @param string   $nonce_action Nonce action.
	 * @param string   $capability Capability.
	 */
	private function assertBoundary( callable $handler, string $nonce_action, string $capability ): void {
		$_SERVER['REQUEST_METHOD']                                = 'POST';
		$_REQUEST['_wpnonce']                                     = 'valid-nonce';
		$GLOBALS['pressqueue_test_valid_nonces'][ $nonce_action ] = 'valid-nonce';

		$this->assertRefused( $handler, 'not allowed' );

		$GLOBALS['pressqueue_test_current_user_caps'][ $capability ] = true;
		$_SERVER['REQUEST_METHOD']                                    = 'GET';
		$this->assertRefused( $handler, 'require POST' );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_REQUEST['_wpnonce']      = 'wrong';
		$this->assertRefused( $handler, 'Nonce check failed.' );
	}

	/**
	 * Assert a handler stops with wp_die.
	 *
	 * @param callable $handler Handler.
	 * @param string   $message Expected message fragment.
	 */
	private function assertRefused( callable $handler, string $message ): void {
		try {
			$handler();
			self::fail( 'Handler must refuse the request.' );
		} catch ( RuntimeException $exception ) {
			self::assertStringContainsString( $message, $exception->getMessage() );
		}
	}

	/**
	 * Return guided form values.
	 *
	 * @param array<string,mixed> $changes Changes.
	 * @return array<string,mixed>
	 */
	private function form( array $changes = array() ): array {
		return array_merge(
			array(
				'author'           => 0,
				'batch_type'       => 'bulk',
				'category'         => 0,
				'interval'         => 1440,
				'max_posts'        => 10,
				'post_type'        => 'post',
				'published_after'  => '',
				'published_before' => '',
				'repost_min_days'  => '30',
				'start_date'       => '',
				'start_time'       => '09:00',
				'tags'             => '',
				'timing'           => 'queue_end',
				'title_search'     => '',
			),
			$changes
		);
	}

	/**
	 * Invoke a private static method.
	 *
	 * @param class-string     $class_name Class.
	 * @param string           $method Method.
	 * @param array<int,mixed> $args Arguments.
	 * @return mixed
	 */
	private function invoke( string $class_name, string $method, array $args = array() ) {
		$reflection = new ReflectionMethod( $class_name, $method );
		$reflection->setAccessible( true );

		return $reflection->invokeArgs( null, $args );
	}
}
