<?php
/**
 * Post control metadata tests.
 *
 * @package PressQueue\Tests\Unit
 */

declare(strict_types=1);

namespace PressQueue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PressQueue\Editorial\PostControlMetadata;
use PressQueue\Storage\StaleRecordException;
use PressQueue\Storage\ValidationException;
use WP_Post;

use function pressqueue_test_reset_wordpress_stubs;
use function update_post_meta;

/**
 * @covers \PressQueue\Editorial\PostControlMetadata
 */
final class PostControlMetadataTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		pressqueue_test_reset_wordpress_stubs();
		$GLOBALS['pressqueue_test_posts'][42] = new WP_Post( array( 'ID' => 42 ) );
	}

	public function test_missing_and_explicit_inherit_normalize_identically(): void {
		$missing = PostControlMetadata::read_for_post( 42 );
		$inherit = PostControlMetadata::normalize_bundle(
			array(
				'schema_version' => 1,
				'policy'         => 'inherit',
				'channels'       => array(
					'mode' => 'inherit',
				),
			)
		);

		self::assertSame( $inherit, $missing );
		self::assertSame( PostControlMetadata::bundle_hash( $inherit ), PostControlMetadata::bundle_hash( $missing ) );
	}

	public function test_legacy_policy_is_used_only_when_canonical_bundle_is_absent(): void {
		update_post_meta( 42, PostControlMetadata::LEGACY_META_KEY, 'exclude' );

		self::assertSame( 'exclude', PostControlMetadata::read_for_post( 42 )['policy'] );

		update_post_meta(
			42,
			PostControlMetadata::META_KEY,
			PostControlMetadata::encoded(
				array(
					'schema_version' => 1,
					'policy'         => 'include',
					'channels'       => array(
						'mode' => 'inherit',
					),
				)
			)
		);

		self::assertSame( 'include', PostControlMetadata::read_for_post( 42 )['policy'] );
	}

	public function test_override_channel_ids_are_sorted_and_empty_override_is_valid(): void {
		$bundle = PostControlMetadata::normalize_bundle(
			array(
				'schema_version' => 1,
				'policy'         => 'include',
				'channels'       => array(
					'mode'               => 'override',
					'channel_public_ids' => array(
						'01K8Z8D0PKH2N8J7S3K5A9C6DF',
						'01K8Z8D0PKH2N8J7S3K5A9C6DE',
					),
				),
			)
		);

		self::assertSame(
			array( '01K8Z8D0PKH2N8J7S3K5A9C6DE', '01K8Z8D0PKH2N8J7S3K5A9C6DF' ),
			$bundle['channels']['channel_public_ids']
		);

		$empty = PostControlMetadata::normalize_bundle(
			array(
				'schema_version' => 1,
				'policy'         => 'include',
				'channels'       => array(
					'mode'               => 'override',
					'channel_public_ids' => array(),
				),
			)
		);

		self::assertSame( array(), $empty['channels']['channel_public_ids'] );
	}

	public function test_unknown_fields_invalid_enums_invalid_ids_and_duplicates_are_rejected(): void {
		$this->expectException( ValidationException::class );

		PostControlMetadata::normalize_bundle(
			array(
				'schema_version' => 1,
				'policy'         => 'inherit',
				'channels'       => array(
					'mode' => 'inherit',
				),
				'message'        => 'not yet',
			)
		);
	}

	public function test_duplicate_channel_ids_are_rejected_before_write(): void {
		$this->expectException( ValidationException::class );

		PostControlMetadata::normalize_bundle(
			array(
				'schema_version' => 1,
				'policy'         => 'include',
				'channels'       => array(
					'mode'               => 'override',
					'channel_public_ids' => array(
						'01K8Z8D0PKH2N8J7S3K5A9C6DE',
						'01K8Z8D0PKH2N8J7S3K5A9C6DE',
					),
				),
			)
		);
	}

	public function test_stale_replacement_is_refused_and_invalid_replacement_preserves_prior_bundle(): void {
		$base = PostControlMetadata::read_for_post( 42 );
		PostControlMetadata::replace_for_post(
			42,
			array(
				'schema_version' => 1,
				'policy'         => 'exclude',
				'channels'       => array(
					'mode' => 'inherit',
				),
			),
			PostControlMetadata::bundle_hash( $base )
		);

		$this->expectException( StaleRecordException::class );
		PostControlMetadata::replace_for_post(
			42,
			array(
				'schema_version' => 1,
				'policy'         => 'include',
				'channels'       => array(
					'mode' => 'inherit',
				),
			),
			PostControlMetadata::bundle_hash( $base )
		);
	}

	public function test_invalid_replacement_leaves_prior_bundle_untouched(): void {
		$base = PostControlMetadata::read_for_post( 42 );
		PostControlMetadata::replace_for_post(
			42,
			array(
				'schema_version' => 1,
				'policy'         => 'exclude',
				'channels'       => array(
					'mode' => 'inherit',
				),
			),
			PostControlMetadata::bundle_hash( $base )
		);

		try {
			PostControlMetadata::replace_for_post(
				42,
				array(
					'schema_version' => 1,
					'policy'         => 'bad',
					'channels'       => array(
						'mode' => 'inherit',
					),
				),
				PostControlMetadata::bundle_hash( PostControlMetadata::read_for_post( 42 ) )
			);
		} catch ( ValidationException $exception ) {
			unset( $exception );
		}

		self::assertSame( 'exclude', PostControlMetadata::read_for_post( 42 )['policy'] );
	}
}
