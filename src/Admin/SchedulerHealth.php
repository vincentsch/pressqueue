<?php
/**
 * Detects when background tasks are not running.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Scheduler\ActionSchedulerLoader;
use PressQueue\Scheduler\ActionSchedulerQueue;
use PressQueue\Storage\SettingsRepository;

/**
 * PressQueue sends everything through Action Scheduler, which relies on WordPress cron.
 * When cron cannot run, shares wait forever, so tell the site owner.
 */
final class SchedulerHealth {
	/**
	 * How late a PressQueue task may be before it counts as stuck.
	 */
	private const LATE_SECONDS = 900;

	/**
	 * Return a problem description, or null when background tasks look healthy.
	 *
	 * @return array{title:string,text:string}|null
	 */
	public static function problem(): ?array {
		try {
			if ( ! ( new ActionSchedulerLoader() )->is_ready() ) {
				// Right after activation WordPress has not had a chance to run its tasks yet.
				$activated = (int) get_option( SettingsRepository::ACTIVATED_AT_OPTION, 0 );
				if ( $activated > 0 && time() - $activated < self::LATE_SECONDS ) {
					return null;
				}

				return array(
					'title' => __( 'Background tasks have not started yet', 'pressqueue' ),
					'text'  => __( 'PressQueue sends shares in the background. WordPress starts background tasks when someone visits the site. If this message stays after a few visits, your server may stop WordPress from running its scheduled tasks.', 'pressqueue' ),
				);
			}

			if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
				return null;
			}

			$late = as_get_scheduled_actions(
				array(
					'group'        => ActionSchedulerQueue::GROUP,
					'status'       => 'pending',
					'date'         => gmdate( 'Y-m-d H:i:s', time() - self::LATE_SECONDS ),
					'date_compare' => '<=',
					'per_page'     => 1,
				),
				'ids'
			);
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return null;
		}

		if ( is_array( $late ) && array() !== $late ) {
			return array(
				'title' => __( 'Background tasks are running late', 'pressqueue' ),
				'text'  => __( 'Some PressQueue tasks should have run more than 15 minutes ago. WordPress runs scheduled tasks when someone visits the site, so a quiet site or a server that blocks these requests delays sharing.', 'pressqueue' ),
			);
		}

		return null;
	}

	/**
	 * Render the warning card when there is a problem.
	 */
	public static function render_card(): void {
		$problem = self::problem();
		if ( null === $problem ) {
			return;
		}

		echo '<div class="pq-card pq-card--pad pq-stack" style="border-color:#f2d7a6;background:#fffcf5">';
		echo '<div><h2>' . esc_html( $problem['title'] ) . '</h2><p class="pq-sub">' . esc_html( $problem['text'] ) . '</p></div>';
		echo '<details class="pq-more"><summary>' . esc_html__( 'How to fix it', 'pressqueue' ) . '</summary><div class="pq-more-body pq-stack">';
		echo '<p style="margin:0">' . esc_html__( 'Ask your host to run WordPress cron every five minutes, or add this cron job on the server:', 'pressqueue' ) . '</p>';
		echo '<code class="pq-code">*/5 * * * * curl -s ' . esc_html( site_url( 'wp-cron.php?doing_wp_cron' ) ) . ' &gt;/dev/null</code>';
		echo '<p class="pq-muted" style="margin:0">' . esc_html__( 'With WP-CLI you can also run waiting tasks by hand: wp action-scheduler run', 'pressqueue' ) . '</p>';
		echo '</div></details></div>';
	}
}
