<?php
/**
 * WCPOS sync read surface.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

use WCPOS\WooCommercePOS\Services\Settings;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.

/**
 * Representation-config FINGERPRINT — the fourth change signal ADR 0006 adds to
 * close Scenario 1 (settings-change staleness), the gap ADR 0005's three-tier
 * hybrid is STRUCTURALLY blind to.
 *
 * THE PROBLEM. A global WCPOS/WooCommerce SETTING change alters the *served
 * representation* of MANY records without bumping any record's `date_modified`
 * and without touching any record's storage. The canonical case: an admin flips
 * which meta key is the POS "barcode" field (`_sku` -> `_global_unique_id`).
 * Every product's effective barcode changes, but no product row changes — so
 * TIER 1 sequence-log (no save hook fires), TIER 2 hash-checksum (raw row
 * unchanged) are both blind, and TIER 3 revision-hash would catch it but is
 * never polled (112-142s at 10k). See
 * docs/experiments/config-change-signal-2026-06-17.md.
 *
 * THE SIGNAL. Per collection, a cheap FINGERPRINT = md5 over the canonicalized
 * (sorted-key) set of representation-affecting settings for that collection. The
 * client retains a per-collection baseline and diffs each poll; on a move it
 * marks that collection stale and re-derives (or re-fetches).
 *
 * WHY HASHED FROM LIVE OPTIONS, NOT A HOOK COUNTER. The endpoint recomputes this
 * from the ACTUAL current options on every call, so it is SELF-HEALING: a setting
 * changed by a hook-bypassing path (another plugin's `update_option`, wp-cli, a
 * direct SQL write to `wp_options`) is detected on the next poll just like a
 * hooked one — the property a hook-only counter could not give. Live recompute
 * is the ONLY mechanism; there is no stored snapshot to go stale.
 *
 * ADR 0003 (discovery only): the fingerprint LOCATES that a representation config
 * moved; it never produces a value the POS trusts. The trusted re-derivation runs
 * client-side over already-synced filtered payloads (or a normal filtered
 * re-fetch). `barcode_fields` reports the resolved active PAYLOAD field names so
 * the client can rebuild its local barcode index without a server round-trip.
 *
 * The representation-setting set is a deliberate SUPERSET of every option that
 * changes the served representation: under-scoping would MISS a config change, so
 * the safe direction is to over-include. The set MUST GROW as new
 * representation-affecting settings are added.
 */
final class Config_Fingerprint {
	/**
	 * Default barcode meta key when the option is unset/blank.
	 */
	public const DEFAULT_BARCODE_FIELD = '_global_unique_id';

	/**
	 * Bump when a new one-time cleanup step is added to maybe_cleanup_legacy_options().
	 * Stored per-site so the sweep runs exactly once per upgrade, not once per request.
	 */
	public const CLEANUP_VERSION = 1;

	/**
	 * The latch for the one-time sweep. DELIBERATELY NOT under the
	 * LEGACY_PROACTIVE_OPTION_PREFIX namespace: an option that both names the sweep
	 * and lives inside the range the sweep deletes would erase its own latch and
	 * re-run forever.
	 */
	public const CLEANUP_VERSION_OPTION = 'woocommerce_pos_sync_config_fingerprint_cleanup_version';

	/**
	 * Where the removed PROACTIVE snapshot used to be stored, per collection. The
	 * write half is gone (its only reader was deleted first); the rows it left in
	 * `wp_options` on existing installs are swept by maybe_cleanup_legacy_options().
	 */
	private const LEGACY_PROACTIVE_OPTION_PREFIX = 'woocommerce_pos_sync_config_fp_';

	/**
	 * Raw barcode META key -> synced-doc PAYLOAD field name, for the mappings the
	 * catalog proxy serves as NATIVE top-level wc/v3 fields. Keys are the values
	 * the production barcode setting can hold; values are the top-level payload
	 * field names the client indexes against.
	 *
	 * HONESTY CONSTRAINT (review finding 9): this map may ONLY list a TOP-LEVEL
	 * field the sync read surface ACTUALLY serves. That surface is the catalog
	 * proxy → raw wc/v3 (Catalog_Proxy_Controller forwards to /wc/v3/products),
	 * which emits `sku` and `global_unique_id` natively but NEVER a top-level
	 * `barcode` — that stamping lives only on the wcpos/v1 Products_Controller
	 * (an override of a DIFFERENT namespace the proxy never dispatches through).
	 *
	 * A custom meta key has no top-level field, but its value DOES reach the
	 * client: proxied responses carry it in `meta_data` (pinned by
	 * Test_Catalog_Proxy_Barcode read-parity tests). So a key absent from this
	 * map is advertised as a `meta_data:<key>` SELECTOR by barcode_fields() if
	 * WooCommerce does not classify it as internal. Internal product properties
	 * are excluded from serialized `meta_data`, so their selector list remains
	 * empty.
	 */
	private const BARCODE_META_TO_PAYLOAD = array(
		'_sku'              => 'sku',
		'_global_unique_id' => 'global_unique_id',
	);

	/** The collections this signal covers, in the engine's vocabulary. */
	/**
	 * Registry projection (#421 increment 8): the fingerprinted collections.
	 */
	public static function collections(): array {
		return array_keys( Collections::with( 'fingerprint' ) );
	}

	/**
	 * The active barcode META key, read from production settings and coerced to a
	 * non-empty string (a blank option falls back to the default mapping rather
	 * than producing an empty representation).
	 */
	public function active_barcode_meta_key(): string {
		$value = Settings::instance()->barcode_field();

		return '' === trim( $value ) ? self::DEFAULT_BARCODE_FIELD : $value;
	}

	/**
	 * The canonicalized representation-affecting settings for a collection.
	 * DELIBERATELY A SUPERSET: this set must GROW as new representation settings
	 * are added, because an omitted setting would silently miss its config
	 * change. ksort() is mandatory (ADR 0006 "Canonicalization discipline") — an
	 * unstable serialization order would change the hash every poll and
	 * false-positive forever.
	 */
	public function representation_settings( string $collection ): array {
		if ( \in_array( $collection, self::barcode_collections(), true ) ) {
			$settings = array( 'barcode_field' => $this->active_barcode_meta_key() );
		} else {
			// tax_rates (and any non-barcode collection): no representation
			// setting tracked yet. Still hashed, so adding one later just widens
			// this array.
			$settings = array();
		}

		ksort( $settings );

		return $settings;
	}

	/**
	 * The per-collection fingerprint — md5 over the canonical settings JSON.
	 */
	public function fingerprint( string $collection ): string {
		return md5( (string) wp_json_encode( $this->representation_settings( $collection ) ) );
	}

	/**
	 * The resolved active barcode selectors for a collection: the payload field
	 * name the client indexes (`sku`, `global_unique_id`) for native mappings,
	 * or a `meta_data:<key>` selector for any other configured meta key (#1385
	 * — the proxied `meta_data` carries the value, so the client derives its
	 * local index from the named entry). Empty for collections with no barcode
	 * mapping.
	 */
	public function barcode_fields( string $collection ): array {
		if ( ! \in_array( $collection, self::barcode_collections(), true ) ) {
			return array();
		}
		$meta_key = $this->active_barcode_meta_key();

		if ( ! \array_key_exists( $meta_key, self::BARCODE_META_TO_PAYLOAD ) ) {
			// @phpstan-ignore-next-line -- WC_Data_Store forwards this public method to its loaded store.
			$internal_meta_keys = \WC_Data_Store::load( 'product' )->get_internal_meta_keys();

			return \in_array( $meta_key, $internal_meta_keys, true ) ? array() : array( 'meta_data:' . $meta_key );
		}
		$payload_field = self::BARCODE_META_TO_PAYLOAD[ $meta_key ];

		// wc/v3 only serves global_unique_id from WC 9.2 — advertising it on older
		// versions would tell the client to index a field that never arrives.
		if ( 'global_unique_id' === $payload_field && \function_exists( 'WC' ) && version_compare( WC()->version, '9.2', '<' ) ) {
			return array();
		}

		return array( $payload_field );
	}

	/**
	 * The endpoint's read model: fingerprints + barcode fields, per collection.
	 */
	public function snapshot( array $collections ): array {
		$fingerprints   = array();
		$barcode_fields = array();
		foreach ( $collections as $collection ) {
			$collection                  = (string) $collection;
			$fingerprints[ $collection ]   = $this->fingerprint( $collection );
			$barcode_fields[ $collection ] = $this->barcode_fields( $collection );
		}

		return array(
			'fingerprints'   => $fingerprints,
			'barcode_fields' => $barcode_fields,
		);
	}

	/**
	 * One-time sweep of the orphaned proactive-snapshot options. An install that ran
	 * the old settings-save hook still has `woocommerce_pos_sync_config_fp_<collection>` rows
	 * in `wp_options` that NOTHING reads. Deleting the writer left the data behind;
	 * this removes it on the next upgrade.
	 *
	 * Deletes by EXACT key over the known COLLECTIONS rather than a
	 * `LIKE 'woocommerce_pos_sync_config_fp_%'` scan: the namespace is a closed set, so the
	 * exact-key form needs no $wpdb query and cannot collide with a future option that
	 * happens to share the prefix. Only the barcode collections were ever written, but
	 * sweeping the full COLLECTIONS set costs one extra no-op delete and catches a
	 * stray row from any revision.
	 *
	 * Idempotent and correctness-neutral: the endpoint recomputes the fingerprint from
	 * live options as the sole source of truth, so removing these rows cannot change a
	 * served value.
	 */
	public function maybe_cleanup_legacy_options(): void {
		if ( (int) get_option( self::CLEANUP_VERSION_OPTION, 0 ) >= self::CLEANUP_VERSION ) {
			return;
		}

		foreach ( self::collections() as $collection ) {
			delete_option( self::LEGACY_PROACTIVE_OPTION_PREFIX . $collection );
		}

		update_option( self::CLEANUP_VERSION_OPTION, self::CLEANUP_VERSION, false );
	}

	/** Collections whose served representation depends on the barcode setting. */
	/**
	 * Registry projection: the collections with barcode representation settings.
	 */
	private static function barcode_collections(): array {
		$barcode = array();
		foreach ( Collections::with( 'fingerprint' ) as $collection => $row ) {
			if ( $row['fingerprint']['barcode'] ) {
				$barcode[] = $collection;
			}
		}

		return $barcode;
	}
}
