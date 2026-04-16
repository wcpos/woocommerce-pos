<?php
/**
 * Analytics service.
 *
 * Thin wrapper around the PostHog capture API. Sends anonymous product
 * analytics so the WCPOS team can understand how the plugin is used and
 * make better product decisions.
 *
 * Events are only sent when the user has explicitly opted in via the
 * `tracking_consent` setting. All calls are no-ops otherwise, so callers
 * can invoke them unconditionally.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use Ramsey\Uuid\Uuid;
use WP_User;
use const WCPOS\WooCommercePOS\VERSION as PLUGIN_VERSION;

/**
 * Analytics service class.
 */
class Analytics {
	/**
	 * Default PostHog project token.
	 *
	 * Client-side PostHog project tokens are designed to be public. They
	 * authorize event ingestion into a specific project only.
	 *
	 * Override with the `WCPOS_POSTHOG_TOKEN` constant if needed.
	 *
	 * @var string
	 */
	const DEFAULT_TOKEN = 'phc_BhTJzZ7fXMqcD4MiaUJQsQqPkEpu94yoSAthXFBWemvd';

	/**
	 * Default PostHog ingestion host.
	 *
	 * Uses a reverse proxy on wcpos.com to reduce the chance of being
	 * blocked by privacy tooling. Override with `WCPOS_POSTHOG_HOST`.
	 *
	 * @var string
	 */
	const DEFAULT_HOST = 'https://ph.wcpos.com';

	/**
	 * Capture endpoint path.
	 *
	 * @var string
	 */
	const CAPTURE_PATH = '/capture/';

	/**
	 * HTTP request timeout in seconds.
	 *
	 * Kept low because capture is fire-and-forget. We set
	 * `blocking => false` in practice, but the timeout still applies to
	 * the TCP connect step.
	 *
	 * @var float
	 */
	const REQUEST_TIMEOUT = 2.0;

	/**
	 * Singleton instance.
	 *
	 * @var null|self
	 */
	private static $instance = null;

	/**
	 * Cached consent state for the current request.
	 *
	 * @var null|bool
	 */
	private $enabled_cache = null;

	/**
	 * Get the singleton instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Reset the singleton. Intended for tests only.
	 */
	public static function reset_instance(): void {
		self::$instance = null;
	}

	/**
	 * Whether analytics is enabled for the current site.
	 *
	 * Returns true only when the user has explicitly allowed tracking
	 * via the general settings. Cached for the duration of the request.
	 */
	public function is_enabled(): bool {
		if ( null !== $this->enabled_cache ) {
			return $this->enabled_cache;
		}

		$consent             = woocommerce_pos_get_settings( 'general', 'tracking_consent' );
		$this->enabled_cache = ( 'allowed' === $consent );

		return $this->enabled_cache;
	}

	/**
	 * Clear the cached consent state.
	 *
	 * Useful after programmatically changing the consent value within a
	 * single request (for example, the AJAX consent notice handler).
	 */
	public function clear_consent_cache(): void {
		$this->enabled_cache = null;
	}

	/**
	 * Capture an event.
	 *
	 * No-op unless analytics is enabled. Automatically attaches the
	 * current user's UUID as `distinct_id`, groups the event under the
	 * site UUID, and merges in a small set of default context properties.
	 *
	 * @param string $event      Event name, e.g. `upgrade_cta_clicked`.
	 * @param array  $properties Event properties. Caller-supplied values
	 *                           take precedence over defaults.
	 *
	 * @return bool True when a request was dispatched, false otherwise.
	 */
	public function capture( string $event, array $properties = array() ): bool {
		if ( ! $this->is_enabled() ) {
			return false;
		}

		if ( '' === $event ) {
			return false;
		}

		$distinct_id = $this->get_distinct_id();
		if ( '' === $distinct_id ) {
			return false;
		}

		$merged_properties = array_merge( $this->get_default_properties(), $properties );

		// PostHog reserves $identify / $groupidentify for person / group
		// definitions. Auto-attaching a $groups binding to those would
		// either duplicate the event's own $group_type/$group_key or
		// incorrectly cross-link them to an unrelated group, so only
		// attach $groups to regular events.
		if ( ! $this->is_reserved_event( $event ) ) {
			$site_id = $this->get_site_id();
			if ( '' !== $site_id ) {
				$merged_properties['$groups'] = array( 'site' => $site_id );
			}
		}

		$payload = array(
			'api_key'     => $this->get_token(),
			'event'       => $event,
			'distinct_id' => $distinct_id,
			'properties'  => $merged_properties,
			'timestamp'   => gmdate( 'c' ),
		);

		return $this->send( self::CAPTURE_PATH, $payload );
	}

	/**
	 * Set person properties on the current user.
	 *
	 * Uses the PostHog `$identify` event. Properties set via `$set_once`
	 * only apply the first time they are seen.
	 *
	 * @param array $set      Properties to set (overwrite).
	 * @param array $set_once Properties to set only on first sighting.
	 */
	public function identify( array $set = array(), array $set_once = array() ): bool {
		if ( ! $this->is_enabled() ) {
			return false;
		}

		$properties = array();
		if ( ! empty( $set ) ) {
			$properties['$set'] = $set;
		}
		if ( ! empty( $set_once ) ) {
			$properties['$set_once'] = $set_once;
		}

		return $this->capture( '$identify', $properties );
	}

	/**
	 * Set group properties.
	 *
	 * Uses the PostHog `$groupidentify` event. Every plugin install maps
	 * to a single `site` group keyed by the site UUID.
	 *
	 * @param string $group_type Group type, e.g. `site`.
	 * @param string $group_key  Group key, e.g. the site UUID.
	 * @param array  $properties Group properties.
	 */
	public function group( string $group_type, string $group_key, array $properties = array() ): bool {
		if ( ! $this->is_enabled() ) {
			return false;
		}

		if ( '' === $group_type || '' === $group_key ) {
			return false;
		}

		return $this->capture(
			'$groupidentify',
			array(
				'$group_type' => $group_type,
				'$group_key'  => $group_key,
				'$group_set'  => $properties,
			)
		);
	}

	/**
	 * Get the PostHog project token.
	 *
	 * Allows override via constant (`WCPOS_POSTHOG_TOKEN`) or filter
	 * (`woocommerce_pos_posthog_token`) for self-hosted deployments.
	 */
	public function get_token(): string {
		$token = \defined( 'WCPOS_POSTHOG_TOKEN' ) ? (string) \WCPOS_POSTHOG_TOKEN : self::DEFAULT_TOKEN;

		/**
		 * Filters the PostHog project token used for analytics.
		 *
		 * @since 1.8.14
		 *
		 * @param string $token The default project token.
		 */
		return (string) apply_filters( 'woocommerce_pos_posthog_token', $token );
	}

	/**
	 * Get the PostHog host URL.
	 *
	 * Allows override via constant (`WCPOS_POSTHOG_HOST`) or filter
	 * (`woocommerce_pos_posthog_host`).
	 */
	public function get_host(): string {
		$host = \defined( 'WCPOS_POSTHOG_HOST' ) ? (string) \WCPOS_POSTHOG_HOST : self::DEFAULT_HOST;

		/**
		 * Filters the PostHog host URL used for analytics.
		 *
		 * @since 1.8.14
		 *
		 * @param string $host The default host URL.
		 */
		return untrailingslashit( (string) apply_filters( 'woocommerce_pos_posthog_host', $host ) );
	}

	/**
	 * Get the distinct ID for the current user.
	 *
	 * Returns the user's POS UUID meta, lazily provisioning it if
	 * missing. This matches the existing pattern in
	 * `Templates\Frontend` for users who load the POS frontend, and
	 * ensures analytics events from the WP admin (where `Frontend` is
	 * never loaded) still have a stable `distinct_id`.
	 *
	 * Empty string when no user is logged in.
	 */
	public function get_distinct_id(): string {
		$user = wp_get_current_user();
		if ( ! $user instanceof WP_User || 0 === $user->ID ) {
			return '';
		}

		$uuid = get_user_meta( $user->ID, '_woocommerce_pos_uuid', true );
		if ( \is_string( $uuid ) && '' !== $uuid ) {
			return $uuid;
		}

		$uuid = Uuid::uuid4()->toString();
		update_user_meta( $user->ID, '_woocommerce_pos_uuid', $uuid );

		return $uuid;
	}

	/**
	 * Get the site UUID used as the `site` group key.
	 *
	 * Lazily provisions the site UUID if missing so admin-only
	 * installs (fresh plugin activation, no POS frontend load yet)
	 * still have a stable site identifier for grouping.
	 */
	public function get_site_id(): string {
		$uuid = get_option( 'woocommerce_pos_uuid', '' );
		if ( \is_string( $uuid ) && '' !== $uuid ) {
			return $uuid;
		}

		$uuid = Uuid::uuid4()->toString();
		update_option( 'woocommerce_pos_uuid', $uuid );

		return $uuid;
	}

	/**
	 * Whether the given event name is a PostHog-reserved identifier
	 * event that should not have a `$groups` binding auto-attached.
	 *
	 * @param string $event Event name.
	 */
	private function is_reserved_event( string $event ): bool {
		return '$identify' === $event || '$groupidentify' === $event;
	}

	/**
	 * Get default properties attached to every captured event.
	 */
	private function get_default_properties(): array {
		return array(
			'plugin_version' => PLUGIN_VERSION,
			'pro_active'     => class_exists( '\WCPOS\WooCommercePOSPro\WooCommercePOSPro' ),
			'locale'         => get_locale(),
		);
	}

	/**
	 * Dispatch a non-blocking HTTPS POST to the PostHog ingestion host.
	 *
	 * @param string $path    Endpoint path (e.g. /capture/).
	 * @param array  $payload JSON payload.
	 */
	private function send( string $path, array $payload ): bool {
		$url    = $this->get_host() . $path;
		$body   = wp_json_encode( $payload );
		if ( false === $body ) {
			return false;
		}

		$response = wp_remote_post(
			$url,
			array(
				'blocking' => false,
				'timeout'  => self::REQUEST_TIMEOUT,
				'headers'  => array( 'Content-Type' => 'application/json' ),
				'body'     => $body,
			)
		);

		return ! is_wp_error( $response );
	}
}
