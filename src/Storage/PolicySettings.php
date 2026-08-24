<?php
/**
 * Policy settings snapshot.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Storage;

// phpcs:disable Squiz.Commenting.VariableComment.Missing -- Constructor docs cover this immutable value object.

/**
 * Immutable runtime policy setting values.
 */
final class PolicySettings {
	public bool $kill_switch_enabled;
	public bool $dry_run_enabled;
	public string $delivery_mode;
	public string $delivery_state;

	/**
	 * Constructor.
	 *
	 * @param bool   $kill_switch_enabled Whether outbound delivery is held.
	 * @param bool   $dry_run_enabled Whether dry run is enabled.
	 * @param string $delivery_mode Delivery mode.
	 * @param string $delivery_state Delivery state.
	 */
	public function __construct(
		bool $kill_switch_enabled,
		bool $dry_run_enabled,
		string $delivery_mode,
		string $delivery_state
	) {
		$this->kill_switch_enabled = $kill_switch_enabled;
		$this->dry_run_enabled     = $dry_run_enabled;
		$this->delivery_mode       = $delivery_mode;
		$this->delivery_state      = $delivery_state;
	}
}
