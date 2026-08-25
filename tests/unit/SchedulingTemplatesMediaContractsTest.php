<?php
/**
 * Scheduling, template, capability, and media contract tests.
 *
 * @package PressQueue\Tests\Unit
 */

declare(strict_types=1);

namespace PressQueue\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use PressQueue\Editorial\PostControlMetadata;
use PressQueue\Planning\MediaResolver;
use PressQueue\Planning\MediaSnapshot;
use PressQueue\Planning\NormalizedEvent;
use PressQueue\Planning\SafeMediaUrlProbe;
use PressQueue\Planning\SchedulePolicy;
use PressQueue\Planning\ScheduleResolver;
use PressQueue\Planning\TemplateRenderer;
use PressQueue\Planning\TemplateSetResolver;
use PressQueue\Provider\Buffer\BufferCapabilityCatalog;
use PressQueue\Provider\Capability\DeliveryCapability;
use PressQueue\Storage\DeliveryPayloadDocuments;
use PressQueue\Storage\Record\RuleRecord;
use PressQueue\Storage\RuleDocuments;
use PressQueue\Storage\ValidationException;
use WP_Post;

use function pressqueue_test_reset_wordpress_stubs;

/**
 * @covers \PressQueue\Planning\MediaPolicy
 * @covers \PressQueue\Planning\MediaResolver
 * @covers \PressQueue\Planning\MediaSnapshot
 * @covers \PressQueue\Planning\SafeMediaUrlProbe
 * @covers \PressQueue\Planning\SchedulePolicy
 * @covers \PressQueue\Planning\ScheduleResolver
 * @covers \PressQueue\Planning\TemplateRenderer
 * @covers \PressQueue\Planning\TemplateSetResolver
 * @covers \PressQueue\Provider\Buffer\BufferCapabilityCatalog
 * @covers \PressQueue\Provider\Capability\DeliveryCapability
 * @covers \PressQueue\Storage\RuleDocuments
 */
final class SchedulingTemplatesMediaContractsTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		pressqueue_test_reset_wordpress_stubs();
	}

	public function test_template_set_validation_service_fallback_and_policy_documents(): void {
		$template_set = RuleDocuments::template_set(
			array(
				'default_message'  => '{{ title }}',
				'metadata'         => array(
					'audience' => '<strong>Editors</strong>',
				),
				'schema_version'   => 1,
				'service_messages' => array(
					'mastodon' => '{{ title | truncate:12 }}',
				),
			)
		);
		$resolver     = new TemplateSetResolver();

		self::assertSame( '{{ title | truncate:12 }}', $resolver->message_for_service( $template_set, 'mastodon' ) );
		self::assertSame( '{{ title }}', $resolver->message_for_service( $template_set, 'twitter' ) );
		self::assertSame( 'Editors', $template_set['metadata']['audience'] );
		self::assertSame(
			array(
				'mode'           => 'queue_end',
				'schema_version' => 1,
			),
			RuleDocuments::schedule_policy( 'queue_end' )
		);
		self::assertSame(
			array(
				'mode'           => 'featured_image',
				'schema_version' => 1,
			),
			RuleDocuments::media_policy(
				array(
					'mode'           => 'featured_image',
					'schema_version' => 1,
				)
			)
		);
	}

	public function test_reusable_template_creation_allows_meta_tags_until_rule_validation(): void {
		$template_set = RuleDocuments::template_set(
			array(
				'default_message'  => '{{ meta:campaign }}',
				'schema_version'   => 1,
				'service_messages' => array(
					'linkedin' => '{{ meta:campaign }} update',
				),
			)
		);

		self::assertSame( '{{ meta:campaign }}', $template_set['default_message'] );
		self::assertSame( $template_set, RuleDocuments::template_set( $template_set, array( 'campaign' ) ) );

		try {
			RuleDocuments::template_set( $template_set, array() );
			self::fail( 'Rule adoption must validate template meta tags against the destination allowlist.' );
		} catch ( ValidationException $exception ) {
			self::assertStringContainsString( 'custom field tag', $exception->getMessage() );
		}
	}

	public function test_empty_service_messages_and_rule_explicit_schedule_are_refused(): void {
		try {
			RuleDocuments::template_set(
				array(
					'default_message'  => '{{ title }}',
					'schema_version'   => 1,
					'service_messages' => array(
						'twitter' => '',
					),
				)
			);
			self::fail( 'Empty service messages must fail.' );
		} catch ( ValidationException $exception ) {
			self::assertStringContainsString( 'Template message', $exception->getMessage() );
		}

		$this->expectException( ValidationException::class );
		RuleDocuments::schedule_policy(
			array(
				'mode'           => 'explicit_time',
				'schema_version' => 1,
			)
		);
	}

	public function test_schedule_resolution_supports_rule_slots_explicit_times_and_dst_refusals(): void {
		$resolver = new ScheduleResolver( new DateTimeImmutable( '2026-08-25 12:00:00', new DateTimeZone( 'UTC' ) ) );
		$rule     = $this->rule(
			array(
				'schedule_policy'          => 'queue_next',
				'schedule_policy_document' => array(
					'mode'           => 'queue_next',
					'schema_version' => 1,
				),
			)
		);

		self::assertSame( 'queue_next', $resolver->resolve( $rule, PostControlMetadata::default_bundle() )->slot );

		$bundle   = PostControlMetadata::normalize_bundle(
			array(
				'channels'       => array( 'mode' => 'inherit' ),
				'media'          => array( 'mode' => 'inherit' ),
				'message'        => array( 'mode' => 'inherit' ),
				'policy'         => 'inherit',
				'schedule'       => array(
					'local_date' => '2026-09-15',
					'local_time' => '09:30',
					'mode'       => 'explicit_time',
					'timezone'   => 'Europe/Berlin',
				),
				'schema_version' => 2,
			)
		);
		$explicit = $resolver->resolve( $rule, $bundle );

		self::assertSame( 'explicit_time', $explicit->slot );
		self::assertSame( '2026-09-15 07:30:00', $explicit->scheduled_for_storage() );
		self::assertSame( 'Europe/Berlin', $explicit->timezone );

		$ambiguous                           = $bundle;
		$ambiguous['schedule']['local_date'] = '2026-10-25';
		$ambiguous['schedule']['local_time'] = '02:30';
		$this->expectException( ValidationException::class );
		$resolver->resolve( $rule, $ambiguous );
	}

	public function test_media_resolver_builds_featured_image_snapshot_and_reuses_matching_cache(): void {
		$GLOBALS['pressqueue_test_current_user_caps']['edit_post'] = true;
		$GLOBALS['pressqueue_test_posts'][42]                      = new WP_Post( array( 'ID' => 42 ) );
		$GLOBALS['pressqueue_test_posts'][99]                      = new WP_Post(
			array(
				'ID'                => 99,
				'post_mime_type'    => 'image/png',
				'post_modified_gmt' => '2026-08-25 10:00:00',
				'post_parent'       => 42,
				'post_type'         => 'attachment',
			)
		);
		$GLOBALS['pressqueue_test_featured_images'][42]            = 99;
		$GLOBALS['pressqueue_test_attachment_urls'][99]            = 'https://media.example.test/image.png';
		$GLOBALS['pressqueue_test_attachment_metadata'][99]        = array(
			'filesize' => 1234,
			'height'   => 480,
			'width'    => 640,
		);

		$capability = $this->capability();
		$probe      = new SafeMediaUrlProbe(
			static function (): array {
				return array( '93.184.216.34' );
			},
			static function ( string $method, string $url, array $args ): array {
				self::assertSame( 'HEAD', $method );
				self::assertSame( 'https://media.example.test/image.png', $url );
				self::assertSame( 1048576, $args['limit_response_size'] );

				return array(
					'byte_size' => 1234,
					'height'    => 480,
					'mime_type' => 'image/png',
					'status'    => 200,
					'width'     => 640,
				);
			}
		);

		$snapshot = ( new MediaResolver( $probe ) )->resolve(
			$this->rule(
				array(
					'media_policy' => array(
						'mode'           => 'featured_image',
						'schema_version' => 1,
					),
				)
			),
			$this->event(),
			$capability,
			true
		);

		self::assertNotNull( $snapshot->identity_hash() );
		self::assertSame( 99, $snapshot->document()['items'][0]['attachment_id'] );
		self::assertSame( DeliveryCapability::hash( $capability ), $snapshot->document()['items'][0]['capability_hash'] );

		$cache           = array(
			99 => array(
				'attachment_modified_at' => '2026-08-25 10:00:00',
				'byte_size'              => 1234,
				'capability_hash'        => DeliveryCapability::hash( $capability ),
				'height'                 => 480,
				'mime_type'              => 'image/png',
				'probed_at'              => gmdate( 'Y-m-d H:i:s' ),
				'url'                    => 'https://media.example.test/image.png',
				'width'                  => 640,
			),
		);
		$cached_snapshot = ( new MediaResolver( null, $cache ) )->resolve(
			$this->rule(
				array(
					'media_policy' => array(
						'mode'           => 'featured_image',
						'schema_version' => 1,
					),
				)
			),
			$this->event(),
			$capability,
			false
		);

		self::assertSame( $snapshot->document()['items'][0]['probe_result_hash'], $cached_snapshot->document()['items'][0]['probe_result_hash'] );
	}

	public function test_safe_media_probe_follows_cname_uses_pinned_address_and_allows_five_mb_image_facts(): void {
		$probe = new SafeMediaUrlProbe(
			static function ( string $host ): array {
				if ( 'media.example.test' === $host ) {
					return array( 'cdn.example.test' );
				}

				self::assertSame( 'cdn.example.test', $host );

				return array( '93.184.216.34' );
			},
			static function ( string $method, string $url, array $args ): array {
				self::assertSame( 'HEAD', $method );
				self::assertSame( 'https://media.example.test/image.png', $url );
				self::assertSame( '93.184.216.34', $args['pressqueue_resolved_ip'] );
				self::assertSame( 1048576, $args['limit_response_size'] );

				return array(
					'byte_size' => 2 * 1024 * 1024,
					'height'    => 1080,
					'mime_type' => 'image/png',
					'status'    => 200,
					'width'     => 1920,
				);
			}
		);

		$result = $probe->probe( 'https://media.example.test/image.png' );

		self::assertSame( 2 * 1024 * 1024, $result['byte_size'] );
		self::assertSame( 1920, $result['width'] );
	}

	public function test_media_probe_uses_attachment_metadata_when_head_has_no_dimensions(): void {
		$GLOBALS['pressqueue_test_current_user_caps']['edit_post'] = true;
		$GLOBALS['pressqueue_test_posts'][42]                      = new WP_Post( array( 'ID' => 42 ) );
		$GLOBALS['pressqueue_test_posts'][100]                     = new WP_Post(
			array(
				'ID'                => 100,
				'post_mime_type'    => 'image/jpeg',
				'post_modified_gmt' => '2026-08-25 10:00:00',
				'post_parent'       => 42,
				'post_type'         => 'attachment',
			)
		);
		$GLOBALS['pressqueue_test_featured_images'][42]            = 100;
		$GLOBALS['pressqueue_test_attachment_urls'][100]           = 'https://media.example.test/photo.jpg';
		$GLOBALS['pressqueue_test_attachment_metadata'][100]       = array(
			'filesize' => 4567,
			'height'   => 768,
			'width'    => 1024,
		);

		$probe = new SafeMediaUrlProbe(
			static function (): array {
				return array( '93.184.216.34' );
			},
			static function ( string $method ): array {
				self::assertSame( 'HEAD', $method );

				return array( 'status' => 200 );
			}
		);

		$snapshot = ( new MediaResolver( $probe ) )->resolve(
			$this->rule(
				array(
					'media_policy' => array(
						'mode'           => 'featured_image',
						'schema_version' => 1,
					),
				)
			),
			$this->event(),
			$this->capability(),
			true
		);

		self::assertSame( 'image/jpeg', $snapshot->document()['items'][0]['mime_type'] );
		self::assertSame( 4567, $snapshot->document()['items'][0]['byte_size'] );
		self::assertSame( 1024, $snapshot->document()['items'][0]['width'] );
		self::assertSame( 768, $snapshot->document()['items'][0]['height'] );

		$cached_snapshot = ( new MediaResolver(
			new SafeMediaUrlProbe(
				static function (): array {
					self::fail( 'Durable media planning must not perform DNS resolution when a fresh probe cache exists.' );
				},
				static function (): array {
					self::fail( 'Durable media planning must not perform HTTP when a fresh probe cache exists.' );
				}
			)
		) )->resolve(
			$this->rule(
				array(
					'media_policy' => array(
						'mode'           => 'featured_image',
						'schema_version' => 1,
					),
				)
			),
			$this->event(),
			$this->capability(),
			false
		);

		self::assertSame( $snapshot->identity_hash(), $cached_snapshot->identity_hash() );

		$GLOBALS['pressqueue_test_attachment_metadata'][100]['filesize'] = 4568;

		try {
			( new MediaResolver(
				new SafeMediaUrlProbe(
					static function (): array {
						self::fail( 'Stale media cache reuse must not perform DNS when probing is disallowed.' );
					},
					static function (): array {
						self::fail( 'Stale media cache reuse must not perform HTTP when probing is disallowed.' );
					}
				)
			) )->resolve(
				$this->rule(
					array(
						'media_policy' => array(
							'mode'           => 'featured_image',
							'schema_version' => 1,
						),
					)
				),
				$this->event(),
				$this->capability(),
				false
			);
			self::fail( 'Cached probes must be invalidated when attachment facts change.' );
		} catch ( ValidationException $exception ) {
			self::assertStringContainsString( 'missing or stale', $exception->getMessage() );
		}
	}

	public function test_safe_media_probe_refuses_private_addresses_and_capability_limits_media(): void {
		foreach (
			array(
				'10.0.0.5',
				'100.64.0.1',
				'192.0.2.1',
				'198.18.0.1',
				'::ffff:127.0.0.1',
				'64:ff9b::0808:0808',
				'2001:20::1',
				'3fff::1',
			) as $address
		) {
			try {
				( new SafeMediaUrlProbe(
					static function () use ( $address ): array {
						return array( $address );
					},
					static function (): array {
						return array();
					}
				) )->probe( 'https://media.example.test/image.png' );
				self::fail( 'Unsafe media address was accepted: ' . $address );
			} catch ( ValidationException $exception ) {
				self::assertStringContainsString( 'unsafe address', $exception->getMessage() );
			}
		}
	}

	public function test_capability_and_schema_two_payload_preview_cover_text_schedule_and_media(): void {
		$capability = $this->capability();
		$media      = MediaSnapshot::none();
		$schedule   = new SchedulePolicy( 'immediate' );
		$preview    = ( new TemplateRenderer() )->payload_preview_v2(
			'{{ title }} {{ canonical_url }}',
			array(
				'default_message' => '{{ title }} {{ canonical_url }}',
				'schema_version'  => 1,
			),
			array( 'kind' => 'embedded' ),
			array(),
			$this->event(),
			array(
				'channel_id'           => 3,
				'channel_label'        => 'Example Channel',
				'channel_public_id'    => '01K8Z8D0PKH2N8J7S3K5A9C6DF',
				'connection_id'        => 2,
				'connection_label'     => 'Example Connection',
				'connection_public_id' => '01K8Z8D0PKH2N8J7S3K5A9C6DE',
				'provider'             => 'buffer',
				'remote_channel_id'    => 'remote-channel-1',
				'service'              => 'twitter',
			),
			'01K8Z8D0PKH2N8J7S3K5A9C6DG',
			4,
			$schedule,
			$media,
			$capability
		);

		self::assertSame( 2, $preview['payload_schema_version'] );
		self::assertSame( 2, $preview['adapter_encoding_version'] );
		self::assertSame( "Launch https://example.test/post\n\nhttps://example.test/post", $preview['final_encoded_text'] );
		self::assertTrue( $preview['capability_decision']['compatible'] );
		self::assertSame( BufferCapabilityCatalog::VERSION, $preview['bound_capability_catalog_version'] );
		self::assertTrue( DeliveryCapability::supports_payload( $capability, 'immediate', $preview['final_encoded_text_length'], $preview['media_snapshot'] ) );

		$missing_media = $preview;
		unset( $missing_media['media_snapshot'] );
		try {
			DeliveryPayloadDocuments::schema_two_facts( $missing_media, 'immediate', null, null, null );
			self::fail( 'Schema version 2 payloads must include media snapshots.' );
		} catch ( ValidationException $exception ) {
			self::assertStringContainsString( 'media snapshot', $exception->getMessage() );
		}

		$understated_length                              = $preview;
		$understated_length['final_encoded_text_length'] = 1;
		try {
			DeliveryPayloadDocuments::schema_two_facts( $understated_length, 'immediate', null, null, null );
			self::fail( 'Schema version 2 payloads must not trust understated text lengths.' );
		} catch ( ValidationException $exception ) {
			self::assertStringContainsString( 'length is inconsistent', $exception->getMessage() );
		}

		self::assertFalse(
			DeliveryCapability::supports_payload(
				$capability,
				'immediate',
				10,
				array(
					'items'          => array( array() ),
					'mode'           => 'featured_image',
					'schema_version' => 1,
				)
			)
		);
	}

	public function test_media_identity_ignores_capability_and_probe_provenance(): void {
		$item  = array(
			'alt_text'                   => 'Alt',
			'attachment_id'              => 100,
			'attachment_modified_at'     => '2026-08-25 10:00:00',
			'byte_size'                  => 4567,
			'capability_catalog_version' => BufferCapabilityCatalog::VERSION,
			'capability_hash'            => hash( 'sha256', 'capability-a' ),
			'capability_sources'         => array(),
			'height'                     => 768,
			'image_kind'                 => 'image',
			'mime_type'                  => 'image/jpeg',
			'position'                   => 0,
			'probe_result_hash'          => hash( 'sha256', 'probe-a' ),
			'url'                        => 'https://media.example.test/photo.jpg',
			'width'                      => 1024,
		);
		$first = new MediaSnapshot(
			array(
				'items'          => array( $item ),
				'mode'           => 'featured_image',
				'schema_version' => 1,
			)
		);

		$item['capability_hash']   = hash( 'sha256', 'capability-b' );
		$item['probe_result_hash'] = hash( 'sha256', 'probe-b' );
		$second                    = new MediaSnapshot(
			array(
				'items'          => array( $item ),
				'mode'           => 'featured_image',
				'schema_version' => 1,
			)
		);

		self::assertSame( $first->identity_hash(), $second->identity_hash() );

		$item['byte_size'] = 4568;
		$third             = new MediaSnapshot(
			array(
				'items'          => array( $item ),
				'mode'           => 'featured_image',
				'schema_version' => 1,
			)
		);

		self::assertNotSame( $first->identity_hash(), $third->identity_hash() );
	}

	/**
	 * Return a Buffer schema 2 capability.
	 *
	 * @return array<string,mixed>
	 */
	private function capability(): array {
		return ( new BufferCapabilityCatalog() )->capability_for_service( 'twitter' );
	}

	/**
	 * Build a rule fixture.
	 *
	 * @param array<string,mixed> $overrides Overrides.
	 * @return RuleRecord
	 */
	private function rule( array $overrides = array() ): RuleRecord {
		$now = new DateTimeImmutable( '2026-08-25 12:00:00', new DateTimeZone( 'UTC' ) );

		return new RuleRecord(
			1,
			'01K8Z8D0PKH2N8J7S3K5A9C6DG',
			'Rule',
			'include',
			100,
			array(
				'schema_version' => 1,
				'triggers'       => array( 'first_publish' ),
			),
			array(
				'children'       => array(
					array(
						'condition' => 'post_type',
						'operator'  => 'equals',
						'type'      => 'condition',
						'value'     => 'post',
					),
				),
				'mode'           => 'ALL',
				'schema_version' => 1,
				'type'           => 'group',
			),
			array(
				'custom_field_allowlist' => array(),
				'message'                => '{{ title }}',
				'schema_version'         => 1,
			),
			null,
			null,
			array(
				'default_message' => '{{ title }}',
				'schema_version'  => 1,
			),
			array(
				'destinations'   => array(),
				'schema_version' => 1,
			),
			array(),
			(string) ( $overrides['schedule_policy'] ?? 'immediate' ),
			$overrides['schedule_policy_document'] ?? array(
				'mode'           => 'immediate',
				'schema_version' => 1,
			),
			$overrides['media_policy'] ?? array(
				'mode'           => 'none',
				'schema_version' => 1,
			),
			false,
			null,
			1,
			1,
			$now,
			$now
		);
	}

	/**
	 * Build an event fixture.
	 *
	 * @return NormalizedEvent
	 */
	private function event(): NormalizedEvent {
		return new NormalizedEvent(
			'preview',
			'unit',
			'first_publish',
			42,
			'post',
			'publish',
			7,
			'https://example.test/post',
			'2026-08-25 10:00:00',
			'2026-08-25 10:00:00',
			array(),
			array(),
			array(
				'canonical_url' => 'https://example.test/post',
				'title'         => 'Launch',
			),
			null,
			null,
			PostControlMetadata::default_bundle()
		);
	}
}
