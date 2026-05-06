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

use WCPOS\WooCommercePOS\i18n;

/**
 * Receipt_I18n_Labels class.
 */
class Receipt_I18n_Labels {

	/**
	 * Get all standalone receipt labels, translated for the current locale.
	 *
	 * These are referenced in templates as {{i18n.key}}.
	 *
	 * @param string $locale Optional locale to use while resolving labels.
	 *
	 * @return array<string, string> Map of label key => translated string.
	 */
	public static function get_labels( string $locale = '' ): array {
		if ( '' !== $locale && get_locale() !== $locale && function_exists( 'switch_to_locale' ) && switch_to_locale( $locale ) ) {
			try {
				new i18n();

				return self::get_labels();
			} finally {
				restore_previous_locale();
			}
		}

		return array(
			// Order meta.
			'order'                  => /* translators: Standalone label used in printed receipt templates. */ __( 'Order', 'woocommerce-pos' ),
			'date'                   => /* translators: Standalone label used in printed receipt templates. */ __( 'Date', 'woocommerce-pos' ),
			'invoice_no'             => /* translators: Standalone label used in printed receipt templates. */ __( 'Invoice No.', 'woocommerce-pos' ),
			'reference'              => /* translators: Standalone label used in printed receipt templates. */ __( 'Reference', 'woocommerce-pos' ),

			// People.
			'cashier'                => /* translators: Standalone label used in printed receipt templates. */ __( 'Cashier', 'woocommerce-pos' ),
			'customer'               => /* translators: Standalone label used in printed receipt templates. */ __( 'Customer', 'woocommerce-pos' ),
			'customer_tax_id'        => /* translators: Standalone label used in printed receipt templates. */ __( 'Customer Tax ID', 'woocommerce-pos' ),
			'customer_tax_ids'       => /* translators: Standalone label used in printed receipt templates. */ __( 'Customer Tax IDs', 'woocommerce-pos' ),
			'tax_id_eu_vat'          => /* translators: Standalone label used in printed receipt templates. */ __( 'VAT Number', 'woocommerce-pos' ),
			'tax_id_gb_vat'          => /* translators: Standalone label used in printed receipt templates. */ __( 'VAT Number', 'woocommerce-pos' ),
			'tax_id_sa_vat'          => /* translators: Standalone label used in printed receipt templates. */ __( 'VAT Number', 'woocommerce-pos' ),
			'tax_id_au_abn'          => /* translators: Standalone label used in printed receipt templates. */ __( 'ABN', 'woocommerce-pos' ),
			'tax_id_br_cpf'          => /* translators: Standalone label used in printed receipt templates. */ __( 'CPF', 'woocommerce-pos' ),
			'tax_id_br_cnpj'         => /* translators: Standalone label used in printed receipt templates. */ __( 'CNPJ', 'woocommerce-pos' ),
			'tax_id_in_gst'          => /* translators: Standalone label used in printed receipt templates. */ __( 'GSTIN', 'woocommerce-pos' ),
			'tax_id_it_cf'           => /* translators: Standalone label used in printed receipt templates. */ __( 'Codice Fiscale', 'woocommerce-pos' ),
			'tax_id_it_piva'         => /* translators: Standalone label used in printed receipt templates. */ __( 'Partita IVA', 'woocommerce-pos' ),
			'tax_id_es_nif'          => /* translators: Standalone label used in printed receipt templates. */ __( 'NIF', 'woocommerce-pos' ),
			'tax_id_ar_cuit'         => /* translators: Standalone label used in printed receipt templates. */ __( 'CUIT', 'woocommerce-pos' ),
			'tax_id_ca_gst_hst'      => /* translators: Standalone label used in printed receipt templates. */ __( 'GST/HST', 'woocommerce-pos' ),
			'tax_id_us_ein'          => /* translators: Standalone label used in printed receipt templates. */ __( 'EIN', 'woocommerce-pos' ),
			'tax_id_other'           => /* translators: Standalone label used in printed receipt templates. */ __( 'Tax ID', 'woocommerce-pos' ),

			// Store tax IDs.
			'store_tax_ids'                      => /* translators: Standalone label used in printed receipt templates. */ __( 'Tax IDs', 'woocommerce-pos' ),
			// Store tax-ID per-type labels (separate namespace from customer-side
			// tax_id_<type> keys added in PR #850 — same type can need different
			// display copy on the store line vs the customer line per locale).
			'store_tax_id_label_eu_vat'          => /* translators: Standalone label used in printed receipt templates. */ __( 'VAT ID', 'woocommerce-pos' ),
			'store_tax_id_label_gb_vat'          => /* translators: Standalone label used in printed receipt templates. */ __( 'VAT No.', 'woocommerce-pos' ),
			'store_tax_id_label_sa_vat'          => /* translators: Standalone label used in printed receipt templates. */ __( 'VAT No.', 'woocommerce-pos' ),
			'store_tax_id_label_au_abn'          => /* translators: Standalone label used in printed receipt templates. */ __( 'ABN', 'woocommerce-pos' ),
			'store_tax_id_label_br_cpf'          => /* translators: Standalone label used in printed receipt templates. */ __( 'CPF', 'woocommerce-pos' ),
			'store_tax_id_label_br_cnpj'         => /* translators: Standalone label used in printed receipt templates. */ __( 'CNPJ', 'woocommerce-pos' ),
			'store_tax_id_label_in_gst'          => /* translators: Standalone label used in printed receipt templates. */ __( 'GSTIN', 'woocommerce-pos' ),
			'store_tax_id_label_it_cf'           => /* translators: Standalone label used in printed receipt templates. */ __( 'Codice Fiscale', 'woocommerce-pos' ),
			'store_tax_id_label_it_piva'         => /* translators: Standalone label used in printed receipt templates. */ __( 'P.IVA', 'woocommerce-pos' ),
			'store_tax_id_label_es_nif'          => /* translators: Standalone label used in printed receipt templates. */ __( 'NIF', 'woocommerce-pos' ),
			'store_tax_id_label_ar_cuit'         => /* translators: Standalone label used in printed receipt templates. */ __( 'CUIT', 'woocommerce-pos' ),
			'store_tax_id_label_ca_gst_hst'      => /* translators: Standalone label used in printed receipt templates. */ __( 'GST/HST No.', 'woocommerce-pos' ),
			'store_tax_id_label_us_ein'          => /* translators: Standalone label used in printed receipt templates. */ __( 'EIN', 'woocommerce-pos' ),
			'store_tax_id_label_de_ust_id'       => /* translators: Standalone label used in printed receipt templates. */ __( 'USt-IdNr.', 'woocommerce-pos' ),
			'store_tax_id_label_de_steuernummer' => /* translators: Standalone label used in printed receipt templates. */ __( 'Steuernummer', 'woocommerce-pos' ),
			'store_tax_id_label_de_hrb'          => /* translators: Standalone label used in printed receipt templates. */ __( 'HRB', 'woocommerce-pos' ),
			'store_tax_id_label_nl_kvk'          => /* translators: Standalone label used in printed receipt templates. */ __( 'KVK', 'woocommerce-pos' ),
			'store_tax_id_label_fr_siret'        => /* translators: Standalone label used in printed receipt templates. */ __( 'SIRET', 'woocommerce-pos' ),
			'store_tax_id_label_fr_siren'        => /* translators: Standalone label used in printed receipt templates. */ __( 'SIREN', 'woocommerce-pos' ),
			'store_tax_id_label_gb_company'      => /* translators: Standalone label used in printed receipt templates. */ __( 'Company No.', 'woocommerce-pos' ),
			'store_tax_id_label_ch_uid'          => /* translators: Standalone label used in printed receipt templates. */ __( 'UID', 'woocommerce-pos' ),
			'store_tax_id_label_other'           => /* translators: Standalone label used in printed receipt templates. */ __( 'Tax ID', 'woocommerce-pos' ),

			// Customer tax-ID labels — mirror the store-side keys so customer.tax_ids[]
			// renders with the same per-type labels (ABN, VAT ID, NIF, GSTIN, etc.) the
			// receipt header uses for the store. Resolved by Receipt_Data_Builder via
			// the shared tax-id-label helper. Stores in the wild may want to translate
			// these differently for the bill-to block (e.g. "Customer ABN") — splitting
			// the namespace lets them.
			'customer_tax_id_label_eu_vat'          => /* translators: Standalone label used in printed receipt templates. */ __( 'VAT ID', 'woocommerce-pos' ),
			'customer_tax_id_label_gb_vat'          => /* translators: Standalone label used in printed receipt templates. */ __( 'VAT No.', 'woocommerce-pos' ),
			'customer_tax_id_label_sa_vat'          => /* translators: Standalone label used in printed receipt templates. */ __( 'VAT No.', 'woocommerce-pos' ),
			'customer_tax_id_label_au_abn'          => /* translators: Standalone label used in printed receipt templates. */ __( 'ABN', 'woocommerce-pos' ),
			'customer_tax_id_label_br_cpf'          => /* translators: Standalone label used in printed receipt templates. */ __( 'CPF', 'woocommerce-pos' ),
			'customer_tax_id_label_br_cnpj'         => /* translators: Standalone label used in printed receipt templates. */ __( 'CNPJ', 'woocommerce-pos' ),
			'customer_tax_id_label_in_gst'          => /* translators: Standalone label used in printed receipt templates. */ __( 'GSTIN', 'woocommerce-pos' ),
			'customer_tax_id_label_it_cf'           => /* translators: Standalone label used in printed receipt templates. */ __( 'Codice Fiscale', 'woocommerce-pos' ),
			'customer_tax_id_label_it_piva'         => /* translators: Standalone label used in printed receipt templates. */ __( 'P.IVA', 'woocommerce-pos' ),
			'customer_tax_id_label_es_nif'          => /* translators: Standalone label used in printed receipt templates. */ __( 'NIF', 'woocommerce-pos' ),
			'customer_tax_id_label_ar_cuit'         => /* translators: Standalone label used in printed receipt templates. */ __( 'CUIT', 'woocommerce-pos' ),
			'customer_tax_id_label_ca_gst_hst'      => /* translators: Standalone label used in printed receipt templates. */ __( 'GST/HST No.', 'woocommerce-pos' ),
			'customer_tax_id_label_us_ein'          => /* translators: Standalone label used in printed receipt templates. */ __( 'EIN', 'woocommerce-pos' ),
			'customer_tax_id_label_de_ust_id'       => /* translators: Standalone label used in printed receipt templates. */ __( 'USt-IdNr.', 'woocommerce-pos' ),
			'customer_tax_id_label_de_steuernummer' => /* translators: Standalone label used in printed receipt templates. */ __( 'Steuernummer', 'woocommerce-pos' ),
			'customer_tax_id_label_de_hrb'          => /* translators: Standalone label used in printed receipt templates. */ __( 'HRB', 'woocommerce-pos' ),
			'customer_tax_id_label_nl_kvk'          => /* translators: Standalone label used in printed receipt templates. */ __( 'KVK', 'woocommerce-pos' ),
			'customer_tax_id_label_fr_siret'        => /* translators: Standalone label used in printed receipt templates. */ __( 'SIRET', 'woocommerce-pos' ),
			'customer_tax_id_label_fr_siren'        => /* translators: Standalone label used in printed receipt templates. */ __( 'SIREN', 'woocommerce-pos' ),
			'customer_tax_id_label_gb_company'      => /* translators: Standalone label used in printed receipt templates. */ __( 'Company No.', 'woocommerce-pos' ),
			'customer_tax_id_label_ch_uid'          => /* translators: Standalone label used in printed receipt templates. */ __( 'UID', 'woocommerce-pos' ),
			'customer_tax_id_label_other'           => /* translators: Standalone label used in printed receipt templates. */ __( 'Tax ID', 'woocommerce-pos' ),

			'prepared_for'           => /* translators: Standalone label used in printed receipt templates. */ __( 'Prepared For', 'woocommerce-pos' ),
			'processed_by'           => /* translators: Standalone label used in printed receipt templates. */ __( 'Processed by', 'woocommerce-pos' ),

			// Addresses.
			'bill_to'                => /* translators: Standalone label used in printed receipt templates. */ __( 'Bill To', 'woocommerce-pos' ),
			'ship_to'                => /* translators: Standalone label used in printed receipt templates. */ __( 'Ship To', 'woocommerce-pos' ),
			'billing_address'        => /* translators: Standalone label used in printed receipt templates. */ __( 'Billing Address', 'woocommerce-pos' ),

			// Table headers.
			'item'                   => /* translators: Standalone label used in printed receipt templates. */ __( 'Item', 'woocommerce-pos' ),
			'sku'                    => /* translators: Standalone label used in printed receipt templates. */ __( 'SKU', 'woocommerce-pos' ),
			'qty'                    => /* translators: Standalone label used in printed receipt templates. */ __( 'Qty', 'woocommerce-pos' ),
			'unit_price'             => /* translators: Standalone label used in printed receipt templates. */ __( 'Unit Price', 'woocommerce-pos' ),
			'unit_excl'              => /* translators: Standalone label used in printed receipt templates. */ __( 'Unit (excl.)', 'woocommerce-pos' ),
			'total_excl'             => /* translators: Standalone label used in printed receipt templates. */ __( 'Total (excl.)', 'woocommerce-pos' ),
			'discount'               => /* translators: Standalone label used in printed receipt templates. */ __( 'Discount', 'woocommerce-pos' ),
			'packed'                 => /* translators: Standalone label used in printed receipt templates. */ __( 'Packed', 'woocommerce-pos' ),

			// Short receipt table headers.
			'item_short'             => /* translators: Short receipt table header for product/item name. Keep very short, ideally 3–8 characters. */ _x( 'Item', 'short receipt table header', 'woocommerce-pos' ),
			'sku_short'              => /* translators: Short receipt table header for SKU/reference/product code. Keep very short. */ _x( 'SKU', 'short receipt table header', 'woocommerce-pos' ),
			'qty_short'              => /* translators: Short receipt table header for quantity. Keep very short. */ _x( 'Qty', 'short receipt table header', 'woocommerce-pos' ),
			'unit_excl_short'        => /* translators: Short receipt table header for unit price excluding tax/VAT/GST. Keep compact for narrow receipt columns. */ _x( 'Unit excl.', 'short receipt table header', 'woocommerce-pos' ),
			'tax_rate_short'         => /* translators: Short receipt table header for tax/VAT/GST rate percentage, not tax amount. Keep compact for narrow receipt columns. */ _x( 'Tax %', 'short receipt table header: tax rate percentage', 'woocommerce-pos' ),
			'tax_amount_short'       => /* translators: Short receipt table header for tax/VAT/GST amount, not tax rate. Keep compact for narrow receipt columns. */ _x( 'Tax', 'short receipt table header: tax amount', 'woocommerce-pos' ),
			'total_incl_tax_short'   => /* translators: Short receipt table header for line total including tax/VAT/GST. Keep compact for narrow receipt columns. */ _x( 'Total incl.', 'short receipt table header', 'woocommerce-pos' ),
			'taxable_excl_short'     => /* translators: Short receipt table header for taxable amount excluding tax/VAT/GST. Keep compact for narrow receipt columns. */ _x( 'Taxable excl.', 'short receipt table header', 'woocommerce-pos' ),
			'taxable_incl_short'     => /* translators: Short receipt table header for taxable amount including tax/VAT/GST. Keep compact for narrow receipt columns. */ _x( 'Taxable incl.', 'short receipt table header', 'woocommerce-pos' ),

			// Totals.
			'subtotal'               => /* translators: Standalone label used in printed receipt templates. */ __( 'Subtotal', 'woocommerce-pos' ),
			'subtotal_excl_tax'      => /* translators: Standalone label used in printed receipt templates. */ __( 'Subtotal (excl. tax)', 'woocommerce-pos' ),
			'total'                  => /* translators: Standalone label used in printed receipt templates. */ __( 'Total', 'woocommerce-pos' ),
			'total_tax'              => /* translators: Standalone label used in printed receipt templates. */ __( 'Total Tax', 'woocommerce-pos' ),
			'total_incl_tax'         => /* translators: Standalone label used in printed receipt templates. */ __( 'Total (incl. tax)', 'woocommerce-pos' ),
			'grand_total_incl_tax'   => /* translators: Standalone label used in printed receipt templates. */ __( 'Grand Total (incl. tax)', 'woocommerce-pos' ),
			'tax'                    => /* translators: Standalone label used in printed receipt templates. */ __( 'Tax', 'woocommerce-pos' ),
			'paid'                   => /* translators: Standalone label used in printed receipt templates. */ __( 'Paid', 'woocommerce-pos' ),
			'tendered'               => /* translators: Standalone label used in printed receipt templates. */ __( 'Tendered', 'woocommerce-pos' ),
			'change'                 => /* translators: Standalone label used in printed receipt templates. */ __( 'Change', 'woocommerce-pos' ),

			// Tax summary.
			'tax_summary'            => /* translators: Standalone label used in printed receipt templates. */ __( 'Tax Summary', 'woocommerce-pos' ),
			'taxable_excl'           => /* translators: Standalone label used in printed receipt templates. */ __( 'Taxable (excl.)', 'woocommerce-pos' ),
			'tax_amount'             => /* translators: Standalone label used in printed receipt templates. */ __( 'Tax Amount', 'woocommerce-pos' ),
			'taxable_incl'           => /* translators: Standalone label used in printed receipt templates. */ __( 'Taxable (incl.)', 'woocommerce-pos' ),

			// Document titles.
			'invoice'                => /* translators: Standalone label used in printed receipt templates. */ __( 'Invoice', 'woocommerce-pos' ),
			'tax_invoice'            => /* translators: Standalone label used in printed receipt templates. */ __( 'Tax Invoice', 'woocommerce-pos' ),
			'quote'                  => /* translators: Standalone label used in printed receipt templates. */ __( 'Quote', 'woocommerce-pos' ),
			'receipt'                => /* translators: Standalone label used in printed receipt templates. */ __( 'Receipt', 'woocommerce-pos' ),
			'gift_receipt'           => /* translators: Standalone label used in printed receipt templates. */ __( 'Gift Receipt', 'woocommerce-pos' ),
			'credit_note'            => /* translators: Standalone label used in printed receipt templates. */ __( 'Credit Note', 'woocommerce-pos' ),
			'packing_slip'           => /* translators: Standalone label used in printed receipt templates. */ __( 'Packing Slip', 'woocommerce-pos' ),

			// Returned items.
			'returned_items'         => /* translators: Standalone label used in printed receipt templates. */ __( 'Returned Items', 'woocommerce-pos' ),
			'amount'                 => /* translators: Standalone label used in printed receipt templates. */ __( 'Amount', 'woocommerce-pos' ),
			'total_refunded'         => /* translators: Standalone label used in printed receipt templates. */ __( 'Total Refunded', 'woocommerce-pos' ),

			// Section labels.
			'customer_note'          => /* translators: Standalone label used in printed receipt templates. */ __( 'Customer Note', 'woocommerce-pos' ),
			'terms_and_conditions'   => /* translators: Standalone label used in printed receipt templates. */ __( 'Terms & Conditions', 'woocommerce-pos' ),
			'a_message_for_you'      => __( 'A message for you', 'woocommerce-pos' ),

			// Footers.
			'thank_you'              => /* translators: Standalone label used in printed receipt templates. */ __( 'Thank you!', 'woocommerce-pos' ),
			'thank_you_purchase'     => __( 'Thank you for your purchase!', 'woocommerce-pos' ),
			'thank_you_shopping'     => __( 'Thank you for shopping with us!', 'woocommerce-pos' ),
			'thank_you_business'     => __( 'Thank you for your business.', 'woocommerce-pos' ),
			'gift_return_policy'     => __( 'Items may be returned or exchanged within 30 days with this receipt.', 'woocommerce-pos' ),
			'quote_validity'         => __( 'This quote is valid for 30 days from the date of issue. Prices are subject to change after the validity period. This is not a receipt or confirmation of purchase.', 'woocommerce-pos' ),
			'quote_not_receipt'      => __( 'This is a quote, not a receipt', 'woocommerce-pos' ),
			'return_retain_receipt'  => __( 'Please retain this receipt for your records.', 'woocommerce-pos' ),

			// Thermal / kitchen.
			'kitchen'                => /* translators: Standalone label used in printed receipt templates. */ __( 'KITCHEN', 'woocommerce-pos' ),

			// Fiscal.
			'signature'              => /* translators: Standalone label used in printed receipt templates. */ __( 'Signature', 'woocommerce-pos' ),
			'document_type'          => /* translators: Standalone label used in printed receipt templates. */ __( 'Document Type', 'woocommerce-pos' ),
			'copy'                   => /* translators: Standalone label used in printed receipt templates. */ __( 'Copy', 'woocommerce-pos' ),
			'copy_number'            => /* translators: Standalone label used in printed receipt templates. */ __( 'Copy No.', 'woocommerce-pos' ),

			// Order meta + footer (used by detailed-receipt header / order column / footer).
			'status'                 => /* translators: Standalone label used in printed receipt templates. */ __( 'Status', 'woocommerce-pos' ),
			'completed'              => /* translators: Standalone label used in printed receipt templates. */ __( 'Completed', 'woocommerce-pos' ),
			'printed'                => /* translators: Standalone label used in printed receipt templates. */ __( 'Printed', 'woocommerce-pos' ),
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
			'Ref: {{transaction_id}}'          => array(
				/* translators: %s: payment transaction ID */
				'format'    => __( 'Ref: %s', 'woocommerce-pos' ),
				'variables' => array( '{{transaction_id}}' ),
			),
			'Ref: #{{order.number}}'          => array(
				/* translators: %s: order number with # prefix */
				'format'    => __( 'Ref: %s', 'woocommerce-pos' ),
				'variables' => array( '#{{order.number}}' ),
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
