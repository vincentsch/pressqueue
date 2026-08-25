<?php
/**
 * WordPress personal data exporter.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Privacy;

use PressQueue\Storage\Database;
use PressQueue\Storage\Schema;

/**
 * Registers and serves subject-scoped privacy exports.
 */
final class PersonalDataExporter {
	private const PAGE_SIZE = 100;

	/**
	 * Register WordPress privacy exporter hook.
	 */
	public static function register_hooks(): void {
		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'register_exporter' ) );
		}
	}

	/**
	 * Register exporter callback.
	 *
	 * @param array<string,mixed> $exporters Exporters.
	 * @return array<string,mixed>
	 */
	public static function register_exporter( array $exporters ): array {
		$exporters['pressqueue'] = array(
			'callback'               => array( self::class, 'export' ),
			'exporter_friendly_name' => __( 'PressQueue', 'pressqueue' ),
		);

		return $exporters;
	}

	/**
	 * Export subject-scoped data.
	 *
	 * @param string $email_address Email address.
	 * @param int    $page Page.
	 * @return array<string,mixed>
	 */
	public static function export( string $email_address, int $page = 1 ): array {
		$user_id = self::user_id_for_email( $email_address );
		if ( null === $user_id || ! Database::has_global_database() ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$database = Database::from_globals();
		$offset   = max( 0, $page - 1 ) * self::PAGE_SIZE;
		$limit    = self::PAGE_SIZE;
		$groups   = array();
		$total    = 0;

		foreach ( self::families() as $family ) {
			$count  = self::family_count( $database, $user_id, $family );
			$total += $count;

			if ( $offset >= $count ) {
				$offset -= $count;
				continue;
			}

			if ( $limit <= 0 ) {
				continue;
			}

			$family_groups = self::family_groups( $database, $user_id, $family, $limit, $offset );
			$groups        = array_merge( $groups, $family_groups );
			$limit        -= count( $family_groups );
			$offset        = 0;
		}

		return array(
			'data' => $groups,
			'done' => ( max( 0, $page - 1 ) * self::PAGE_SIZE ) + count( $groups ) >= $total,
		);
	}

	/**
	 * Resolve an email address to a user ID.
	 *
	 * @param string $email_address Email address.
	 * @return int|null
	 */
	private static function user_id_for_email( string $email_address ): ?int {
		if ( ! function_exists( 'get_user_by' ) ) {
			return null;
		}

		$user = get_user_by( 'email', $email_address );

		return $user instanceof \WP_User ? (int) $user->ID : null;
	}

	/**
	 * Return export families in stable order.
	 *
	 * @return array<int,string>
	 */
	private static function families(): array {
		return array( 'decisions', 'deliveries', 'batches' );
	}

	/**
	 * Count one export family.
	 *
	 * @param Database $database Database.
	 * @param int      $user_id User ID.
	 * @param string   $family Family.
	 * @return int
	 */
	private static function family_count( Database $database, int $user_id, string $family ): int {
		if ( 'decisions' === $family ) {
			$table = $database->table( Schema::DECISIONS );
			return (int) $database->get_var( "SELECT COUNT(*) FROM `$table` WHERE author_id = %d", array( $user_id ) );
		}

		if ( 'deliveries' === $family ) {
			$deliveries = $database->table( Schema::DELIVERIES );
			$decisions  = $database->table( Schema::DECISIONS );
			return (int) $database->get_var(
				"SELECT COUNT(*)
					FROM `$deliveries` v
					INNER JOIN `$decisions` d ON d.public_id = v.decision_public_id
					WHERE d.author_id = %d",
				array( $user_id )
			);
		}

		$table = $database->table( Schema::BATCHES );
		return (int) $database->get_var( "SELECT COUNT(*) FROM `$table` WHERE created_by = %d OR confirmed_by = %d", array( $user_id, $user_id ) );
	}

	/**
	 * Build export groups for one family.
	 *
	 * @param Database $database Database.
	 * @param int      $user_id User ID.
	 * @param string   $family Family.
	 * @param int      $limit Limit.
	 * @param int      $offset Offset.
	 * @return array<int,array<string,mixed>>
	 */
	private static function family_groups( Database $database, int $user_id, string $family, int $limit, int $offset ): array {
		if ( 'decisions' === $family ) {
			return self::decision_groups( $database, $user_id, $limit, $offset );
		}

		if ( 'deliveries' === $family ) {
			return self::delivery_groups( $database, $user_id, $limit, $offset );
		}

		return self::batch_groups( $database, $user_id, $limit, $offset );
	}

	/**
	 * Build decision export groups.
	 *
	 * @param Database $database Database.
	 * @param int      $user_id User ID.
	 * @param int      $limit Limit.
	 * @param int      $offset Offset.
	 * @return array<int,array<string,mixed>>
	 */
	private static function decision_groups( Database $database, int $user_id, int $limit, int $offset ): array {
		$table = $database->table( Schema::DECISIONS );
		$rows  = $database->get_results_array(
			"SELECT public_id, post_id, post_type, post_status, trigger, event_mode, result, reasons_json, snapshot_hash, created_at
				FROM `$table`
				WHERE author_id = %d
				ORDER BY created_at ASC, public_id ASC
				LIMIT %d OFFSET %d",
			array( $user_id, $limit, $offset )
		);

		return self::groups_from_rows( 'pressqueue-decisions', __( 'PressQueue publishing decisions', 'pressqueue' ), __( 'PressQueue decision', 'pressqueue' ), $rows );
	}

	/**
	 * Build delivery export groups.
	 *
	 * @param Database $database Database.
	 * @param int      $user_id User ID.
	 * @param int      $limit Limit.
	 * @param int      $offset Offset.
	 * @return array<int,array<string,mixed>>
	 */
	private static function delivery_groups( Database $database, int $user_id, int $limit, int $offset ): array {
		$deliveries = $database->table( Schema::DELIVERIES );
		$decisions  = $database->table( Schema::DECISIONS );
		$rows       = $database->get_results_array(
			"SELECT v.public_id, v.decision_public_id, v.delivery_mode, v.provider, v.state, v.schedule_slot, v.payload_hash, v.remote_id, v.reconciliation_status, v.created_at, v.updated_at
				FROM `$deliveries` v
				INNER JOIN `$decisions` d ON d.public_id = v.decision_public_id
				WHERE d.author_id = %d
				ORDER BY v.created_at ASC, v.public_id ASC
				LIMIT %d OFFSET %d",
			array( $user_id, $limit, $offset )
		);

		return self::groups_from_rows( 'pressqueue-deliveries', __( 'PressQueue delivery history', 'pressqueue' ), __( 'PressQueue delivery', 'pressqueue' ), $rows );
	}

	/**
	 * Build batch export groups.
	 *
	 * @param Database $database Database.
	 * @param int      $user_id User ID.
	 * @param int      $limit Limit.
	 * @param int      $offset Offset.
	 * @return array<int,array<string,mixed>>
	 */
	private static function batch_groups( Database $database, int $user_id, int $limit, int $offset ): array {
		$table = $database->table( Schema::BATCHES );
		$rows  = $database->get_results_array(
			"SELECT public_id, batch_type, status, created_by, confirmed_by, preview_snapshot_hash, started_at, confirmed_at, completed_at, created_at, updated_at
				FROM `$table`
				WHERE created_by = %d OR confirmed_by = %d
				ORDER BY created_at ASC, public_id ASC
				LIMIT %d OFFSET %d",
			array( $user_id, $user_id, $limit, $offset )
		);

		return self::groups_from_rows( 'pressqueue-batches', __( 'PressQueue batch history', 'pressqueue' ), __( 'PressQueue batch', 'pressqueue' ), $rows );
	}

	/**
	 * Convert rows to WordPress personal data groups.
	 *
	 * @param string                         $group_id Group ID.
	 * @param string                         $group_label Group label.
	 * @param string                         $item_label Item label.
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function groups_from_rows( string $group_id, string $group_label, string $item_label, array $rows ): array {
		$redactor = new ExportRedactor();
		$groups   = array();

		foreach ( $rows as $row ) {
			foreach ( $row as $field => $value ) {
				if ( is_string( $field ) && str_ends_with( $field, '_json' ) && is_string( $value ) ) {
					$row[ $field ] = $redactor->redact_json( $value, false );
				}
			}

			$groups[] = array(
				'group_id'    => $group_id,
				'group_label' => $group_label,
				'item_id'     => (string) ( $row['public_id'] ?? '' ),
				'data'        => array(
					array(
						'name'  => $item_label,
						'value' => wp_json_encode( $redactor->redact_value( $row, false ), JSON_UNESCAPED_SLASHES ),
					),
				),
			);
		}

		return $groups;
	}
}
