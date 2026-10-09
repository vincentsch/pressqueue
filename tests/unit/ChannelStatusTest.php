<?php
/**
 * Channels screen wording tests.
 *
 * @package PressQueue\Tests\Unit
 */

declare(strict_types=1);

namespace {
	if ( ! function_exists( '_n' ) ) {
		/**
		 * Minimal plural stub for unit tests.
		 *
		 * @param string $single Singular form.
		 * @param string $plural Plural form.
		 * @param int    $number Number.
		 * @param string $domain Text domain.
		 * @return string
		 */
		function _n( $single, $plural, $number, $domain = 'default' ) {
			unset( $domain );

			return 1 === (int) $number ? $single : $plural;
		}
	}
}

namespace PressQueue\Tests\Unit {
	use DateTimeImmutable;
	use DateTimeZone;
	use PHPUnit\Framework\TestCase;
	use PressQueue\Admin\ChannelStatus;
	use PressQueue\Provider\Buffer\BufferCapabilityCatalog;
	use PressQueue\Storage\Record\ChannelRecord;
	use PressQueue\Storage\Record\RuleRecord;

	use function pressqueue_test_reset_wordpress_stubs;

	/**
	 * @covers \PressQueue\Admin\ChannelStatus
	 */
	final class ChannelStatusTest extends TestCase {
		private const ORG = 'org_one';

		protected function setUp(): void {
			parent::setUp();

			pressqueue_test_reset_wordpress_stubs();
		}

		public function test_usage_counts_only_enabled_sharing_rules_and_counts_each_rule_once(): void {
			$counts = ChannelStatus::usage_counts(
				array(
					$this->rule( 'include', true, array( 'A', 'B', 'A' ) ),
					$this->rule( 'include', true, array( 'A' ) ),
					$this->rule( 'include', false, array( 'B' ) ),
					$this->rule( 'exclude', true, array() ),
				)
			);

			self::assertSame(
				array(
					'A' => 2,
					'B' => 1,
				),
				$counts
			);
		}

		public function test_summary_names_the_network_and_its_limits(): void {
			self::assertSame( 'LinkedIn · up to 280 characters with the link · 1 image', ChannelStatus::summary( $this->channel( 'linkedin' ) ) );
			self::assertSame( 'X · up to 280 characters with the link · 1 image', ChannelStatus::summary( $this->channel( 'twitter' ) ) );
		}

		public function test_mastodon_summary_explains_the_lower_limit(): void {
			$channel = $this->channel( 'mastodon', 'available', array(), array( 'maxCharacters' => 500 ) );

			self::assertSame( 'Mastodon · up to 280 characters with the link · 1 image. PressQueue uses the lower of the server limit and 280.', ChannelStatus::summary( $channel ) );

			$small = $this->channel( 'mastodon', 'available', array(), array( 'maxCharacters' => 200 ) );
			self::assertStringContainsString( 'up to 200 characters', ChannelStatus::summary( $small ) );
		}

		public function test_usable_channel_has_no_problem(): void {
			self::assertNull( ChannelStatus::problem( $this->channel( 'linkedin' ), self::ORG ) );
			self::assertTrue( ChannelStatus::can_switch_on( $this->channel( 'linkedin' ) ) );
		}

		/**
		 * @dataProvider problemProvider
		 *
		 * @param ChannelRecord $channel Channel.
		 * @param string        $key Expected reason key.
		 * @param string        $text Expected words in the sentence.
		 */
		public function test_unavailable_channels_explain_why_in_words( ChannelRecord $channel, string $key, string $text ): void {
			$problem = ChannelStatus::problem( $channel, self::ORG );

			self::assertNotNull( $problem );
			self::assertSame( $key, $problem['key'] );
			self::assertStringContainsString( $text, $problem['text'] );
			self::assertFalse( ChannelStatus::can_switch_on( $channel ) );
		}

		/**
		 * @return array<string,array{0:ChannelRecord,1:string,2:string}>
		 */
		public function problemProvider(): array {
			return array(
				'unsupported service' => array( $this->channel( 'instagram', 'unsupported', array(), array(), false ), 'unsupported_service', 'image-first posts' ),
				'video network'       => array( $this->channel( 'tiktok', 'unsupported', array(), array(), false ), 'unsupported_service', 'video posts' ),
				'disconnected'        => array( $this->channel( 'linkedin', 'unsupported', array( 'isDisconnected' => true ), array(), false ), 'disconnected', 'Reconnect it in Buffer' ),
				'locked'              => array( $this->channel( 'linkedin', 'unsupported', array( 'isLocked' => true ), array(), false ), 'locked', 'Buffer plan' ),
				'queue paused'        => array( $this->channel( 'linkedin', 'unsupported', array( 'isQueuePaused' => true ), array(), false ), 'queue_paused', 'queue for this channel is paused' ),
				'unknown status'      => array( $this->channel( 'linkedin', 'unsupported', array( 'isLocked' => 'unknown' ), array(), false ), 'unknown_status', 'keeps it off to be safe' ),
				'missing permission'  => array( $this->channel( 'linkedin', 'unsupported', array(), array(), false ), 'missing_permission', 'not allowed to post' ),
				'other organization'  => array( $this->channel( 'linkedin', 'unsupported', array(), array(), false, 'org_two' ), 'other_organization', 'another Buffer organization' ),
				'missing in buffer'   => array( $this->channel( 'linkedin', 'missing' ), 'missing', 'no longer lists this channel' ),
				'never checked'       => array( $this->channel( 'linkedin', 'unverified' ), 'unchecked', 'has not checked' ),
				'mastodon limit'      => array( $this->channel( 'mastodon', 'unsupported' ), 'unknown_limit', 'character limit' ),
			);
		}

		public function test_sort_puts_usable_channels_first(): void {
			$sorted = ChannelStatus::sort(
				array(
					$this->channel( 'instagram', 'unsupported', array(), array(), false ),
					$this->channel( 'twitter' ),
					$this->channel( 'linkedin', 'missing' ),
					$this->channel( 'bluesky' ),
				)
			);

			self::assertSame( array( 'bluesky', 'twitter', 'instagram', 'linkedin' ), array_map( static fn ( ChannelRecord $channel ): string => $channel->service, $sorted ) );
		}

		/**
		 * Build a channel record the way discovery stores it.
		 *
		 * @param string              $service Service.
		 * @param string              $availability Availability.
		 * @param array<string,mixed> $flags Status flag overrides.
		 * @param array<string,mixed> $metadata Provider metadata.
		 * @param bool                $supported Whether discovery found no problem.
		 * @param string              $organization Organization ID.
		 * @return ChannelRecord
		 */
		private function channel( string $service, string $availability = 'available', array $flags = array(), array $metadata = array(), bool $supported = true, string $organization = self::ORG ): ChannelRecord {
			$now        = new DateTimeImmutable( '2026-10-09 10:00:00', new DateTimeZone( 'UTC' ) );
			$capability = ( new BufferCapabilityCatalog() )->capability_for_service( $service, $metadata, $supported );

			return new ChannelRecord(
				1,
				'01H00000000000000000000000',
				1,
				'01H00000000000000000000001',
				'buffer',
				$organization,
				'chan_' . $service,
				$service,
				array(
					'display_name'     => ucfirst( $service ) . ' page',
					'raw_status_flags' => array_merge(
						array(
							'isDisconnected' => false,
							'isLocked'       => false,
							'isQueuePaused'  => false,
						),
						$flags
					),
				),
				$capability,
				$availability,
				false,
				$now,
				$now,
				$now,
				$now,
				$now
			);
		}

		/**
		 * Build a rule record.
		 *
		 * @param string            $effect Effect.
		 * @param bool              $enabled Whether the rule is on.
		 * @param array<int,string> $channel_ids Destination channel public IDs.
		 * @return RuleRecord
		 */
		private function rule( string $effect, bool $enabled, array $channel_ids ): RuleRecord {
			$now          = new DateTimeImmutable( '2026-10-09 10:00:00', new DateTimeZone( 'UTC' ) );
			$destinations = 'exclude' === $effect
				? array(
					'schema_version' => 1,
					'scope'          => 'all',
				)
				: array(
					'schema_version' => 1,
					'destinations'   => array_map(
						static function ( string $id ): array {
							return array( 'channel_public_id' => $id );
						},
						$channel_ids
					),
				);

			return new RuleRecord( 1, '01H00000000000000000000002', 'Rule', $effect, 10, array(), array(), array(), null, null, null, $destinations, array(), 'queue_end', array(), array(), $enabled, null, 1, 1, $now, $now );
		}
	}
}
