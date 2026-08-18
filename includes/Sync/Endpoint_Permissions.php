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
 * The two-tier permission model shared by the sync REST controllers (D2):
 *
 * - `permissions_check` is the client tier. It requires
 *   `access_woocommerce_pos`, then applies the F13 install-health gate. Every
 *   sync read and push route uses this tier; /status opts out of the health gate
 *   so it can report an unhealthy install.
 * - `admin_permissions_check` is the out-of-band operations tier. It requires
 *   `manage_woocommerce`, then applies the same health gate. Only
 *   /uuid/backfill, /orders/index/backfill, and /integrity/rebuild use it.
 *
 * Order matters: capability FIRST, so an unauthenticated caller still gets
 * 401/403 rather than a peek at server install state; only an authorized caller
 * learns the store is unhealthy (Health::unhealthy_error, 503).
 *
 * The write path layers more on top of this: /push/{collection} forwards via
 * rest_do_request. Write_Controller scopes the client-tier grant around raw
 * product, variation, and coupon mutation checks; other collections keep their
 * native wc/v3 capabilities.
 *
 * `health_gated()` lets /status report a broken install and is the reserved
 * opt-out seam for a future repair endpoint that can actually cure one. The
 * current out-of-band operations endpoints (/uuid/backfill,
 * /orders/index/backfill, /integrity/rebuild) cannot create the gated tables,
 * so they stay gated and fail against an unhealthy store.
 */
trait Endpoint_Permissions {
	/**
	 * Check the POS client capability and sync-store health.
	 *
	 * @return bool|WP_Error
	 */
	public function permissions_check( WP_REST_Request $request ) {
		if ( ! current_user_can( 'access_woocommerce_pos' ) ) {
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
	 * Check the WooCommerce admin capability and sync-store health.
	 *
	 * @return bool|WP_Error
	 */
	public function admin_permissions_check( WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}
		if ( $this->health_gated() && ! Health::is_healthy() ) {
			return Health::unhealthy_error();
		}

		return true;
	}

	/**
	 * Whether this controller's endpoints refuse to run on a broken/half-installed
	 * store (F13). Default yes. Override to false ONLY for an admin/repair endpoint
	 * that neither reads nor writes the gated tables and that an operator
	 * legitimately needs while the sync store is broken, or for the status
	 * endpoint that reports whether those tables exist.
	 */
	protected function health_gated(): bool {
		return true;
	}
}
