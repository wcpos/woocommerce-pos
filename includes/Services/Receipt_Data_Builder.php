<?php
/**
 * Receipt data builder service.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WC_Abstract_Order;

/**
 * Receipt_Data_Builder class.
 */
class Receipt_Data_Builder {
	/**
	 * Build a canonical receipt payload.
	 *
	 * @param WC_Abstract_Order $order Receipt order.
	 * @param string            $mode  Receipt mode.
	 *
	 * @return array
	 */
	public function build( WC_Abstract_Order $order, string $mode = 'live' ): array {
		$meta = array(
			'schema_version'   => Receipt_Data_Schema::VERSION,
			'mode'             => $mode,
			'created_at_gmt'   => current_time( 'mysql', true ),
			'created_at_local' => current_time( 'mysql', false ),
			'order_id'         => $order->get_id(),
			'order_number'     => $order->get_order_number(),
			'currency'         => $order->get_currency(),
			'customer_note'    => $order->get_customer_note(),
		);

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
		$logo_url                = ( is_array( $logo_src ) && ! empty( $logo_src[0] ) ) ? $logo_src[0] : null;
		$opening_hours           = $pos_store->get_opening_hours();
		$personal_notes          = $pos_store->get_personal_notes();
		$policies_and_conditions = $pos_store->get_policies_and_conditions();
		$footer_imprint          = $pos_store->get_footer_imprint();

		$store['logo']                    = $this->filter_site_logo_fallback( $pos_store, $logo_url );
		$store['opening_hours']           = $opening_hours ? $opening_hours : null;
		$store['personal_notes']          = $personal_notes ? $personal_notes : null;
		$store['policies_and_conditions'] = $policies_and_conditions ? $policies_and_conditions : null;
		$store['footer_imprint']          = $footer_imprint ? $footer_imprint : null;

		$cashier = array(
			'id'   => (int) $order->get_meta( '_pos_user' ),
			'name' => '',
		);
		if ( $cashier['id'] > 0 ) {
			$user = get_user_by( 'id', $cashier['id'] );
			if ( $user ) {
				$cashier['name'] = $user->display_name;
			}
		}

		$customer_id   = $order->get_customer_id();
		$customer_name = trim( $order->get_formatted_billing_full_name() );

		if ( ! $customer_id && '' === $customer_name ) {
			$customer_name = __( 'Guest', 'woocommerce-pos' );
		}

		$customer = array(
			'id'               => $customer_id ? $customer_id : null,
			'name'             => $customer_name,
			'billing_address'  => $order->get_address( 'billing' ),
			'shipping_address' => $order->get_address( 'shipping' ),
			'tax_id'           => '',
		);

		$lines = array();
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$line_total_excl = (float) $item->get_total();
			$line_tax_total  = (float) $item->get_total_tax();
			$line_total_incl = $line_total_excl + $line_tax_total;

			$line_subtotal_excl = (float) $item->get_subtotal();
			$line_subtotal_tax  = (float) $item->get_subtotal_tax();
			$line_subtotal_incl = $line_subtotal_excl + $line_subtotal_tax;

			$qty = (float) $item->get_quantity();
			if ( $qty <= 0 ) {
				$qty = 0.0;
			}
			$unit_price_incl = $qty > 0 ? $line_total_incl / $qty : 0.0;
			$unit_price_excl = $qty > 0 ? $line_total_excl / $qty : 0.0;

			$lines[] = array(
				'key'               => (string) $item_id,
				'sku'               => $item->get_product() ? $item->get_product()->get_sku() : '',
				'name'              => $item->get_name(),
				'qty'               => $qty,
				'unit_price_incl'   => $unit_price_incl,
				'unit_price_excl'   => $unit_price_excl,
				'line_subtotal_incl' => $line_subtotal_incl,
				'line_subtotal_excl' => $line_subtotal_excl,
				'discounts_incl'    => max( 0, $line_subtotal_incl - $line_total_incl ),
				'discounts_excl'    => max( 0, $line_subtotal_excl - $line_total_excl ),
				'line_total_incl'   => $line_total_incl,
				'line_total_excl'   => $line_total_excl,
				'taxes'             => $this->get_line_taxes( $item ),
			);
		}

		$shipping_total_excl = (float) $order->get_shipping_total();
		$shipping_total_tax  = (float) $order->get_shipping_tax();
		$shipping            = array();
		if ( $shipping_total_excl > 0 || $shipping_total_tax > 0 ) {
			$shipping[] = array(
				'label'      => __( 'Shipping', 'woocommerce-pos' ),
				'total_incl' => $shipping_total_excl + $shipping_total_tax,
				'total_excl' => $shipping_total_excl,
			);
		}

		$fees = array();
		foreach ( $order->get_fees() as $fee ) {
			$fee_total_excl = (float) $fee->get_total();
			$fee_total_tax  = (float) $fee->get_total_tax();
			$fees[]         = array(
				'label'      => $fee->get_name(),
				'total_incl' => $fee_total_excl + $fee_total_tax,
				'total_excl' => $fee_total_excl,
			);
		}

		$discount_total_excl = (float) $order->get_discount_total();
		$discount_total_tax  = (float) $order->get_discount_tax();
		$discounts           = array();
		if ( $discount_total_excl > 0 || $discount_total_tax > 0 ) {
			$discounts[] = array(
				'label'      => __( 'Discount', 'woocommerce-pos' ),
				'total_incl' => $discount_total_excl + $discount_total_tax,
				'total_excl' => $discount_total_excl,
			);
		}

		$subtotal_excl = (float) $order->get_subtotal();
		$subtotal_tax  = (float) $order->get_cart_tax();
		$subtotal_incl = $subtotal_excl + $subtotal_tax;

		$tax_total = (float) $order->get_total_tax();
		$total     = (float) $order->get_total();

		$totals = array(
			'subtotal_incl'        => $subtotal_incl,
			'subtotal_excl'        => $subtotal_excl,
			'discount_total_incl'  => $discount_total_excl + $discount_total_tax,
			'discount_total_excl'  => $discount_total_excl,
			'tax_total'            => $tax_total,
			'grand_total_incl'     => $total,
			'grand_total_excl'     => $total - $tax_total,
			'paid_total'           => $total,
			'change_total'         => (float) $order->get_meta( '_pos_cash_change' ),
		);

		$payments = array(
			array(
				'method_id'    => $order->get_payment_method(),
				'method_title' => $order->get_payment_method_title(),
				'amount'       => $total,
				'reference'    => '',
				'tendered'     => (float) $order->get_meta( '_pos_cash_amount_tendered' ),
				'change'       => (float) $order->get_meta( '_pos_cash_change' ),
			),
		);

		$tax_display_mode = get_option( 'woocommerce_tax_total_display', 'itemized' );
		$presentation_hints = array(
			'display_tax'              => wc_tax_enabled() ? ( $tax_display_mode ? $tax_display_mode : 'itemized' ) : 'hidden',
			'prices_entered_with_tax'  => wc_prices_include_tax(),
			'rounding_mode'            => get_option( 'woocommerce_tax_round_at_subtotal', 'no' ),
			'locale'                   => get_locale(),
		);

		$fiscal = array(
			'immutable_id'      => '',
			'receipt_number'    => '',
			'sequence'          => null,
			'hash'              => '',
			'qr_payload'        => '',
			'tax_agency_code'   => '',
			'signed_at'         => '',
			'signature_excerpt' => '',
			'document_label'    => '',
			'is_reprint'        => false,
			'reprint_count'     => 0,
			'extra_fields'      => array(),
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
			'tax_summary'        => $this->get_tax_summary( $order ),
			'payments'           => $payments,
			'fiscal'             => $fiscal,
			'presentation_hints' => $presentation_hints,
			'i18n'               => Receipt_I18n_Labels::get_labels(),
		);
	}

	/**
	 * Hide site-logo fallback when the active POS store explicitly opts out.
	 *
	 * @param object      $pos_store Active POS store object.
	 * @param string|null $logo_url  Resolved store logo URL.
	 *
	 * @return string|null
	 */
	private function filter_site_logo_fallback( $pos_store, ?string $logo_url ): ?string {
		if ( empty( $logo_url ) ) {
			return null;
		}

		$store_id      = method_exists( $pos_store, 'get_id' ) ? (int) $pos_store->get_id() : 0;
		$use_site_logo = true;
		if ( $store_id > 0 ) {
			$use_site_logo = 'no' !== get_post_meta( $store_id, '_use_site_logo', true );
		}

		if ( $use_site_logo ) {
			return $logo_url;
		}

		$custom_logo_id = (int) get_theme_mod( 'custom_logo' );
		if ( $custom_logo_id <= 0 ) {
			return $logo_url;
		}

		$site_logo_src = wp_get_attachment_image_src( $custom_logo_id, 'full' );
		$site_logo_url = ( is_array( $site_logo_src ) && ! empty( $site_logo_src[0] ) ) ? $site_logo_src[0] : null;

		if ( $site_logo_url && $site_logo_url === $logo_url ) {
			return null;
		}

		return $logo_url;
	}

	/**
	 * Build tax summary.
	 *
	 * @param WC_Abstract_Order $order Order object.
	 *
	 * @return array
	 */
	private function get_tax_summary( WC_Abstract_Order $order ): array {
		$summary = array();

		foreach ( $order->get_items( 'tax' ) as $tax_item ) {
			$tax_amount = (float) $tax_item->get_tax_total() + (float) $tax_item->get_shipping_tax_total();
			$rate       = (float) $tax_item->get_rate_percent();
			$taxable_excl = $rate > 0 ? $tax_amount / ( $rate / 100 ) : null;
			$taxable_incl = null;

			if ( null !== $taxable_excl ) {
				$taxable_incl = $taxable_excl + $tax_amount;
			}

			$summary[] = array(
				'code'                => (string) $tax_item->get_rate_id(),
				'rate'                => $rate > 0 ? $rate : null,
				'label'               => $tax_item->get_label( $order ),
				'taxable_amount_excl' => $taxable_excl,
				'tax_amount'          => $tax_amount,
				'taxable_amount_incl' => $taxable_incl,
			);
		}

		return $summary;
	}

	/**
	 * Get formatted store country/state string.
	 *
	 * The woocommerce_default_country option stores "CC:SS" (e.g. "US:AL").
	 * This converts it to display names like "Alabama, United States (US)".
	 *
	 * @return string
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
	 * Build line tax rows.
	 *
	 * @param \WC_Order_Item_Product $item Order line item.
	 *
	 * @return array
	 */
	private function get_line_taxes( $item ): array {
		$taxes = array();

		foreach ( $item->get_taxes()['total'] ?? array() as $tax_rate_id => $tax_amount ) {
			if ( ! $tax_amount ) {
				continue;
			}

			$taxes[] = array(
				'code'   => (string) $tax_rate_id,
				'rate'   => null,
				'label'  => (string) $tax_rate_id,
				'amount' => (float) $tax_amount,
			);
		}

		return $taxes;
	}
}
