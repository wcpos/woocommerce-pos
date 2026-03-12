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
		if ( null === $lookup ) {
			$lookup = array_flip( self::MONEY_FIELDS );
		}

		$result = array();

		foreach ( $data as $k => $value ) {
			if ( \is_array( $value ) ) {
				$result[ $k ] = self::format_money_fields( $value, $currency );
			} elseif ( is_numeric( $value ) && isset( $lookup[ $k ] ) ) {
				$result[ $k ] = html_entity_decode(
					wp_strip_all_tags(
						wc_price( (float) $value, array( 'currency' => $currency ) )
					),
					ENT_QUOTES | ENT_SUBSTITUTE,
					'UTF-8'
				);
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
					'schema_version' => array(
						'type'  => 'string',
						'label' => __( 'Schema Version', 'woocommerce-pos' ),
					),
					'mode'           => array(
						'type'  => 'string',
						'label' => __( 'Mode', 'woocommerce-pos' ),
					),
					'order_id'       => array(
						'type'  => 'number',
						'label' => __( 'Order ID', 'woocommerce-pos' ),
					),
					'order_number'   => array(
						'type'  => 'string',
						'label' => __( 'Order Number', 'woocommerce-pos' ),
					),
					'created_at_gmt' => array(
						'type'  => 'string',
						'label' => __( 'Date/Time', 'woocommerce-pos' ),
					),
					'currency'       => array(
						'type'  => 'string',
						'label' => __( 'Currency', 'woocommerce-pos' ),
					),
					'customer_note'  => array(
						'type'  => 'string',
						'label' => __( 'Customer Note', 'woocommerce-pos' ),
					),
				),
			),
			'store'       => array(
				'label'  => __( 'Store', 'woocommerce-pos' ),
				'fields' => array(
					'name'          => array(
						'type'  => 'string',
						'label' => __( 'Store Name', 'woocommerce-pos' ),
					),
					'address_lines' => array(
						'type'  => 'string[]',
						'label' => __( 'Address Lines', 'woocommerce-pos' ),
					),
					'tax_id'        => array(
						'type'  => 'string',
						'label' => __( 'Tax ID', 'woocommerce-pos' ),
					),
					'phone'         => array(
						'type'  => 'string',
						'label' => __( 'Phone', 'woocommerce-pos' ),
					),
					'email'         => array(
						'type'  => 'string',
						'label' => __( 'Email', 'woocommerce-pos' ),
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
					'unit_price_incl'    => array(
						'type'  => 'money',
						'label' => __( 'Unit Price (incl tax)', 'woocommerce-pos' ),
					),
					'unit_price_excl'    => array(
						'type'  => 'money',
						'label' => __( 'Unit Price (excl tax)', 'woocommerce-pos' ),
					),
					'line_subtotal_incl' => array(
						'type'  => 'money',
						'label' => __( 'Subtotal (incl tax)', 'woocommerce-pos' ),
					),
					'line_subtotal_excl' => array(
						'type'  => 'money',
						'label' => __( 'Subtotal (excl tax)', 'woocommerce-pos' ),
					),
					'discounts_incl'     => array(
						'type'  => 'money',
						'label' => __( 'Discounts (incl tax)', 'woocommerce-pos' ),
					),
					'discounts_excl'     => array(
						'type'  => 'money',
						'label' => __( 'Discounts (excl tax)', 'woocommerce-pos' ),
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
					'subtotal_incl'       => array(
						'type'  => 'money',
						'label' => __( 'Subtotal (incl tax)', 'woocommerce-pos' ),
					),
					'subtotal_excl'       => array(
						'type'  => 'money',
						'label' => __( 'Subtotal (excl tax)', 'woocommerce-pos' ),
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
					'immutable_id'    => array(
						'type'  => 'string',
						'label' => __( 'Immutable ID', 'woocommerce-pos' ),
					),
					'receipt_number'  => array(
						'type'  => 'string',
						'label' => __( 'Receipt Number', 'woocommerce-pos' ),
					),
					'sequence'        => array(
						'type'  => 'number',
						'label' => __( 'Sequence', 'woocommerce-pos' ),
					),
					'hash'            => array(
						'type'  => 'string',
						'label' => __( 'Hash', 'woocommerce-pos' ),
					),
					'qr_payload'      => array(
						'type'  => 'string',
						'label' => __( 'QR Payload', 'woocommerce-pos' ),
					),
					'tax_agency_code' => array(
						'type'  => 'string',
						'label' => __( 'Tax Agency Code', 'woocommerce-pos' ),
					),
					'signed_at'       => array(
						'type'  => 'string',
						'label' => __( 'Signed At', 'woocommerce-pos' ),
					),
				),
			),
		);
	}

	/**
	 * Get mock receipt data for the template editor preview.
	 *
	 * Used as fallback when no POS order exists. Provides realistic sample
	 * data that exercises all template sections (lines, fees, tax, etc.).
	 *
	 * @return array
	 */
	public static function get_mock_receipt_data(): array {
		$store_name = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'name' ) : '';

		return array(
			'meta'               => array(
				'schema_version' => self::VERSION,
				'mode'           => 'preview',
				'created_at_gmt' => gmdate( 'Y-m-d H:i:s' ),
				'order_id'       => 1234,
				'order_number'   => '1234',
				'currency'       => function_exists( 'get_option' ) ? get_option( 'woocommerce_currency', 'USD' ) : 'USD',
				'customer_note'  => 'Please gift wrap this order. Thank you!',
			),
			'store'              => array(
				'name'          => $store_name ? $store_name : 'Sample Store',
				'address_lines' => array( '123 Main Street', 'Anytown, ST 12345' ),
				'tax_id'        => '',
				'phone'         => '(555) 123-4567',
				'email'         => function_exists( 'get_option' ) ? get_option( 'admin_email', 'store@example.com' ) : 'store@example.com',
			),
			'cashier'            => array(
				'id'   => 1,
				'name' => 'Jane Smith',
			),
			'customer'           => array(
				'id'               => 42,
				'name'             => 'John Doe',
				'billing_address'  => array(
					'first_name' => 'John',
					'last_name'  => 'Doe',
					'company'    => '',
					'address_1'  => '123 Main Street',
					'address_2'  => '',
					'city'       => 'Anytown',
					'state'      => 'CA',
					'postcode'   => '90210',
					'country'    => 'US',
					'email'      => 'john.doe@example.com',
					'phone'      => '(555) 123-4567',
				),
				'shipping_address' => array(
					'first_name' => 'John',
					'last_name'  => 'Doe',
					'company'    => '',
					'address_1'  => '123 Main Street',
					'address_2'  => '',
					'city'       => 'Anytown',
					'state'      => 'CA',
					'postcode'   => '90210',
					'country'    => 'US',
				),
				'tax_id'           => '',
			),
			'lines'              => array(
				array(
					'key'                => '1',
					'sku'                => 'WIDGET-001',
					'name'               => 'Premium Widget',
					'qty'                => 2.0,
					'unit_price_incl'    => 29.99,
					'unit_price_excl'    => 27.26,
					'line_subtotal_incl' => 59.98,
					'line_subtotal_excl' => 54.53,
					'discounts_incl'     => 0.0,
					'discounts_excl'     => 0.0,
					'line_total_incl'    => 59.98,
					'line_total_excl'    => 54.53,
					'taxes'              => array(),
				),
				array(
					'key'                => '2',
					'sku'                => 'GADGET-002',
					'name'               => 'Standard Gadget',
					'qty'                => 1.0,
					'unit_price_incl'    => 15.50,
					'unit_price_excl'    => 14.09,
					'line_subtotal_incl' => 15.50,
					'line_subtotal_excl' => 14.09,
					'discounts_incl'     => 0.0,
					'discounts_excl'     => 0.0,
					'line_total_incl'    => 15.50,
					'line_total_excl'    => 14.09,
					'taxes'              => array(),
				),
			),
			'fees'               => array(
				array(
					'label'      => 'Gift Wrapping',
					'total_incl' => 2.75,
					'total_excl' => 2.50,
				),
			),
			'shipping'           => array(
				array(
					'label'      => 'Flat Rate Shipping',
					'total_incl' => 11.00,
					'total_excl' => 10.00,
				),
			),
			'discounts'          => array(
				array(
					'label'      => 'Summer Sale (10%)',
					'total_incl' => 7.55,
					'total_excl' => 6.86,
				),
			),
			'totals'             => array(
				'subtotal_incl'       => 75.48,
				'subtotal_excl'       => 68.62,
				'discount_total_incl' => 7.55,
				'discount_total_excl' => 6.86,
				'tax_total'           => 7.42,
				'grand_total_incl'    => 81.68,
				'grand_total_excl'    => 74.26,
				'paid_total'          => 81.68,
				'change_total'        => 18.32,
			),
			'tax_summary'        => array(
				array(
					'code'                => '1',
					'rate'                => 10.0,
					'label'               => 'Tax',
					'taxable_amount_excl' => 74.26,
					'tax_amount'          => 7.42,
					'taxable_amount_incl' => 81.68,
				),
			),
			'payments'           => array(
				array(
					'method_id'    => 'pos_cash',
					'method_title' => 'Cash',
					'amount'       => 81.68,
					'reference'    => '',
					'tendered'     => 100.00,
					'change'       => 18.32,
				),
			),
			'fiscal'             => array(
				'immutable_id'    => '',
				'receipt_number'  => '',
				'sequence'        => 0,
				'hash'            => '',
				'qr_payload'      => '',
				'tax_agency_code' => '',
				'signed_at'       => '',
			),
			'presentation_hints' => array(
				'display_tax'             => 'itemized',
				'prices_entered_with_tax' => false,
				'rounding_mode'           => 'no',
				'locale'                  => function_exists( 'get_locale' ) ? get_locale() : 'en_US',
			),
		);
	}
}
