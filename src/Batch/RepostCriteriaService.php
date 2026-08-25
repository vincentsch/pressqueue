<?php
/**
 * Repost criteria validation.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Batch;

use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\PublicIds;
use PressQueue\Storage\StorageValidator;
use PressQueue\Storage\ValidationException;

/**
 * Normalizes repost-specific criteria inside batch filters.
 */
final class RepostCriteriaService {
	private const REPOST_KEYS = array(
		'required_destination_channel_public_ids',
		'prior_delivery_states',
		'prior_accepted_from_utc',
		'prior_accepted_to_utc',
		'minimum_days_since_prior_acceptance',
	);

	/**
	 * Normalize repost-only criteria from filters.
	 *
	 * @param array<string,mixed> $filters Filters.
	 * @return array<string,mixed>
	 */
	public function normalize( array $filters ): array {
		$criteria = array(
			'prior_delivery_states' => array( 'accepted' ),
			'schema_version'        => 1,
		);

		if ( isset( $filters['required_destination_channel_public_ids'] ) ) {
			if ( ! is_array( $filters['required_destination_channel_public_ids'] ) || ! array_is_list( $filters['required_destination_channel_public_ids'] ) ) {
				throw new ValidationException( 'Required repost destinations must be a list.' );
			}

			$ids = array();
			foreach ( $filters['required_destination_channel_public_ids'] as $id ) {
				if ( ! is_string( $id ) ) {
					throw new ValidationException( 'Required repost destinations must be public IDs.' );
				}

				PublicIds::assert( $id );
				$ids[] = $id;
			}
			sort( $ids, SORT_STRING );
			$criteria['required_destination_channel_public_ids'] = array_values( array_unique( $ids ) );
		}

		if ( isset( $filters['prior_delivery_states'] ) ) {
			if ( ! is_array( $filters['prior_delivery_states'] ) || ! array_is_list( $filters['prior_delivery_states'] ) ) {
				throw new ValidationException( 'Prior delivery states must be a list.' );
			}

			$states = array_values( array_unique( $filters['prior_delivery_states'] ) );
			if ( array( 'accepted' ) !== $states ) {
				throw new ValidationException( 'Repost currently supports only accepted prior deliveries.' );
			}
		}

		foreach ( array( 'prior_accepted_from_utc', 'prior_accepted_to_utc' ) as $key ) {
			if ( isset( $filters[ $key ] ) && '' !== $filters[ $key ] ) {
				if ( ! is_string( $filters[ $key ] ) ) {
					throw new ValidationException( 'Prior acceptance date filters must be UTC timestamps.' );
				}

				StorageValidator::assert_utc_timestamp( 'Prior acceptance time', $filters[ $key ] );
				$criteria[ $key ] = $filters[ $key ];
			}
		}

		if ( isset( $filters['minimum_days_since_prior_acceptance'] ) ) {
			$days = (int) $filters['minimum_days_since_prior_acceptance'];
			if ( $days < 1 || $days > 3650 ) {
				throw new ValidationException( 'Minimum days since prior acceptance is out of range.' );
			}

			$criteria['minimum_days_since_prior_acceptance'] = $days;
		}

		return $criteria;
	}

	/**
	 * Return only the common batch filter keys.
	 *
	 * @param array<string,mixed> $filters Filters.
	 * @return array<string,mixed>
	 */
	public function strip_repost_keys( array $filters ): array {
		foreach ( self::REPOST_KEYS as $key ) {
			unset( $filters[ $key ] );
		}

		return $filters;
	}

	/**
	 * Hash criteria for occurrence identity.
	 *
	 * @param array<string,mixed> $criteria Normalized criteria.
	 * @return string
	 */
	public function hash( array $criteria ): string {
		return CanonicalJson::hash( 'repost_criteria', $criteria );
	}
}
