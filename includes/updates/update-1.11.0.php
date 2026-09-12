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
 * Gallery templates carry the version they were copied from, so a later release can tell a
 * merchant their copy has fallen behind the bundled one. That comparison needs a fingerprint of
 * the content as installed, which no existing template has — backfill it wherever it can be
 * established safely (see Gallery_Update_Status::backfill_source_hashes), then bring any copy
 * that is both behind and provably unedited up to date in place.
 *
 * Nothing a merchant has edited is touched by either pass. Both are idempotent, so activation
 * running them after the upgrade already has is a no-op.
 */
Templates\Gallery_Update_Status::backfill_source_hashes();
Templates\Gallery_Update_Status::sync_untouched();
