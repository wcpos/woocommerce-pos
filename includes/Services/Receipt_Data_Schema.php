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
	const VERSION = '1.0.0';

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
	);

	/**
	 * Money keys that must be present in totals.
	 */
	const TOTAL_MONEY_KEYS = array(
		'subtotal_incl',
		'subtotal_excl',
		'discount_total_incl',
		'discount_total_excl',
		'tax_total',
		'grand_total_incl',
		'grand_total_excl',
		'paid_total',
		'change_total',
	);

	/**
	 * Field names (terminal key segment) that represent money values.
	 *
	 * Used by the logicless renderer to auto-format currency output.
	 * Matched against the last segment of dot-path keys during substitution.
	 */
	const MONEY_FIELDS = array(
		// Line items.
		'unit_price_incl',
		'unit_price_excl',
		'line_subtotal_incl',
		'line_subtotal_excl',
		'discounts_incl',
		'discounts_excl',
		'line_total_incl',
		'line_total_excl',
		// Fees, shipping, discounts (shared names).
		'total_incl',
		'total_excl',
		// Totals.
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
	 * Get the field tree for the template editor field picker.
	 *
	 * Returns a structured array describing every section and field in the
	 * receipt_data contract. Used by the JS field picker sidebar.
	 *
	 * @return array<string, array{label: string, is_array?: bool, fields: array<string, array{type: string, label: string}>}>
	 */
	public static function get_field_tree(): array {
		return array(
			'meta'        => array(
				'label'  => __( 'Order Meta', 'woocommerce-pos' ),
				'fields' => array(
					'order_number'   => array( 'type' => 'string', 'label' => __( 'Order Number', 'woocommerce-pos' ) ),
					'created_at_gmt' => array( 'type' => 'string', 'label' => __( 'Date/Time', 'woocommerce-pos' ) ),
					'currency'       => array( 'type' => 'string', 'label' => __( 'Currency', 'woocommerce-pos' ) ),
				),
			),
			'store'       => array(
				'label'  => __( 'Store', 'woocommerce-pos' ),
				'fields' => array(
					'name'          => array( 'type' => 'string', 'label' => __( 'Store Name', 'woocommerce-pos' ) ),
					'address_lines' => array( 'type' => 'string[]', 'label' => __( 'Address Lines', 'woocommerce-pos' ) ),
					'tax_id'        => array( 'type' => 'string', 'label' => __( 'Tax ID', 'woocommerce-pos' ) ),
					'phone'         => array( 'type' => 'string', 'label' => __( 'Phone', 'woocommerce-pos' ) ),
					'email'         => array( 'type' => 'string', 'label' => __( 'Email', 'woocommerce-pos' ) ),
				),
			),
			'cashier'     => array(
				'label'  => __( 'Cashier', 'woocommerce-pos' ),
				'fields' => array(
					'name' => array( 'type' => 'string', 'label' => __( 'Cashier Name', 'woocommerce-pos' ) ),
				),
			),
			'customer'    => array(
				'label'  => __( 'Customer', 'woocommerce-pos' ),
				'fields' => array(
					'name'   => array( 'type' => 'string', 'label' => __( 'Customer Name', 'woocommerce-pos' ) ),
					'tax_id' => array( 'type' => 'string', 'label' => __( 'Tax ID', 'woocommerce-pos' ) ),
				),
			),
			'lines'       => array(
				'label'    => __( 'Line Items', 'woocommerce-pos' ),
				'is_array' => true,
				'fields'   => array(
					'sku'               => array( 'type' => 'string', 'label' => __( 'SKU', 'woocommerce-pos' ) ),
					'name'              => array( 'type' => 'string', 'label' => __( 'Product Name', 'woocommerce-pos' ) ),
					'qty'               => array( 'type' => 'number', 'label' => __( 'Quantity', 'woocommerce-pos' ) ),
					'unit_price_incl'   => array( 'type' => 'money', 'label' => __( 'Unit Price (incl tax)', 'woocommerce-pos' ) ),
					'unit_price_excl'   => array( 'type' => 'money', 'label' => __( 'Unit Price (excl tax)', 'woocommerce-pos' ) ),
					'line_subtotal_incl' => array( 'type' => 'money', 'label' => __( 'Subtotal (incl tax)', 'woocommerce-pos' ) ),
					'line_subtotal_excl' => array( 'type' => 'money', 'label' => __( 'Subtotal (excl tax)', 'woocommerce-pos' ) ),
					'discounts_incl'    => array( 'type' => 'money', 'label' => __( 'Discounts (incl tax)', 'woocommerce-pos' ) ),
					'discounts_excl'    => array( 'type' => 'money', 'label' => __( 'Discounts (excl tax)', 'woocommerce-pos' ) ),
					'line_total_incl'   => array( 'type' => 'money', 'label' => __( 'Line Total (incl tax)', 'woocommerce-pos' ) ),
					'line_total_excl'   => array( 'type' => 'money', 'label' => __( 'Line Total (excl tax)', 'woocommerce-pos' ) ),
				),
			),
			'fees'        => array(
				'label'    => __( 'Fees', 'woocommerce-pos' ),
				'is_array' => true,
				'fields'   => array(
					'label'      => array( 'type' => 'string', 'label' => __( 'Fee Label', 'woocommerce-pos' ) ),
					'total_incl' => array( 'type' => 'money', 'label' => __( 'Total (incl tax)', 'woocommerce-pos' ) ),
					'total_excl' => array( 'type' => 'money', 'label' => __( 'Total (excl tax)', 'woocommerce-pos' ) ),
				),
			),
			'shipping'    => array(
				'label'    => __( 'Shipping', 'woocommerce-pos' ),
				'is_array' => true,
				'fields'   => array(
					'label'      => array( 'type' => 'string', 'label' => __( 'Shipping Label', 'woocommerce-pos' ) ),
					'total_incl' => array( 'type' => 'money', 'label' => __( 'Total (incl tax)', 'woocommerce-pos' ) ),
					'total_excl' => array( 'type' => 'money', 'label' => __( 'Total (excl tax)', 'woocommerce-pos' ) ),
				),
			),
			'discounts'   => array(
				'label'    => __( 'Discounts', 'woocommerce-pos' ),
				'is_array' => true,
				'fields'   => array(
					'label'      => array( 'type' => 'string', 'label' => __( 'Discount Label', 'woocommerce-pos' ) ),
					'total_incl' => array( 'type' => 'money', 'label' => __( 'Total (incl tax)', 'woocommerce-pos' ) ),
					'total_excl' => array( 'type' => 'money', 'label' => __( 'Total (excl tax)', 'woocommerce-pos' ) ),
				),
			),
			'totals'      => array(
				'label'  => __( 'Totals', 'woocommerce-pos' ),
				'fields' => array(
					'subtotal_incl'       => array( 'type' => 'money', 'label' => __( 'Subtotal (incl tax)', 'woocommerce-pos' ) ),
					'subtotal_excl'       => array( 'type' => 'money', 'label' => __( 'Subtotal (excl tax)', 'woocommerce-pos' ) ),
					'discount_total_incl' => array( 'type' => 'money', 'label' => __( 'Discount Total (incl tax)', 'woocommerce-pos' ) ),
					'discount_total_excl' => array( 'type' => 'money', 'label' => __( 'Discount Total (excl tax)', 'woocommerce-pos' ) ),
					'tax_total'           => array( 'type' => 'money', 'label' => __( 'Tax Total', 'woocommerce-pos' ) ),
					'grand_total_incl'    => array( 'type' => 'money', 'label' => __( 'Grand Total (incl tax)', 'woocommerce-pos' ) ),
					'grand_total_excl'    => array( 'type' => 'money', 'label' => __( 'Grand Total (excl tax)', 'woocommerce-pos' ) ),
					'paid_total'          => array( 'type' => 'money', 'label' => __( 'Paid Total', 'woocommerce-pos' ) ),
					'change_total'        => array( 'type' => 'money', 'label' => __( 'Change', 'woocommerce-pos' ) ),
				),
			),
			'tax_summary' => array(
				'label'    => __( 'Tax Summary', 'woocommerce-pos' ),
				'is_array' => true,
				'fields'   => array(
					'label'               => array( 'type' => 'string', 'label' => __( 'Tax Label', 'woocommerce-pos' ) ),
					'rate'                => array( 'type' => 'number', 'label' => __( 'Tax Rate (%)', 'woocommerce-pos' ) ),
					'taxable_amount_excl' => array( 'type' => 'money', 'label' => __( 'Taxable Amount (excl)', 'woocommerce-pos' ) ),
					'tax_amount'          => array( 'type' => 'money', 'label' => __( 'Tax Amount', 'woocommerce-pos' ) ),
					'taxable_amount_incl' => array( 'type' => 'money', 'label' => __( 'Taxable Amount (incl)', 'woocommerce-pos' ) ),
				),
			),
			'payments'    => array(
				'label'    => __( 'Payments', 'woocommerce-pos' ),
				'is_array' => true,
				'fields'   => array(
					'method_title' => array( 'type' => 'string', 'label' => __( 'Payment Method', 'woocommerce-pos' ) ),
					'amount'       => array( 'type' => 'money', 'label' => __( 'Amount', 'woocommerce-pos' ) ),
					'tendered'     => array( 'type' => 'money', 'label' => __( 'Tendered', 'woocommerce-pos' ) ),
					'change'       => array( 'type' => 'money', 'label' => __( 'Change', 'woocommerce-pos' ) ),
				),
			),
			'fiscal'      => array(
				'label'  => __( 'Fiscal', 'woocommerce-pos' ),
				'fields' => array(
					'receipt_number' => array( 'type' => 'string', 'label' => __( 'Receipt Number', 'woocommerce-pos' ) ),
					'qr_payload'     => array( 'type' => 'string', 'label' => __( 'QR Payload', 'woocommerce-pos' ) ),
				),
			),
		);
	}
}
