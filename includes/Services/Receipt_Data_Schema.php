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
	 */
	const VERSION = '1.1.0';

	/**
	 * Top-level keys required in a receipt payload.
	 */
	const REQUIRED_KEYS = array(
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
	 * @param array  $data     Receipt data array (or nested sub-array).
	 * @param string $currency WooCommerce currency code.
	 *
	 * @return array Data with money fields formatted as strings.
	 */
	public static function format_money_fields( array $data, string $currency = 'USD' ): array {
		static $lookup = null;
		static $zero_falsy = null;
		if ( null === $lookup ) {
			$lookup     = array_flip( self::MONEY_FIELDS );
			$zero_falsy = array_flip( self::ZERO_FALSY_MONEY_FIELDS );
		}

		$result = array();

		foreach ( $data as $k => $value ) {
			if ( \is_array( $value ) ) {
				$result[ $k ] = self::format_money_fields( $value, $currency );
			} elseif ( is_numeric( $value ) && isset( $lookup[ $k ] ) ) {
				// For conditional-display fields (change, tendered, discounts, etc.),
				// keep zero as numeric 0 so Mustache section guards treat them as falsy.
				if ( 0.0 === (float) $value && isset( $zero_falsy[ $k ] ) ) {
					$result[ $k ] = 0;
				} else {
					$result[ $k ] = html_entity_decode(
						wp_strip_all_tags(
							wc_price( (float) $value, array( 'currency' => $currency ) )
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
			'meta'        => array(
				'label'  => __( 'Order Meta', 'woocommerce-pos' ),
				'fields' => array(
					'schema_version'   => array(
						'type'  => 'string',
						'label' => __( 'Schema Version', 'woocommerce-pos' ),
					),
					'mode'             => array(
						'type'  => 'string',
						'label' => __( 'Mode', 'woocommerce-pos' ),
					),
					'order_id'         => array(
						'type'  => 'number',
						'label' => __( 'Order ID', 'woocommerce-pos' ),
					),
					'order_number'     => array(
						'type'  => 'string',
						'label' => __( 'Order Number', 'woocommerce-pos' ),
					),
					'created_at_gmt'   => array(
						'type'  => 'string',
						'label' => __( 'Date/Time (GMT)', 'woocommerce-pos' ),
					),
					'created_at_local' => array(
						'type'  => 'string',
						'label' => __( 'Date/Time (Local)', 'woocommerce-pos' ),
					),
					'currency'         => array(
						'type'  => 'string',
						'label' => __( 'Currency', 'woocommerce-pos' ),
					),
					'customer_note'    => array(
						'type'  => 'string',
						'label' => __( 'Customer Note', 'woocommerce-pos' ),
					),
				),
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
						'type'  => 'string',
						'label' => __( 'Opening Hours', 'woocommerce-pos' ),
					),
					'personal_notes'          => array(
						'type'  => 'string',
						'label' => __( 'Personal Notes', 'woocommerce-pos' ),
					),
					'policies_and_conditions' => array(
						'type'  => 'string',
						'label' => __( 'Policies & Conditions', 'woocommerce-pos' ),
					),
					'footer_imprint'          => array(
						'type'  => 'string',
						'label' => __( 'Footer Imprint', 'woocommerce-pos' ),
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
			'discounts'   => array(
				'label'    => __( 'Discounts', 'woocommerce-pos' ),
				'is_array' => true,
				'fields'   => array(
					'label'      => array(
						'type'  => 'string',
						'label' => __( 'Discount Label', 'woocommerce-pos' ),
					),
					'codes'      => array(
						'type'     => 'array',
						'label'    => __( 'Coupon Codes', 'woocommerce-pos' ),
						'is_array' => true,
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
					'reference'    => array(
						'type'  => 'string',
						'label' => __( 'Reference', 'woocommerce-pos' ),
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
}
