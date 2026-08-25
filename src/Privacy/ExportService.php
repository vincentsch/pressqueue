<?php
/**
 * Full-site privacy export service.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Privacy;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Administrator exports are required to stream through php://temp.

use PressQueue\Runtime\RuntimeDisabledLatch;
use PressQueue\Security\Capabilities;
use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\Database;
use PressQueue\Storage\Schema;
use PressQueue\Storage\ValidationException;

/**
 * Creates paged JSON exports for administrators.
 */
final class ExportService {
	private const DEFAULT_PAGE_SIZE  = 10000;
	private const DEFAULT_BYTE_LIMIT = 52428800;

	/**
	 * Database wrapper.
	 *
	 * @var Database
	 */
	private Database $database;

	/**
	 * Redactor.
	 *
	 * @var ExportRedactor
	 */
	private ExportRedactor $redactor;

	/**
	 * Constructor.
	 *
	 * @param Database|null       $database Database.
	 * @param ExportRedactor|null $redactor Redactor.
	 */
	public function __construct( ?Database $database = null, ?ExportRedactor $redactor = null ) {
		$this->database = $database ?? Database::from_globals();
		$this->redactor = $redactor ?? new ExportRedactor();
	}

	/**
	 * Build one export page.
	 *
	 * @param string|null $cursor_string Cursor string.
	 * @param bool        $include_rendered_payloads Include rendered payload text and media URLs.
	 * @param int         $page_size Page size.
	 * @param int         $byte_limit Byte limit.
	 * @return array<string,mixed>
	 */
	public function page( ?string $cursor_string = null, bool $include_rendered_payloads = false, int $page_size = self::DEFAULT_PAGE_SIZE, int $byte_limit = self::DEFAULT_BYTE_LIMIT ): array {
		$page_size  = max( 1, min( self::DEFAULT_PAGE_SIZE, $page_size ) );
		$byte_limit = max( 1024, min( self::DEFAULT_BYTE_LIMIT, $byte_limit ) );
		$cursor     = ExportCursor::from_string( $cursor_string );
		$started_at = gmdate( 'Y-m-d H:i:s' );
		$payload    = array(
			'metadata'    => array(
				'byte_limit'                => $byte_limit,
				'cursor'                    => null === $cursor ? null : $cursor->to_array(),
				'generated_at_utc'          => self::rfc3339( $started_at ),
				'include_rendered_payloads' => $include_rendered_payloads,
				'page_size'                 => $page_size,
				'plugin_version'            => defined( 'PRESSQUEUE_VERSION' ) ? PRESSQUEUE_VERSION : '0.1.0-dev',
				'schema_version'            => 1,
				'site_url_hash'             => $this->site_url_hash(),
				'snapshot_cutoff_utc'       => self::rfc3339( $started_at ),
			),
			'redaction'   => $this->redaction_document( $include_rendered_payloads ),
			'tables'      => array(
				'settings' => $this->settings_snapshot(),
			),
			'truncated'   => false,
			'next_cursor' => null,
		);

		foreach ( array_keys( $this->table_fields() ) as $suffix ) {
			$payload['tables'][ $suffix ] = array();
		}

		$emitted     = 0;
		$next_cursor = null;

		foreach ( $this->table_fields() as $suffix => $fields ) {
			if ( ! $this->table_is_reachable( $suffix, $cursor ) ) {
				continue;
			}

			$table_cursor = null !== $cursor && $cursor->table === $suffix ? $cursor : null;
			$rows         = $this->fetch_rows( $suffix, $fields, $table_cursor, $page_size - $emitted + 1, $started_at );

			foreach ( $rows as $row ) {
				$normalized                       = $this->normalize_row( $suffix, $row, $include_rendered_payloads );
				$candidate                        = $payload;
				$candidate['tables'][ $suffix ][] = $normalized;
				$encoded_candidate                = wp_json_encode( $candidate );

				if ( ! is_string( $encoded_candidate ) ) {
					throw new ValidationException( 'Export JSON could not be encoded.' );
				}

				if ( strlen( $encoded_candidate ) > $byte_limit ) {
					if ( 0 === $emitted ) {
						throw new ValidationException( 'One export row exceeds the byte limit.' );
					}

					$payload['truncated'] = true;
					break 2;
				}

				$payload = $candidate;
				++$emitted;

				$next_cursor = new ExportCursor( $suffix, (string) $row[ $this->timestamp_field( $suffix ) ], (string) $row['public_id'] );

				if ( $emitted >= $page_size ) {
					$payload['truncated'] = true;
					break 2;
				}
			}
		}

		if ( $payload['truncated'] && null !== $next_cursor ) {
			$payload['next_cursor'] = $next_cursor->to_array();
		}

		return $payload;
	}

	/**
	 * Stream an administrator JSON download.
	 *
	 * @param bool        $include_rendered_payloads Include rendered payload text and media URLs.
	 * @param string|null $cursor_string Cursor string.
	 */
	public function stream_download( bool $include_rendered_payloads, ?string $cursor_string = null ): void {
		$handle = $this->page_stream( $cursor_string, $include_rendered_payloads );

		try {
			if ( ! headers_sent() ) {
				header( 'Content-Type: application/json; charset=UTF-8' );
				header( 'Content-Disposition: attachment; filename="pressqueue-export-' . gmdate( 'Ymd-His' ) . '.json"' );
				header( 'X-Content-Type-Options: nosniff' );
				header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
			}

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The download body is generated JSON, not HTML.
			fpassthru( $handle );
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * Build a validated page stream.
	 *
	 * @param string|null $cursor_string Cursor string.
	 * @param bool        $include_rendered_payloads Include rendered payload text and media URLs.
	 * @param int         $page_size Page size.
	 * @param int         $byte_limit Byte limit.
	 * @return resource
	 */
	public function page_stream( ?string $cursor_string = null, bool $include_rendered_payloads = false, int $page_size = self::DEFAULT_PAGE_SIZE, int $byte_limit = self::DEFAULT_BYTE_LIMIT ) {
		$payload = $this->page( $cursor_string, $include_rendered_payloads, $page_size, $byte_limit );
		$json    = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		if ( ! is_string( $json ) ) {
			throw new ValidationException( 'Export JSON could not be encoded.' );
		}

		$handle = fopen( 'php://temp/maxmemory:' . (string) $byte_limit, 'w+b' );
		if ( ! is_resource( $handle ) ) {
			throw new ValidationException( 'Export JSON could not be encoded.' );
		}

		try {
			$written = fwrite( $handle, $json );
			if ( ! is_int( $written ) || strlen( $json ) !== $written ) {
				throw new ValidationException( 'Export JSON could not be encoded.' );
			}

			$stats = fstat( $handle );
			if ( ! is_array( $stats ) || (int) ( $stats['size'] ?? 0 ) > $byte_limit ) {
				throw new ValidationException( 'One export row exceeds the byte limit.' );
			}

			rewind( $handle );

			return $handle;
		} catch ( \Throwable $throwable ) {
			fclose( $handle );
			throw $throwable;
		}
	}

	/**
	 * Return table fields to export.
	 *
	 * @return array<string,array<int,string>>
	 */
	public function table_fields(): array {
		return array(
			Schema::CONNECTIONS     => array( 'public_id', 'provider', 'label', 'account_json', 'remote_account_id', 'remote_organization_id', 'verified_identity_hash', 'connection_status', 'credential_verified_at', 'credential_rotated_at', 'last_sync_started_at', 'last_sync_completed_at', 'last_error_json', 'enabled', 'created_at', 'updated_at' ),
			Schema::CHANNELS        => array( 'public_id', 'connection_public_id', 'provider', 'remote_organization_id', 'remote_channel_id', 'service', 'display_json', 'capabilities_json', 'availability', 'enabled', 'last_synced_at', 'last_seen_at', 'availability_checked_at', 'created_at', 'updated_at' ),
			Schema::RULES           => array( 'public_id', 'name', 'effect', 'priority', 'triggers_json', 'condition_tree_json', 'inline_template_json', 'source_template_public_id', 'source_template_version', 'template_set_json', 'destination_set_json', 'custom_field_allowlist_json', 'schedule_policy', 'schedule_policy_json', 'media_policy_json', 'enabled', 'last_previewed_version', 'version', 'lock_version', 'created_at', 'updated_at' ),
			Schema::TEMPLATES       => array( 'public_id', 'name', 'template_set_json', 'enabled', 'version', 'lock_version', 'created_at', 'updated_at' ),
			Schema::DECISIONS       => array( 'public_id', 'event_key', 'decision_key', 'intent_occurrence_key', 'scope_type', 'scope_public_id', 'post_id', 'post_type', 'post_status', 'author_id', 'revision_fingerprint', 'trigger', 'event_mode', 'rule_public_id', 'rule_version', 'result', 'reasons_json', 'snapshot_json', 'snapshot_hash', 'created_at' ),
			Schema::DELIVERIES      => array( 'public_id', 'decision_public_id', 'event_key', 'delivery_key', 'intent_occurrence_key', 'delivery_mode', 'provider', 'connection_public_id', 'channel_public_id', 'remote_channel_id', 'rule_public_id', 'rule_version', 'state', 'schedule_slot', 'scheduled_for_utc', 'schedule_timezone', 'payload_preview_json', 'payload_hash', 'created_at', 'updated_at', 'bound_provider', 'bound_connection_public_id', 'bound_channel_public_id', 'bound_remote_account_id', 'bound_remote_organization_id', 'bound_remote_channel_id', 'bound_capability_schema_version', 'bound_capability_hash', 'bound_capability_catalog_version', 'media_snapshot_json', 'media_identity_hash', 'queued_at', 'last_attempt_at', 'attempt_count', 'automatic_attempt_count', 'next_attempt_at', 'lease_expires_at', 'action_id', 'provider_result', 'retry_safety', 'receipt_json', 'remote_id', 'remote_receipt_key', 'error_class', 'error_detail_json', 'reconciliation_status', 'reconciled_at', 'reconciled_by', 'reconciliation_note', 'payload_schema_version', 'adapter_encoding_version', 'state_changed_at' ),
			Schema::DELIVERY_EVENTS => array( 'public_id', 'delivery_public_id', 'event_type', 'from_state', 'to_state', 'attempt_number', 'action_id', 'actor_type', 'actor_user_id', 'provider_result', 'retry_safety', 'detail_json', 'created_at' ),
			Schema::BATCHES         => array( 'public_id', 'batch_type', 'status', 'created_by', 'confirmed_by', 'filters_json', 'preview_summary_json', 'preview_snapshot_hash', 'batch_limits_json', 'preview_expires_at', 'started_at', 'confirmed_at', 'completed_at', 'lock_version', 'created_at', 'updated_at' ),
			Schema::BATCH_ITEMS     => array( 'public_id', 'batch_public_id', 'item_key', 'intent_occurrence_key', 'position', 'post_id', 'channel_public_id', 'state', 'semantic_preview_hash', 'decision_public_id', 'delivery_public_id', 'duplicate_of_delivery_public_id', 'inclusion_rule_public_id', 'inclusion_rule_version', 'scheduled_for_utc', 'preview_item_json', 'reason_json', 'lock_version', 'created_at', 'updated_at' ),
		);
	}

	/**
	 * Fetch rows after the table cursor.
	 *
	 * @param string            $suffix Table suffix.
	 * @param array<int,string> $fields Fields.
	 * @param ExportCursor|null $cursor Cursor.
	 * @param int               $limit Limit.
	 * @param string            $snapshot_cutoff_utc Snapshot cutoff UTC.
	 * @return array<int,array<string,mixed>>
	 */
	private function fetch_rows( string $suffix, array $fields, ?ExportCursor $cursor, int $limit, string $snapshot_cutoff_utc ): array {
		$table      = $this->database->table( $suffix );
		$field_sql  = implode( ', ', array_map( static fn( string $field ): string => '`' . $field . '`', $fields ) );
		$time_field = $this->timestamp_field( $suffix );
		$where      = ' WHERE `' . $time_field . '` <= %s';
		$args       = array( $snapshot_cutoff_utc );

		if ( null !== $cursor ) {
			$where .= ' AND (`' . $time_field . '` > %s OR (`' . $time_field . '` = %s AND `public_id` > %s))';
			$args[] = $cursor->timestamp_utc;
			$args[] = $cursor->timestamp_utc;
			$args[] = $cursor->public_id;
		}

		$args[] = $limit;

		return $this->database->get_results_array(
			"SELECT $field_sql FROM `$table`$where ORDER BY `$time_field` ASC, `public_id` ASC LIMIT %d",
			$args
		);
	}

	/**
	 * Normalize one exported row.
	 *
	 * @param string              $suffix Table suffix.
	 * @param array<string,mixed> $row Row.
	 * @param bool                $include_rendered_payloads Include rendered payload text and media URLs.
	 * @return array<string,mixed>
	 */
	private function normalize_row( string $suffix, array $row, bool $include_rendered_payloads ): array {
		foreach ( $row as $field => $value ) {
			if ( Schema::DELIVERY_EVENTS === $suffix && 'actor_user_id' === $field && null !== $value ) {
				$row[ $field ] = ExportRedactor::marker( 'actor_user' );
				continue;
			}

			if ( is_string( $field ) && str_ends_with( $field, '_json' ) && is_string( $value ) ) {
				$row[ $field ] = $this->redactor->redact_json( $value, $include_rendered_payloads );
				continue;
			}

			if ( is_string( $field ) && $this->looks_like_datetime_field( $field ) && is_string( $value ) && '' !== $value ) {
				$row[ $field ] = self::rfc3339( $value );
			}
		}

		return $this->redactor->redact_value( $row, $include_rendered_payloads );
	}

	/**
	 * Check whether a table is reachable from the cursor.
	 *
	 * @param string            $suffix Table suffix.
	 * @param ExportCursor|null $cursor Cursor.
	 * @return bool
	 */
	private function table_is_reachable( string $suffix, ?ExportCursor $cursor ): bool {
		if ( null === $cursor ) {
			return true;
		}

		$order         = array_keys( $this->table_fields() );
		$current_index = array_search( $suffix, $order, true );
		$cursor_index  = array_search( $cursor->table, $order, true );

		if ( false === $cursor_index ) {
			throw new ValidationException( 'Export cursor table is invalid.' );
		}

		return false !== $current_index && $current_index >= $cursor_index;
	}

	/**
	 * Return a settings snapshot.
	 *
	 * @return array<string,mixed>
	 */
	private function settings_snapshot(): array {
		return array(
			'capability_schema_version' => function_exists( 'get_option' ) ? (int) get_option( Capabilities::VERSION_OPTION, 0 ) : 0,
			'kill_switch'               => function_exists( 'get_option' ) ? get_option( 'pressqueue_kill_switch_enabled', '0' ) : '0',
			'privacy_settings'          => ( new PrivacySettings() )->read(),
			'retention_state'           => array(
				'last_completion' => ( new RetentionStateStore() )->read()['last_completion'] ?? null,
			),
			'runtime_disabled'          => RuntimeDisabledLatch::is_closed(),
			'schema_version'            => function_exists( 'get_option' ) ? (int) get_option( Schema::VERSION_OPTION, 0 ) : 0,
		);
	}

	/**
	 * Return export redaction metadata.
	 *
	 * @param bool $include_rendered_payloads Include rendered payload text and media URLs.
	 * @return array<string,mixed>
	 */
	private function redaction_document( bool $include_rendered_payloads ): array {
		return array(
			'credentials_excluded'       => true,
			'classes_applied'            => array(
				'actor_user',
				'credential_or_secret',
				'rendered_payload',
			),
			'media_urls_included'        => $include_rendered_payloads,
			'rendered_payloads_included' => $include_rendered_payloads,
		);
	}

	/**
	 * Return the ordering timestamp field for a table.
	 *
	 * @param string $suffix Table suffix.
	 * @return string
	 */
	private function timestamp_field( string $suffix ): string {
		if ( Schema::DECISIONS === $suffix || Schema::DELIVERY_EVENTS === $suffix ) {
			return 'created_at';
		}

		return 'updated_at';
	}

	/**
	 * Check whether a field name stores a UTC timestamp.
	 *
	 * @param string $field Field.
	 * @return bool
	 */
	private function looks_like_datetime_field( string $field ): bool {
		return str_ends_with( $field, '_at' ) || str_ends_with( $field, '_utc' );
	}

	/**
	 * Return a site URL hash.
	 *
	 * @return string
	 */
	private function site_url_hash(): string {
		$url = function_exists( 'home_url' ) ? home_url() : ( function_exists( 'site_url' ) ? site_url() : '' );

		return hash( 'sha256', (string) $url );
	}

	/**
	 * Convert a UTC datetime to RFC3339.
	 *
	 * @param string $value MySQL datetime.
	 * @return string
	 */
	public static function rfc3339( string $value ): string {
		$timestamp = strtotime( $value . ' UTC' );
		if ( false === $timestamp ) {
			return $value;
		}

		return gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}
}
