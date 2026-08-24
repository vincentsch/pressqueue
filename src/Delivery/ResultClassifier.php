<?php
/**
 * Delivery result classifier.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Delivery;

use PressQueue\Provider\Dto\ProviderDeliveryRequest;
use PressQueue\Provider\Dto\ProviderDeliveryResult;
use Throwable;

/**
 * Normalizes worker failures that happen outside provider adapters.
 */
final class ResultClassifier {
	/**
	 * Convert a worker exception into a provider-style result.
	 *
	 * @param ProviderDeliveryRequest $request Request.
	 * @param Throwable               $throwable Failure.
	 * @param bool                    $request_may_have_reached_provider Whether submission may have begun.
	 * @return ProviderDeliveryResult
	 */
	public function worker_exception( ProviderDeliveryRequest $request, Throwable $throwable, bool $request_may_have_reached_provider ): ProviderDeliveryResult {
		unset( $throwable );

		if ( $request_may_have_reached_provider ) {
			return ProviderDeliveryResult::failure(
				$request,
				'ambiguous',
				'reconcile_first',
				'worker_exception_after_submission_started',
				array(
					'classification' => 'worker_exception_after_submission_started',
					'schema_version' => 1,
				),
				null,
				true,
				true
			);
		}

		return ProviderDeliveryResult::failure(
			$request,
			'retryable_failure',
			'automatic_allowed',
			'worker_exception_before_submission_started',
			array(
				'classification' => 'worker_exception_before_submission_started',
				'schema_version' => 1,
			),
			null,
			false,
			false
		);
	}
}
