<?php
/**
 * Install lifecycle analytics.
 *
 * Reports the four events that make retention measurable — install, upgrade,
 * deactivation, uninstall — and keeps the PostHog `site` group properties
 * current via a daily refresh.
 *
 * The awkward part of this surface is that consent and installation happen in
 * the wrong order. `tracking_consent` starts at `undecided` and the consent
 * pop-up is only shown on the NEXT admin page load, so an install event fired
 * from the activation hook is guaranteed to be suppressed by the consent gate
 * and lost. Install and upgrade events are therefore RECORDED at the moment
 * they happen and REPORTED once consent allows it, carrying their original
 * timestamp so retention cohorts stay accurate.
 *
 * Consent is honoured at both ends: nothing is queued once the user has said
 * no, and a queued event is discarded rather than sent if the answer turns out
 * to be no.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * Lifecycle_Events service class.
 */
class Lifecycle_Events {
	/**
	 * Option holding events recorded before consent was decided.
	 *
	 * @var string
	 */
	const PENDING_OPTION = 'woocommerce_pos_analytics_pending_events';

	/**
	 * Option latching that the install event has been recorded.
	 *
	 * Set at activation and never cleared while the plugin is installed, so a
	 * deactivate/reactivate cycle does not report a second install.
	 *
	 * @var string
	 */
	const INSTALL_RECORDED_OPTION = 'woocommerce_pos_analytics_install_recorded';

	/**
	 * Cron hook for the daily group property refresh.
	 *
	 * @var string
	 */
	const REFRESH_HOOK = 'wcpos_analytics_group_refresh';

	/**
	 * Option holding the most recently reported order-count band.
	 *
	 * The uninstaller runs with no plugin code loaded and no warm cache to rely
	 * on. Persisting the band each refresh means the churn metric survives an
	 * uninstall that happens hours after the last page load.
	 *
	 * @var string
	 */
	const LAST_ORDER_BAND_OPTION = 'woocommerce_pos_analytics_order_band';

	/**
	 * Maximum queued events.
	 *
	 * The queue only ever holds one install plus a handful of upgrades, so this
	 * is a backstop against an unbounded option, not a working limit.
	 *
	 * @var int
	 */
	const MAX_PENDING = 20;

	/**
	 * Register the hooks this service owns.
	 */
	public function register_hooks(): void {
		add_action( 'admin_init', array( $this, 'flush_pending' ) );
		add_action( 'admin_init', array( $this, 'maybe_schedule_refresh' ) );
		add_action( self::REFRESH_HOOK, array( $this, 'refresh_group_properties' ) );
	}

	/**
	 * Record the install event. Called from the activation hook.
	 *
	 * No-op after the first call, so only a genuine first install reports.
	 */
	public function record_install(): void {
		if ( get_option( self::INSTALL_RECORDED_OPTION ) ) {
			return;
		}

		// Latch before recording: if the record fails we would rather lose one
		// install event than report an install on every reactivation.
		update_option( self::INSTALL_RECORDED_OPTION, 1, false );

		// Sites that predate this code are not new installs — reporting one on
		// the upgrade that introduces this latch would invent an install spike
		// out of the existing user base. Two independent signals say "WCPOS has
		// run here before": a stored db version (written by the first upgrade
		// pass) and an install timestamp (written the first time the landing
		// profile is built). Latch silently on either.
		if ( '0' !== (string) Settings::get_db_version() ) {
			return;
		}

		if ( false !== get_option( 'woocommerce_pos_installed_at' ) ) {
			return;
		}

		// Stamp the install epoch NOW. Landing_Profile would otherwise create it
		// the first time the profile is built — which, for a site that leaves
		// consent undecided for a month, is a month late. Every days_since_install
		// the site ever reports would be short by that gap.
		add_option( 'woocommerce_pos_installed_at', time() );

		$this->record( 'wcpos_installed' );
	}

	/**
	 * Record a version upgrade.
	 *
	 * @param string $from_version Version being upgraded from.
	 * @param string $to_version   Version being upgraded to.
	 */
	public function record_upgrade( string $from_version, string $to_version ): void {
		// A fresh install runs the upgrade path with no previous version. That
		// is an install, and it has already been reported as one.
		if ( '' === $from_version || '0' === $from_version ) {
			return;
		}

		$this->record(
			'wcpos_upgraded',
			array(
				'from_version' => $from_version,
				'to_version'   => $to_version,
			)
		);
	}

	/**
	 * Report the deactivation event. Called from the deactivation hook.
	 *
	 * Reported immediately rather than queued: a deactivated plugin never gets
	 * another admin page load to flush from, so an unsent deactivation would
	 * sit in the queue until the user reactivates, by which point it is a lie.
	 */
	public function report_deactivation(): void {
		// A network-wide deactivation walks every blog in one request, and
		// Analytics caches the consent answer for the request. Without this the
		// first blog's "yes" would be reused for blogs that said no.
		Analytics::instance()->clear_consent_cache();

		// Check consent before gathering anything: the churn properties run
		// store queries, and a site that opted out should not pay for them.
		if ( ! Analytics::instance()->is_enabled() ) {
			return;
		}

		$analytics = Analytics::instance();

		// `wp plugin deactivate` runs with no current user, so get_distinct_id()
		// comes back empty and capture() would drop the event. Fall back to the
		// site identity, the same way the group refresh and the uninstall
		// reporter do.
		$distinct_id = $analytics->get_distinct_id();
		if ( '' === $distinct_id ) {
			$site_id = $analytics->get_site_id();
			if ( '' === $site_id ) {
				return;
			}

			$distinct_id = 'site_' . $site_id;
		}

		$analytics->capture( 'wcpos_deactivated', $this->get_churn_properties(), $distinct_id );
	}

	/**
	 * Send any events recorded before consent was decided.
	 *
	 * Gated on consent first so that a site which has not opted in never pays
	 * for the queue lookup.
	 */
	public function flush_pending(): void {
		if ( ! Analytics::instance()->is_enabled() ) {
			// A queued event outlives an undecided answer. Once the answer is
			// no, drop it rather than leaving it to sit in the options table
			// waiting for a consent that is not coming.
			if ( 'denied' === Settings::instance()->tracking_consent() ) {
				delete_option( self::PENDING_OPTION );
			}

			return;
		}

		$pending = get_option( self::PENDING_OPTION );
		if ( empty( $pending ) || ! \is_array( $pending ) ) {
			return;
		}

		// Clear first. A failed send is not worth retrying forever, and leaving
		// the queue populated would re-send on every admin page load.
		delete_option( self::PENDING_OPTION );

		$analytics = Analytics::instance();

		foreach ( $pending as $entry ) {
			if ( ! \is_array( $entry ) || empty( $entry['event'] ) ) {
				continue;
			}

			$analytics->capture(
				(string) $entry['event'],
				\is_array( $entry['properties'] ?? null ) ? $entry['properties'] : array(),
				'',
				isset( $entry['timestamp'] ) ? (string) $entry['timestamp'] : ''
			);
		}

		// Queued events describe the install, so the site profile that goes with
		// them is worth sending in the same pass.
		$this->refresh_group_properties();
	}

	/**
	 * Schedule the daily group property refresh if consent allows it.
	 */
	public function maybe_schedule_refresh(): void {
		if ( ! Analytics::instance()->is_enabled() ) {
			// Consent can be withdrawn — stop refreshing if it has been. Guarded
			// so an opted-out site does not touch the cron array on every load.
			if ( wp_next_scheduled( self::REFRESH_HOOK ) ) {
				$this->clear_schedule();
			}

			return;
		}

		if ( ! wp_next_scheduled( self::REFRESH_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::REFRESH_HOOK );
		}
	}

	/**
	 * Clear the scheduled refresh.
	 */
	public function clear_schedule(): void {
		wp_clear_scheduled_hook( self::REFRESH_HOOK );
	}

	/**
	 * Transient guarding the on-page-load group refresh.
	 *
	 * @var string
	 */
	const REFRESH_THROTTLE_TRANSIENT = 'wcpos_analytics_group_refreshed';

	/**
	 * Refresh the site profile from a page load, at most once a day.
	 *
	 * The scheduled refresh is the primary path; this is the fallback for
	 * installs where WP-Cron is unreliable or disabled. Throttled because the
	 * profile is a slow-moving description of the site, not a page-view metric.
	 */
	public function maybe_refresh_group_properties(): void {
		if ( ! Analytics::instance()->is_enabled() ) {
			return;
		}

		if ( false !== get_transient( self::REFRESH_THROTTLE_TRANSIENT ) ) {
			return;
		}

		set_transient( self::REFRESH_THROTTLE_TRANSIENT, 1, DAY_IN_SECONDS );

		$this->refresh_group_properties();
	}

	/**
	 * Push the current site profile onto the PostHog `site` group.
	 */
	public function refresh_group_properties(): void {
		$analytics = Analytics::instance();
		$site_id   = $analytics->get_site_id();

		if ( '' === $site_id ) {
			return;
		}

		$properties = ( new Analytics_Profile() )->get_group_properties();

		// Leave the band somewhere uninstall.php can read it without the plugin.
		if ( isset( $properties['order_count_band'] ) ) {
			update_option( self::LAST_ORDER_BAND_OPTION, $properties['order_count_band'], false );
		}

		$analytics->group( 'site', $site_id, $properties );
	}

	/**
	 * Properties describing how much the site had invested when it churned.
	 *
	 * The order count is banded like every other count we report. Churn
	 * analysis only asks whether they left with nothing or left with a real
	 * trading history, and a band answers that without carrying an exact
	 * figure out of the store.
	 *
	 * @return array<string, mixed>
	 */
	private function get_churn_properties(): array {
		$metrics = ( new Landing_Profile() )->get_metrics();

		return array(
			'days_since_install' => (int) ( $metrics['days_since_install'] ?? 0 ),
			'order_count_band'   => Analytics_Profile::band( (int) ( $metrics['order_count'] ?? 0 ) ),
		);
	}

	/**
	 * Queue a lifecycle event for the next admin page load.
	 *
	 * Always queued, never sent inline — and that is not just about consent.
	 * Install and upgrade both run before the plugin is fully booted: the
	 * activation hook fires in a request where `plugins_loaded` has already
	 * passed, so Init never ran and `wcpos-functions.php` is not loaded, and
	 * the upgrade check runs before `new Init()`. Capturing from either point
	 * would call `wcpos_get_site_uuid()` before it exists and fatal the
	 * activation. Queueing needs nothing but the options API.
	 *
	 * The event carries only its own properties. The environment and store
	 * snapshot lives on the `site` group, which flush_pending() refreshes in
	 * the same pass — no need to copy it onto every event.
	 *
	 * @param string $event      Event name.
	 * @param array  $properties Event properties.
	 */
	private function record( string $event, array $properties = array() ): void {
		// An explicit "no" is an answer, not a delay.
		if ( 'denied' === Settings::instance()->tracking_consent() ) {
			return;
		}

		$pending = get_option( self::PENDING_OPTION );
		if ( ! \is_array( $pending ) ) {
			$pending = array();
		}

		if ( \count( $pending ) >= self::MAX_PENDING ) {
			return;
		}

		$pending[] = array(
			'event'      => $event,
			'properties' => $properties,
			'timestamp'  => gmdate( 'c' ),
		);

		update_option( self::PENDING_OPTION, $pending, false );
	}
}
