<?php
/**
 * Read-only summaries for the overview and activity screens.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Storage\Database;
use PressQueue\Storage\Record\ChannelRecord;
use PressQueue\Storage\Record\DecisionRecord;
use PressQueue\Storage\Repository\ChannelRepository;
use PressQueue\Storage\Repository\DecisionRepository;
use PressQueue\Storage\Repository\DeliveryRepository;
use PressQueue\Storage\Repository\RuleRepository;
use PressQueue\Storage\Schema;

/**
 * Groups recent deliveries and decisions into one feed per post event.
 */
final class ActivitySummary {
	/**
	 * Database.
	 *
	 * @var Database
	 */
	private Database $database;

	/**
	 * Channel cache keyed by public ID.
	 *
	 * @var array<string,ChannelRecord>|null
	 */
	private ?array $channels = null;

	/**
	 * Rule names keyed by public ID.
	 *
	 * @var array<string,string>|null
	 */
	private ?array $rule_names = null;

	/**
	 * Constructor.
	 *
	 * @param Database|null $database Database.
	 */
	public function __construct( ?Database $database = null ) {
		$this->database = $database ?? Database::from_globals();
	}

	/**
	 * Count deliveries by state created in the last number of days.
	 *
	 * @param int $days Window in days.
	 * @return array<string,int>
	 */
	public function delivery_counts( int $days = 7 ): array {
		$since = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );
		$rows  = $this->database->get_results(
			'SELECT state, COUNT(*) AS total FROM `' . $this->database->table( Schema::DELIVERIES ) . '` WHERE created_at >= %s GROUP BY state',
			array( $since )
		);

		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ (string) $row->state ] = (int) $row->total;
		}

		return $counts;
	}

	/**
	 * Count deliveries in the given states regardless of age.
	 *
	 * @param array<int,string> $states States.
	 * @return int
	 */
	public function count_in_states( array $states ): int {
		if ( array() === $states ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $states ), '%s' ) );
		$value        = $this->database->get_var(
			'SELECT COUNT(*) FROM `' . $this->database->table( Schema::DELIVERIES ) . '` WHERE state IN (' . $placeholders . ')',
			array_values( $states )
		);

		return (int) $value;
	}

	/**
	 * Count runtime decisions that did not share in the last number of days.
	 *
	 * @param int $days Window in days.
	 * @return int
	 */
	public function skipped_count( int $days = 7 ): int {
		$since = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );
		$value = $this->database->get_var(
			'SELECT COUNT(DISTINCT event_key) FROM `' . $this->database->table( Schema::DECISIONS ) . "` WHERE created_at >= %s AND event_mode = 'runtime' AND result IN ('skipped', 'excluded', 'policy_error') AND event_key NOT IN (SELECT event_key FROM `" . $this->database->table( Schema::DECISIONS ) . "` WHERE created_at >= %s AND result = 'included')",
			array( $since, $since )
		);

		return (int) $value;
	}

	/**
	 * Return a feed of recent post events.
	 *
	 * Each entry describes one post event: either the deliveries it created or why it was not shared.
	 *
	 * @param int $limit Maximum entries.
	 * @return array<int,array<string,mixed>>
	 */
	public function recent_feed( int $limit = 8 ): array {
		$limit     = max( 1, min( 25, $limit ) );
		$decisions = ( new DecisionRepository( $this->database ) )->find_recent( min( 100, $limit * 6 ) );
		$events    = array();

		foreach ( $decisions as $decision ) {
			if ( 'runtime' !== $decision->event_mode ) {
				continue;
			}

			$key = $decision->event_key;
			if ( ! isset( $events[ $key ] ) ) {
				if ( count( $events ) >= $limit ) {
					continue;
				}

				$events[ $key ] = array(
					'post_id'    => $decision->post_id,
					'trigger'    => $decision->trigger,
					'created_at' => $decision->created_at,
					'decisions'  => array(),
				);
			}

			$events[ $key ]['decisions'][] = $decision;
		}

		$deliveries = new DeliveryRepository( $this->database );
		$feed       = array();

		foreach ( $events as $event_key => $event ) {
			$rows      = $deliveries->find_by_event_key( (string) $event_key );
			$shares    = array();
			$rule_name = '';
			foreach ( $rows as $delivery ) {
				$channel  = $this->channel( $delivery->channel_public_id );
				$shares[] = array(
					'delivery_public_id' => $delivery->public_id,
					'state'              => $delivery->state,
					'service'            => null === $channel ? $delivery->provider : $channel->service,
					'channel_name'       => null === $channel ? $delivery->channel_public_id : self::channel_display_name( $channel ),
					'schedule_slot'      => $delivery->schedule_slot,
				);
			}

			$included = array_values(
				array_filter(
					$event['decisions'],
					static function ( DecisionRecord $decision ): bool {
						return 'included' === $decision->result;
					}
				)
			);

			if ( array() !== $included && null !== $included[0]->rule_public_id ) {
				$rule_name = $this->rule_name( $included[0]->rule_public_id );
			}

			$feed[] = array(
				'post_id'      => $event['post_id'],
				'trigger'      => $event['trigger'],
				'created_at'   => $event['created_at'],
				'shares'       => $shares,
				'rule'         => $rule_name,
				'not_shared'   => array() === $shares ? self::primary_reason( $event['decisions'] ) : '',
				'blocked_kind' => array() === $shares ? self::blocked_kind( $event['decisions'] ) : '',
			);
		}

		return $feed;
	}

	/**
	 * Return the channel display name.
	 *
	 * @param ChannelRecord $channel Channel.
	 * @return string
	 */
	public static function channel_display_name( ChannelRecord $channel ): string {
		foreach ( array( 'display_name', 'display_label', 'name' ) as $key ) {
			if ( isset( $channel->display[ $key ] ) && is_string( $channel->display[ $key ] ) && '' !== $channel->display[ $key ] ) {
				return $channel->display[ $key ];
			}
		}

		return Labels::service( $channel->service );
	}

	/**
	 * Return a channel by public ID.
	 *
	 * @param string $public_id Channel public ID.
	 * @return ChannelRecord|null
	 */
	public function channel( string $public_id ): ?ChannelRecord {
		if ( null === $this->channels ) {
			$this->channels = array();
			foreach ( ( new ChannelRepository( $this->database ) )->find_all() as $channel ) {
				$this->channels[ $channel->public_id ] = $channel;
			}
		}

		return $this->channels[ $public_id ] ?? null;
	}

	/**
	 * Return a rule name by public ID.
	 *
	 * @param string $public_id Rule public ID.
	 * @return string
	 */
	public function rule_name( string $public_id ): string {
		if ( null === $this->rule_names ) {
			$this->rule_names = array();
			foreach ( ( new RuleRepository( $this->database ) )->find_all() as $rule ) {
				$this->rule_names[ $rule->public_id ] = $rule->name;
			}
		}

		return $this->rule_names[ $public_id ] ?? '';
	}

	/**
	 * Pick the most useful explanation for an event that did not share.
	 *
	 * @param array<int,DecisionRecord> $decisions Decisions for one event.
	 * @return string
	 */
	public static function primary_reason( array $decisions ): string {
		$priority = array( 'excluded', 'policy_error', 'skipped' );

		foreach ( $priority as $result ) {
			foreach ( $decisions as $decision ) {
				if ( $result !== $decision->result ) {
					continue;
				}

				foreach ( $decision->reasons as $reason ) {
					if ( ! is_array( $reason ) || ! isset( $reason['code'] ) || ! is_string( $reason['code'] ) ) {
						continue;
					}

					if ( in_array( $reason['code'], array( 'destination_available', 'included_destination' ), true ) ) {
						continue;
					}

					return Labels::reason( $reason['code'], is_string( $reason['label'] ?? null ) ? $reason['label'] : '' );
				}
			}
		}

		return __( 'No sharing rule matched.', 'pressqueue' );
	}

	/**
	 * Return the strongest non-share result for an event.
	 *
	 * @param array<int,DecisionRecord> $decisions Decisions for one event.
	 * @return string
	 */
	private static function blocked_kind( array $decisions ): string {
		foreach ( array( 'policy_error', 'excluded', 'skipped' ) as $result ) {
			foreach ( $decisions as $decision ) {
				if ( $result === $decision->result ) {
					return $result;
				}
			}
		}

		return 'skipped';
	}
}
