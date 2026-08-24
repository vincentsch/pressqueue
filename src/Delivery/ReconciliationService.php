<?php
/**
 * Ambiguous delivery reconciliation.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Delivery;

use PressQueue\Storage\Database;
use PressQueue\Storage\Record\DeliveryRecord;
use PressQueue\Storage\Repository\DeliveryRepository;
use PressQueue\Storage\Repository\DeliveryTransitionRepository;
use PressQueue\Storage\StorageException;
use PressQueue\Storage\StorageValidator;
use PressQueue\Storage\ValidationException;

/**
 * Records operator evidence for ambiguous provider outcomes.
 */
final class ReconciliationService {
	/**
	 * Deliveries.
	 *
	 * @var DeliveryRepository
	 */
	private DeliveryRepository $deliveries;

	/**
	 * Transitions.
	 *
	 * @var DeliveryTransitionRepository
	 */
	private DeliveryTransitionRepository $transitions;

	/**
	 * Constructor.
	 *
	 * @param Database|null $database Database.
	 */
	public function __construct( ?Database $database = null ) {
		$database          = $database ?? Database::from_globals();
		$this->deliveries  = new DeliveryRepository( $database );
		$this->transitions = new DeliveryTransitionRepository( $database );
	}

	/**
	 * Confirm an ambiguous delivery outcome.
	 *
	 * @param string      $delivery_public_id Delivery public ID.
	 * @param string      $outcome Reconciliation outcome.
	 * @param string|null $remote_id Operator-confirmed remote ID.
	 * @param string|null $note Optional evidence note.
	 * @param int|null    $actor_user_id Actor user ID.
	 * @return DeliveryRecord
	 */
	public function reconcile( string $delivery_public_id, string $outcome, ?string $remote_id, ?string $note, ?int $actor_user_id ): DeliveryRecord {
		$delivery = $this->must_find( $delivery_public_id );
		$note     = $this->sanitize_note( $note );

		if ( 'ambiguous' !== $delivery->state ) {
			$this->refuse( $delivery, $actor_user_id, 'reconciliation_not_allowed' );
		}

		$updated = null;

		try {
			switch ( $outcome ) {
				case 'confirmed_remote_id':
					if ( null === $remote_id || '' === trim( $remote_id ) ) {
						throw new ValidationException( 'Remote ID is required for this reconciliation outcome.' );
					}
					StorageValidator::assert_remote_identifier( 'Remote ID', $remote_id );
					$updated = $this->transitions->reconcile_confirmed_remote_id(
						$delivery->public_id,
						$remote_id,
						$actor_user_id,
						$note,
						array(
							'classification' => 'operator_reconciled_ambiguous_delivery',
							'schema_version' => 1,
						)
					);
					break;

				case 'confirmed_not_accepted':
					$updated = $this->transitions->reconcile_confirmed_not_accepted(
						$delivery->public_id,
						$actor_user_id,
						$note,
						array(
							'classification' => 'operator_reconciled_ambiguous_delivery',
							'schema_version' => 1,
						)
					);
					break;

				case 'cancelled':
					$updated = $this->transitions->reconcile_cancelled(
						$delivery->public_id,
						$actor_user_id,
						$note,
						array(
							'classification' => 'operator_reconciled_ambiguous_delivery',
							'schema_version' => 1,
						)
					);
					break;

				default:
					throw new ValidationException( 'Reconciliation outcome is not supported.' );
			}
		} catch ( ValidationException $exception ) {
			unset( $exception );

			$this->refuse( $delivery, $actor_user_id, 'reconciliation_refused' );
		}

		if ( null === $updated ) {
			$this->refuse( $delivery, $actor_user_id, 'reconciliation_state_changed' );
		}

		return $updated;
	}

	/**
	 * Load a delivery or fail.
	 *
	 * @param string $delivery_public_id Delivery public ID.
	 * @return DeliveryRecord
	 */
	private function must_find( string $delivery_public_id ): DeliveryRecord {
		$delivery = $this->deliveries->find_by_public_id( $delivery_public_id );

		if ( null === $delivery ) {
			throw new ValidationException( 'Delivery does not exist.' );
		}

		return $delivery;
	}

	/**
	 * Sanitize an optional evidence note.
	 *
	 * @param string|null $note Note.
	 * @return string|null
	 */
	private function sanitize_note( ?string $note ): ?string {
		if ( null === $note ) {
			return null;
		}

		$note = trim( $note );
		if ( '' === $note ) {
			return null;
		}

		if ( function_exists( 'sanitize_textarea_field' ) ) {
			$note = sanitize_textarea_field( $note );
		} else {
			$note = trim( preg_replace( '/<[^>]*>/', '', $note ) ?? '' );
		}

		if ( strlen( $note ) > 500 ) {
			$note = substr( $note, 0, 500 );
		}

		return '' === $note ? null : $note;
	}

	/**
	 * Record an authorized refused transition and throw a safe validation error.
	 *
	 * @param DeliveryRecord $delivery Delivery.
	 * @param int|null       $actor_user_id Actor user ID.
	 * @param string         $classification Redacted classification.
	 * @throws StorageException When the event ledger cannot be written.
	 * @throws ValidationException Always throws.
	 */
	private function refuse( DeliveryRecord $delivery, ?int $actor_user_id, string $classification ): void {
		try {
			$this->transitions->record_refused_transition(
				$delivery->public_id,
				array(
					'classification' => $classification,
					'schema_version' => 1,
				),
				$actor_user_id
			);
		} catch ( StorageException $exception ) {
			throw $exception;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Classification is a redacted internal enum, not rendered directly.
		throw new ValidationException( 'Delivery reconciliation refused: ' . $classification . '.' );
	}
}
