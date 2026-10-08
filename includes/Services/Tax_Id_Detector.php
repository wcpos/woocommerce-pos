<?php
/**
 * Tax ID Detector.
 *
 * Detects which third-party tax-ID plugin (if any) is active on the site and
 * builds a per-type "write map" — for each Tax_Id_Types type, the meta key that
 * WCPOS should write to. Order of precedence:
 *
 *   1. Detected active third-party plugin (recognised by basename + populated keys).
 *   2. Populated-key scan over recent orders (when no plugin is recognised).
 *   3. WCPOS sensible defaults (see Tax_Id_Settings::default_write_map()).
 *
 * The result is consumed by Tax_Id_Writer. Pure-logic helpers are exposed as
 * statics so the heuristics are unit-testable without WordPress.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * Tax_Id_Detector class.
 */
class Tax_Id_Detector {
	/**
	 * Per-request detection summary, built once per request by {@see summary()}.
	 *
	 * @var null|array{plugins:array<int,string>,write_map:array<string,string>}
	 */
	private static $summary_cache = null;

	/**
	 * Recognised plugin definitions. Each entry maps a "plugin id" used in the
	 * detection result to:
	 *
	 *   - basename: the plugin file basename (matches `is_plugin_active()`).
	 *   - alt_basenames: alternative folders/files seen in the wild.
	 *   - keys: per-type meta keys that this plugin writes.
	 *
	 * @var array<string,array{basename:string,alt_basenames?:array<int,string>,keys:array<string,string>}>
	 */
	const PLUGINS = array(
		'wc_eu_vat_number' => array(
			'basename'      => 'woocommerce-eu-vat-number/woocommerce-eu-vat-number.php',
			'alt_basenames' => array(),
			'keys'          => array(
				Tax_Id_Types::TYPE_EU_VAT => '_billing_vat_number',
				Tax_Id_Types::TYPE_GB_VAT => '_billing_vat_number',
			),
		),
		'aelia_eu_vat'     => array(
			'basename'      => 'aelia-eu-vat-assistant/aelia-eu-vat-assistant.php',
			'alt_basenames' => array(),
			'keys'          => array(
				Tax_Id_Types::TYPE_EU_VAT => '_eu_vat_data',
				Tax_Id_Types::TYPE_GB_VAT => '_eu_vat_data',
			),
		),
		'wpfactory_eu_vat' => array(
			'basename'      => 'eu-vat-for-woocommerce/eu-vat-for-woocommerce.php',
			'alt_basenames' => array(
				'wpfactory-eu-vat-number/wpfactory-eu-vat-number.php',
			),
			'keys'          => array(
				Tax_Id_Types::TYPE_EU_VAT => '_billing_eu_vat_number',
				Tax_Id_Types::TYPE_GB_VAT => '_billing_eu_vat_number',
			),
		),
		'germanized'       => array(
			'basename'      => 'woocommerce-germanized/woocommerce-germanized.php',
			'alt_basenames' => array(
				'woocommerce-germanized-pro/woocommerce-germanized-pro.php',
			),
			'keys'          => array(
				Tax_Id_Types::TYPE_EU_VAT => '_billing_vat_id',
			),
		),
		'br_market'        => array(
			'basename'      => 'woocommerce-extra-checkout-fields-for-brazil/woocommerce-extra-checkout-fields-for-brazil.php',
			'alt_basenames' => array(
				'brazilian-market-on-woocommerce/brazilian-market-on-woocommerce.php',
			),
			'keys'          => array(
				Tax_Id_Types::TYPE_BR_CPF  => '_billing_cpf',
				Tax_Id_Types::TYPE_BR_CNPJ => '_billing_cnpj',
			),
		),
		'es_nif'           => array(
			'basename'      => 'wc-apg-nif-cif-spain/wc-apg-nif-cif-spain.php',
			'alt_basenames' => array(
				'woocommerce-nif-cif-spain/woocommerce-nif-cif-spain.php',
			),
			'keys'          => array(
				Tax_Id_Types::TYPE_ES_NIF => '_billing_nif',
			),
		),
	);

	/**
	 * Whether `is_plugin_active()` is callable in this request context.
	 * Loads `wp-admin/includes/plugin.php` lazily if necessary.
	 *
	 * @return bool
	 */
	public static function ensure_plugin_helpers_loaded(): bool {
		if ( \function_exists( 'is_plugin_active' ) ) {
			return true;
		}

		$file = ABSPATH . 'wp-admin/includes/plugin.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}

		return \function_exists( 'is_plugin_active' );
	}

	/**
	 * Detect active recognised plugins.
	 *
	 * @return array<int,string> Plugin ids (keys of self::PLUGINS) that are active.
	 */
	public static function active_plugin_ids(): array {
		if ( ! self::ensure_plugin_helpers_loaded() ) {
			return array();
		}

		$active = array();
		foreach ( self::PLUGINS as $plugin_id => $def ) {
			$candidates = array_merge( array( $def['basename'] ), $def['alt_basenames'] );
			foreach ( $candidates as $basename ) {
				if ( \is_plugin_active( $basename ) ) {
					$active[] = $plugin_id;
					break;
				}
			}
		}

		return $active;
	}

	/**
	 * Build the per-type write map by combining detection signals with defaults.
	 *
	 * Precedence (later entries overwrite earlier):
	 *   1. WCPOS defaults
	 *   2. Inferred from populated-key scan (if any)
	 *   3. Active plugin claims
	 *   4. User overrides (passed in)
	 *
	 * @param array<string,string> $defaults     Default per-type → meta-key map.
	 * @param array<string,string> $inferred     Per-type → meta-key map inferred from order scan.
	 * @param array<int,string>    $active_plugins Plugin ids that are active.
	 * @param array<string,string> $overrides    User-supplied per-type overrides.
	 *
	 * @return array<string,string>
	 */
	public static function compose_write_map(
		array $defaults,
		array $inferred,
		array $active_plugins,
		array $overrides
	): array {
		$map = $defaults;

		foreach ( $inferred as $type => $key ) {
			if ( Tax_Id_Types::is_valid_type( $type ) && \is_string( $key ) && '' !== $key ) {
				$map[ $type ] = $key;
			}
		}

		foreach ( $active_plugins as $plugin_id ) {
			$plugin = self::PLUGINS[ $plugin_id ] ?? null;
			if ( null === $plugin ) {
				continue;
			}
			foreach ( $plugin['keys'] as $type => $key ) {
				if ( ! Tax_Id_Types::is_valid_type( $type ) ) {
					continue;
				}
				$map[ $type ] = $key;
			}
		}

		foreach ( $overrides as $type => $key ) {
			if ( Tax_Id_Types::is_valid_type( $type ) && \is_string( $key ) && '' !== $key ) {
				$map[ $type ] = $key;
			}
		}

		return $map;
	}

	/**
	 * Scan recent orders for populated tax-ID-like meta keys and return the
	 * per-type → meta-key map this implies.
	 *
	 * Heuristic: for each candidate key, count the number of populated rows in
	 * the last `$limit` orders. Pick the most-populated key per type.
	 *
	 * @param int $limit Max number of recent orders to inspect.
	 *
	 * @return array<string,string>
	 */
	public static function infer_from_recent_orders( int $limit = 200 ): array {
		if ( $limit <= 0 ) {
			return array();
		}

		// Candidate key → type mapping for the scan. Reuses Tax_Id_Reader's fallback chain
		// for direct types; generic VAT keys all map to TYPE_EU_VAT here (the inference is
		// intentionally coarse — a per-row country prefix lookup is overkill for this).
		$candidates = array(
			'_billing_vat_number'     => Tax_Id_Types::TYPE_EU_VAT,
			'_billing_eu_vat_number'  => Tax_Id_Types::TYPE_EU_VAT,
			'_vat_number'             => Tax_Id_Types::TYPE_EU_VAT,
			'_billing_vat'            => Tax_Id_Types::TYPE_EU_VAT,
			'_billing_vat_id'         => Tax_Id_Types::TYPE_EU_VAT,
			'_billing_cpf'            => Tax_Id_Types::TYPE_BR_CPF,
			'_billing_cnpj'           => Tax_Id_Types::TYPE_BR_CNPJ,
			'_billing_gstin'          => Tax_Id_Types::TYPE_IN_GST,
			'_billing_cf'             => Tax_Id_Types::TYPE_IT_CF,
			'_billing_codice_fiscale' => Tax_Id_Types::TYPE_IT_CF,
			'_billing_piva'           => Tax_Id_Types::TYPE_IT_PIVA,
			'_billing_partita_iva'    => Tax_Id_Types::TYPE_IT_PIVA,
			'_billing_nif'            => Tax_Id_Types::TYPE_ES_NIF,
			'_billing_cuit'           => Tax_Id_Types::TYPE_AR_CUIT,
		);

		if ( ! \function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		/**
		 * Ids only, then one grouped count over the meta table. Hydrating the
		 * orders loaded every meta row of the newest 200 into memory on each POS
		 * order write and exhausted a 128 MB request on a legacy-storage store.
		 *
		 * @var array<int, int|string>|mixed $ids The stub over-narrows every wc_get_orders() result to WC_Order[].
		 */
		$ids = \wc_get_orders(
			array(
				'limit'   => $limit,
				'orderby' => 'date',
				'order'   => 'DESC',
				'status'  => 'any',
				'return'  => 'ids',
			)
		);
		$ids = array_values( array_filter( array_map( 'intval', \is_array( $ids ) ? $ids : array() ) ) );
		if ( array() === $ids ) {
			return array();
		}

		$counts = self::count_populated_keys( $ids, array_keys( $candidates ) );

		// Pick the top-counted key per type.
		$best = array();
		foreach ( $candidates as $meta_key => $type ) {
			if ( $counts[ $meta_key ] <= 0 ) {
				continue;
			}
			if ( ! isset( $best[ $type ] ) || $counts[ $meta_key ] > $counts[ $best[ $type ] ] ) {
				$best[ $type ] = $meta_key;
			}
		}

		$inferred = array();
		foreach ( $best as $type => $meta_key ) {
			$inferred[ $type ] = $meta_key;
		}

		return $inferred;
	}

	/**
	 * How many of the given orders carry a non-empty value for each meta key.
	 *
	 * Reads the order meta table of the active datastore directly: under HPOS
	 * the rows live in `wc_orders_meta`, otherwise in `wp_postmeta`, the same
	 * split {@see Pos_Uuid::get_order_ids_by_uuid()} makes.
	 *
	 * @param int[]    $ids  Order ids to inspect.
	 * @param string[] $keys Candidate meta keys.
	 *
	 * @return array<string,int> Populated-order count per key, zero when absent.
	 */
	private static function count_populated_keys( array $ids, array $keys ): array {
		global $wpdb;

		$counts = array_fill_keys( $keys, 0 );
		if ( array() === $ids || array() === $keys ) {
			return $counts;
		}

		$order_util = '\\Automattic\\WooCommerce\\Utilities\\OrderUtil';
		$hpos       = class_exists( $order_util )
			&& method_exists( $order_util, 'custom_orders_table_usage_is_enabled' )
			&& call_user_func( array( $order_util, 'custom_orders_table_usage_is_enabled' ) );
		$table      = $hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
		$id_column  = $hpos ? 'order_id' : 'post_id';

		$id_placeholders  = implode( ',', array_fill( 0, \count( $ids ), '%d' ) );
		$key_placeholders = implode( ',', array_fill( 0, \count( $keys ), '%s' ) );

		// "Populated" means what the order getter used to decode as non-empty: a
		// plugin that initialises a key with an empty array, an empty string or
		// null stores `a:0:{}`, `s:0:"";` or `N;`, and those must not count.
		$empty_values = array( '', 'a:0:{}', 's:0:"";', 'N;' );
		$empty_placeholders = implode( ',', array_fill( 0, \count( $empty_values ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table and column names are fixed above; every value goes through a placeholder.
		$sql = "SELECT meta_key, COUNT(DISTINCT {$id_column}) AS populated FROM {$table}"
			. " WHERE {$id_column} IN ({$id_placeholders}) AND meta_key IN ({$key_placeholders})"
			. " AND meta_value NOT IN ({$empty_placeholders})"
			. ' GROUP BY meta_key';
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $ids, $keys, $empty_values ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared here with the placeholders built above.

		foreach ( (array) $rows as $row ) {
			if ( isset( $counts[ $row['meta_key'] ] ) ) {
				$counts[ $row['meta_key'] ] = (int) $row['populated'];
			}
		}

		return $counts;
	}

	/**
	 * Build the full detection summary for a request. Cached per-request.
	 *
	 * @return array{plugins:array<int,string>,write_map:array<string,string>}
	 */
	public function summary(): array {
		if ( null !== self::$summary_cache ) {
			return self::$summary_cache;
		}

		$active    = self::active_plugin_ids();
		$inferred  = empty( $active ) ? self::infer_from_recent_orders() : array();
		$overrides = Tax_Id_Settings::get_overrides();
		$defaults  = Tax_Id_Settings::default_write_map();

		self::$summary_cache = array(
			'plugins'   => $active,
			'write_map' => self::compose_write_map( $defaults, $inferred, $active, $overrides ),
		);

		return self::$summary_cache;
	}

	/**
	 * Discard the per-request summary cache. Tests only: the PHPUnit process
	 * never ends between cases, so a warm cache would hide whether a write
	 * path asks the detector at all.
	 *
	 * @internal
	 */
	public static function reset_request_state(): void {
		self::$summary_cache = null;
	}
}
