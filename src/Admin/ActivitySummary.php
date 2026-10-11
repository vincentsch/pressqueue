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
			'SELECT COUNT(DISTINCT event_key) FROM `' . $this->database->table( Schema::DECISIONS ) . "` WHERE created_at >= %s AND event_mode = 'runtime' AND result IN ('skipped', 'excluded', 'policy_error') AND reasons_json NOT LIKE %s AND event_key NOT IN (SELECT event_key FROM `" . $this->database->table( Schema::DECISIONS ) . "` WHERE created_at >= %s AND result = 'included')",
			array( $since, '%no\\_enabled\\_rules%', $since )
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
			if ( 'runtime' !== $decision->event_mode || self::only_no_rules( $decision ) ) {
				continue;
			}

			// Batches plan each channel as its own event; show one row per post and batch hour.
			$key = in_array( $decision->trigger, array( 'bulk', 'repost' ), true )
				? $decision->trigger . '|' . $decision->post_id . '|' . $decision->created_at->format( 'YmdH' )
				: $decision->event_key;
			if ( ! isset( $events[ $key ] ) ) {
				if ( count( $events ) >= $limit ) {
					continue;
				}

				$events[ $key ] = array(
					'post_id'    => $decision->post_id,
					'trigger'    => $decision->trigger,
					'created_at' => $decision->created_at,
					'decisions'  => array(),
					'event_keys' => array(),
				);
			}

			$events[ $key ]['decisions'][]                        = $decision;
			$events[ $key ]['event_keys'][ $decision->event_key ] = true;
		}

		$deliveries = new DeliveryRepository( $this->database );
		$feed       = array();

		foreach ( $events as $event ) {
			$rows = array();
			foreach ( array_keys( $event['event_keys'] ) as $event_key ) {
				$rows = array_merge( $rows, $deliveries->find_by_event_key( (string) $event_key ) );
			}
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
					'scheduled_for_utc'  => $delivery->scheduled_for_utc,
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
				'not_shared'   => array() === $shares ? $this->not_shared_reason( $event['decisions'] ) : '',
				'blocked_kind' => array() === $shares ? self::blocked_kind( $event['decisions'] ) : '',
			);
		}

		return $feed;
	}

	/**
	 * Check whether a decision only says that no rule exists for its trigger.
	 *
	 * Those events, such as updates on a site without update rules, are noise on the overview.
	 *
	 * @param DecisionRecord $decision Decision.
	 * @return bool
	 */
	private static function only_no_rules( DecisionRecord $decision ): bool {
		if ( 'skipped' !== $decision->result ) {
			return false;
		}

		foreach ( $decision->reasons as $reason ) {
			if ( ! is_array( $reason ) || 'no_enabled_rules' !== ( $reason['code'] ?? null ) ) {
				return false;
			}
		}

		return array() !== $decision->reasons;
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
	 * Explain an event that did not share, naming the "never share" rule when one matched.
	 *
	 * @param array<int,DecisionRecord> $decisions Decisions for one event.
	 * @return string
	 */
	public function not_shared_reason( array $decisions ): string {
		foreach ( $decisions as $decision ) {
			if ( 'excluded' !== $decision->result ) {
				continue;
			}

			foreach ( $decision->reasons as $reason ) {
				if ( is_array( $reason ) && 'explicit_post_exclusion' === ( $reason['code'] ?? null ) ) {
					return Labels::reason( 'explicit_post_exclusion' );
				}
			}

			$name = null === $decision->rule_public_id ? '' : $this->rule_name( $decision->rule_public_id );
			if ( '' !== $name ) {
				/* translators: %s: rule name. */
				return sprintf( __( 'Matches the "never share" rule "%s".', 'pressqueue' ), $name );
			}
		}

		return self::primary_reason( $decisions );
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
