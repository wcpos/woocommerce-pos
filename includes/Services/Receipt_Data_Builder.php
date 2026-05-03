<?php
/**
 * Receipt data builder service.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

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
	 * @param string            $mode      Receipt mode.
	 * @param object|null       $pos_store POS store object. Falls back to order meta or default.
	 *
	 * @return array
	 */
	public function build( WC_Abstract_Order $order, string $mode = 'live', $pos_store = null ): array {
		$display_incl = 'incl' === get_option( 'woocommerce_tax_display_cart', 'excl' );

		$meta = array(
			'schema_version'   => Receipt_Data_Schema::VERSION,
			'created_at_gmt'   => current_time( 'mysql', true ),
			'created_at_local' => current_time( 'mysql', false ),
			'order_id'         => $order->get_id(),
			'order_number'     => $order->get_order_number(),
			'currency'         => $order->get_currency(),
			'customer_note'    => $order->get_customer_note(),
			'wc_status'        => method_exists( $order, 'get_status' ) ? (string) $order->get_status() : '',
			'created_via'      => method_exists( $order, 'get_created_via' ) ? (string) $order->get_created_via() : '',
		);

		$receipt = array(
			'mode'    => $mode,
			'printed' => Receipt_Date_Formatter::from_timestamp( time(), wp_timezone() ),
		);

		$order_data = array(
			'id'            => $order->get_id(),
			'number'        => (string) $order->get_order_number(),
			'currency'      => (string) $order->get_currency(),
			'customer_note' => (string) $order->get_customer_note(),
			'wc_status'     => method_exists( $order, 'get_status' ) ? (string) $order->get_status() : '',
			'created_via'   => method_exists( $order, 'get_created_via' ) ? (string) $order->get_created_via() : '',
			'created'       => Receipt_Date_Formatter::from_wc_datetime( $order->get_date_created() ),
			'paid'          => Receipt_Date_Formatter::from_wc_datetime( $order->get_date_paid() ),
			'completed'     => Receipt_Date_Formatter::from_wc_datetime( $order->get_date_completed() ),
		);

		if ( null === $pos_store ) {
			$order_store_id = (int) $order->get_meta( '_pos_store' );
			$pos_store      = $order_store_id > 0 ? wcpos_get_store( $order_store_id ) : wcpos_get_store();
		}
		if ( ! \is_object( $pos_store ) ) {
			$pos_store = wcpos_get_store();
		}
		if ( ! \is_object( $pos_store ) ) {
			$pos_store = new Store();
		}
		$store_name            = (string) $this->get_store_value( $pos_store, 'get_name', '' );
		$store_address         = (string) $this->get_store_value( $pos_store, 'get_store_address', '' );
		$store_address_2       = (string) $this->get_store_value( $pos_store, 'get_store_address_2', '' );
		$store_city            = (string) $this->get_store_value( $pos_store, 'get_store_city', '' );
		$store_postcode        = (string) $this->get_store_value( $pos_store, 'get_store_postcode', '' );
		$store_country         = (string) $this->get_store_value( $pos_store, 'get_store_country', '' );
		$store_state           = (string) $this->get_store_value( $pos_store, 'get_store_state', '' );
		$store_phone           = (string) $this->get_store_value( $pos_store, 'get_phone', '' );
		$store_email           = (string) $this->get_store_value( $pos_store, 'get_email', '' );

		$store = array(
			'name'          => '' !== $store_name ? $store_name : get_bloginfo( 'name' ),
			'address_lines' => array_values(
				array_filter(
					array(
						$store_address,
						$store_address_2,
						trim( $store_city . ' ' . $store_postcode ),
						$this->format_country_state( $store_country, $store_state ),
					)
				)
			),
			'tax_id'        => get_option( 'woocommerce_store_tax_number', '' ),
			'phone'         => $store_phone,
			'email'         => $store_email,
		);

		$opening_hours_raw       = $this->get_store_value( $pos_store, 'get_opening_hours', array() );
		$personal_notes          = (string) $this->get_store_value( $pos_store, 'get_personal_notes', '' );
		$policies_and_conditions = (string) $this->get_store_value( $pos_store, 'get_policies_and_conditions', '' );
		$footer_imprint          = (string) $this->get_store_value( $pos_store, 'get_footer_imprint', '' );

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
		$opening_hours_notes              = (string) $this->get_store_value( $pos_store, 'get_opening_hours_notes', '' );
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
			$customer_name = __( 'Guest', 'woocommerce-pos' );
		}

		$tax_ids = ( new Tax_Id_Reader() )->read_for_order( $order );

		$customer = array(
			'id'               => $customer_id ? $customer_id : null,
			'name'             => $customer_name,
			'billing_address'  => $order->get_address( 'billing' ),
			'shipping_address' => $order->get_address( 'shipping' ),
			// Backward-compat scalar — first formatted tax-ID value, or empty.
			'tax_id'           => $this->format_primary_tax_id( $tax_ids ),
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
			$dp                 = wc_get_price_decimals();
			$unit_price_incl    = $qty > 0 ? round( $line_total_incl / $qty, $dp ) : 0.0;
			$unit_price_excl    = $qty > 0 ? round( $line_total_excl / $qty, $dp ) : 0.0;
			$unit_subtotal_incl = $qty > 0 ? round( $line_subtotal_incl / $qty, $dp ) : 0.0;
			$unit_subtotal_excl = $qty > 0 ? round( $line_subtotal_excl / $qty, $dp ) : 0.0;

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
				'label'      => $coupon_item->get_code(),
				'code'       => $coupon_item->get_code(),
				'codes'      => $coupon_item->get_code(),
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

		$tax_total = (float) $order->get_total_tax();
		$total     = (float) $order->get_total();

		$grand_total_excl = $total - $tax_total;
		$refund_total     = method_exists( $order, 'get_total_refunded' )
			? abs( (float) $order->get_total_refunded() )
			: 0.0;

		$totals = array(
			'subtotal'             => $display_incl ? $subtotal_incl : $subtotal_excl,
			'subtotal_incl'        => $subtotal_incl,
			'subtotal_excl'        => $subtotal_excl,
			'discount_total'       => $display_incl ? $discount_total_incl : $discount_total_excl,
			'discount_total_incl'  => $discount_total_incl,
			'discount_total_excl'  => $discount_total_excl,
			'tax_total'            => $tax_total,
			'grand_total'          => $display_incl ? $total : $grand_total_excl,
			'grand_total_incl'     => $total,
			'grand_total_excl'     => $grand_total_excl,
			'paid_total'           => $total,
			'change_total'         => (float) $order->get_meta( '_pos_cash_change' ),
			'refund_total'         => $refund_total,
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
			'receipt'            => $receipt,
			'order'              => $order_data,
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
			'refunds'            => $this->get_refunds( $order, $display_incl ),
			'fiscal'             => $fiscal,
			'presentation_hints' => $presentation_hints,
			'i18n'               => Receipt_I18n_Labels::get_labels(),
		);
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
				'compound'            => method_exists( $tax_item, 'is_compound' ) ? (bool) $tax_item->is_compound() : false,
				'taxable_amount_excl' => $taxable_excl,
				'tax_amount'          => $tax_amount,
				'taxable_amount_incl' => $taxable_incl,
			);
		}

		return $summary;
	}

	/**
	 * Format country and state codes into display names.
	 *
	 * Converts codes like "US" / "AL" to "Alabama, United States (US)".
	 *
	 * @param string $country Country code.
	 * @param string $state   State code.
	 *
	 * @return string
	 */
	private function format_country_state( string $country, string $state ): string {
		if ( '' === $country ) {
			return '';
		}

		$country_name = WC()->countries->get_countries()[ $country ] ?? $country;

		if ( '' !== $state ) {
			$states     = WC()->countries->get_states( $country );
			$state_name = $states[ $state ] ?? $state;

			return $state_name . ', ' . $country_name;
		}

		return $country_name;
	}

	/**
	 * Safely read a value from a POS store object.
	 *
	 * @param mixed  $pos_store Store object.
	 * @param string $getter    Getter method name.
	 * @param mixed  $fallback  Fallback value.
	 *
	 * @return mixed
	 */
	private function get_store_value( $pos_store, string $getter, $fallback ) {
		if ( ! \is_object( $pos_store ) || ! method_exists( $pos_store, $getter ) ) {
			return $fallback;
		}

		return $pos_store->{$getter}();
	}

	/**
	 * Format the primary tax ID for the legacy `customer.tax_id` scalar.
	 *
	 * For VAT types we render `<COUNTRY><VALUE>` (e.g. `DE123456789`) when the
	 * value isn't already country-prefixed; for everything else we use the raw
	 * value. Returns an empty string when the tax-ID list is empty.
	 *
	 * @param array<int,array<string,mixed>> $tax_ids Tax ID list.
	 *
	 * @return string
	 */
	private function format_primary_tax_id( array $tax_ids ): string {
		if ( empty( $tax_ids ) ) {
			return '';
		}

		$primary = $tax_ids[0];
		$value   = isset( $primary['value'] ) ? (string) $primary['value'] : '';
		$country = isset( $primary['country'] ) ? (string) $primary['country'] : '';
		$type    = isset( $primary['type'] ) ? (string) $primary['type'] : '';

		$is_vat = \in_array(
			$type,
			array( Tax_Id_Types::TYPE_EU_VAT, Tax_Id_Types::TYPE_GB_VAT ),
			true
		);

		if ( $is_vat && '' !== $country && ! preg_match( '/^[A-Z]{2}/', $value ) ) {
			return $country . $value;
		}

		return $value;
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
			$pairs[] = array(
				'key'   => wp_strip_all_tags( $meta_entry->display_key ),
				'value' => wp_strip_all_tags( $meta_entry->display_value ),
			);
		}

		return $pairs;
	}

	/**
	 * Build refunds[] block from $order->get_refunds().
	 *
	 * @param WC_Abstract_Order $order        Order object.
	 * @param bool              $display_incl Whether totals should be tax-inclusive (matches shop tax display).
	 *
	 * @return array
	 */
	private function get_refunds( WC_Abstract_Order $order, bool $display_incl ): array {
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
				$registry = WC()->payment_gateways();
				$gateways = $registry ? $registry->payment_gateways() : array();
				if ( isset( $gateways[ $pos_gateway_id ] ) && method_exists( $gateways[ $pos_gateway_id ], 'get_title' ) ) {
					$pos_gateway_title = (string) $gateways[ $pos_gateway_id ]->get_title();
				}
			}

			$refunds[] = array(
				'id'               => (int) $refund->get_id(),
				'date'             => Receipt_Date_Formatter::from_wc_datetime( $refund->get_date_created() ),
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
}
