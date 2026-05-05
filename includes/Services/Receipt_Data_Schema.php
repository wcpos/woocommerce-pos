<?php
/**
 * Receipt data schema constants.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * Receipt_Data_Schema class.
 */
class Receipt_Data_Schema {
	/**
	 * Current schema version.
	 *
	 * 1.3.0 — added structured customer.tax_ids[] (TaxId[]) alongside legacy
	 * customer.tax_id scalar, sourced via Tax_Id_Reader fallback.
	 * 1.4.0 — added structured store.tax_ids[] (TaxId[]) on the store block;
	 * store.tax_id remains as a derived scalar.
	 */
	const VERSION = '1.4.0';

	/**
	 * Top-level keys required in a receipt payload.
	 */
	const REQUIRED_KEYS = array(
		'receipt',
		'order',
		'meta',
		'store',
		'cashier',
		'customer',
		'lines',
		'fees',
		'shipping',
		'discounts',
		'totals',
		'tax_summary',
		'payments',
		'refunds',
		'fiscal',
		'presentation_hints',
		'i18n',
	);

	/**
	 * Money keys that must be present in totals.
	 */
	const TOTAL_MONEY_KEYS = array(
		'subtotal',
		'subtotal_incl',
		'subtotal_excl',
		'discount_total',
		'discount_total_incl',
		'discount_total_excl',
		'tax_total',
		'grand_total',
		'grand_total_incl',
		'grand_total_excl',
		'paid_total',
		'change_total',
		'refund_total',
	);

	/**
	 * Money fields where a zero value should remain numeric 0 (Mustache-falsy)
	 * so that section guards like {{#change}}...{{/change}} treat them as empty.
	 *
	 * All other money fields are always formatted to a currency string,
	 * including when their value is zero (e.g. "$0.00").
	 */
	const ZERO_FALSY_MONEY_FIELDS = array(
		'change',
		'tendered',
		'discounts',
		'discounts_incl',
		'discounts_excl',
		'change_total',
		'discount_total',
		'discount_total_incl',
		'discount_total_excl',
		'refund_total',
		'total_refunded',
	);

	/**
	 * Field names (terminal key segment) that represent money values.
	 *
	 * Used by the logicless renderer to auto-format currency output.
	 * Matched against the last segment of dot-path keys during substitution.
	 */
	const MONEY_FIELDS = array(
		// Line items (display).
		'unit_subtotal',
		'unit_price',
		'line_subtotal',
		'discounts',
		'line_total',
		// Line items (explicit incl/excl).
		'unit_subtotal_incl',
		'unit_subtotal_excl',
		'unit_price_incl',
		'unit_price_excl',
		'line_subtotal_incl',
		'line_subtotal_excl',
		'discounts_incl',
		'discounts_excl',
		'line_total_incl',
		'line_total_excl',
		// Fees, shipping, discounts (shared names — only used in these array contexts).
		'total',
		'total_incl',
		'total_excl',
		// Totals (display).
		'subtotal',
		'discount_total',
		'grand_total',
		// Totals (explicit incl/excl).
		'subtotal_incl',
		'subtotal_excl',
		'discount_total_incl',
		'discount_total_excl',
		'tax_total',
		'grand_total_incl',
		'grand_total_excl',
		'paid_total',
		'change_total',
		'refund_total',
		// Per-line refund.
		'total_refunded',
		// Refund-level totals.
		'shipping_total',
		'shipping_tax',
		// Tax summary.
		'taxable_amount_excl',
		'tax_amount',
		'taxable_amount_incl',
		// Payments.
		'amount',
		'tendered',
		'change',
	);

	/**
	 * Format money fields in receipt data using wc_price().
	 *
	 * Recursively walks the data structure and replaces numeric values
	 * whose terminal key matches a known money field with a formatted
	 * currency string (e.g. "$29.99").
	 *
	 * @param array               $data               Receipt data array (or nested sub-array).
	 * @param string              $currency           WooCommerce currency code.
	 * @param array<string,mixed> $presentation_hints Receipt presentation hints.
	 *
	 * @return array Data with money fields formatted as strings.
	 */
	public static function format_money_fields( array $data, string $currency = 'USD', array $presentation_hints = array() ): array {
		static $lookup = null;
		static $zero_falsy = null;
		if ( null === $lookup ) {
			$lookup     = array_flip( self::MONEY_FIELDS );
			$zero_falsy = array_flip( self::ZERO_FALSY_MONEY_FIELDS );
		}

		if ( empty( $presentation_hints ) && isset( $data['presentation_hints'] ) && \is_array( $data['presentation_hints'] ) ) {
			$presentation_hints = $data['presentation_hints'];
		}

		$price_args = self::get_price_format_args( $currency, $presentation_hints );
		$result = array();

		foreach ( $data as $k => $value ) {
			if ( \is_array( $value ) ) {
				$result[ $k ] = self::format_money_fields( $value, $currency, $presentation_hints );
			} elseif ( is_numeric( $value ) && isset( $lookup[ $k ] ) ) {
				// For conditional-display fields (change, tendered, discounts, etc.),
				// keep zero as numeric 0 so Mustache section guards treat them as falsy.
				if ( 0.0 === (float) $value && isset( $zero_falsy[ $k ] ) ) {
					$result[ $k ] = 0;
				} else {
					$result[ $k ] = html_entity_decode(
						wp_strip_all_tags(
							wc_price( (float) $value, $price_args )
						),
						ENT_QUOTES | ENT_SUBSTITUTE,
						'UTF-8'
					);
				}
			} else {
				$result[ $k ] = $value;
			}
		}

		return $result;
	}

	/**
	 * Build wc_price() arguments from presentation hints.
	 *
	 * @param string              $currency           WooCommerce currency code.
	 * @param array<string,mixed> $presentation_hints Receipt presentation hints.
	 *
	 * @return array<string,mixed>
	 */
	private static function get_price_format_args( string $currency, array $presentation_hints ): array {
		$args = array( 'currency' => $currency );

		if ( isset( $presentation_hints['currency_position'] ) ) {
			$args['price_format'] = self::get_price_format_for_position( (string) $presentation_hints['currency_position'] );
		}
		if ( isset( $presentation_hints['price_decimal_separator'] ) ) {
			$args['decimal_separator'] = (string) $presentation_hints['price_decimal_separator'];
		}
		if ( isset( $presentation_hints['price_thousand_separator'] ) ) {
			$args['thousand_separator'] = (string) $presentation_hints['price_thousand_separator'];
		}
		if ( isset( $presentation_hints['price_num_decimals'] ) && is_numeric( $presentation_hints['price_num_decimals'] ) ) {
			$args['decimals'] = (int) $presentation_hints['price_num_decimals'];
		}

		return $args;
	}

	/**
	 * Convert a WooCommerce currency position option to a wc_price format.
	 *
	 * @param string $position WooCommerce currency position.
	 *
	 * @return string wc_price format.
	 */
	private static function get_price_format_for_position( string $position ): string {
		switch ( $position ) {
			case 'right':
				return '%2$s%1$s';
			case 'left_space':
				return '%1$s %2$s';
			case 'right_space':
				return '%2$s %1$s';
			case 'left':
			default:
				return '%1$s%2$s';
		}
	}

	/**
	 * Get the field tree for the template editor field picker.
	 *
	 * Returns a structured array describing template-picker sections and fields
	 * from the receipt_data contract. Used by the JS field picker sidebar.
	 * Internal sections (e.g. presentation_hints) are intentionally excluded.
	 *
	 * @return array<string, array{label: string, is_array?: bool, fields: array<string, array{type: string, label: string}>}>
	 */
	public static function get_field_tree(): array {
		return array(
			'receipt'     => array(
				'label'  => __( 'Receipt', 'woocommerce-pos' ),
				'fields' => array(
					'mode'             => array(
						'type'  => 'string',
						'label' => __( 'Mode', 'woocommerce-pos' ),
					),
				),
			),
			'receipt.printed' => array(
				'label'  => __( 'Receipt Printed', 'woocommerce-pos' ),
				'fields' => self::get_date_field_tree_fields(),
			),
			'order'       => array(
				'label'  => __( 'Order', 'woocommerce-pos' ),
				'fields' => array(
					'id'           => array(
						'type'  => 'number',
						'label' => __( 'Order ID', 'woocommerce-pos' ),
					),
					'number'       => array(
						'type'  => 'string',
						'label' => __( 'Order Number', 'woocommerce-pos' ),
					),
					'currency'     => array(
						'type'  => 'string',
						'label' => __( 'Currency', 'woocommerce-pos' ),
					),
					'customer_note' => array(
						'type'  => 'string',
						'label' => __( 'Customer Note', 'woocommerce-pos' ),
					),
					'wc_status'    => array(
						'type'  => 'string',
						'label' => __( 'WC Status', 'woocommerce-pos' ),
					),
					'created_via'  => array(
						'type'  => 'string',
						'label' => __( 'Created Via', 'woocommerce-pos' ),
					),
				),
			),
			'order.created' => array(
				'label'  => __( 'Order Created', 'woocommerce-pos' ),
				'fields' => self::get_date_field_tree_fields(),
			),
			'order.paid' => array(
				'label'  => __( 'Order Paid', 'woocommerce-pos' ),
				'fields' => self::get_date_field_tree_fields(),
			),
			'order.completed' => array(
				'label'  => __( 'Order Completed', 'woocommerce-pos' ),
				'fields' => self::get_date_field_tree_fields(),
			),
			'store'       => array(
				'label'  => __( 'Store', 'woocommerce-pos' ),
				'fields' => array(
					'name'                    => array(
						'type'  => 'string',
						'label' => __( 'Store Name', 'woocommerce-pos' ),
					),
					'address_lines'           => array(
						'type'  => 'string[]',
						'label' => __( 'Address Lines', 'woocommerce-pos' ),
					),
					'tax_id'                  => array(
						'type'  => 'string',
						'label' => __( 'Tax ID', 'woocommerce-pos' ),
					),
					'phone'                   => array(
						'type'  => 'string',
						'label' => __( 'Phone', 'woocommerce-pos' ),
					),
					'email'                   => array(
						'type'  => 'string',
						'label' => __( 'Email', 'woocommerce-pos' ),
					),
					'logo'                    => array(
						'type'  => 'string',
						'label' => __( 'Logo URL', 'woocommerce-pos' ),
					),
					'opening_hours'           => array(
						'type'     => 'string',
						'nullable' => true,
						'label'    => __( 'Opening Hours', 'woocommerce-pos' ),
					),
					'opening_hours_vertical' => array(
						'type'     => 'string',
						'nullable' => true,
						'label'    => __( 'Opening Hours (Vertical)', 'woocommerce-pos' ),
					),
					'opening_hours_inline'   => array(
						'type'     => 'string',
						'nullable' => true,
						'label'    => __( 'Opening Hours (Inline)', 'woocommerce-pos' ),
					),
					'opening_hours_notes'    => array(
						'type'     => 'string',
						'nullable' => true,
						'label'    => __( 'Opening Hours Notes', 'woocommerce-pos' ),
					),
					'personal_notes'          => array(
						'type'     => 'string',
						'nullable' => true,
						'label'    => __( 'Personal Notes', 'woocommerce-pos' ),
					),
					'policies_and_conditions' => array(
						'type'     => 'string',
						'nullable' => true,
						'label'    => __( 'Policies & Conditions', 'woocommerce-pos' ),
					),
					'footer_imprint'          => array(
						'type'     => 'string',
						'nullable' => true,
						'label'    => __( 'Footer Imprint', 'woocommerce-pos' ),
					),
				),
			),
			'cashier'     => array(
				'label'  => __( 'Cashier', 'woocommerce-pos' ),
				'fields' => array(
					'id'   => array(
						'type'  => 'number',
						'label' => __( 'Cashier ID', 'woocommerce-pos' ),
					),
					'name' => array(
						'type'  => 'string',
						'label' => __( 'Cashier Name', 'woocommerce-pos' ),
					),
				),
			),
			'customer'    => array(
				'label'  => __( 'Customer', 'woocommerce-pos' ),
				'fields' => array(
					'id'               => array(
						'type'  => 'number',
						'label' => __( 'Customer ID', 'woocommerce-pos' ),
					),
					'name'             => array(
						'type'  => 'string',
						'label' => __( 'Customer Name', 'woocommerce-pos' ),
					),
					'billing_address'  => array(
						'type'  => 'object',
						'label' => __( 'Billing Address', 'woocommerce-pos' ),
					),
					'shipping_address' => array(
						'type'  => 'object',
						'label' => __( 'Shipping Address', 'woocommerce-pos' ),
					),
					'tax_id'           => array(
						'type'  => 'string',
						'label' => __( 'Tax ID', 'woocommerce-pos' ),
					),
				),
			),
			'customer.tax_ids' => array(
				'label'    => __( 'Customer Tax IDs', 'woocommerce-pos' ),
				'is_array' => true,
				'fields'   => array(
					'type'    => array(
						'type'  => 'string',
						'label' => __( 'Type', 'woocommerce-pos' ),
					),
					'value'   => array(
						'type'  => 'string',
						'label' => __( 'Value', 'woocommerce-pos' ),
					),
					'country' => array(
						'type'  => 'string',
						'label' => __( 'Country', 'woocommerce-pos' ),
					),
					'label'   => array(
						'type'  => 'string',
						'label' => __( 'Label', 'woocommerce-pos' ),
					),
				),
			),
			'store.tax_ids' => array(
				'label'    => __( 'Store Tax IDs', 'woocommerce-pos' ),
				'is_array' => true,
				'fields'   => array(
					'type'    => array(
						'type'  => 'string',
						'label' => __( 'Type', 'woocommerce-pos' ),
					),
					'value'   => array(
						'type'  => 'string',
						'label' => __( 'Value', 'woocommerce-pos' ),
					),
					'country' => array(
						'type'  => 'string',
						'label' => __( 'Country', 'woocommerce-pos' ),
					),
					'label'   => array(
						'type'  => 'string',
						'label' => __( 'Label', 'woocommerce-pos' ),
					),
				),
			),
			'lines'       => array(
				'label'    => __( 'Line Items', 'woocommerce-pos' ),
				'is_array' => true,
				'fields'   => array(
					'key'                => array(
						'type'  => 'string',
						'label' => __( 'Item Key', 'woocommerce-pos' ),
					),
					'sku'                => array(
						'type'  => 'string',
						'label' => __( 'SKU', 'woocommerce-pos' ),
					),
					'name'               => array(
						'type'  => 'string',
						'label' => __( 'Product Name', 'woocommerce-pos' ),
					),
					'qty'                => array(
						'type'  => 'number',
						'label' => __( 'Quantity', 'woocommerce-pos' ),
					),
					'qty_refunded'       => array(
						'type'  => 'number',
						'label' => __( 'Quantity Refunded', 'woocommerce-pos' ),
					),
					'unit_subtotal'      => array(
						'type'  => 'money',
						'label' => __( 'Unit Subtotal', 'woocommerce-pos' ),
					),
					'unit_subtotal_incl' => array(
						'type'  => 'money',
						'label' => __( 'Unit Subtotal (incl tax)', 'woocommerce-pos' ),
					),
					'unit_subtotal_excl' => array(
						'type'  => 'money',
						'label' => __( 'Unit Subtotal (excl tax)', 'woocommerce-pos' ),
					),
					'unit_price'         => array(
						'type'  => 'money',
						'label' => __( 'Unit Price', 'woocommerce-pos' ),
					),
					'unit_price_incl'    => array(
						'type'  => 'money',
						'label' => __( 'Unit Price (incl tax)', 'woocommerce-pos' ),
					),
					'unit_price_excl'    => array(
						'type'  => 'money',
						'label' => __( 'Unit Price (excl tax)', 'woocommerce-pos' ),
					),
					'line_subtotal'      => array(
						'type'  => 'money',
						'label' => __( 'Subtotal', 'woocommerce-pos' ),
					),
					'line_subtotal_incl' => array(
						'type'  => 'money',
						'label' => __( 'Subtotal (incl tax)', 'woocommerce-pos' ),
					),
					'line_subtotal_excl' => array(
						'type'  => 'money',
						'label' => __( 'Subtotal (excl tax)', 'woocommerce-pos' ),
					),
					'discounts'          => array(
						'type'  => 'money',
						'label' => __( 'Discounts', 'woocommerce-pos' ),
					),
					'discounts_incl'     => array(
						'type'  => 'money',
						'label' => __( 'Discounts (incl tax)', 'woocommerce-pos' ),
					),
					'discounts_excl'     => array(
						'type'  => 'money',
						'label' => __( 'Discounts (excl tax)', 'woocommerce-pos' ),
					),
					'line_total'         => array(
						'type'  => 'money',
						'label' => __( 'Line Total', 'woocommerce-pos' ),
					),
					'line_total_incl'    => array(
						'type'  => 'money',
						'label' => __( 'Line Total (incl tax)', 'woocommerce-pos' ),
					),
					'line_total_excl'    => array(
						'type'  => 'money',
						'label' => __( 'Line Total (excl tax)', 'woocommerce-pos' ),
					),
					'total_refunded'     => array(
						'type'  => 'money',
						'label' => __( 'Total Refunded', 'woocommerce-pos' ),
					),
					'taxes'              => array(
						'type'  => 'array',
						'label' => __( 'Line Taxes', 'woocommerce-pos' ),
					),
					'meta'               => array(
						'type'  => 'array',
						'label' => __( 'Item Meta', 'woocommerce-pos' ),
					),
				),
			),
			'fees'        => array(
				'label'    => __( 'Fees', 'woocommerce-pos' ),
				'is_array' => true,
				'fields'   => array(
					'label'      => array(
						'type'  => 'string',
						'label' => __( 'Fee Label', 'woocommerce-pos' ),
					),
					'total'      => array(
						'type'  => 'money',
						'label' => __( 'Total', 'woocommerce-pos' ),
					),
					'total_incl' => array(
						'type'  => 'money',
						'label' => __( 'Total (incl tax)', 'woocommerce-pos' ),
					),
					'total_excl' => array(
						'type'  => 'money',
						'label' => __( 'Total (excl tax)', 'woocommerce-pos' ),
					),
					'taxes'      => array(
						'type'  => 'array',
						'label' => __( 'Fee Taxes', 'woocommerce-pos' ),
					),
					'meta'       => array(
						'type'  => 'array',
						'label' => __( 'Fee Meta', 'woocommerce-pos' ),
					),
				),
			),
			'shipping'    => array(
				'label'    => __( 'Shipping', 'woocommerce-pos' ),
				'is_array' => true,
				'fields'   => array(
					'label'      => array(
						'type'  => 'string',
						'label' => __( 'Shipping Label', 'woocommerce-pos' ),
					),
					'method_id'  => array(
						'type'  => 'string',
						'label' => __( 'Shipping Method ID', 'woocommerce-pos' ),
					),
					'total'      => array(
						'type'  => 'money',
						'label' => __( 'Total', 'woocommerce-pos' ),
					),
					'total_incl' => array(
						'type'  => 'money',
						'label' => __( 'Total (incl tax)', 'woocommerce-pos' ),
					),
					'total_excl' => array(
						'type'  => 'money',
						'label' => __( 'Total (excl tax)', 'woocommerce-pos' ),
					),
					'taxes'      => array(
						'type'  => 'array',
						'label' => __( 'Shipping Taxes', 'woocommerce-pos' ),
					),
					'meta'       => array(
						'type'  => 'array',
						'label' => __( 'Shipping Meta', 'woocommerce-pos' ),
					),
				),
			),
			'discounts'   => array(
				'label'    => __( 'Discounts', 'woocommerce-pos' ),
				'is_array' => true,
				'fields'   => array(
					'label'      => array(
						'type'  => 'string',
						'label' => __( 'Discount Label', 'woocommerce-pos' ),
					),
					'code'       => array(
						'type'  => 'string',
						'label' => __( 'Coupon Code', 'woocommerce-pos' ),
					),
					'codes'      => array(
						'type'  => 'string',
						'label' => __( 'Coupon Codes', 'woocommerce-pos' ),
					),
					'total'      => array(
						'type'  => 'money',
						'label' => __( 'Total', 'woocommerce-pos' ),
					),
					'total_incl' => array(
						'type'  => 'money',
						'label' => __( 'Total (incl tax)', 'woocommerce-pos' ),
					),
					'total_excl' => array(
						'type'  => 'money',
						'label' => __( 'Total (excl tax)', 'woocommerce-pos' ),
					),
				),
			),
			'totals'      => array(
				'label'  => __( 'Totals', 'woocommerce-pos' ),
				'fields' => array(
					'subtotal'            => array(
						'type'  => 'money',
						'label' => __( 'Subtotal', 'woocommerce-pos' ),
					),
					'subtotal_incl'       => array(
						'type'  => 'money',
						'label' => __( 'Subtotal (incl tax)', 'woocommerce-pos' ),
					),
					'subtotal_excl'       => array(
						'type'  => 'money',
						'label' => __( 'Subtotal (excl tax)', 'woocommerce-pos' ),
					),
					'discount_total'      => array(
						'type'  => 'money',
						'label' => __( 'Discount Total', 'woocommerce-pos' ),
					),
					'discount_total_incl' => array(
						'type'  => 'money',
						'label' => __( 'Discount Total (incl tax)', 'woocommerce-pos' ),
					),
					'discount_total_excl' => array(
						'type'  => 'money',
						'label' => __( 'Discount Total (excl tax)', 'woocommerce-pos' ),
					),
					'tax_total'           => array(
						'type'  => 'money',
						'label' => __( 'Tax Total', 'woocommerce-pos' ),
					),
					'grand_total'         => array(
						'type'  => 'money',
						'label' => __( 'Grand Total', 'woocommerce-pos' ),
					),
					'grand_total_incl'    => array(
						'type'  => 'money',
						'label' => __( 'Grand Total (incl tax)', 'woocommerce-pos' ),
					),
					'grand_total_excl'    => array(
						'type'  => 'money',
						'label' => __( 'Grand Total (excl tax)', 'woocommerce-pos' ),
					),
					'paid_total'          => array(
						'type'  => 'money',
						'label' => __( 'Paid Total', 'woocommerce-pos' ),
					),
					'change_total'        => array(
						'type'  => 'money',
						'label' => __( 'Change', 'woocommerce-pos' ),
					),
					'refund_total'        => array(
						'type'  => 'money',
						'label' => __( 'Refund Total', 'woocommerce-pos' ),
					),
				),
			),
			'tax_summary' => array(
				'label'    => __( 'Tax Summary', 'woocommerce-pos' ),
				'is_array' => true,
				'fields'   => array(
					'code'                => array(
						'type'  => 'string',
						'label' => __( 'Tax Code', 'woocommerce-pos' ),
					),
					'label'               => array(
						'type'  => 'string',
						'label' => __( 'Tax Label', 'woocommerce-pos' ),
					),
					'rate'                => array(
						'type'  => 'number',
						'label' => __( 'Tax Rate (%)', 'woocommerce-pos' ),
					),
					'compound'            => array(
						'type'  => 'boolean',
						'label' => __( 'Compound Tax', 'woocommerce-pos' ),
					),
					'taxable_amount_excl' => array(
						'type'  => 'money',
						'label' => __( 'Taxable Amount (excl)', 'woocommerce-pos' ),
					),
					'tax_amount'          => array(
						'type'  => 'money',
						'label' => __( 'Tax Amount', 'woocommerce-pos' ),
					),
					'taxable_amount_incl' => array(
						'type'  => 'money',
						'label' => __( 'Taxable Amount (incl)', 'woocommerce-pos' ),
					),
				),
			),
			'payments'    => array(
				'label'    => __( 'Payments', 'woocommerce-pos' ),
				'is_array' => true,
				'fields'   => array(
					'method_id'    => array(
						'type'  => 'string',
						'label' => __( 'Method ID', 'woocommerce-pos' ),
					),
					'method_title' => array(
						'type'  => 'string',
						'label' => __( 'Payment Method', 'woocommerce-pos' ),
					),
					'amount'       => array(
						'type'  => 'money',
						'label' => __( 'Amount', 'woocommerce-pos' ),
					),
					'tendered'     => array(
						'type'  => 'money',
						'label' => __( 'Tendered', 'woocommerce-pos' ),
					),
					'change'       => array(
						'type'  => 'money',
						'label' => __( 'Change', 'woocommerce-pos' ),
					),
					'transaction_id' => array(
						'type'  => 'string',
						'label' => __( 'Transaction ID', 'woocommerce-pos' ),
					),
				),
			),
			'refunds'     => array(
				'label'    => __( 'Refunds', 'woocommerce-pos' ),
				'is_array' => true,
				'fields'   => array(
					'id'                => array(
						'type'  => 'number',
						'label' => __( 'Refund ID', 'woocommerce-pos' ),
					),
					'date'              => array(
						'type'  => 'object',
						'label' => __( 'Refund Date', 'woocommerce-pos' ),
					),
					'amount'            => array(
						'type'  => 'money',
						'label' => __( 'Refund Amount', 'woocommerce-pos' ),
					),
					'subtotal'          => array(
						'type'  => 'money',
						'label' => __( 'Refund Subtotal', 'woocommerce-pos' ),
					),
					'tax_total'         => array(
						'type'  => 'money',
						'label' => __( 'Refund Tax Total', 'woocommerce-pos' ),
					),
					'shipping_total'    => array(
						'type'  => 'money',
						'label' => __( 'Refund Shipping Total', 'woocommerce-pos' ),
					),
					'shipping_tax'      => array(
						'type'  => 'money',
						'label' => __( 'Refund Shipping Tax', 'woocommerce-pos' ),
					),
					'reason'            => array(
						'type'  => 'string',
						'label' => __( 'Refund Reason', 'woocommerce-pos' ),
					),
					'refunded_by_id'    => array(
						'type'     => 'number',
						'nullable' => true,
						'label'    => __( 'Refunded By (User ID)', 'woocommerce-pos' ),
					),
					'refunded_by_name'  => array(
						'type'  => 'string',
						'label' => __( 'Refunded By (Name)', 'woocommerce-pos' ),
					),
					'refunded_payment'  => array(
						'type'  => 'boolean',
						'label' => __( 'Refunded Payment', 'woocommerce-pos' ),
					),
					'destination'       => array(
						'type'  => 'string',
						'label' => __( 'Refund Destination', 'woocommerce-pos' ),
					),
					'gateway_id'        => array(
						'type'  => 'string',
						'label' => __( 'Refund Gateway ID', 'woocommerce-pos' ),
					),
					'gateway_title'     => array(
						'type'  => 'string',
						'label' => __( 'Refund Gateway Title', 'woocommerce-pos' ),
					),
					'processing_mode'   => array(
						'type'  => 'string',
						'label' => __( 'Refund Processing Mode', 'woocommerce-pos' ),
					),
					'lines'             => array(
						'type'     => 'array',
						'label'    => __( 'Refund Lines', 'woocommerce-pos' ),
						'is_array' => true,
						'fields'   => array(
							'name'       => array(
								'type'  => 'string',
								'label' => __( 'Product Name', 'woocommerce-pos' ),
							),
							'sku'        => array(
								'type'  => 'string',
								'label' => __( 'SKU', 'woocommerce-pos' ),
							),
							'qty'        => array(
								'type'  => 'number',
								'label' => __( 'Quantity', 'woocommerce-pos' ),
							),
							'total'      => array(
								'type'  => 'money',
								'label' => __( 'Refund Line Total', 'woocommerce-pos' ),
							),
							'total_incl' => array(
								'type'  => 'money',
								'label' => __( 'Refund Line Total (incl tax)', 'woocommerce-pos' ),
							),
							'total_excl' => array(
								'type'  => 'money',
								'label' => __( 'Refund Line Total (excl tax)', 'woocommerce-pos' ),
							),
							'taxes'      => array(
								'type'  => 'array',
								'label' => __( 'Refund Line Taxes', 'woocommerce-pos' ),
							),
						),
					),
					'fees'              => array(
						'type'     => 'array',
						'label'    => __( 'Refund Fees', 'woocommerce-pos' ),
						'is_array' => true,
						'fields'   => array(
							'label'      => array(
								'type'  => 'string',
								'label' => __( 'Fee Label', 'woocommerce-pos' ),
							),
							'total'      => array(
								'type'  => 'money',
								'label' => __( 'Refund Fee Total', 'woocommerce-pos' ),
							),
							'total_incl' => array(
								'type'  => 'money',
								'label' => __( 'Refund Fee Total (incl tax)', 'woocommerce-pos' ),
							),
							'total_excl' => array(
								'type'  => 'money',
								'label' => __( 'Refund Fee Total (excl tax)', 'woocommerce-pos' ),
							),
							'taxes'      => array(
								'type'  => 'array',
								'label' => __( 'Refund Fee Taxes', 'woocommerce-pos' ),
							),
						),
					),
					'shipping'          => array(
						'type'     => 'array',
						'label'    => __( 'Refund Shipping', 'woocommerce-pos' ),
						'is_array' => true,
						'fields'   => array(
							'label'      => array(
								'type'  => 'string',
								'label' => __( 'Shipping Label', 'woocommerce-pos' ),
							),
							'method_id'  => array(
								'type'  => 'string',
								'label' => __( 'Shipping Method ID', 'woocommerce-pos' ),
							),
							'total'      => array(
								'type'  => 'money',
								'label' => __( 'Refund Shipping Total', 'woocommerce-pos' ),
							),
							'total_incl' => array(
								'type'  => 'money',
								'label' => __( 'Refund Shipping Total (incl tax)', 'woocommerce-pos' ),
							),
							'total_excl' => array(
								'type'  => 'money',
								'label' => __( 'Refund Shipping Total (excl tax)', 'woocommerce-pos' ),
							),
							'taxes'      => array(
								'type'  => 'array',
								'label' => __( 'Refund Shipping Taxes', 'woocommerce-pos' ),
							),
						),
					),
				),
			),
			'fiscal'      => array(
				'label'  => __( 'Fiscal', 'woocommerce-pos' ),
				'fields' => array(
					'immutable_id'      => array(
						'type'  => 'string',
						'label' => __( 'Immutable ID', 'woocommerce-pos' ),
					),
					'receipt_number'    => array(
						'type'  => 'string',
						'label' => __( 'Receipt Number', 'woocommerce-pos' ),
					),
					'sequence'          => array(
						'type'  => 'number',
						'label' => __( 'Sequence', 'woocommerce-pos' ),
					),
					'hash'              => array(
						'type'  => 'string',
						'label' => __( 'Hash', 'woocommerce-pos' ),
					),
					'qr_payload'        => array(
						'type'  => 'string',
						'label' => __( 'QR Payload', 'woocommerce-pos' ),
					),
					'tax_agency_code'   => array(
						'type'  => 'string',
						'label' => __( 'Tax Agency Code', 'woocommerce-pos' ),
					),
					'signed_at'         => array(
						'type'  => 'string',
						'label' => __( 'Signed At', 'woocommerce-pos' ),
					),
					'signature_excerpt' => array(
						'type'  => 'string',
						'label' => __( 'Signature Excerpt', 'woocommerce-pos' ),
					),
					'document_label'    => array(
						'type'  => 'string',
						'label' => __( 'Document Label', 'woocommerce-pos' ),
					),
					'is_reprint'        => array(
						'type'  => 'boolean',
						'label' => __( 'Is Reprint', 'woocommerce-pos' ),
					),
					'reprint_count'     => array(
						'type'  => 'number',
						'label' => __( 'Reprint Count', 'woocommerce-pos' ),
					),
					'extra_fields'      => array(
						'type'     => 'array',
						'label'    => __( 'Extra Fields', 'woocommerce-pos' ),
						'is_array' => true,
						'fields'   => array(
							'label' => array(
								'type'  => 'string',
								'label' => __( 'Label', 'woocommerce-pos' ),
							),
							'value' => array(
								'type'  => 'string',
								'label' => __( 'Value', 'woocommerce-pos' ),
							),
						),
					),
				),
			),
			// Note: field_tree is for the template editor picker — include commonly used keys.
			// Full list is in Receipt_I18n_Labels::get_labels().
			'i18n'        => array(
				'label'  => __( 'Labels (i18n)', 'woocommerce-pos' ),
				'fields' => array(
					'order'    => array(
						'type'  => 'string',
						'label' => __( 'Order', 'woocommerce-pos' ),
					),
					'date'     => array(
						'type'  => 'string',
						'label' => __( 'Date', 'woocommerce-pos' ),
					),
					'cashier'  => array(
						'type'  => 'string',
						'label' => __( 'Cashier', 'woocommerce-pos' ),
					),
					'customer' => array(
						'type'  => 'string',
						'label' => __( 'Customer', 'woocommerce-pos' ),
					),
					'subtotal' => array(
						'type'  => 'string',
						'label' => __( 'Subtotal', 'woocommerce-pos' ),
					),
					'total'    => array(
						'type'  => 'string',
						'label' => __( 'Total', 'woocommerce-pos' ),
					),
				),
			),
		);
	}

	/**
	 * Get field metadata for a semantic date section.
	 *
	 * @return array<string, array{type: string, label: string}>
	 */
	private static function get_date_field_tree_fields(): array {
		return array(
			'datetime'       => array(
				'type'  => 'string',
				'label' => __( 'Date & Time', 'woocommerce-pos' ),
			),
			'date'           => array(
				'type'  => 'string',
				'label' => __( 'Date', 'woocommerce-pos' ),
			),
			'time'           => array(
				'type'  => 'string',
				'label' => __( 'Time', 'woocommerce-pos' ),
			),
			'datetime_short' => array(
				'type'  => 'string',
				'label' => __( 'Short Date & Time', 'woocommerce-pos' ),
			),
			'datetime_long'  => array(
				'type'  => 'string',
				'label' => __( 'Long Date & Time', 'woocommerce-pos' ),
			),
			'datetime_full'  => array(
				'type'  => 'string',
				'label' => __( 'Full Date & Time', 'woocommerce-pos' ),
			),
			'date_short'     => array(
				'type'  => 'string',
				'label' => __( 'Short Date', 'woocommerce-pos' ),
			),
			'date_long'      => array(
				'type'  => 'string',
				'label' => __( 'Long Date', 'woocommerce-pos' ),
			),
			'date_full'      => array(
				'type'  => 'string',
				'label' => __( 'Full Date', 'woocommerce-pos' ),
			),
			'date_ymd'       => array(
				'type'  => 'string',
				'label' => __( 'YYYY-MM-DD', 'woocommerce-pos' ),
			),
			'date_dmy'       => array(
				'type'  => 'string',
				'label' => __( 'DD/MM/YYYY', 'woocommerce-pos' ),
			),
			'date_mdy'       => array(
				'type'  => 'string',
				'label' => __( 'MM/DD/YYYY', 'woocommerce-pos' ),
			),
			'weekday_short'  => array(
				'type'  => 'string',
				'label' => __( 'Weekday Short', 'woocommerce-pos' ),
			),
			'weekday_long'   => array(
				'type'  => 'string',
				'label' => __( 'Weekday Long', 'woocommerce-pos' ),
			),
			'day'            => array(
				'type'  => 'string',
				'label' => __( 'Day', 'woocommerce-pos' ),
			),
			'month'          => array(
				'type'  => 'string',
				'label' => __( 'Month Number', 'woocommerce-pos' ),
			),
			'month_short'    => array(
				'type'  => 'string',
				'label' => __( 'Month Short', 'woocommerce-pos' ),
			),
			'month_long'     => array(
				'type'  => 'string',
				'label' => __( 'Month Long', 'woocommerce-pos' ),
			),
			'year'           => array(
				'type'  => 'string',
				'label' => __( 'Year', 'woocommerce-pos' ),
			),
		);
	}

	/**
	 * Get the JSON Schema for canonical receipt_data payloads.
	 *
	 * PHP remains the source of truth in this repository. This export is used by
	 * generated TypeScript artifacts and downstream renderer/studio checks.
	 *
	 * @return array<string, mixed> JSON-Schema-compatible receipt data schema.
	 */
	public static function get_json_schema(): array {
		$schema = array(
			'$schema'              => 'https://json-schema.org/draft/2020-12/schema',
			'$id'                  => 'https://wcpos.com/schemas/receipt-data.schema.json',
			'title'                => 'ReceiptData',
			'type'                 => 'object',
			'additionalProperties' => true,
			'required'             => self::REQUIRED_KEYS,
			'properties'           => array(),
		);

		foreach ( self::REQUIRED_KEYS as $key ) {
			$schema['properties'][ $key ] = self::get_default_section_schema( $key );
		}

		foreach ( self::get_field_tree() as $path => $section ) {
			self::merge_field_tree_section_schema( $schema, $path, $section );
		}

		$schema['properties']['meta']['properties']['schema_version'] = array(
			'type'        => 'string',
			'const'       => self::VERSION,
			'description' => 'Receipt data schema version.',
		);
		$schema['properties']['meta']['properties']['wc_status'] = array(
			'type'        => 'string',
			'description' => 'WooCommerce order status (e.g. processing, completed, refunded).',
		);
		$schema['properties']['meta']['properties']['created_via'] = array(
			'type'        => 'string',
			'description' => 'How the order was created (e.g. woocommerce-pos, checkout, admin, rest-api).',
		);
		unset( $schema['properties']['presentation_hints']['properties'] );

		return $schema;
	}

	/**
	 * Get a default schema for a top-level receipt section.
	 *
	 * @param string $key Top-level receipt data key.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_default_section_schema( string $key ): array {
		if ( \in_array( $key, array( 'lines', 'fees', 'shipping', 'discounts', 'tax_summary', 'payments', 'refunds' ), true ) ) {
			return array(
				'type'                 => 'array',
				'items'                => array(
					'type'                 => 'object',
					'additionalProperties' => true,
					'properties'           => array(),
				),
				'additionalProperties' => true,
			);
		}

		return array(
			'type'                 => 'object',
			'additionalProperties' => true,
			'properties'           => array(),
		);
	}

	/**
	 * Merge a template editor field-tree section into the JSON Schema.
	 *
	 * @param array<string, mixed> $schema  Full schema, passed by reference.
	 * @param string               $path    Dot path for the field-tree section.
	 * @param array<string, mixed> $section Field-tree section metadata.
	 *
	 * @return void
	 */
	private static function merge_field_tree_section_schema( array &$schema, string $path, array $section ): void {
		$segments = explode( '.', $path );
		$top_key  = array_shift( $segments );

		if ( ! \is_string( $top_key ) || '' === $top_key ) {
			return;
		}

		if ( ! isset( $schema['properties'][ $top_key ] ) ) {
			$schema['properties'][ $top_key ] = self::get_default_section_schema( $top_key );
		}

		$target                    =& $schema['properties'][ $top_key ];
		$target_is_collection_item = false;

		if ( 'array' === ( $target['type'] ?? null ) ) {
			$target                    =& $target['items'];
			$target_is_collection_item = true;
		}

		foreach ( $segments as $segment ) {
			if ( ! isset( $target['properties'][ $segment ] ) ) {
				$target['properties'][ $segment ] = array(
					'type'                 => 'object',
					'additionalProperties' => true,
					'properties'           => array(),
				);
			}
			$target =& $target['properties'][ $segment ];
		}

		$target['description'] = isset( $section['label'] ) ? (string) $section['label'] : $path;

		if ( ! empty( $section['is_array'] ) && ! $target_is_collection_item ) {
			$target['type']  = 'array';
			$target['items'] = $target['items'] ?? array(
				'type'                 => 'object',
				'additionalProperties' => true,
				'properties'           => array(),
			);

			// Strip object-only keywords that may have been seeded by the
			// segment-walk above; they are invalid on an array schema.
			unset( $target['additionalProperties'], $target['properties'] );
		} else {
			$target['type']                 = 'object';
			$target['additionalProperties'] = true;
			$target['properties']           = $target['properties'] ?? array();
		}

		$field_target =& $target;
		if ( 'array' === ( $target['type'] ?? null ) ) {
			$field_target =& $target['items'];
		}

		foreach ( $section['fields'] ?? array() as $field_name => $field ) {
			$field_target['properties'][ $field_name ] = self::field_metadata_to_json_schema( $field );
		}
	}

	/**
	 * Convert one field-tree field to a JSON Schema property.
	 *
	 * @param array<string,mixed> $field Field metadata.
	 *
	 * @return array<string,mixed>
	 */
	private static function field_metadata_to_json_schema( array $field ): array {
		$type        = isset( $field['type'] ) ? (string) $field['type'] : 'string';
		$schema_type = self::field_type_to_json_type( $type );

		// Honor a nullable flag from the field-tree metadata: producers may
		// legitimately emit null for a field whose JSON Schema type would
		// otherwise reject it (e.g. refunds[].refunded_by_id when no user).
		if ( ! empty( $field['nullable'] ) ) {
			if ( \is_array( $schema_type ) ) {
				if ( ! \in_array( 'null', $schema_type, true ) ) {
					$schema_type[] = 'null';
				}
			} else {
				$schema_type = array( $schema_type, 'null' );
			}
		}

		$schema = array(
			'type'        => $schema_type,
			'description' => isset( $field['label'] ) ? (string) $field['label'] : '',
		);

		if ( \in_array( $type, array( 'string[]', 'array' ), true ) ) {
			$schema['items'] = array( 'type' => 'string[]' === $type ? 'string' : array( 'string', 'number', 'boolean', 'object', 'array', 'null' ) );
		}

		if ( ! empty( $field['is_array'] ) && ! empty( $field['fields'] ) ) {
			$schema['type']  = 'array';
			$schema['items'] = array(
				'type'                 => 'object',
				'additionalProperties' => true,
				'properties'           => array(),
			);

			foreach ( $field['fields'] as $child_name => $child_field ) {
				$schema['items']['properties'][ $child_name ] = self::field_metadata_to_json_schema( $child_field );
			}
		}

		return $schema;
	}

	/**
	 * Map template field-tree scalar types to JSON Schema types.
	 *
	 * @param string $type Field-tree type.
	 *
	 * @return string|array<int,string>
	 */
	private static function field_type_to_json_type( string $type ) {
		switch ( $type ) {
			case 'number':
				return 'number';
			case 'boolean':
				return 'boolean';
			case 'money':
				return array( 'number', 'string' );
			case 'object':
				return 'object';
			case 'array':
			case 'string[]':
				return 'array';
			case 'string':
			default:
				return 'string';
		}
	}

	/**
	 * Get mock receipt data for template preview.
	 *
	 * Returns a representative receipt payload with realistic values
	 * for use in the template editor preview and tests.
	 *
	 * @return array Mock receipt data.
	 */
	public static function get_mock_receipt_data(): array {
		$created   = Receipt_Date_Formatter::from_timestamp( strtotime( '2024-01-15 10:30:00 UTC' ) );
		$paid      = Receipt_Date_Formatter::from_timestamp( strtotime( '2024-01-15 10:35:00 UTC' ) );
		$completed = Receipt_Date_Formatter::from_timestamp( strtotime( '2024-01-15 10:42:00 UTC' ) );
		$printed   = Receipt_Date_Formatter::from_timestamp( strtotime( '2024-01-15 10:45:00 UTC' ) );

		return array(
			'receipt' => array(
				'mode'    => 'sale',
				'printed' => $printed,
			),
			'order'   => array(
				'id'            => 1001,
				'number'        => '1001',
				'currency'      => 'USD',
				'customer_note' => '',
				'wc_status'     => 'completed',
				'created_via'   => 'woocommerce-pos',
				'created'       => $created,
				'paid'          => $paid,
				'completed'     => $completed,
			),
			'meta'    => array(
				'schema_version'   => self::VERSION,
				'order_id'         => 1001,
				'order_number'     => '#1001',
				'created_at_gmt'   => '2024-01-15T10:30:00Z',
				'created_at_local' => '2024-01-15 10:30:00',
				'currency'         => 'USD',
				'customer_note'    => '',
				'wc_status'        => 'completed',
				'created_via'      => 'woocommerce-pos',
			),
			'store'   => array(
				'name'                    => 'My Store',
				'address_lines'           => array( '123 Main St', 'Anytown, CA 90210' ),
				'tax_id'                  => '12-3456789',
				'tax_ids'                 => array(
					array(
						'type'    => 'us_ein',
						'value'   => '12-3456789',
						'country' => 'US',
						'label'   => 'EIN',
					),
				),
				'phone'                   => '+1 (555) 123-4567',
				'email'                   => 'hello@mystore.com',
				'logo'                    => 'https://example.com/logo.png',
				'opening_hours'           => "Mon\u{2013}Fri 9:00 AM \u{2013} 5:00 PM\nSat 10:00 AM \u{2013} 4:00 PM\nSun Closed",
				'opening_hours_vertical'  => "Mon 9:00 AM \u{2013} 5:00 PM\nTue 9:00 AM \u{2013} 5:00 PM\nWed 9:00 AM \u{2013} 5:00 PM\nThu 9:00 AM \u{2013} 5:00 PM\nFri 9:00 AM \u{2013} 5:00 PM\nSat 10:00 AM \u{2013} 4:00 PM\nSun Closed",
				'opening_hours_inline'    => "Mon\u{2013}Fri 9:00 AM \u{2013} 5:00 PM, Sat 10:00 AM \u{2013} 4:00 PM, Sun Closed",
				'opening_hours_notes'     => 'Closed on public holidays',
				'personal_notes'          => '',
				'policies_and_conditions' => '',
				'footer_imprint'          => '',
			),
			'cashier' => array(
				'id'   => 1,
				'name' => 'Admin',
			),
		);
	}
}
