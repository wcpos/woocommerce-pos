<?php
/**
 * WCPOS sync read surface.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

use WP_Error;
use WP_REST_Request;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.

/**
 * The ONE `permissions_check` every sync REST controller shares (D2): the
 * `manage_woocommerce` capability check followed by the F13 install-health gate.
 * Previously eight controllers hand-rolled near-identical copies (with drifting
 * signatures) and only pull/push applied the health gate — so a broken or
 * half-installed store served stale reads from every other endpoint instead of
 * the uniform 503.
 *
 * Order matters: capability FIRST, so an unauthenticated caller still gets
 * 401/403 rather than a peek at server install state; only an authorized caller
 * learns the store is unhealthy (Health::unhealthy_error, 503).
 *
 * The write path layers more on top of this: /push/{collection} forwards via
 * rest_do_request, which re-runs wc/v3's own per-collection capability checks.
 *
 * `health_gated()` is the reserved opt-out seam for a repair endpoint that can
 * actually cure a broken install. The current out-of-band operations endpoints
 * (/uuid/backfill, /orders/index/backfill, /integrity/rebuild) cannot create the
 * gated tables, so they stay gated and fail against an unhealthy store.
 *
 * NOT for the fixtures controller: its check is deliberately different
 * (manage_options + the lab-mode guard, and the routes are lab-gated at
 * registration).
 */
trait Endpoint_Permissions {
	/**
	 * Check the namespace capability and sync-store health.
	 *
	 * @return bool|WP_Error
	 */
	public function permissions_check( WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}
		// F13: refuse to serve/persist against a broken or still-installing store — 503,
		// not a stale read or a silently-swallowed write.
		if ( $this->health_gated() && ! Health::is_healthy() ) {
			return Health::unhealthy_error();
		}

		return true;
	}

	/**
	 * Whether this controller's endpoints refuse to run on a broken/half-installed
	 * store (F13). Default yes. Override to false ONLY for an admin/repair endpoint
	 * that neither reads nor writes the gated tables and that an operator
	 * legitimately needs while the sync store is broken.
	 */
	protected function health_gated(): bool {
		return true;
	}
}
