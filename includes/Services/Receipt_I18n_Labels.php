<?php
/**
 * Receipt i18n label dictionary.
 *
 * Provides translated label strings for receipt templates.
 * Used by Receipt_Data_Builder and Preview_Receipt_Builder to populate
 * the i18n section of receipt data, and by the gallery install step
 * to translate interpolated strings at copy-time.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * Receipt_I18n_Labels class.
 */
class Receipt_I18n_Labels {

	/**
	 * Get all standalone receipt labels, translated for the current locale.
	 *
	 * These are referenced in templates as {{i18n.key}}.
	 *
	 * @return array<string, string> Map of label key => translated string.
	 */
	public static function get_labels(): array {
		return array(
			// Order meta.
			'order'                  => __( 'Order', 'woocommerce-pos' ),
			'date'                   => __( 'Date', 'woocommerce-pos' ),
			'invoice_no'             => __( 'Invoice No.', 'woocommerce-pos' ),
			'reference'              => __( 'Reference', 'woocommerce-pos' ),

			// People.
			'cashier'                => __( 'Cashier', 'woocommerce-pos' ),
			'customer'               => __( 'Customer', 'woocommerce-pos' ),
			'customer_tax_id'        => __( 'Customer Tax ID', 'woocommerce-pos' ),
			'prepared_for'           => __( 'Prepared For', 'woocommerce-pos' ),
			'processed_by'           => __( 'Processed by', 'woocommerce-pos' ),

			// Addresses.
			'bill_to'                => __( 'Bill To', 'woocommerce-pos' ),
			'ship_to'                => __( 'Ship To', 'woocommerce-pos' ),
			'billing_address'        => __( 'Billing Address', 'woocommerce-pos' ),

			// Table headers.
			'item'                   => __( 'Item', 'woocommerce-pos' ),
			'sku'                    => __( 'SKU', 'woocommerce-pos' ),
			'qty'                    => __( 'Qty', 'woocommerce-pos' ),
			'unit_price'             => __( 'Unit Price', 'woocommerce-pos' ),
			'unit_excl'              => __( 'Unit (excl.)', 'woocommerce-pos' ),
			'total_excl'             => __( 'Total (excl.)', 'woocommerce-pos' ),
			'discount'               => __( 'Discount', 'woocommerce-pos' ),
			'packed'                 => __( 'Packed', 'woocommerce-pos' ),

			// Totals.
			'subtotal'               => __( 'Subtotal', 'woocommerce-pos' ),
			'subtotal_excl_tax'      => __( 'Subtotal (excl. tax)', 'woocommerce-pos' ),
			'total'                  => __( 'Total', 'woocommerce-pos' ),
			'total_tax'              => __( 'Total Tax', 'woocommerce-pos' ),
			'grand_total_incl_tax'   => __( 'Grand Total (incl. tax)', 'woocommerce-pos' ),
			'tax'                    => __( 'Tax', 'woocommerce-pos' ),
			'paid'                   => __( 'Paid', 'woocommerce-pos' ),
			'tendered'               => __( 'Tendered', 'woocommerce-pos' ),
			'change'                 => __( 'Change', 'woocommerce-pos' ),

			// Tax summary.
			'tax_summary'            => __( 'Tax Summary', 'woocommerce-pos' ),
			'taxable_excl'           => __( 'Taxable (excl.)', 'woocommerce-pos' ),
			'tax_amount'             => __( 'Tax Amount', 'woocommerce-pos' ),
			'taxable_incl'           => __( 'Taxable (incl.)', 'woocommerce-pos' ),

			// Document titles.
			'invoice'                => __( 'Invoice', 'woocommerce-pos' ),
			'tax_invoice'            => __( 'Tax Invoice', 'woocommerce-pos' ),
			'quote'                  => __( 'Quote', 'woocommerce-pos' ),
			'receipt'                => __( 'Receipt', 'woocommerce-pos' ),
			'gift_receipt'           => __( 'Gift Receipt', 'woocommerce-pos' ),
			'credit_note'            => __( 'Credit Note', 'woocommerce-pos' ),
			'packing_slip'           => __( 'Packing Slip', 'woocommerce-pos' ),

			// Returned items.
			'returned_items'         => __( 'Returned Items', 'woocommerce-pos' ),
			'amount'                 => __( 'Amount', 'woocommerce-pos' ),
			'total_refunded'         => __( 'Total Refunded', 'woocommerce-pos' ),

			// Section labels.
			'customer_note'          => __( 'Customer Note', 'woocommerce-pos' ),
			'terms_and_conditions'   => __( 'Terms & Conditions', 'woocommerce-pos' ),
			'a_message_for_you'      => __( 'A message for you', 'woocommerce-pos' ),

			// Footers.
			'thank_you'              => __( 'Thank you!', 'woocommerce-pos' ),
			'thank_you_purchase'     => __( 'Thank you for your purchase!', 'woocommerce-pos' ),
			'thank_you_shopping'     => __( 'Thank you for shopping with us!', 'woocommerce-pos' ),
			'thank_you_business'     => __( 'Thank you for your business.', 'woocommerce-pos' ),
			'tax_invoice_retain'     => __( 'This is a tax invoice. Please retain for your records.', 'woocommerce-pos' ),
			'gift_return_policy'     => __( 'Items may be returned or exchanged within 30 days with this receipt.', 'woocommerce-pos' ),
			'quote_validity'         => __( 'This quote is valid for 30 days from the date of issue. Prices are subject to change after the validity period. This is not a receipt or confirmation of purchase.', 'woocommerce-pos' ),
			'quote_not_receipt'      => __( 'This is a quote, not a receipt', 'woocommerce-pos' ),
			'return_retain_receipt'  => __( 'Please retain this receipt for your records.', 'woocommerce-pos' ),

			// Thermal / kitchen.
			'kitchen'                => __( 'KITCHEN', 'woocommerce-pos' ),

			// Fiscal.
			'signature'              => __( 'Signature', 'woocommerce-pos' ),
			'document_type'          => __( 'Document Type', 'woocommerce-pos' ),
			'copy'                   => __( 'Copy', 'woocommerce-pos' ),
			'copy_number'            => __( 'Copy No.', 'woocommerce-pos' ),
		);
	}

	/**
	 * Get interpolated phrases for copy-time translation.
	 *
	 * Each entry maps the English phrase (as it appears in gallery templates,
	 * with Mustache variables intact) to a translatable format string.
	 * At copy-time, the format string is translated via __() and %s placeholders
	 * are replaced with the original Mustache variable(s).
	 *
	 * Structure: 'English phrase with {{var}}' => array(
	 *     'format'       => 'English phrase with %s',
	 *     'variables'    => array( '{{var}}' ),
	 * )
	 *
	 * @return array<string, array{format: string, variables: string[]}> Interpolated phrase map.
	 */
	public static function get_interpolated_phrases(): array {
		return array(
			'Tax ID: {{store.tax_id}}'         => array(
				/* translators: %s: store tax identification number */
				'format'    => __( 'Tax ID: %s', 'woocommerce-pos' ),
				'variables' => array( '{{store.tax_id}}' ),
			),
			'Phone: {{store.phone}}'           => array(
				/* translators: %s: store phone number */
				'format'    => __( 'Phone: %s', 'woocommerce-pos' ),
				'variables' => array( '{{store.phone}}' ),
			),
			'Email: {{store.email}}'           => array(
				/* translators: %s: store email address */
				'format'    => __( 'Email: %s', 'woocommerce-pos' ),
				'variables' => array( '{{store.email}}' ),
			),
			'Paid via {{method_title}}'        => array(
				/* translators: %s: payment method name */
				'format'    => __( 'Paid via %s', 'woocommerce-pos' ),
				'variables' => array( '{{method_title}}' ),
			),
			'Refunded via {{method_title}}'    => array(
				/* translators: %s: payment method name */
				'format'    => __( 'Refunded via %s', 'woocommerce-pos' ),
				'variables' => array( '{{method_title}}' ),
			),
			'Served by {{cashier.name}}'       => array(
				/* translators: %s: cashier name */
				'format'    => __( 'Served by %s', 'woocommerce-pos' ),
				'variables' => array( '{{cashier.name}}' ),
			),
			'Customer: {{customer.name}}'      => array(
				/* translators: %s: customer name */
				'format'    => __( 'Customer: %s', 'woocommerce-pos' ),
				'variables' => array( '{{customer.name}}' ),
			),
			'Thank you, {{customer.name}}!'    => array(
				/* translators: %s: customer name */
				'format'    => __( 'Thank you, %s!', 'woocommerce-pos' ),
				'variables' => array( '{{customer.name}}' ),
			),
			'Ref: {{reference}}'               => array(
				/* translators: %s: payment reference */
				'format'    => __( 'Ref: %s', 'woocommerce-pos' ),
				'variables' => array( '{{reference}}' ),
			),
			'Ref: #{{meta.order_number}}'      => array(
				/* translators: %s: order number with # prefix */
				'format'    => __( 'Ref: %s', 'woocommerce-pos' ),
				'variables' => array( '#{{meta.order_number}}' ),
			),
			'Ref: #{{order.number}}'          => array(
				/* translators: %s: order number with # prefix */
				'format'    => __( 'Ref: %s', 'woocommerce-pos' ),
				'variables' => array( '#{{order.number}}' ),
			),
			'Order #{{meta.order_number}}'     => array(
				/* translators: %s: order number with # prefix */
				'format'    => __( 'Order %s', 'woocommerce-pos' ),
				'variables' => array( '#{{meta.order_number}}' ),
			),
			'Order #{{order.number}}'         => array(
				/* translators: %s: order number with # prefix */
				'format'    => __( 'Order %s', 'woocommerce-pos' ),
				'variables' => array( '#{{order.number}}' ),
			),
			'SKU: {{sku}}'                     => array(
				/* translators: %s: product SKU */
				'format'    => __( 'SKU: %s', 'woocommerce-pos' ),
				'variables' => array( '{{sku}}' ),
			),
			'Subtotal: {{line_subtotal_incl}}' => array(
				/* translators: %s: line subtotal amount */
				'format'    => __( 'Subtotal: %s', 'woocommerce-pos' ),
				'variables' => array( '{{line_subtotal_incl}}' ),
			),
			'Discount: -{{discounts_incl}}'    => array(
				/* translators: %s: discount amount with minus sign */
				'format'    => __( 'Discount: %s', 'woocommerce-pos' ),
				'variables' => array( '-{{discounts_incl}}' ),
			),
			'Taxable: {{taxable_amount_excl}}' => array(
				/* translators: %s: taxable amount */
				'format'    => __( 'Taxable: %s', 'woocommerce-pos' ),
				'variables' => array( '{{taxable_amount_excl}}' ),
			),
		);
	}

	/**
	 * Translate interpolated phrases in template content.
	 *
	 * Replaces English phrases containing Mustache variables with
	 * their translated equivalents, preserving the Mustache variables.
	 *
	 * @param string $content Template content with English phrases.
	 *
	 * @return string Content with interpolated phrases translated.
	 */
	public static function translate_interpolated_phrases( string $content ): string {
		$phrases = self::get_interpolated_phrases();

		// Sort by key length descending to prevent substring replacement issues.
		uksort(
			$phrases,
			function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);

		foreach ( $phrases as $english => $config ) {
			if ( false === strpos( $content, $english ) ) {
				continue;
			}

			// Build the translated string with Mustache variables re-inserted.
			// Handle both %s and %1$s numbered placeholders that translators may use.
			$translated = $config['format'];
			foreach ( $config['variables'] as $index => $variable ) {
				$numbered   = '%' . ( $index + 1 ) . '$s';
				$translated = str_replace( $numbered, $variable, $translated );
			}
			// Replace any remaining positional %s (single-variable case).
			if ( 1 === count( $config['variables'] ) ) {
				$translated = str_replace( '%s', $config['variables'][0], $translated );
			}

			$content = str_replace( $english, $translated, $content );
		}

		return $content;
	}
}
