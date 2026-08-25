<?php
/**
 * WP-CLI record serialization.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\WpCli\Query;

use DateTimeImmutable;
use DateTimeZone;
use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\Record\BatchItemRecord;
use PressQueue\Storage\Record\BatchRecord;
use PressQueue\Storage\Record\DeliveryRecord;
use PressQueue\Storage\StorageValidator;
use PressQueue\Storage\ValidationException;

/**
 * Formats storage rows into redacted CLI documents.
 */
final class RecordFormatter {
	/**
	 * Format a connection row.
	 *
	 * @param object $row Row.
	 * @return array<string,mixed>
	 */
	public static function connection_row( object $row ): array {
		return array(
			'account'                    => self::redacted_document( self::object_json( $row->account_json ?? '{}' ) ),
			'connection_status'          => (string) $row->connection_status,
			'created_at_utc'             => self::rfc3339_value( $row->created_at ?? null ),
			'credential_rotated_at_utc'  => self::rfc3339_value( $row->credential_rotated_at ?? null ),
			'credential_verified_at_utc' => self::rfc3339_value( $row->credential_verified_at ?? null ),
			'enabled'                    => '1' === (string) $row->enabled,
			'has_credential'             => null !== ( $row->credential_envelope_json ?? null ) && '' !== (string) $row->credential_envelope_json,
			'label'                      => (string) $row->label,
			'last_error'                 => null === ( $row->last_error_json ?? null ) ? null : self::redacted_document( self::object_json( $row->last_error_json ) ),
			'last_sync_completed_at_utc' => self::rfc3339_value( $row->last_sync_completed_at ?? null ),
			'last_sync_started_at_utc'   => self::rfc3339_value( $row->last_sync_started_at ?? null ),
			'provider'                   => (string) $row->provider,
			'public_id'                  => (string) $row->public_id,
			'remote_account_id'          => null === $row->remote_account_id ? null : (string) $row->remote_account_id,
			'remote_organization_id'     => null === $row->remote_organization_id ? null : (string) $row->remote_organization_id,
			'sync_lease_expires_at_utc'  => self::rfc3339_value( $row->sync_lease_expires_at ?? null ),
			'sync_lease_owner_present'   => null !== ( $row->sync_lease_owner ?? null ),
			'updated_at_utc'             => self::rfc3339_value( $row->updated_at ?? null ),
			'verified_identity_hash'     => null === $row->verified_identity_hash ? null : (string) $row->verified_identity_hash,
		);
	}

	/**
	 * Format a channel row.
	 *
	 * @param object $row Row.
	 * @return array<string,mixed>
	 */
	public static function channel_row( object $row ): array {
		return array(
			'availability'                => (string) $row->availability,
			'availability_checked_at_utc' => self::rfc3339_value( $row->availability_checked_at ?? null ),
			'capabilities'                => self::redacted_document( self::object_json( $row->capabilities_json ?? '{}' ) ),
			'connection_public_id'        => (string) $row->connection_public_id,
			'created_at_utc'              => self::rfc3339_value( $row->created_at ?? null ),
			'display'                     => self::redacted_document( self::object_json( $row->display_json ?? '{}' ) ),
			'enabled'                     => '1' === (string) $row->enabled,
			'last_seen_at_utc'            => self::rfc3339_value( $row->last_seen_at ?? null ),
			'last_synced_at_utc'          => self::rfc3339_value( $row->last_synced_at ?? null ),
			'provider'                    => (string) $row->provider,
			'public_id'                   => (string) $row->public_id,
			'remote_channel_id'           => (string) $row->remote_channel_id,
			'remote_organization_id'      => (string) $row->remote_organization_id,
			'service'                     => (string) $row->service,
			'updated_at_utc'              => self::rfc3339_value( $row->updated_at ?? null ),
		);
	}

	/**
	 * Format a rule row.
	 *
	 * @param object $row Row.
	 * @param bool   $detail Include stored documents.
	 * @return array<string,mixed>
	 */
	public static function rule_row( object $row, bool $detail = false ): array {
		$document_hashes = array(
			'condition_tree'         => self::json_hash( $row->condition_tree_json ?? '{}' ),
			'custom_field_allowlist' => self::json_hash( $row->custom_field_allowlist_json ?? '[]' ),
			'destination_set'        => self::json_hash( $row->destination_set_json ?? '{}' ),
			'inline_template'        => self::json_hash( $row->inline_template_json ?? '{}' ),
			'media_policy'           => self::json_hash( $row->media_policy_json ?? '{}' ),
			'schedule_policy'        => self::json_hash( $row->schedule_policy_json ?? '{}' ),
			'template_set'           => null === ( $row->template_set_json ?? null ) ? null : self::json_hash( $row->template_set_json ),
			'triggers'               => self::json_hash( $row->triggers_json ?? '{}' ),
		);

		$data = array(
			'created_at_utc'            => self::rfc3339_value( $row->created_at ?? null ),
			'document_hashes'           => $document_hashes,
			'effect'                    => (string) $row->effect,
			'enabled'                   => '1' === (string) $row->enabled,
			'last_previewed_version'    => null === $row->last_previewed_version ? null : (int) $row->last_previewed_version,
			'lock_version'              => (int) $row->lock_version,
			'name'                      => (string) $row->name,
			'priority'                  => (int) $row->priority,
			'public_id'                 => (string) $row->public_id,
			'schedule_policy'           => (string) $row->schedule_policy,
			'source_template_public_id' => null === $row->source_template_public_id ? null : (string) $row->source_template_public_id,
			'source_template_version'   => null === $row->source_template_version ? null : (int) $row->source_template_version,
			'updated_at_utc'            => self::rfc3339_value( $row->updated_at ?? null ),
			'version'                   => (int) $row->version,
		);

		if ( $detail ) {
			$data['documents'] = array(
				'condition_tree'         => self::object_json( $row->condition_tree_json ?? '{}' ),
				'custom_field_allowlist' => self::list_json( $row->custom_field_allowlist_json ?? '[]' ),
				'destination_set'        => self::object_json( $row->destination_set_json ?? '{}' ),
				'inline_template'        => self::object_json( $row->inline_template_json ?? '{}' ),
				'media_policy'           => self::object_json( $row->media_policy_json ?? '{}' ),
				'schedule_policy'        => self::object_json( $row->schedule_policy_json ?? '{}' ),
				'template_set'           => null === ( $row->template_set_json ?? null ) ? null : self::object_json( $row->template_set_json ),
				'triggers'               => self::object_json( $row->triggers_json ?? '{}' ),
			);
		}

		return $data;
	}

	/**
	 * Format a decision row.
	 *
	 * @param object $row Row.
	 * @return array<string,mixed>
	 */
	public static function decision_row( object $row ): array {
		return array(
			'created_at_utc'        => self::rfc3339_value( $row->created_at ?? null ),
			'decision_key'          => (string) $row->decision_key,
			'event_key'             => (string) $row->event_key,
			'event_mode'            => (string) $row->event_mode,
			'intent_occurrence_key' => null === ( $row->intent_occurrence_key ?? null ) ? null : (string) $row->intent_occurrence_key,
			'post_id'               => (int) $row->post_id,
			'post_status'           => (string) $row->post_status,
			'post_type'             => (string) $row->post_type,
			'public_id'             => (string) $row->public_id,
			'reasons'               => self::list_json( $row->reasons_json ?? '[]' ),
			'result'                => (string) $row->result,
			'revision_fingerprint'  => (string) $row->revision_fingerprint,
			'rule_public_id'        => null === $row->rule_public_id ? null : (string) $row->rule_public_id,
			'rule_version'          => null === $row->rule_version ? null : (int) $row->rule_version,
			'scope_public_id'       => null === $row->scope_public_id ? null : (string) $row->scope_public_id,
			'scope_type'            => (string) $row->scope_type,
			'snapshot_hash'         => (string) $row->snapshot_hash,
			'trigger'               => (string) $row->trigger,
		);
	}

	/**
	 * Format a delivery record.
	 *
	 * @param DeliveryRecord                 $record Delivery.
	 * @param array<int,array<string,mixed>> $events Events.
	 * @return array<string,mixed>
	 */
	public static function delivery_record( DeliveryRecord $record, array $events = array() ): array {
		return array(
			'action_id'                        => $record->action_id,
			'adapter_encoding_version'         => $record->adapter_encoding_version,
			'automatic_attempt_count'          => $record->automatic_attempt_count,
			'bound_capability_catalog_version' => $record->bound_capability_catalog_version,
			'bound_capability_hash'            => $record->bound_capability_hash,
			'bound_capability_schema_version'  => $record->bound_capability_schema_version,
			'bound_channel_public_id'          => $record->bound_channel_public_id,
			'bound_connection_public_id'       => $record->bound_connection_public_id,
			'bound_provider'                   => $record->bound_provider,
			'channel_public_id'                => $record->channel_public_id,
			'connection_public_id'             => $record->connection_public_id,
			'created_at_utc'                   => self::rfc3339_value( $record->created_at ),
			'decision_public_id'               => $record->decision_public_id,
			'delivery_key'                     => $record->delivery_key,
			'delivery_mode'                    => $record->delivery_mode,
			'error_class'                      => $record->error_class,
			'error_detail'                     => self::redacted_document( $record->error_detail ),
			'event_key'                        => $record->event_key,
			'events'                           => $events,
			'intent_occurrence_key'            => $record->intent_occurrence_key,
			'last_attempt_at_utc'              => self::rfc3339_value( $record->last_attempt_at ),
			'media_identity_hash'              => $record->media_identity_hash,
			'next_attempt_at_utc'              => self::rfc3339_value( $record->next_attempt_at ),
			'payload_hash'                     => $record->payload_hash,
			'payload_schema_version'           => $record->payload_schema_version,
			'payload_summary'                  => self::delivery_payload_summary( $record ),
			'provider'                         => $record->provider,
			'provider_result'                  => $record->provider_result,
			'public_id'                        => $record->public_id,
			'queued_at_utc'                    => self::rfc3339_value( $record->queued_at ),
			'receipt'                          => self::redacted_document( $record->receipt ),
			'reconciliation_note'              => $record->reconciliation_note,
			'reconciliation_status'            => $record->reconciliation_status,
			'reconciled_at_utc'                => self::rfc3339_value( $record->reconciled_at ),
			'remote_channel_id'                => $record->remote_channel_id,
			'remote_id'                        => $record->remote_id,
			'remote_receipt_key'               => $record->remote_receipt_key,
			'retry_safety'                     => $record->retry_safety,
			'rule_public_id'                   => $record->rule_public_id,
			'rule_version'                     => $record->rule_version,
			'schedule_slot'                    => $record->schedule_slot,
			'schedule_timezone'                => $record->schedule_timezone,
			'scheduled_for_utc'                => self::rfc3339_value( $record->scheduled_for_utc ),
			'state'                            => $record->state,
			'state_changed_at_utc'             => self::rfc3339_value( $record->state_changed_at ),
			'updated_at_utc'                   => self::rfc3339_value( $record->updated_at ),
		);
	}

	/**
	 * Format a delivery event row.
	 *
	 * @param object $row Row.
	 * @return array<string,mixed>
	 */
	public static function delivery_event_row( object $row ): array {
		$detail = self::object_json( $row->detail_json ?? '{}' );

		return array(
			'action_id'          => null === $row->action_id ? null : (int) $row->action_id,
			'actor_type'         => (string) $row->actor_type,
			'attempt_number'     => null === $row->attempt_number ? null : (int) $row->attempt_number,
			'classification'     => isset( $detail['classification'] ) && is_string( $detail['classification'] ) ? $detail['classification'] : null,
			'created_at_utc'     => self::rfc3339_value( $row->created_at ?? null ),
			'delivery_public_id' => (string) $row->delivery_public_id,
			'event_public_id'    => (string) $row->public_id,
			'event_type'         => (string) $row->event_type,
			'from_state'         => null === $row->from_state ? null : (string) $row->from_state,
			'provider_result'    => null === $row->provider_result ? null : (string) $row->provider_result,
			'retry_safety'       => null === $row->retry_safety ? null : (string) $row->retry_safety,
			'to_state'           => null === $row->to_state ? null : (string) $row->to_state,
		);
	}

	/**
	 * Format a batch record.
	 *
	 * @param BatchRecord                    $record Batch.
	 * @param array<int,array<string,mixed>> $items Items.
	 * @return array<string,mixed>
	 */
	public static function batch_record( BatchRecord $record, array $items = array() ): array {
		return array(
			'batch_limits'           => $record->batch_limits,
			'batch_type'             => $record->batch_type,
			'completed_at_utc'       => self::rfc3339_value( $record->completed_at ),
			'confirmed_at_utc'       => self::rfc3339_value( $record->confirmed_at ),
			'confirmed_by'           => $record->confirmed_by,
			'created_at_utc'         => self::rfc3339_value( $record->created_at ),
			'created_by'             => $record->created_by,
			'filters'                => $record->filters,
			'items'                  => $items,
			'lock_version'           => $record->lock_version,
			'preview_expires_at_utc' => self::rfc3339_value( $record->preview_expires_at ),
			'preview_snapshot_hash'  => $record->preview_snapshot_hash,
			'preview_summary'        => $record->preview_summary,
			'public_id'              => $record->public_id,
			'started_at_utc'         => self::rfc3339_value( $record->started_at ),
			'status'                 => $record->status,
			'updated_at_utc'         => self::rfc3339_value( $record->updated_at ),
		);
	}

	/**
	 * Format a batch item record.
	 *
	 * @param BatchItemRecord $item Item.
	 * @return array<string,mixed>
	 */
	public static function batch_item_record( BatchItemRecord $item ): array {
		return array(
			'batch_public_id'                 => $item->batch_public_id,
			'channel_public_id'               => $item->channel_public_id,
			'created_at_utc'                  => self::rfc3339_value( $item->created_at ),
			'decision_public_id'              => $item->decision_public_id,
			'delivery_public_id'              => $item->delivery_public_id,
			'duplicate_of_delivery_public_id' => $item->duplicate_of_delivery_public_id,
			'inclusion_rule_public_id'        => $item->inclusion_rule_public_id,
			'inclusion_rule_version'          => $item->inclusion_rule_version,
			'intent_occurrence_key'           => $item->intent_occurrence_key,
			'item_key'                        => $item->item_key,
			'lock_version'                    => $item->lock_version,
			'position'                        => $item->position,
			'post_id'                         => $item->post_id,
			'public_id'                       => $item->public_id,
			'reason'                          => $item->reason,
			'scheduled_for_utc'               => self::rfc3339_value( $item->scheduled_for_utc ),
			'semantic_preview_hash'           => $item->semantic_preview_hash,
			'state'                           => $item->state,
			'updated_at_utc'                  => self::rfc3339_value( $item->updated_at ),
		);
	}

	/**
	 * Build a delivery payload summary without content or media URLs.
	 *
	 * @param DeliveryRecord $record Delivery.
	 * @return array<string,mixed>
	 */
	private static function delivery_payload_summary( DeliveryRecord $record ): array {
		$preview = $record->payload_preview;

		return array(
			'adapter_encoding_version'  => $record->adapter_encoding_version,
			'bound_capability_hash'     => $record->bound_capability_hash,
			'final_encoded_text_length' => isset( $preview['final_encoded_text_length'] ) ? (int) $preview['final_encoded_text_length'] : null,
			'media_identity_hash'       => $record->media_identity_hash,
			'payload_hash'              => $record->payload_hash,
			'payload_schema_version'    => $record->payload_schema_version,
			'schedule_slot'             => $record->schedule_slot,
			'scheduled_for_utc'         => self::rfc3339_value( $record->scheduled_for_utc ),
		);
	}

	/**
	 * Decode an object JSON document.
	 *
	 * @param mixed $json JSON.
	 * @return array<string,mixed>
	 */
	public static function object_json( $json ): array {
		try {
			return CanonicalJson::decode_object( (string) $json );
		} catch ( ValidationException $exception ) {
			unset( $exception );

			return array(
				'invalid'        => true,
				'schema_version' => 1,
			);
		}
	}

	/**
	 * Decode a list JSON document.
	 *
	 * @param mixed $json JSON.
	 * @return array<int,mixed>
	 */
	public static function list_json( $json ): array {
		try {
			return CanonicalJson::decode_list( (string) $json );
		} catch ( ValidationException $exception ) {
			unset( $exception );

			return array();
		}
	}

	/**
	 * Return a document hash.
	 *
	 * @param mixed $json JSON.
	 * @return string
	 */
	private static function json_hash( $json ): string {
		return hash( 'sha256', (string) $json );
	}

	/**
	 * Redact secret-shaped keys recursively.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public static function redacted_document( $value ) {
		if ( null === $value ) {
			return null;
		}

		if ( is_array( $value ) ) {
			$redacted = array();
			foreach ( $value as $key => $entry ) {
				if ( is_string( $key ) && 1 === preg_match( '/(authorization|bearer|cookie|credential|envelope|key|nonce|password|secret|token)/i', $key ) ) {
					$redacted[ $key ] = '[redacted]';
					continue;
				}

				$redacted[ $key ] = self::redacted_document( $entry );
			}

			return $redacted;
		}

		if ( is_string( $value ) ) {
			if ( strlen( $value ) > 1000 ) {
				return substr( $value, 0, 1000 ) . '...';
			}

			if ( 1 === preg_match( '/bearer\s+[a-z0-9._~+\/=-]+/i', $value ) ) {
				return '[redacted]';
			}
		}

		return $value;
	}

	/**
	 * Format a timestamp as UTC RFC 3339.
	 *
	 * @param DateTimeImmutable|string|null $value Value.
	 * @return string|null
	 */
	public static function rfc3339_value( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		if ( $value instanceof DateTimeImmutable ) {
			return $value->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
		}

		return StorageValidator::parse_required_date_time( (string) $value )
			->setTimezone( new DateTimeZone( 'UTC' ) )
			->format( 'Y-m-d\TH:i:s\Z' );
	}
}
