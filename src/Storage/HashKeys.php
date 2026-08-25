<?php
/**
 * Stable storage hash key helpers.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage;

/**
 * Builds event, decision, delivery, snapshot, and payload hashes.
 */
final class HashKeys {
	/**
	 * Calculate a local event key.
	 *
	 * @param array<string,mixed> $event_fields Canonical event fields.
	 * @return string
	 */
	public static function event_key( array $event_fields ): string {
		return CanonicalJson::hash( 'event_key', $event_fields );
	}

	/**
	 * Calculate a decision key.
	 *
	 * @param array<string,mixed> $event_fields Canonical event fields.
	 * @param string              $event_key Event key.
	 * @param string              $scope_type Decision scope type.
	 * @param string|null         $scope_public_id Scope public ID when scoped.
	 * @param string|null         $rule_public_id Rule public ID for rule-scoped decisions.
	 * @param int|null            $rule_version Rule version for rule-scoped decisions.
	 * @param string              $result Decision result.
	 * @return string
	 */
	public static function decision_key(
		array $event_fields,
		string $event_key,
		string $scope_type,
		?string $scope_public_id,
		?string $rule_public_id,
		?int $rule_version,
		string $result
	): string {
		return CanonicalJson::hash(
			'decision_key',
			array(
				'event_fields'    => $event_fields,
				'event_key'       => $event_key,
				'result'          => $result,
				'rule_public_id'  => $rule_public_id,
				'rule_version'    => $rule_version,
				'scope_public_id' => $scope_public_id,
				'scope_type'      => $scope_type,
			)
		);
	}

	/**
	 * Calculate a delivery key.
	 *
	 * @param string      $delivery_mode Delivery mode.
	 * @param string      $provider Provider key.
	 * @param string      $connection_public_id Connection public ID.
	 * @param string      $channel_public_id Channel public ID.
	 * @param string      $remote_channel_id Provider channel ID.
	 * @param int         $post_id WordPress post ID.
	 * @param string      $revision_fingerprint Revision fingerprint.
	 * @param string      $trigger Rule trigger.
	 * @param string      $rule_public_id Winning rule public ID.
	 * @param int         $rule_version Winning rule version.
	 * @param string      $schedule_slot Schedule slot.
	 * @param string|null $scheduled_for_utc Explicit due time.
	 * @param string|null $media_identity_hash Media identity hash.
	 * @param string|null $intent_occurrence_key Optional batch occurrence key.
	 * @return string
	 */
	public static function delivery_key(
		string $delivery_mode,
		string $provider,
		string $connection_public_id,
		string $channel_public_id,
		string $remote_channel_id,
		int $post_id,
		string $revision_fingerprint,
		string $trigger,
		string $rule_public_id,
		int $rule_version,
		string $schedule_slot = 'immediate',
		?string $scheduled_for_utc = null,
		?string $media_identity_hash = null,
		?string $intent_occurrence_key = null
	): string {
		$data = array(
			'channel_public_id'    => $channel_public_id,
			'connection_public_id' => $connection_public_id,
			'delivery_mode'        => $delivery_mode,
			'post_id'              => $post_id,
			'provider'             => $provider,
			'remote_channel_id'    => $remote_channel_id,
			'revision_fingerprint' => $revision_fingerprint,
			'rule_public_id'       => $rule_public_id,
			'rule_version'         => $rule_version,
			'schedule_slot'        => $schedule_slot,
			'trigger'              => $trigger,
		);

		if ( null !== $intent_occurrence_key ) {
			StorageValidator::assert_hash( $intent_occurrence_key );
			$data['intent_occurrence_key'] = $intent_occurrence_key;
		}

		if ( null !== $scheduled_for_utc ) {
			$data['scheduled_for_utc'] = $scheduled_for_utc;
		}

		if ( null !== $media_identity_hash ) {
			$data['media_identity_hash'] = $media_identity_hash;
		}

		return CanonicalJson::hash( 'delivery_key', $data );
	}

	/**
	 * Calculate a batch item key.
	 *
	 * @param string      $batch_public_id Batch public ID.
	 * @param int         $post_id WordPress post ID.
	 * @param string|null $channel_public_id Destination channel public ID.
	 * @param string      $semantic_preview_hash Stable item preview hash.
	 * @return string
	 */
	public static function batch_item_key( string $batch_public_id, int $post_id, ?string $channel_public_id, string $semantic_preview_hash ): string {
		PublicIds::assert( $batch_public_id );
		if ( null !== $channel_public_id ) {
			PublicIds::assert( $channel_public_id );
		}
		StorageValidator::assert_unsigned_id( 'Post ID', $post_id );
		StorageValidator::assert_hash( $semantic_preview_hash );

		return CanonicalJson::hash(
			'batch_item_key',
			array(
				'batch_public_id'       => $batch_public_id,
				'channel_public_id'     => $channel_public_id ?? 'global',
				'post_id'               => $post_id,
				'semantic_preview_hash' => $semantic_preview_hash,
			)
		);
	}

	/**
	 * Calculate the stable occurrence key for one manual batch item.
	 *
	 * @param string      $mode Batch mode.
	 * @param string      $batch_public_id Batch public ID.
	 * @param string      $batch_item_public_id Batch item public ID.
	 * @param int         $post_id WordPress post ID.
	 * @param string|null $channel_public_id Destination channel public ID.
	 * @param string      $semantic_hash Semantic preview hash or repost criteria hash.
	 * @return string
	 */
	public static function intent_occurrence_key( string $mode, string $batch_public_id, string $batch_item_public_id, int $post_id, ?string $channel_public_id, string $semantic_hash ): string {
		StorageEnums::assert_batch_type( $mode );
		PublicIds::assert( $batch_public_id );
		PublicIds::assert( $batch_item_public_id );
		if ( null !== $channel_public_id ) {
			PublicIds::assert( $channel_public_id );
		}
		StorageValidator::assert_unsigned_id( 'Post ID', $post_id );
		StorageValidator::assert_hash( $semantic_hash );

		$data = array(
			'batch_item_public_id' => $batch_item_public_id,
			'batch_public_id'      => $batch_public_id,
			'channel_public_id'    => $channel_public_id ?? 'global',
			'mode'                 => $mode,
			'post_id'              => $post_id,
		);

		if ( 'bulk' === $mode ) {
			$data['semantic_preview_hash'] = $semantic_hash;
		} else {
			$data['repost_criteria_hash'] = $semantic_hash;
		}

		return CanonicalJson::hash( 'intent_occurrence', $data );
	}

	/**
	 * Calculate the stable occurrence key for ordinary captured publishing.
	 *
	 * @param string $site_identifier Stable site identifier.
	 * @param int    $post_id WordPress post ID.
	 * @param string $trigger Runtime trigger.
	 * @param string $semantic_occurrence_hash Captured semantic occurrence hash.
	 * @return string
	 */
	public static function ordinary_intent_occurrence_key( string $site_identifier, int $post_id, string $trigger, string $semantic_occurrence_hash ): string {
		if ( '' === trim( $site_identifier ) || strlen( $site_identifier ) > 191 ) {
			throw new ValidationException( 'Site identifier is invalid.' );
		}
		if ( ! in_array( $trigger, array( 'first_publish', 'update', 'scheduled_publication' ), true ) ) {
			throw new ValidationException( 'Ordinary occurrence trigger is unsupported.' );
		}
		StorageValidator::assert_unsigned_id( 'Post ID', $post_id );
		StorageValidator::assert_hash( $semantic_occurrence_hash );

		return CanonicalJson::hash(
			'intent_occurrence',
			array(
				'mode'                     => 'ordinary',
				'post_id'                  => $post_id,
				'semantic_occurrence_hash' => $semantic_occurrence_hash,
				'site_identifier'          => $site_identifier,
				'trigger'                  => $trigger,
			)
		);
	}

	/**
	 * Calculate a decision snapshot hash.
	 *
	 * @param array<string,mixed> $snapshot Snapshot document.
	 * @return string
	 */
	public static function snapshot_hash( array $snapshot ): string {
		return CanonicalJson::hash( 'decision_snapshot', $snapshot );
	}

	/**
	 * Calculate a rendered payload preview hash.
	 *
	 * @param array<string,mixed> $payload_fields Semantic payload fields.
	 * @return string
	 */
	public static function payload_hash( array $payload_fields ): string {
		return CanonicalJson::hash( 'payload_preview', $payload_fields );
	}

	/**
	 * Calculate a verified provider account and organization identity hash.
	 *
	 * @param string $provider Provider key.
	 * @param string $remote_account_id Provider account ID.
	 * @param string $remote_organization_id Provider organization ID.
	 * @return string
	 */
	public static function verified_identity_hash( string $provider, string $remote_account_id, string $remote_organization_id ): string {
		return CanonicalJson::hash(
			'verified_connection_identity',
			array(
				'provider'               => $provider,
				'remote_account_id'      => $remote_account_id,
				'remote_organization_id' => $remote_organization_id,
			)
		);
	}

	/**
	 * Calculate a local remote receipt uniqueness key.
	 *
	 * @param string $provider Provider key.
	 * @param string $connection_public_id Connection public ID.
	 * @param string $remote_id Remote provider ID.
	 * @return string
	 */
	public static function remote_receipt_key( string $provider, string $connection_public_id, string $remote_id ): string {
		return CanonicalJson::hash(
			'remote_receipt_key',
			array(
				'connection_public_id' => $connection_public_id,
				'provider'             => $provider,
				'remote_id'            => $remote_id,
			)
		);
	}
}
