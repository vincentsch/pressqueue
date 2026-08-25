<?php
/**
 * WordPress personal data eraser.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Privacy;

use PressQueue\Storage\Database;
use PressQueue\Storage\Schema;

/**
 * Redacts nullable operator actor fields without deleting audit evidence.
 */
final class PersonalDataEraser {
	/**
	 * Register WordPress privacy eraser hook.
	 */
	public static function register_hooks(): void {
		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'register_eraser' ) );
		}
	}

	/**
	 * Register eraser callback.
	 *
	 * @param array<string,mixed> $erasers Erasers.
	 * @return array<string,mixed>
	 */
	public static function register_eraser( array $erasers ): array {
		$erasers['pressqueue'] = array(
			'callback'             => array( self::class, 'erase' ),
			'eraser_friendly_name' => __( 'PressQueue', 'pressqueue' ),
		);

		return $erasers;
	}

	/**
	 * Erase nullable subject actor fields.
	 *
	 * @param string $email_address Email address.
	 * @param int    $page Page.
	 * @return array<string,mixed>
	 */
	public static function erase( string $email_address, int $page = 1 ): array {
		unset( $page );

		$user_id = self::user_id_for_email( $email_address );
		if ( null === $user_id || ! Database::has_global_database() ) {
			return self::response( false );
		}

		$database = Database::from_globals();
		$changed  = 0;

		$changed += $database->query(
			'UPDATE `' . $database->table( Schema::DELIVERY_EVENTS ) . '` SET actor_user_id = NULL WHERE actor_user_id = %d',
			array( $user_id )
		);
		$changed += $database->query(
			'UPDATE `' . $database->table( Schema::DELIVERIES ) . '` SET reconciled_by = NULL WHERE reconciled_by = %d',
			array( $user_id )
		);
		$changed += $database->query(
			'UPDATE `' . $database->table( Schema::BATCHES ) . '` SET confirmed_by = NULL WHERE confirmed_by = %d',
			array( $user_id )
		);

		return self::response( $changed > 0 );
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
	 * Build a WordPress eraser response.
	 *
	 * @param bool $items_removed Whether data was changed.
	 * @return array<string,mixed>
	 */
	private static function response( bool $items_removed ): array {
		return array(
			'done'           => true,
			'items_removed'  => $items_removed,
			'items_retained' => true,
			'messages'       => array(
				__( 'PressQueue retained required local audit references such as post author IDs and batch creator IDs.', 'pressqueue' ),
			),
		);
	}
}
