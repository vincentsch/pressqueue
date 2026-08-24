<?php
/**
 * Provider delivery contract.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Provider;

use PressQueue\Provider\Dto\ProviderDeliveryRequest;
use PressQueue\Provider\Dto\ProviderDeliveryResult;

/**
 * Submits immutable delivery payloads to a provider.
 */
interface DeliveryProvider {
	/**
	 * Submit one provider delivery.
	 *
	 * @param ProviderDeliveryRequest $request Delivery request.
	 * @param string                  $credential Opaque provider credential.
	 * @return ProviderDeliveryResult
	 */
	public function submit( ProviderDeliveryRequest $request, string $credential ): ProviderDeliveryResult;
}
