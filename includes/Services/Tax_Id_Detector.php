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

		// Best-effort SQL: tolerate environments where wc_get_orders() / HPOS aren't
		// available. We use the wc_get_orders() API for cross-store compatibility.
		if ( ! \function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$orders = \wc_get_orders(
			array(
				'limit'   => $limit,
				'orderby' => 'date',
				'order'   => 'DESC',
				'status'  => 'any',
				'return'  => 'ids',
			)
		);
		if ( ! \is_array( $orders ) || empty( $orders ) ) {
			return array();
		}

		// Tally populated rows per candidate.
		$counts = array_fill_keys( array_keys( $candidates ), 0 );
		foreach ( $orders as $order_id ) {
			if ( \is_object( $order_id ) && \method_exists( $order_id, 'get_id' ) ) {
				$order_id = $order_id->get_id();
			}
			$meta = \get_post_meta( (int) $order_id );
			if ( ! \is_array( $meta ) || empty( $meta ) ) {
				continue;
			}
			foreach ( $candidates as $meta_key => $_type ) {
				$value = isset( $meta[ $meta_key ][0] ) ? $meta[ $meta_key ][0] : null;
				if ( '' === $value || array() === $value || null === $value ) {
					continue;
				}
				++$counts[ $meta_key ];
			}
		}

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
	 * Build the full detection summary for a request. Cached per-request.
	 *
	 * @return array{plugins:array<int,string>,write_map:array<string,string>}
	 */
	public function summary(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}

		$active    = self::active_plugin_ids();
		$inferred  = empty( $active ) ? self::infer_from_recent_orders() : array();
		$overrides = Tax_Id_Settings::get_overrides();
		$defaults  = Tax_Id_Settings::default_write_map();

		$cache = array(
			'plugins'   => $active,
			'write_map' => self::compose_write_map( $defaults, $inferred, $active, $overrides ),
		);

		return $cache;
	}
}
