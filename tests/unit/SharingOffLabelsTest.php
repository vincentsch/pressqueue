<?php
/**
 * "Sharing off" label and detection tests.
 *
 * @package PressQueue\Tests\Unit
 */

declare(strict_types=1);

namespace PressQueue\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use PressQueue\Admin\ActivitySummary;
use PressQueue\Admin\Labels;
use PressQueue\Batch\PostShareNowService;
use PressQueue\Storage\Record\DecisionRecord;

/**
 * @covers \PressQueue\Admin\ActivitySummary
 * @covers \PressQueue\Admin\Labels
 * @covers \PressQueue\Admin\PostSharingSummary
 * @covers \PressQueue\Batch\PostShareNowService
 */
final class SharingOffLabelsTest extends TestCase {
	public function test_the_post_switch_reads_as_a_calm_choice_and_never_share_keeps_its_tone(): void {
		$off   = Labels::decision_result( 'switched_off' );
		$never = Labels::decision_result( 'excluded' );

		self::assertSame( 'Sharing off', $off['label'] );
		self::assertSame( 'idle', $off['tone'] );
		self::assertSame( '"Share this post" was switched off for this post.', $off['help'] );
		self::assertSame( 'Never share', $never['label'] );
		self::assertSame( 'bad', $never['tone'] );
		self::assertSame( 'unknown', Labels::decision_result( 'unknown' )['label'] );
	}

	public function test_the_editor_summary_names_the_image_each_share_gets(): void {
		$image_text = new \ReflectionMethod( \PressQueue\Admin\PostSharingSummary::class, 'image_text' );
		$image      = array( 'url' => 'https://example.com/image.jpg' );

		self::assertSame( 'with a social image of the title', $image_text->invoke( null, array( 'media_snapshot' => array( 'mode' => 'social_card', 'items' => array( $image ) ) ) ) );
		self::assertSame( 'with the featured image', $image_text->invoke( null, array( 'media_snapshot' => array( 'mode' => 'featured_image_optional', 'items' => array( $image ) ) ) ) );
		self::assertSame( 'with 1 chosen image', $image_text->invoke( null, array( 'media_snapshot' => array( 'mode' => 'post_media', 'items' => array( $image ) ) ) ) );
		self::assertSame( 'without an image', $image_text->invoke( null, array( 'media_snapshot' => array( 'mode' => 'social_card', 'items' => array() ) ) ) );
	}

	public function test_only_an_exclusion_without_a_rule_counts_as_the_post_switch(): void {
		$switch = $this->decision( 'excluded', null, 'explicit_post_exclusion' );
		$rule   = $this->decision( 'excluded', '01K3KGXB71P2MR4K9A7V6Q5C8F', 'taxonomy_category_matched' );

		self::assertTrue( ActivitySummary::is_switched_off( $switch ) );
		self::assertFalse( ActivitySummary::is_switched_off( $rule ) );
		self::assertFalse( ActivitySummary::is_switched_off( $this->decision( 'skipped', null, 'explicit_post_exclusion' ) ) );
		self::assertFalse( ActivitySummary::is_switched_off( $this->decision( 'excluded', '01K3KGXB71P2MR4K9A7V6Q5C8F', 'explicit_post_exclusion' ) ) );

		self::assertSame( 'switched_off', ActivitySummary::not_shared_kind( array( $switch ) ) );
		self::assertSame( 'excluded', ActivitySummary::not_shared_kind( array( $rule ) ) );
		self::assertSame( 'excluded', ActivitySummary::not_shared_kind( array( $switch, $rule ) ), 'a matching "never share" rule still wins' );
		self::assertSame( 'policy_error', ActivitySummary::not_shared_kind( array( $switch, $this->decision( 'policy_error', null, 'malformed_rule' ) ) ) );
		self::assertSame( 'skipped', ActivitySummary::not_shared_kind( array( $this->decision( 'skipped', null, 'no_matching_rules' ) ) ) );
		self::assertSame( 'skipped', ActivitySummary::not_shared_kind( array() ) );
	}

	public function test_share_now_documents_describe_one_post_with_the_rules_timing(): void {
		$post              = new \WP_Post();
		$post->ID          = 42;
		$post->post_type   = 'post';
		$post->post_status = 'publish';

		self::assertSame(
			array(
				'post_ids'       => array( 42 ),
				'post_statuses'  => array( 'publish' ),
				'post_types'     => array( 'post' ),
				'schema_version' => 1,
			),
			PostShareNowService::filters( $post )
		);
		self::assertSame( 1, PostShareNowService::limits( 'inherit' )['candidate_limit'] );
		self::assertSame( 'inherit', PostShareNowService::limits( 'inherit' )['schedule']['mode'] );
		self::assertSame( 'queue_end', PostShareNowService::limits( 'queue_end' )['schedule']['mode'] );
	}

	/**
	 * Build a stored decision.
	 *
	 * @param string      $result Result.
	 * @param string|null $rule_public_id Rule public ID.
	 * @param string      $code Reason code.
	 * @return DecisionRecord
	 */
	private function decision( string $result, ?string $rule_public_id, string $code ): DecisionRecord {
		return new DecisionRecord(
			1,
			'01K3KGXB71P2MR4K9A7V6Q5C8D',
			str_repeat( 'a', 64 ),
			str_repeat( 'b', 64 ),
			null,
			'global',
			null,
			42,
			'post',
			'publish',
			7,
			str_repeat( 'c', 64 ),
			'first_publish',
			'runtime',
			null === $rule_public_id ? null : 10,
			$rule_public_id,
			null === $rule_public_id ? null : 1,
			$result,
			array( array( 'code' => $code ) ),
			array(),
			str_repeat( 'd', 64 ),
			new DateTimeImmutable( '2026-08-25 12:00:00', new DateTimeZone( 'UTC' ) )
		);
	}
}
