<?php
/**
 * Receipt data builder service.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use DateTimeZone;
use WCPOS\WooCommercePOS\Abstracts\Store;
use WC_Abstract_Order;

/**
 * Receipt_Data_Builder class.
 */
class Receipt_Data_Builder {
	/**
	 * Build a canonical receipt payload.
	 *
	 * @param WC_Abstract_Order $order     Receipt order.
	 * @param string            $mode      Reserved for caller compatibility; the receipt mode is
	 *                                     carried in the request, not the payload.
	 * @param object|null       $pos_store POS store object. Falls back to order meta or default.
	 *
	 * @return array
	 */
	public function build( WC_Abstract_Order $order, string $mode = 'live', $pos_store = null ): array {
		unset( $mode );

		$wc_status    = method_exists( $order, 'get_status' ) ? (string) $order->get_status() : '';
		$status_label = '';
		if ( '' !== $wc_status && function_exists( 'wc_get_order_status_name' ) ) {
			$status_label = (string) wc_get_order_status_name( $wc_status );
		}

		$order_store_id         = (int) $order->get_meta( '_pos_store' );
		$missing_order_store_id = 0;
		if ( null === $pos_store ) {
			$pos_store = $order_store_id > 0 ? wcpos_get_store(
				$order_store_id,
				array(
					'status' => array( 'publish', 'trash' ),
				)
			) : wcpos_get_store();

			if ( $order_store_id > 0 && ! \is_object( $pos_store ) ) {
				$missing_order_store_id = $order_store_id;
			}
		}
		if ( ! \is_object( $pos_store ) && 0 === $missing_order_store_id ) {
			$pos_store = wcpos_get_store();
		}
		if ( ! \is_object( $pos_store ) ) {
			$pos_store = $missing_order_store_id > 0 ? new \stdClass() : new Store();
		}

		$store_resolver = new Receipt_Store_Resolver( $pos_store );
		$date_timezone  = $store_resolver->resolve_store_timezone();
		$date_locale    = $store_resolver->resolve_locale();
		$order_data    = array(
			'id'            => $order->get_id(),
			'number'        => (string) $order->get_order_number(),
			'currency'      => (string) $order->get_currency(),
			'customer_note' => (string) $order->get_customer_note(),
			'wc_status'     => $wc_status,
			'status_label'  => $status_label,
			'created_via'   => method_exists( $order, 'get_created_via' ) ? (string) $order->get_created_via() : '',
			'created'       => $this->format_wc_datetime_in_timezone( $order->get_date_created(), $date_timezone, $date_locale ),
			'paid'          => $this->format_wc_datetime_in_timezone( $order->get_date_paid(), $date_timezone, $date_locale ),
			'completed'     => $this->format_wc_datetime_in_timezone( $order->get_date_completed(), $date_timezone, $date_locale ),
			// Render-time timestamp: refreshed on every build() call so reprints
			// show the actual print time, not a value persisted to the database.
			'printed'       => Receipt_Date_Formatter::from_timestamp( time(), $date_timezone, $date_locale ),
			// Payment fields — templates can render a "How to pay" section guarded
			// by {{#order.needs_payment}}…{{/order.needs_payment}}. payment_url is
			// always populated (WC's order-pay endpoint accepts the key regardless
			// of status); the boolean controls whether to show it.
			'needs_payment' => method_exists( $order, 'needs_payment' ) ? (bool) $order->needs_payment() : false,
			'payment_url'   => method_exists( $order, 'get_checkout_payment_url' ) ? (string) $order->get_checkout_payment_url() : '',
		);

		$display_incl       = 'incl' === $store_resolver->resolve_store_option_string(
			'get_tax_display_cart',
			get_option( 'woocommerce_tax_display_cart', 'excl' )
		);
		$presentation_hints = $store_resolver->build_presentation_hints( (string) $order->get_currency() );
		$tax                = $store_resolver->build_tax_section();
		$store_id              = (int) $store_resolver->get_store_value( 'get_id', 0 );
		$store_name            = (string) $store_resolver->get_store_value( 'get_name', '' );
		if ( $missing_order_store_id > 0 ) {
			$store_id = $missing_order_store_id;
			// translators: %d: Historical POS store ID that no longer exists.
			$store_name = sprintf( __( 'Store #%d', 'woocommerce-pos' ), $missing_order_store_id );
		}
		$store_address         = (string) $store_resolver->get_store_value( 'get_store_address', '' );
		$store_address_2       = (string) $store_resolver->get_store_value( 'get_store_address_2', '' );
		$store_city            = (string) $store_resolver->get_store_value( 'get_store_city', '' );
		$store_postcode        = (string) $store_resolver->get_store_value( 'get_store_postcode', '' );
		$store_country         = (string) $store_resolver->get_store_value( 'get_store_country', '' );
		$store_state           = (string) $store_resolver->get_store_value( 'get_store_state', '' );
		$store_phone           = (string) $store_resolver->get_store_value( 'get_phone', '' );
		$store_email           = (string) $store_resolver->get_store_value( 'get_email', '' );

		$store_tax_ids = $store_resolver->get_store_value( 'get_tax_ids', array() );
		if ( ! is_array( $store_tax_ids ) ) {
			$store_tax_ids = array();
		}
		$store_tax_ids = Receipt_Store_Resolver::with_store_tax_id_labels( $store_tax_ids, $presentation_hints['locale'] ?? '' );

		$store_address_parts = array(
			'address_1' => $store_address,
			'address_2' => $store_address_2,
			'city'      => $store_city,
			'state'     => $store_state,
			'postcode'  => $store_postcode,
			'country'   => $store_country,
		);

		$store = array(
			'id'            => $store_id,
			'name'          => '' !== $store_name ? $store_name : get_bloginfo( 'name' ),
			// Structured address parts mirror customer.billing_address — templates that
			// want country-specific layouts compose from these. address_lines[] is the
			// pre-formatted default for templates that just iterate, composed via
			// WC_Countries::get_formatted_address() so per-country layouts are honoured.
			'address'       => $store_address_parts,
			'address_lines' => Receipt_Store_Resolver::compose_address_lines( $store_address_parts ),
			'tax_ids'       => $store_tax_ids,
			'phone'         => $store_phone,
			'email'         => $store_email,
		);

		$opening_hours_raw       = $store_resolver->get_store_value( 'get_opening_hours', array() );
		$personal_notes          = (string) $store_resolver->get_store_value( 'get_personal_notes', '' );
		$policies_and_conditions = (string) $store_resolver->get_store_value( 'get_policies_and_conditions', '' );
		$footer_imprint          = (string) $store_resolver->get_store_value( 'get_footer_imprint', '' );

		$store['logo']                    = Store_Logo_Resolver::resolve( $pos_store );
		if ( ! empty( $opening_hours_raw ) && \is_array( $opening_hours_raw ) ) {
			$store['opening_hours']          = Opening_Hours_Formatter::format_compact( $opening_hours_raw );
			$store['opening_hours_vertical'] = Opening_Hours_Formatter::format_vertical( $opening_hours_raw );
			$store['opening_hours_inline']   = Opening_Hours_Formatter::format_inline( $opening_hours_raw );
		} elseif ( \is_string( $opening_hours_raw ) && '' !== trim( $opening_hours_raw ) ) {
			$store['opening_hours']          = $opening_hours_raw;
			$store['opening_hours_vertical'] = null;
			$store['opening_hours_inline']   = null;
		} else {
			$store['opening_hours']          = null;
			$store['opening_hours_vertical'] = null;
			$store['opening_hours_inline']   = null;
		}
		$opening_hours_notes              = (string) $store_resolver->get_store_value( 'get_opening_hours_notes', '' );
		$store['opening_hours_notes']     = '' !== $opening_hours_notes ? $opening_hours_notes : null;
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
			$customer_name = /* translators: Short WCPOS UI label; keep concise. */ __( 'Guest', 'woocommerce-pos' );
		}

		$tax_ids = ( new Tax_Id_Reader() )->read_for_order( $order );
		$tax_ids = self::with_customer_tax_id_labels( $tax_ids, $presentation_hints['locale'] ?? '' );

		$customer = array(
			'id'               => $customer_id ? $customer_id : null,
			'name'             => $customer_name,
			'billing_address'  => $order->get_address( 'billing' ),
			'shipping_address' => $order->get_address( 'shipping' ),
			// Structured TaxId[] — read fallback across the legacy meta-key inventory.
			'tax_ids'          => $tax_ids,
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
			$calc_dp            = wc_get_price_decimals();
			$unit_price_incl    = $qty > 0 ? round( $line_total_incl / $qty, $calc_dp ) : 0.0;
			$unit_price_excl    = $qty > 0 ? round( $line_total_excl / $qty, $calc_dp ) : 0.0;
			$unit_subtotal_incl = $qty > 0 ? round( $line_subtotal_incl / $qty, $calc_dp ) : 0.0;
			$unit_subtotal_excl = $qty > 0 ? round( $line_subtotal_excl / $qty, $calc_dp ) : 0.0;

			$discounts_incl = max( 0, $line_subtotal_incl - $line_total_incl );
			$discounts_excl = max( 0, $line_subtotal_excl - $line_total_excl );

			$qty_refunded   = method_exists( $order, 'get_qty_refunded_for_item' )
				? abs( (float) $order->get_qty_refunded_for_item( $item_id ) )
				: 0.0;
			$total_refunded = method_exists( $order, 'get_total_refunded_for_item' )
				? abs( (float) $order->get_total_refunded_for_item( $item_id ) )
				: 0.0;

			$lines[] = array(
				'key'                => (string) $item_id,
				'sku'                => $item->get_product() ? $item->get_product()->get_sku() : '',
				'name'               => $item->get_name(),
				'qty'                => $qty,
				'qty_refunded'       => $qty_refunded,
				'unit_subtotal'      => $display_incl ? $unit_subtotal_incl : $unit_subtotal_excl,
				'unit_subtotal_incl' => $unit_subtotal_incl,
				'unit_subtotal_excl' => $unit_subtotal_excl,
				'unit_price'         => $display_incl ? $unit_price_incl : $unit_price_excl,
				'unit_price_incl'    => $unit_price_incl,
				'unit_price_excl'    => $unit_price_excl,
				'line_subtotal'      => $display_incl ? $line_subtotal_incl : $line_subtotal_excl,
				'line_subtotal_incl' => $line_subtotal_incl,
				'line_subtotal_excl' => $line_subtotal_excl,
				'discounts'          => $display_incl ? $discounts_incl : $discounts_excl,
				'discounts_incl'     => $discounts_incl,
				'discounts_excl'     => $discounts_excl,
				'line_total'         => $display_incl ? $line_total_incl : $line_total_excl,
				'line_total_incl'    => $line_total_incl,
				'line_total_excl'    => $line_total_excl,
				'total_refunded'     => $total_refunded,
				'taxes'              => $this->get_line_taxes( $item ),
				'meta'               => $this->get_item_meta_pairs( $item ),
				'attributes'         => $this->get_product_attribute_pairs( $item ),
			);
		}

		$shipping = array();
		foreach ( $order->get_items( 'shipping' ) as $shipping_item ) {
			if ( ! $shipping_item instanceof \WC_Order_Item_Shipping ) {
				continue;
			}
			$ship_total_excl = (float) $shipping_item->get_total();
			$ship_total_tax  = (float) $shipping_item->get_total_tax();
			$ship_total_incl = $ship_total_excl + $ship_total_tax;
			$shipping[]      = array(
				'label'      => $shipping_item->get_name(),
				'method_id'  => (string) $shipping_item->get_method_id(),
				'total'      => $display_incl ? $ship_total_incl : $ship_total_excl,
				'total_incl' => $ship_total_incl,
				'total_excl' => $ship_total_excl,
				'taxes'      => $this->get_item_taxes( $shipping_item ),
				'meta'       => $this->get_item_meta_pairs( $shipping_item ),
			);
		}

		$fees = array();
		foreach ( $order->get_fees() as $fee ) {
			$fee_total_excl = (float) $fee->get_total();
			$fee_total_tax  = (float) $fee->get_total_tax();
			$fee_total_incl = $fee_total_excl + $fee_total_tax;
			$fees[]         = array(
				'label'      => $fee->get_name(),
				'total'      => $display_incl ? $fee_total_incl : $fee_total_excl,
				'total_incl' => $fee_total_incl,
				'total_excl' => $fee_total_excl,
				'taxes'      => $this->get_item_taxes( $fee ),
				'meta'       => $this->get_item_meta_pairs( $fee ),
			);
		}

		$discounts = array();
		foreach ( $order->get_items( 'coupon' ) as $coupon_item ) {
			if ( ! $coupon_item instanceof \WC_Order_Item_Coupon ) {
				continue;
			}
			$coupon_excl = (float) $coupon_item->get_discount();
			$coupon_tax  = (float) $coupon_item->get_discount_tax();
			$coupon_incl = $coupon_excl + $coupon_tax;
			$discounts[] = array(
				'label'      => $this->get_coupon_label( $coupon_item ),
				'code'       => $coupon_item->get_code(),
				'total'      => $display_incl ? $coupon_incl : $coupon_excl,
				'total_incl' => $coupon_incl,
				'total_excl' => $coupon_excl,
			);
		}

		$discount_total_excl = (float) $order->get_discount_total();
		$discount_total_tax  = (float) $order->get_discount_tax();
		$discount_total_incl = $discount_total_excl + $discount_total_tax;

		$subtotal_excl = array_sum( array_column( $lines, 'line_subtotal_excl' ) );
		$subtotal_incl = array_sum( array_column( $lines, 'line_subtotal_incl' ) );

		// Item count summaries — useful for packing slips and kitchen tickets
		// where Mustache can't sum/count an array at render time.
		$total_qty  = (float) array_sum( array_column( $lines, 'qty' ) );
		$line_count = \count( $lines );

		$tax_total = (float) $order->get_total_tax();
		$total     = (float) $order->get_total();

		$total_excl = $total - $tax_total;
		$refund_total     = method_exists( $order, 'get_total_refunded' )
			? abs( (float) $order->get_total_refunded() )
			: 0.0;
		// Templates render the customer-facing balance after a partial refund.
		// Stays at 0 when nothing was refunded so detailed-receipt's section
		// guard `{{#totals.net_total}}…{{/totals.net_total}}` collapses.
		$net_total = $refund_total > 0 ? max( 0.0, $total - $refund_total ) : 0.0;

		$totals = array(
			'subtotal'             => $display_incl ? $subtotal_incl : $subtotal_excl,
			'subtotal_incl'        => $subtotal_incl,
			'subtotal_excl'        => $subtotal_excl,
			'discount_total'       => $display_incl ? $discount_total_incl : $discount_total_excl,
			'discount_total_incl'  => $discount_total_incl,
			'discount_total_excl'  => $discount_total_excl,
			'tax_total'            => $tax_total,
			'total'          => $display_incl ? $total : $total_excl,
			'total_incl'     => $total,
			'total_excl'     => $total_excl,
			'paid_total'           => $total,
			'change_total'         => (float) $order->get_meta( '_pos_cash_change' ),
			'refund_total'         => $refund_total,
			'net_total'            => $net_total,
			'total_qty'            => $total_qty,
			'line_count'           => $line_count,
		);

		$payments = array(
			array(
				'method_id'      => $order->get_payment_method(),
				'method_title'   => $order->get_payment_method_title(),
				'amount'         => $total,
				'transaction_id' => (string) $order->get_transaction_id(),
				'tendered'       => (float) $order->get_meta( '_pos_cash_amount_tendered' ),
				'change'         => (float) $order->get_meta( '_pos_cash_change' ),
			),
		);

		$tax_summary = $this->get_tax_summary( $order );

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
			'order'              => $order_data,
			'store'              => $store,
			'cashier'            => $cashier,
			'customer'           => $customer,
			'lines'              => $lines,
			'fees'               => $fees,
			'shipping'           => $shipping,
			'discounts'          => $discounts,
			'totals'             => $totals,
			'tax'                => $tax,
			'tax_summary'        => $tax_summary,
			'has_tax_summary'    => ! empty( $tax_summary ),
			'payments'           => $payments,
			'refunds'            => $this->get_refunds( $order, $display_incl, $date_timezone, $date_locale ),
			'fiscal'             => $fiscal,
			'presentation_hints' => $presentation_hints,
			'i18n'               => Receipt_I18n_Labels::get_labels( $presentation_hints['locale'] ?? '' ),
		);
	}


	/**
	 * Resolve an optional human-facing coupon label for a receipt discount row.
	 *
	 * Coupons are identified by code; the code is already exposed as
	 * `discounts[].code`. Prefer a distinct, user-authored description, but
	 * fall back to the code so templates that render `label` always have text.
	 *
	 * @param \WC_Order_Item_Coupon $coupon_item Coupon order item.
	 * @return string
	 */
	private function get_coupon_label( \WC_Order_Item_Coupon $coupon_item ): string {
		$code = (string) $coupon_item->get_code();
		if ( '' === $code ) {
			return '';
		}

		try {
			$coupon = new \WC_Coupon( $code );
		} catch ( \Exception $exception ) {
			return $code;
		}

		if ( ! $coupon->get_id() ) {
			return $code;
		}

		$label = trim( wp_strip_all_tags( (string) $coupon->get_description() ) );

		return '' !== $label && 0 !== strcasecmp( $label, $code ) ? $label : $code;
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

		$taxable_bases = $this->get_taxable_bases_by_rate_id( $order );

		foreach ( $order->get_items( 'tax' ) as $tax_item ) {
			$tax_amount   = (float) $tax_item->get_tax_total() + (float) $tax_item->get_shipping_tax_total();
			$rate         = (float) $tax_item->get_rate_percent();
			$rate_id      = (string) $tax_item->get_rate_id();
			$taxable_excl = $taxable_bases[ $rate_id ] ?? null;
			$taxable_incl = null !== $taxable_excl ? $taxable_excl + $tax_amount : null;

			$summary[] = array(
				'code'                => $rate_id,
				'rate'                => $rate > 0 ? $rate : null,
				'label'               => $tax_item->get_label( $order ),
				'compound'            => method_exists( $tax_item, 'is_compound' ) ? (bool) $tax_item->is_compound() : false,
				'taxable_amount_excl' => $taxable_excl,
				'tax_amount'          => $tax_amount,
				'taxable_amount_incl' => $taxable_incl,
			);
		}

		return $summary;
	}


	/**
	 * Sum post-discount pre-tax item totals by tax rate id.
	 *
	 * A line taxed by multiple rates contributes its full net total to each
	 * applicable rate. Compound rates intentionally use the pure pre-tax net
	 * base for the v1 contract.
	 *
	 * @param WC_Abstract_Order $order Order object.
	 *
	 * @return array<string,float>
	 */
	private function get_taxable_bases_by_rate_id( WC_Abstract_Order $order ): array {
		$bases = array();

		foreach ( array( 'line_item', 'fee', 'shipping' ) as $item_type ) {
			foreach ( $order->get_items( $item_type ) as $item ) {
				if ( ! method_exists( $item, 'get_taxes' ) || ! method_exists( $item, 'get_total' ) ) {
					continue;
				}

				$raw_taxes = $item->get_taxes();
				$totals    = isset( $raw_taxes['total'] ) && is_array( $raw_taxes['total'] ) ? $raw_taxes['total'] : array();
				$base      = (float) $item->get_total();

				foreach ( $totals as $rate_id => $tax_amount ) {
					if ( '' === (string) $rate_id || '' === (string) $tax_amount ) {
						continue;
					}

					$key = (string) $rate_id;
					if ( ! array_key_exists( $key, $bases ) ) {
						$bases[ $key ] = 0.0;
					}

					$bases[ $key ] += $base;
				}
			}
		}

		return $bases;
	}


	/**
	 * Format a WooCommerce date in a resolved receipt timezone.
	 *
	 * @param \WC_DateTime|null $date     WooCommerce date.
	 * @param DateTimeZone      $timezone Receipt timezone.
	 * @param string            $locale   Receipt locale.
	 *
	 * @return array<string,string>
	 */
	private function format_wc_datetime_in_timezone( $date, DateTimeZone $timezone, string $locale = '' ): array {
		if ( ! $date ) {
			return Receipt_Date_Formatter::empty();
		}

		return Receipt_Date_Formatter::from_timestamp( $date->getTimestamp(), $timezone, $locale );
	}


	/**
	 * Build line tax rows.
	 *
	 * @param \WC_Order_Item_Product $item Order line item.
	 *
	 * @return array
	 */
	private function get_line_taxes( $item ): array {
		return $this->get_item_taxes( $item );
	}

	/**
	 * Build tax rows for any order item that exposes get_taxes().
	 *
	 * Resolves human-readable label and percent rate via WC_Tax when possible,
	 * falling back to the rate id string and null rate.
	 *
	 * @param object $item Order item.
	 *
	 * @return array
	 */
	private function get_item_taxes( $item ): array {
		$taxes = array();

		if ( ! method_exists( $item, 'get_taxes' ) ) {
			return $taxes;
		}

		$raw = $item->get_taxes();
		if ( ! \is_array( $raw ) ) {
			return $taxes;
		}

		$totals = isset( $raw['total'] ) && \is_array( $raw['total'] ) ? $raw['total'] : array();

		foreach ( $totals as $tax_rate_id => $tax_amount ) {
			if ( ! $tax_amount ) {
				continue;
			}

			$rate  = null;
			$label = (string) $tax_rate_id;

			if ( class_exists( '\WC_Tax' ) ) {
				// _get_tax_rate() is internal to WooCommerce; keep the fallback label/rate if it changes.
				try {
					$rate_data = \WC_Tax::_get_tax_rate( (int) $tax_rate_id, OBJECT );
					if ( \is_object( $rate_data ) ) {
						if ( isset( $rate_data->tax_rate ) && '' !== $rate_data->tax_rate ) {
							$rate = (float) $rate_data->tax_rate;
						}
						$resolved_label = \WC_Tax::get_rate_label( $rate_data );
						if ( \is_string( $resolved_label ) && '' !== $resolved_label ) {
							$label = $resolved_label;
						}
					}
				} catch ( \Throwable $exception ) {
					$rate  = null;
					$label = (string) $tax_rate_id;
				}
			}

			$taxes[] = array(
				'code'   => (string) $tax_rate_id,
				'rate'   => $rate,
				'label'  => $label,
				'amount' => (float) $tax_amount,
			);
		}

		return $taxes;
	}

	/**
	 * Extract formatted meta pairs from an order item.
	 *
	 * @param object $item Order item.
	 *
	 * @return array
	 */
	private function get_item_meta_pairs( $item ): array {
		$pairs = array();

		if ( ! method_exists( $item, 'get_formatted_meta_data' ) ) {
			return $pairs;
		}

		$formatted_meta = $item->get_formatted_meta_data( '_', true );
		if ( ! \is_array( $formatted_meta ) ) {
			return $pairs;
		}

		foreach ( $formatted_meta as $meta_entry ) {
			if ( isset( $meta_entry->key ) && '_' === substr( (string) $meta_entry->key, 0, 1 ) ) {
				continue;
			}

			$pairs[] = array(
				'key'   => wp_strip_all_tags( $meta_entry->display_key ),
				'value' => wp_strip_all_tags( $meta_entry->display_value ),
			);
		}

		return $pairs;
	}

	/**
	 * Extract product attributes without order-item add-on metadata.
	 *
	 * @param \WC_Order_Item_Product $item Order product item.
	 *
	 * @return array
	 */
	private function get_product_attribute_pairs( \WC_Order_Item_Product $item ): array {
		$product = $item->get_product();
		$pairs   = array();

		if ( ! $product instanceof \WC_Product ) {
			return $pairs;
		}

		if ( $product instanceof \WC_Product_Variation ) {
			foreach ( $product->get_variation_attributes() as $attribute_key => $attribute_value ) {
				if ( '' === (string) $attribute_value ) {
					continue;
				}

				$taxonomy = preg_replace( '/^attribute_/', '', (string) $attribute_key );
				$value    = $product->get_attribute( $taxonomy );
				$pairs[]  = array(
					'key'   => wp_strip_all_tags( wc_attribute_label( $taxonomy, $product ) ),
					'value' => wp_strip_all_tags( '' !== $value ? $value : (string) $attribute_value ),
				);
			}

			return $pairs;
		}

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute || ! $attribute->get_visible() ) {
				continue;
			}

			$values = $attribute->is_taxonomy()
				? wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) )
				: $attribute->get_options();
			$values = array_filter( array_map( 'wp_strip_all_tags', array_map( 'strval', $values ) ) );

			if ( empty( $values ) ) {
				continue;
			}

			$pairs[] = array(
				'key'   => wp_strip_all_tags( wc_attribute_label( $attribute->get_name(), $product ) ),
				'value' => implode( ', ', $values ),
			);
		}

		return $pairs;
	}

	/**
	 * Build refunds[] block from $order->get_refunds().
	 *
	 * @param WC_Abstract_Order $order         Order object.
	 * @param bool              $display_incl  Whether totals should be tax-inclusive (matches shop tax display).
	 * @param DateTimeZone      $date_timezone Receipt timezone.
	 * @param string            $date_locale   Receipt locale.
	 *
	 * @return array
	 */
	private function get_refunds( WC_Abstract_Order $order, bool $display_incl, DateTimeZone $date_timezone, string $date_locale = '' ): array {
		$refunds = array();

		if ( ! method_exists( $order, 'get_refunds' ) ) {
			return $refunds;
		}

		foreach ( $order->get_refunds() as $refund ) {
			if ( ! $refund instanceof \WC_Order_Refund ) {
				continue;
			}

			$refunded_by_id   = (int) $refund->get_refunded_by();
			$refunded_by_name = '';
			if ( $refunded_by_id > 0 ) {
				$user = get_user_by( 'id', $refunded_by_id );
				if ( $user ) {
					$refunded_by_name = (string) $user->display_name;
				}
			}

			$refund_lines = array();
			foreach ( $refund->get_items( 'line_item' ) as $refund_item ) {
				if ( ! $refund_item instanceof \WC_Order_Item_Product ) {
					continue;
				}
				$line_total_excl = abs( (float) $refund_item->get_total() );
				$line_total_tax  = abs( (float) $refund_item->get_total_tax() );
				$line_total_incl = $line_total_excl + $line_total_tax;
				$refund_lines[]  = array(
					'name'       => (string) $refund_item->get_name(),
					'sku'        => $refund_item->get_product() ? (string) $refund_item->get_product()->get_sku() : '',
					'qty'        => abs( (float) $refund_item->get_quantity() ),
					'total'      => $display_incl ? $line_total_incl : $line_total_excl,
					'total_incl' => $line_total_incl,
					'total_excl' => $line_total_excl,
					'taxes'      => array_map(
						static function ( array $tax ): array {
							$tax['amount'] = abs( (float) $tax['amount'] );
							return $tax;
						},
						$this->get_item_taxes( $refund_item )
					),
				);
			}

			$refund_fees = array();
			foreach ( $refund->get_items( 'fee' ) as $refund_fee ) {
				if ( ! $refund_fee instanceof \WC_Order_Item_Fee ) {
					continue;
				}
				$fee_total_excl = abs( (float) $refund_fee->get_total() );
				$fee_total_tax  = abs( (float) $refund_fee->get_total_tax() );
				$fee_total_incl = $fee_total_excl + $fee_total_tax;
				$refund_fees[]  = array(
					'label'      => (string) $refund_fee->get_name(),
					'total'      => $display_incl ? $fee_total_incl : $fee_total_excl,
					'total_incl' => $fee_total_incl,
					'total_excl' => $fee_total_excl,
					'taxes'      => array_map(
						static function ( array $tax ): array {
							$tax['amount'] = abs( (float) $tax['amount'] );
							return $tax;
						},
						$this->get_item_taxes( $refund_fee )
					),
				);
			}

			$refund_shipping = array();
			foreach ( $refund->get_items( 'shipping' ) as $refund_ship ) {
				if ( ! $refund_ship instanceof \WC_Order_Item_Shipping ) {
					continue;
				}
				$ship_total_excl   = abs( (float) $refund_ship->get_total() );
				$ship_total_tax    = abs( (float) $refund_ship->get_total_tax() );
				$ship_total_incl   = $ship_total_excl + $ship_total_tax;
				$refund_shipping[] = array(
					'label'      => (string) $refund_ship->get_name(),
					'method_id'  => method_exists( $refund_ship, 'get_method_id' ) ? (string) $refund_ship->get_method_id() : '',
					'total'      => $display_incl ? $ship_total_incl : $ship_total_excl,
					'total_incl' => $ship_total_incl,
					'total_excl' => $ship_total_excl,
					'taxes'      => array_map(
						static function ( array $tax ): array {
							$tax['amount'] = abs( (float) $tax['amount'] );
							return $tax;
						},
						$this->get_item_taxes( $refund_ship )
					),
				);
			}

			$pos_destination = (string) $refund->get_meta( '_pos_refund_destination' );
			$pos_mode        = (string) $refund->get_meta( '_pos_refund_mode' );
			$pos_gateway_id  = (string) $refund->get_meta( '_pos_refund_gateway_id' );
			$pos_gateway_title = (string) $refund->get_meta( '_pos_refund_gateway_title' );
			if ( '' === $pos_gateway_title && '' !== $pos_gateway_id && function_exists( 'WC' ) ) {
				// Resolve via the WC()->payment_gateways() method (which returns
				// WC_Payment_Gateways::instance() lazily) instead of the
				// WC()->payment_gateways property — the property can legitimately
				// be null mid-bootstrap or in some test environments.
				$gateways = WC()->payment_gateways()->payment_gateways();
				if ( isset( $gateways[ $pos_gateway_id ] ) && method_exists( $gateways[ $pos_gateway_id ], 'get_title' ) ) {
					$pos_gateway_title = (string) $gateways[ $pos_gateway_id ]->get_title();
				}
			}

			$refunds[] = array(
				'id'               => (int) $refund->get_id(),
				'date'             => $this->format_wc_datetime_in_timezone( $refund->get_date_created(), $date_timezone, $date_locale ),
				'amount'           => abs( (float) $refund->get_amount() ),
				'subtotal'         => method_exists( $refund, 'get_subtotal' ) ? abs( (float) $refund->get_subtotal() ) : 0.0,
				'tax_total'        => method_exists( $refund, 'get_total_tax' ) ? abs( (float) $refund->get_total_tax() ) : 0.0,
				'shipping_total'   => method_exists( $refund, 'get_shipping_total' ) ? abs( (float) $refund->get_shipping_total() ) : 0.0,
				'shipping_tax'     => method_exists( $refund, 'get_shipping_tax' ) ? abs( (float) $refund->get_shipping_tax() ) : 0.0,
				'reason'           => (string) $refund->get_reason(),
				'refunded_by_id'   => $refunded_by_id > 0 ? $refunded_by_id : null,
				'refunded_by_name' => $refunded_by_name,
				'refunded_payment' => method_exists( $refund, 'get_refunded_payment' ) ? (bool) $refund->get_refunded_payment() : false,
				'destination'      => $pos_destination,
				'gateway_id'       => $pos_gateway_id,
				'gateway_title'    => $pos_gateway_title,
				'processing_mode'  => $pos_mode,
				'lines'            => $refund_lines,
				'fees'             => $refund_fees,
				'shipping'         => $refund_shipping,
			);
		}

		return $refunds;
	}


	/**
	 * Ensure customer tax IDs include display labels for logicless templates.
	 *
	 * @param array<int,array<string,mixed>> $tax_ids Customer tax IDs.
	 * @param string                         $locale  Receipt locale.
	 * @return array<int,array<string,mixed>>
	 */
	private static function with_customer_tax_id_labels( array $tax_ids, string $locale = '' ): array {
		return self::with_tax_id_labels( $tax_ids, 'customer', $locale );
	}

	/**
	 * Resolve a display label for each tax-ID entry. Precedence: explicit
	 * `label` → `<scope>_tax_id_label_<type>` i18n key → scope-specific
	 * `_other` fallback.
	 *
	 * @param array<int,array<string,mixed>> $tax_ids Tax IDs.
	 * @param string                         $scope   "store" or "customer".
	 * @param string                         $locale  Receipt locale.
	 * @return array<int,array<string,mixed>>
	 */
	private static function with_tax_id_labels( array $tax_ids, string $scope, string $locale = '' ): array {
		$labels = Receipt_I18n_Labels::get_labels( $locale );
		$prefix = $scope . '_tax_id_label_';

		return array_map(
			static function ( array $tax_id ) use ( $labels, $prefix ): array {
				if ( ! empty( $tax_id['label'] ) ) {
					return $tax_id;
				}

				$type            = isset( $tax_id['type'] ) ? (string) $tax_id['type'] : 'other';
				$key             = $prefix . $type;
				$tax_id['label'] = $labels[ $key ] ?? $labels[ $prefix . 'other' ];

				return $tax_id;
			},
			$tax_ids
		);
	}
}
