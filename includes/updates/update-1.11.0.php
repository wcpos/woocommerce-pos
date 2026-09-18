<?php
/**
 * Update to 1.11.0.
 *
 * POS Only now implies WooCommerce catalog visibility `hidden` (#1862), kept in
 * step by Catalog_Visibility on every later write. Products that were already
 * POS Only get the same invariant here, with their current visibility recorded
 * as the prior, so the locked edit screen never shows Hidden over a stored
 * `visible`. Feature-disabled installs resolve an empty set; re-runs are no-ops
 * because force_hidden() is idempotent. Activation runs the same pass, so the
 * upgrade is covered whichever of the two fires first.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS;

( new Catalog_Visibility() )->force_all();

/*
 * Gallery template fingerprinting and syncing are NOT here on purpose. Both run from
 * Gallery_Update_Status::maintain(), which db_upgrade() calls immediately after this loop and
 * admin_init calls again if an upgrade ever bumps the version without reaching woocommerce_init.
 * A versioned file cannot host a recurring reconciliation: this one stops being included the
 * moment the stored version passes 1.11.0.
 */
