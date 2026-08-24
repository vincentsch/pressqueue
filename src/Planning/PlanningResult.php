<?php
/**
 * Planning result DTO.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Planning;

// phpcs:disable Squiz.Commenting.VariableComment.Missing -- Constructor docs cover this immutable DTO.

/**
 * Contains the decisions and delivery previews from one planning pass.
 */
final class PlanningResult {
	public NormalizedEvent $event;
	/**
	 * Decision summaries.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $decisions;
	/**
	 * Delivery summaries.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $deliveries;
	public bool $durable;

	/**
	 * Constructor.
	 *
	 * @param NormalizedEvent                $event Event.
	 * @param array<int,array<string,mixed>> $decisions Decision summaries.
	 * @param array<int,array<string,mixed>> $deliveries Delivery summaries.
	 * @param bool                           $durable Whether storage was written.
	 */
	public function __construct( NormalizedEvent $event, array $decisions, array $deliveries, bool $durable ) {
		$this->event      = $event;
		$this->decisions  = $decisions;
		$this->deliveries = $deliveries;
		$this->durable    = $durable;
	}

	/**
	 * Return a plain array for admin rendering or tests.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'decisions'  => $this->decisions,
			'deliveries' => $this->deliveries,
			'durable'    => $this->durable,
			'event'      => $this->event->to_array(),
		);
	}
}
