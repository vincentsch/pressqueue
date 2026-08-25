<?php
/**
 * Storage contract tests.
 *
 * @package PressQueue\Tests\Unit
 */

declare(strict_types=1);

namespace PressQueue\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\HashKeys;
use PressQueue\Storage\PublicIds;
use PressQueue\Storage\RuleDocuments;
use PressQueue\Storage\Schema;
use PressQueue\Storage\StorageEnums;
use PressQueue\Storage\StorageValidator;
use PressQueue\Storage\UlidPublicIdGenerator;
use PressQueue\Storage\ValidationException;

/**
 * @covers \PressQueue\Storage\CanonicalJson
 * @covers \PressQueue\Storage\HashKeys
 * @covers \PressQueue\Storage\PublicIds
 * @covers \PressQueue\Storage\RuleDocuments
 * @covers \PressQueue\Storage\Schema
 * @covers \PressQueue\Storage\StorageEnums
 * @covers \PressQueue\Storage\StorageValidator
 * @covers \PressQueue\Storage\UlidPublicIdGenerator
 */
final class StorageContractsTest extends TestCase {
	public function test_canonical_json_sorts_object_keys_and_preserves_empty_shapes(): void {
		self::assertSame(
			'{"a":{"a":1,"b":2},"z":1}',
			CanonicalJson::encode_object(
				array(
					'z' => 1,
					'a' => array(
						'b' => 2,
						'a' => 1,
					),
				)
			)
		);

		self::assertSame( '{}', CanonicalJson::encode_object( array() ) );
		self::assertSame( '[]', CanonicalJson::encode_list( array() ) );
		self::assertSame( array(), CanonicalJson::decode_object( '{}' ) );
		self::assertSame( array(), CanonicalJson::decode_list( '[]' ) );
	}

	public function test_object_decoder_rejects_empty_list_documents(): void {
		$this->expectException( ValidationException::class );

		CanonicalJson::decode_object( '[]' );
	}

	public function test_list_decoder_rejects_empty_object_documents(): void {
		$this->expectException( ValidationException::class );

		CanonicalJson::decode_list( '{}' );
	}

	public function test_fixed_hash_fixtures_are_stable(): void {
		$connection_public_id = '01K8Z8D0PKH2N8J7S3K5A9C6DE';
		$rule_public_id       = '01K8Z8D0PKH2N8J7S3K5A9C6DF';
		$channel_public_id    = '01K8Z8D0PKH2N8J7S3K5A9C6DG';
		$revision             = str_repeat( 'a', 64 );
		$event_fields         = array(
			'author_id'            => 7,
			'event_mode'           => 'runtime',
			'post_id'              => 42,
			'post_status'          => 'publish',
			'post_type'            => 'post',
			'revision_fingerprint' => $revision,
			'trigger'              => 'first_publish',
		);

		$event_key = HashKeys::event_key( $event_fields );

		self::assertSame( 'b8ef3dd744170da51e2d133dbe71d365c2ac2d2f0e655e01ee5cabedadb53db7', $event_key );
		self::assertSame(
			'1714b8efbc5e49e13c2326007df71ed79190c1ba53c2c371951057c1d203c528',
			HashKeys::decision_key( $event_fields, $event_key, 'channel', $channel_public_id, $rule_public_id, 3, 'included' )
		);
		self::assertSame(
			'4e50dbe2bea20d4fabfe32d99ca4c116c3d6282345619f4b3ae3ae0c9252c543',
			HashKeys::delivery_key( 'dry_run', 'buffer', $connection_public_id, $channel_public_id, 'remote-1', 42, $revision, 'first_publish', $rule_public_id, 3 )
		);
		self::assertSame(
			'565514007584db7abf94f9ee0e67c5fa3add17d37f0f81db0375623d142e4152',
			HashKeys::snapshot_hash(
				array(
					'post'     => array( 'id' => 42 ),
					'settings' => array( 'dry_run' => true ),
				)
			)
		);
		self::assertSame(
			'9343e7e4775677400869240f6a27a0a1411aa5f5f91723c713921c4a0d69f74b',
			HashKeys::payload_hash(
				array(
					'canonical_url'        => 'https://example.test/post',
					'channel_public_id'    => $channel_public_id,
					'connection_public_id' => $connection_public_id,
					'message'              => 'Hello',
					'provider'             => 'buffer',
					'remote_channel_id'    => 'remote-1',
					'revision_fingerprint' => $revision,
					'rule_public_id'       => $rule_public_id,
					'rule_version'         => 3,
					'schedule_slot'        => 'immediate',
					'trigger'              => 'first_publish',
				)
			)
		);
		self::assertNotSame(
			HashKeys::payload_hash(
				array(
					'canonical_url'        => 'https://example.test/post',
					'channel_public_id'    => $channel_public_id,
					'connection_public_id' => $connection_public_id,
					'message'              => 'Hello',
					'provider'             => 'buffer',
					'remote_channel_id'    => 'remote-1',
					'revision_fingerprint' => $revision,
					'rule_public_id'       => $rule_public_id,
					'rule_version'         => 3,
					'schedule_slot'        => 'immediate',
					'trigger'              => 'first_publish',
				)
			),
			HashKeys::payload_hash(
				array(
					'canonical_url'        => 'https://example.test/post',
					'channel_public_id'    => $channel_public_id,
					'connection_public_id' => $connection_public_id,
					'message'              => 'Hello',
					'provider'             => 'buffer',
					'remote_channel_id'    => 'remote-1',
					'revision_fingerprint' => str_repeat( 'b', 64 ),
					'rule_public_id'       => $rule_public_id,
					'rule_version'         => 3,
					'schedule_slot'        => 'immediate',
					'trigger'              => 'first_publish',
				)
			)
		);
	}

	public function test_public_id_generator_returns_valid_uppercase_ulids(): void {
		$public_id = ( new UlidPublicIdGenerator() )->generate();

		self::assertTrue( PublicIds::is_valid( $public_id ) );
		self::assertSame( strtoupper( $public_id ), $public_id );
		self::assertSame( 26, strlen( $public_id ) );
	}

	public function test_storage_enums_reject_unknown_values(): void {
		$this->expectException( ValidationException::class );

		StorageEnums::assert_decision_result( 'sent' );
	}

	public function test_rule_documents_validate_and_canonicalize_storage_shapes(): void {
		$condition_tree = array(
			'children'       => array(
				array(
					'condition' => 'post_type',
					'operator'  => 'equals',
					'type'      => 'condition',
					'value'     => 'post',
				),
			),
			'mode'           => 'ALL',
			'type'           => 'group',
			'schema_version' => 1,
		);

		$destination_set = RuleDocuments::destination_set(
			'include',
			array(
				'schema_version' => 1,
				'destinations'   => array(
					array( 'channel_public_id' => '01K8Z8D0PKH2N8J7S3K5A9C6DG' ),
					array( 'channel_public_id' => '01K8Z8D0PKH2N8J7S3K5A9C6DE' ),
				),
			)
		);

		self::assertSame(
			array( 'first_publish', 'update' ),
			RuleDocuments::triggers( array( 'update', 'first_publish', 'update' ) )['triggers']
		);
		self::assertSame( $condition_tree, RuleDocuments::condition_tree( $condition_tree, array() ) );
		self::assertSame(
			array(
				array( 'channel_public_id' => '01K8Z8D0PKH2N8J7S3K5A9C6DE' ),
				array( 'channel_public_id' => '01K8Z8D0PKH2N8J7S3K5A9C6DG' ),
			),
			$destination_set['destinations']
		);
	}

	public function test_condition_tree_aliases_are_canonicalized_and_metadata_values_are_typed(): void {
		$tree = RuleDocuments::condition_tree(
			array(
				'schema_version' => 1,
				'type'           => 'group',
				'operator'       => 'ALL',
				'children'       => array(
					array(
						'type'      => 'condition',
						'condition' => 'metadata',
						'operator'  => 'equals',
						'value'     => array(
							'key'   => 'pressqueue_rating',
							'value' => 5,
						),
					),
				),
			),
			array( 'pressqueue_rating' )
		);

		self::assertArrayNotHasKey( 'operator', $tree );
		self::assertSame( 'ALL', $tree['mode'] );
		self::assertSame(
			array(
				'type'  => 'number',
				'value' => 5,
			),
			$tree['children'][0]['value']['value']
		);
	}

	public function test_rule_documents_reject_metadata_conditions_outside_allowlist(): void {
		$this->expectException( ValidationException::class );

		RuleDocuments::condition_tree(
			array(
				'schema_version' => 1,
				'type'           => 'group',
				'operator'       => 'ALL',
				'children'       => array(
					array(
						'type'      => 'condition',
						'condition' => 'metadata',
						'operator'  => 'equals',
						'value'     => array(
							'key'   => '_private',
							'value' => 'yes',
						),
					),
				),
			),
			array( '_pressqueue_allowed' )
		);
	}

	public function test_rule_documents_require_a_group_condition_tree_root(): void {
		$this->expectException( ValidationException::class );

		RuleDocuments::condition_tree(
			array(
				'schema_version' => 1,
				'type'           => 'condition',
				'condition'      => 'post_type',
				'operator'       => 'equals',
				'value'          => 'post',
			),
			array()
		);
	}

	public function test_condition_results_must_be_supported_strings(): void {
		$this->expectException( ValidationException::class );

		StorageValidator::assert_nested_condition_results(
			array(
				array(
					'condition_result' => 7,
				),
			)
		);
	}

	public function test_application_references_are_indexed_in_the_schema_contract(): void {
		$shape = Schema::expected_shape();

		self::assertSame( array( 'rule_id' ), $shape[ Schema::DECISIONS ]['indexes']['rule_id']['columns'] );
		self::assertSame( array( 'intent_occurrence_key' ), $shape[ Schema::DECISIONS ]['indexes']['intent_occurrence_key']['columns'] );
		self::assertSame( array( 'connection_id' ), $shape[ Schema::DELIVERIES ]['indexes']['connection_id']['columns'] );
		self::assertSame( array( 'channel_id' ), $shape[ Schema::DELIVERIES ]['indexes']['channel_id']['columns'] );
		self::assertSame( array( 'intent_occurrence_key' ), $shape[ Schema::DELIVERIES ]['indexes']['intent_occurrence_key']['columns'] );
		self::assertSame( array( 'batch_public_id', 'state' ), $shape[ Schema::BATCH_ITEMS ]['indexes']['batch_state']['columns'] );
		self::assertSame( array( 'intent_occurrence_key' ), $shape[ Schema::BATCH_ITEMS ]['indexes']['intent_occurrence_key']['columns'] );
		self::assertFalse( $shape[ Schema::DECISIONS ]['indexes']['rule_id']['unique'] );
		self::assertFalse( $shape[ Schema::DELIVERIES ]['indexes']['connection_id']['unique'] );
		self::assertFalse( $shape[ Schema::DELIVERIES ]['indexes']['channel_id']['unique'] );
		self::assertTrue( $shape[ Schema::BATCH_ITEMS ]['indexes']['intent_occurrence_key']['unique'] );
	}
}
