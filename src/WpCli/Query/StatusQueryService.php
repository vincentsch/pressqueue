<?php
/**
 * WP-CLI status query service.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\WpCli\Query;

use PressQueue\Provider\ProviderRegistryFactory;
use PressQueue\Scheduler\ActionSchedulerQueue;
use PressQueue\Security\Capabilities;
use PressQueue\Storage\Database;
use PressQueue\Storage\Schema;
use PressQueue\Storage\SchemaStatus;
use PressQueue\Storage\SettingsRepository;

/**
 * Reads operational status without mutating state.
 */
final class StatusQueryService {
	/**
	 * Return status details.
	 *
	 * @return array<string,mixed>
	 */
	public function status(): array {
		$database = Database::has_global_database() ? Database::from_globals() : null;
		$schema   = new SchemaStatus( $database );
		$settings = new SettingsRepository();
		$queue    = new ActionSchedulerQueue();

		return array(
			'capabilities'     => array(
				'current'           => function_exists( 'get_option' ) ? (int) get_option( Capabilities::VERSION_OPTION, 0 ) >= Capabilities::TARGET_VERSION : false,
				'installed_version' => function_exists( 'get_option' ) ? (int) get_option( Capabilities::VERSION_OPTION, 0 ) : 0,
				'target_version'    => Capabilities::TARGET_VERSION,
			),
			'plugin_version'   => defined( 'PRESSQUEUE_VERSION' ) ? PRESSQUEUE_VERSION : null,
			'providers'        => ProviderRegistryFactory::registry()->summary(),
			'queue'            => array(
				'batches'         => $this->batch_queue_counts( $database ),
				'deliveries'      => $this->delivery_queue_counts( $database ),
				'recurring_sweep' => $queue->has_recurring_sweep(),
				'scheduler_ready' => $queue->is_ready(),
				'schema_version'  => 1,
			),
			'retention'        => null,
			'runtime_disabled' => null,
			'schema_version'   => 1,
			'settings'         => array(
				'dry_run_enabled'     => $settings->is_dry_run_enabled(),
				'kill_switch_enabled' => $settings->is_kill_switch_enabled(),
			),
			'storage'          => array(
				'current'                   => $schema->is_current(),
				'has_active_migration_lock' => $schema->has_active_migration_lock(),
				'has_migration_failed'      => $schema->has_migration_failed(),
				'installed_version'         => $schema->installed_version(),
				'ready_for_planning'        => $schema->is_ready_for_planning(),
				'target_version'            => Schema::TARGET_VERSION,
			),
		);
	}

	/**
	 * Count delivery states.
	 *
	 * @param Database|null $database Database.
	 * @return array<string,int>
	 */
	private function delivery_queue_counts( ?Database $database ): array {
		if ( null === $database ) {
			return array();
		}

		try {
			$rows = $database->get_results_array(
				'SELECT state, COUNT(*) AS count FROM `' . $database->table( Schema::DELIVERIES ) . '` GROUP BY state'
			);
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return array();
		}

		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ (string) $row['state'] ] = (int) $row['count'];
		}

		ksort( $counts, SORT_STRING );
		return $counts;
	}

	/**
	 * Count queueing batches.
	 *
	 * @param Database|null $database Database.
	 * @return array<string,int>
	 */
	private function batch_queue_counts( ?Database $database ): array {
		if ( null === $database ) {
			return array();
		}

		try {
			$rows = $database->get_results_array(
				'SELECT status, COUNT(*) AS count FROM `' . $database->table( Schema::BATCHES ) . '` GROUP BY status'
			);
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return array();
		}

		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['count'];
		}

		ksort( $counts, SORT_STRING );
		return $counts;
	}
}
