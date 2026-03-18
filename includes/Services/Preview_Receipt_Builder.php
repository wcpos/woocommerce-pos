<?php
/**
 * Preview receipt data builder service.
 *
 * Generates realistic sample receipt data for template gallery/editor
 * previews using the store's actual WooCommerce settings.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * Preview_Receipt_Builder class.
 *
 * Builds a sample receipt payload that mirrors the real Receipt_Data_Builder
 * output but uses the store's catalog products, tax rates, and settings
 * instead of data from an actual order.
 */
class Preview_Receipt_Builder {

	/**
	 * Fallback tax rate percentage when no WooCommerce tax rates are configured.
	 *
	 * @var float
	 */
	const FALLBACK_TAX_RATE = 10.0;

	/**
	 * Quantities assigned to each line item (up to 3 products).
	 *
	 * @var int[]
	 */
	const LINE_QUANTITIES = array( 2, 1, 1 );

	/**
	 * Minimum number of line items in the preview.
	 *
	 * @var int
	 */
	const MIN_LINES = 2;

	/**
	 * Fallback product definitions when the catalog is empty.
	 *
	 * @var array[]
	 */
	const FALLBACK_PRODUCTS = array(
		array(
			'name'  => 'Premium Widget',
			'price' => 29.99,
			'sku'   => 'WIDGET-001',
		),
		array(
			'name'  => 'Standard Gadget',
			'price' => 15.50,
			'sku'   => 'GADGET-002',
		),
		array(
			'name'  => 'Deluxe Component',
			'price' => 42.00,
			'sku'   => 'COMP-003',
		),
	);

	/**
	 * Build a preview receipt payload.
	 *
	 * Returns an array matching the receipt data schema with all sections
	 * populated using the store's real settings, products, and tax rates.
	 *
	 * @return array Complete receipt data array.
	 */
	public function build(): array {
		$currency   = get_option( 'woocommerce_currency', 'USD' );
		$tax_config = $this->get_tax_config();
		$tax_rate   = $tax_config['rate'];
		$tax_label  = $tax_config['label'];
		$tax_code   = $tax_config['code'];

		$raw_products       = $this->get_products();
		$prices_include_tax = wc_prices_include_tax();

		// Build line items.
		$lines            = array();
		$lines_total_excl = 0.0;
		$lines_total_incl = 0.0;

		foreach ( $raw_products as $index => $product ) {
			$qty        = self::LINE_QUANTITIES[ $index ] ?? 1;
			$base_price = (float) $product['price'];

			if ( $prices_include_tax ) {
				$unit_incl = $base_price;
				$unit_excl = $base_price / ( 1 + $tax_rate / 100 );
			} else {
				$unit_excl = $base_price;
				$unit_incl = $base_price * ( 1 + $tax_rate / 100 );
			}

			$line_total_incl = round( $unit_incl * $qty, 2 );
			$line_total_excl = round( $unit_excl * $qty, 2 );

			$lines[] = array(
				'key'                => (string) ( $index + 1 ),
				'sku'                => $product['sku'],
				'name'               => $product['name'],
				'qty'                => (float) $qty,
				'unit_price_incl'    => round( $unit_incl, 2 ),
				'unit_price_excl'    => round( $unit_excl, 2 ),
				'line_subtotal_incl' => $line_total_incl,
				'line_subtotal_excl' => $line_total_excl,
				'discounts_incl'     => 0.0,
				'discounts_excl'     => 0.0,
				'line_total_incl'    => $line_total_incl,
				'line_total_excl'    => $line_total_excl,
				'taxes'              => array(),
			);

			$lines_total_excl += $line_total_excl;
			$lines_total_incl += $line_total_incl;
		}

		// Fee (excl tax).
		$fee_excl      = 2.50;
		$fee_tax       = round( $fee_excl * $tax_rate / 100, 2 );
		$fee_incl      = $fee_excl + $fee_tax;
		$fee_label     = __( 'Gift Wrapping', 'woocommerce-pos' );

		// Shipping (excl tax).
		$shipping_excl     = 10.00;
		$shipping_tax      = round( $shipping_excl * $tax_rate / 100, 2 );
		$shipping_incl     = $shipping_excl + $shipping_tax;
		$shipping_label    = __( 'Flat Rate Shipping', 'woocommerce-pos' );

		// Discount: 10% of line items excl total.
		$discount_rate      = 10.0;
		$discount_excl      = round( $lines_total_excl * $discount_rate / 100, 2 );
		$discount_tax       = round( $discount_excl * $tax_rate / 100, 2 );
		$discount_incl      = $discount_excl + $discount_tax;
		/* translators: %s: discount percentage */
		$discount_label     = sprintf( __( 'Summer Sale (%s%%)', 'woocommerce-pos' ), (int) $discount_rate );

		// Totals.
		$subtotal_excl = $lines_total_excl;
		$subtotal_incl = $lines_total_incl;

		// Taxable base: line items - discount + shipping + fee (all excl).
		$taxable_excl = $subtotal_excl - $discount_excl + $shipping_excl + $fee_excl;
		$total_tax    = round( $taxable_excl * $tax_rate / 100, 2 );

		$grand_total_excl = $subtotal_excl - $discount_excl + $shipping_excl + $fee_excl;
		$grand_total_incl = $grand_total_excl + $total_tax;

		// Payment: cash rounded up to nearest 5.
		$tendered     = (float) ( ceil( $grand_total_incl / 5 ) * 5 );
		$change_total = round( $tendered - $grand_total_incl, 2 );

		$meta = array(
			'schema_version' => Receipt_Data_Schema::VERSION,
			'mode'           => 'preview',
			'created_at_gmt' => gmdate( 'Y-m-d H:i:s' ),
			'order_id'       => 1234,
			'order_number'   => '1234',
			'currency'       => $currency,
			'customer_note'  => __( 'Please gift wrap this order. Thank you!', 'woocommerce-pos' ),
		);

		$store = $this->get_store_info();

		$cashier = $this->get_cashier();

		$customer = array(
			'id'               => 42,
			'name'             => 'Sarah Johnson',
			'billing_address'  => array(
				'first_name' => 'Sarah',
				'last_name'  => 'Johnson',
				'company'    => '',
				'address_1'  => '456 Oak Avenue',
				'address_2'  => 'Suite 200',
				'city'       => 'Springfield',
				'state'      => 'IL',
				'postcode'   => '62701',
				'country'    => 'US',
				'email'      => 'sarah.johnson@example.com',
				'phone'      => '(555) 987-6543',
			),
			'shipping_address' => array(
				'first_name' => 'Sarah',
				'last_name'  => 'Johnson',
				'company'    => '',
				'address_1'  => '456 Oak Avenue',
				'address_2'  => 'Suite 200',
				'city'       => 'Springfield',
				'state'      => 'IL',
				'postcode'   => '62701',
				'country'    => 'US',
			),
			'tax_id'           => '',
		);

		$fees = array(
			array(
				'label'      => $fee_label,
				'total_incl' => $fee_incl,
				'total_excl' => $fee_excl,
			),
		);

		$shipping = array(
			array(
				'label'      => $shipping_label,
				'total_incl' => $shipping_incl,
				'total_excl' => $shipping_excl,
			),
		);

		$discounts = array(
			array(
				'label'      => $discount_label,
				'total_incl' => $discount_incl,
				'total_excl' => $discount_excl,
			),
		);

		$totals = array(
			'subtotal_incl'       => $subtotal_incl,
			'subtotal_excl'       => $subtotal_excl,
			'discount_total_incl' => $discount_incl,
			'discount_total_excl' => $discount_excl,
			'tax_total'           => $total_tax,
			'grand_total_incl'    => $grand_total_incl,
			'grand_total_excl'    => $grand_total_excl,
			'paid_total'          => $grand_total_incl,
			'change_total'        => $change_total,
		);

		$taxable_amount_incl = $taxable_excl + $total_tax;

		if ( $tax_rate > 0 ) {
			$tax_summary = array(
				array(
					'code'                => $tax_code,
					'rate'                => $tax_rate,
					'label'               => $tax_label,
					'taxable_amount_excl' => $taxable_excl,
					'tax_amount'          => $total_tax,
					'taxable_amount_incl' => $taxable_amount_incl,
				),
			);
		} else {
			$tax_summary = array();
		}

		$payments = array(
			array(
				'method_id'    => 'pos_cash',
				'method_title' => __( 'Cash', 'woocommerce-pos' ),
				'amount'       => $grand_total_incl,
				'reference'    => '',
				'tendered'     => $tendered,
				'change'       => $change_total,
			),
		);

		$tax_display_mode   = get_option( 'woocommerce_tax_total_display', 'itemized' );
		$presentation_hints = array(
			'display_tax'             => wc_tax_enabled() ? ( $tax_display_mode ? $tax_display_mode : 'itemized' ) : 'hidden',
			'prices_entered_with_tax' => $prices_include_tax,
			'rounding_mode'           => get_option( 'woocommerce_tax_round_at_subtotal', 'no' ),
			'locale'                  => get_locale(),
		);

		$fiscal = array(
			'immutable_id'    => '',
			'receipt_number'  => '',
			'sequence'        => null,
			'hash'            => '',
			'qr_payload'      => '',
			'tax_agency_code' => '',
			'signed_at'       => '',
		);

		return array(
			'meta'               => $meta,
			'store'              => $store,
			'cashier'            => $cashier,
			'customer'           => $customer,
			'lines'              => $lines,
			'fees'               => $fees,
			'shipping'           => $shipping,
			'discounts'          => $discounts,
			'totals'             => $totals,
			'tax_summary'        => $tax_summary,
			'payments'           => $payments,
			'fiscal'             => $fiscal,
			'presentation_hints' => $presentation_hints,
		);
	}

	/**
	 * Get store information from WooCommerce options.
	 *
	 * @return array Store data with name, address, branding, and policy fields.
	 */
	private function get_store_info(): array {
		$store = array(
			'name'          => get_bloginfo( 'name' ),
			'address_lines' => array_values(
				array_filter(
					array(
						get_option( 'woocommerce_store_address', '' ),
						get_option( 'woocommerce_store_address_2', '' ),
						trim( get_option( 'woocommerce_store_city', '' ) . ' ' . get_option( 'woocommerce_store_postcode', '' ) ),
						$this->get_store_country_state(),
					)
				)
			),
			'tax_id'        => get_option( 'woocommerce_store_tax_number', '' ),
			'phone'         => get_option( 'woocommerce_store_phone', '' ),
			'email'         => get_option( 'admin_email', '' ),
		);

		$pos_store               = wcpos_get_store();
		$logo_src                = $pos_store->get_logo_image_src( 'full' );
		$opening_hours           = $pos_store->get_opening_hours();
		$personal_notes          = $pos_store->get_personal_notes();
		$policies_and_conditions = $pos_store->get_policies_and_conditions();
		$footer_imprint          = $pos_store->get_footer_imprint();

		$store['logo']                    = ( is_array( $logo_src ) && ! empty( $logo_src[0] ) ) ? $logo_src[0] : null;
		$store['opening_hours']           = $opening_hours ? $opening_hours : null;
		$store['personal_notes']          = $personal_notes ? $personal_notes : null;
		$store['policies_and_conditions'] = $policies_and_conditions ? $policies_and_conditions : null;
		$store['footer_imprint']          = $footer_imprint ? $footer_imprint : null;

		return $store;
	}

	/**
	 * Get formatted store country/state string.
	 *
	 * The woocommerce_default_country option stores "CC:SS" (e.g. "US:AL").
	 * This converts it to display names like "Alabama, United States (US)".
	 *
	 * @return string Formatted country/state string, or empty string if not set.
	 */
	private function get_store_country_state(): string {
		$raw = get_option( 'woocommerce_default_country', '' );
		if ( '' === $raw ) {
			return '';
		}

		$parts   = explode( ':', $raw );
		$country = $parts[0] ?? '';
		$state   = $parts[1] ?? '';

		$country_name = WC()->countries->get_countries()[ $country ] ?? $country;

		if ( '' !== $state ) {
			$states     = WC()->countries->get_states( $country );
			$state_name = $states[ $state ] ?? $state;

			return $state_name . ', ' . $country_name;
		}

		return $country_name;
	}

	/**
	 * Get cashier data from the current logged-in user.
	 *
	 * Falls back to a sample name if no user is logged in.
	 *
	 * @return array Cashier data with id and name.
	 */
	private function get_cashier(): array {
		$current_user = wp_get_current_user();

		if ( $current_user->exists() ) {
			return array(
				'id'   => $current_user->ID,
				'name' => $current_user->display_name,
			);
		}

		return array(
			'id'   => 0,
			'name' => 'Jane Smith',
		);
	}

	/**
	 * Get product data for line items.
	 *
	 * Queries up to 3 published simple/variable products from the catalog.
	 * Falls back to hardcoded product definitions if the catalog is empty.
	 * Pads the result to at least MIN_LINES items.
	 *
	 * @return array[] Array of product arrays with name, price, and sku keys.
	 */
	private function get_products(): array {
		$products = wc_get_products(
			array(
				'status' => 'publish',
				'type'   => array( 'simple', 'variable' ),
				'limit'  => 3,
				'return' => 'objects',
			)
		);

		$result = array();

		if ( ! empty( $products ) ) {
			foreach ( $products as $product ) {
				$price = (float) $product->get_price();
				if ( $price <= 0 ) {
					$price = 19.99;
				}

				$result[] = array(
					'name'  => $product->get_name(),
					'price' => $price,
					'sku'   => $product->get_sku() ? $product->get_sku() : '',
				);
			}
		}

		// Fall back to hardcoded products if catalog is empty.
		if ( empty( $result ) ) {
			$result = self::FALLBACK_PRODUCTS;
		}

		// Pad to at least MIN_LINES items.
		$fallback_index    = 0;
		$fallback_count    = count( self::FALLBACK_PRODUCTS );
		$result_count      = count( $result );
		while ( $result_count < self::MIN_LINES ) {
			$result[] = self::FALLBACK_PRODUCTS[ $fallback_index % $fallback_count ];
			++$fallback_index;
			++$result_count;
		}

		return array_slice( $result, 0, 3 );
	}

	/**
	 * Get tax configuration from WooCommerce tax rates.
	 *
	 * Uses WC_Tax::find_rates() with the store's base location to find
	 * the primary tax rate. Falls back to a default rate if no rates
	 * are configured.
	 *
	 * @return array Tax config with rate (float), label (string), and code (string).
	 */
	private function get_tax_config(): array {
		if ( ! wc_tax_enabled() ) {
			return array(
				'rate'  => 0.0,
				'label' => '',
				'code'  => '',
			);
		}

		$default = array(
			'rate'  => self::FALLBACK_TAX_RATE,
			'label' => __( 'Tax', 'woocommerce-pos' ),
			'code'  => '1',
		);

		$raw     = get_option( 'woocommerce_default_country', '' );
		$parts   = explode( ':', $raw );
		$country = $parts[0] ?? '';
		$state   = $parts[1] ?? '';

		$rates = \WC_Tax::find_rates(
			array(
				'country'  => $country,
				'state'    => $state,
				'postcode' => get_option( 'woocommerce_store_postcode', '' ),
				'city'     => get_option( 'woocommerce_store_city', '' ),
				'tax_class' => '',
			)
		);

		if ( ! empty( $rates ) ) {
			$first_rate = reset( $rates );
			$rate_id    = key( $rates );

			return array(
				'rate'  => (float) $first_rate['rate'],
				'label' => $first_rate['label'] ? $first_rate['label'] : __( 'Tax', 'woocommerce-pos' ),
				'code'  => (string) $rate_id,
			);
		}

		return $default;
	}
}
