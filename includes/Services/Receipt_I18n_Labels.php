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
			'order'                  => /* translators: Receipt label for the order number or order reference. */ __( 'Order', 'woocommerce-pos' ),
			'date'                   => /* translators: Receipt label for the sale/order date. */ __( 'Date', 'woocommerce-pos' ),
			'invoice_no'             => /* translators: Receipt label for an invoice number. */ __( 'Invoice No.', 'woocommerce-pos' ),
			'reference'              => /* translators: Receipt label for a reference code, transaction reference, or document reference. */ __( 'Reference', 'woocommerce-pos' ),

			// People.
			'cashier'                => /* translators: Receipt label for the POS cashier/operator who served the customer. */ __( 'Cashier', 'woocommerce-pos' ),
			'customer'               => /* translators: Receipt label for the customer name or customer details. */ __( 'Customer', 'woocommerce-pos' ),
			'customer_tax_id'        => /* translators: Receipt label for one customer tax/VAT/GST identification number. */ __( 'Customer Tax ID', 'woocommerce-pos' ),
			'customer_tax_ids'       => /* translators: Receipt section label for multiple customer tax/VAT/GST identification numbers. */ __( 'Customer Tax IDs', 'woocommerce-pos' ),
			'tax_id_eu_vat'          => /* translators: Receipt label for a store tax/VAT/GST/business identification number. */ __( 'VAT Number', 'woocommerce-pos' ),
			'tax_id_gb_vat'          => /* translators: Receipt label for a store tax/VAT/GST/business identification number. */ __( 'VAT Number', 'woocommerce-pos' ),
			'tax_id_sa_vat'          => /* translators: Receipt label for a store tax/VAT/GST/business identification number. */ __( 'VAT Number', 'woocommerce-pos' ),
			'tax_id_au_abn'          => /* translators: Receipt label for a store tax/VAT/GST/business identification number. */ __( 'ABN', 'woocommerce-pos' ),
			'tax_id_br_cpf'          => /* translators: Receipt label for a store tax/VAT/GST/business identification number. */ __( 'CPF', 'woocommerce-pos' ),
			'tax_id_br_cnpj'         => /* translators: Receipt label for a store tax/VAT/GST/business identification number. */ __( 'CNPJ', 'woocommerce-pos' ),
			'tax_id_in_gst'          => /* translators: Receipt label for a store tax/VAT/GST/business identification number. */ __( 'GSTIN', 'woocommerce-pos' ),
			'tax_id_it_cf'           => /* translators: Receipt label for a store tax/VAT/GST/business identification number. */ __( 'Codice Fiscale', 'woocommerce-pos' ),
			'tax_id_it_piva'         => /* translators: Receipt label for a store tax/VAT/GST/business identification number. */ __( 'Partita IVA', 'woocommerce-pos' ),
			'tax_id_es_nif'          => /* translators: Receipt label for a store tax/VAT/GST/business identification number. */ __( 'NIF', 'woocommerce-pos' ),
			'tax_id_ar_cuit'         => /* translators: Receipt label for a store tax/VAT/GST/business identification number. */ __( 'CUIT', 'woocommerce-pos' ),
			'tax_id_ca_gst_hst'      => /* translators: Receipt label for a store tax/VAT/GST/business identification number. */ __( 'GST/HST', 'woocommerce-pos' ),
			'tax_id_us_ein'          => /* translators: Receipt label for a store tax/VAT/GST/business identification number. */ __( 'EIN', 'woocommerce-pos' ),
			'tax_id_other'           => /* translators: Receipt label for a store tax/VAT/GST/business identification number. */ __( 'Tax ID', 'woocommerce-pos' ),

			// Store tax IDs.
			'store_tax_ids'                      => /* translators: Receipt section label for store tax/VAT/GST/business identification numbers. */ __( 'Tax IDs', 'woocommerce-pos' ),
			// Store tax-ID per-type labels (separate namespace from customer-side
			// tax_id_<type> keys added in PR #850 — same type can need different
			// display copy on the store line vs the customer line per locale).
			'store_tax_id_label_eu_vat'          => /* translators: Receipt label for a store tax/VAT/GST/business identification number of this type. */ __( 'VAT ID', 'woocommerce-pos' ),
			'store_tax_id_label_gb_vat'          => /* translators: Receipt label for a store tax/VAT/GST/business identification number of this type. */ __( 'VAT No.', 'woocommerce-pos' ),
			'store_tax_id_label_sa_vat'          => /* translators: Receipt label for a store tax/VAT/GST/business identification number of this type. */ __( 'VAT No.', 'woocommerce-pos' ),
			'store_tax_id_label_au_abn'          => /* translators: Receipt label for a store tax/VAT/GST/business identification number of this type. */ __( 'ABN', 'woocommerce-pos' ),
			'store_tax_id_label_br_cpf'          => /* translators: Receipt label for a store tax/VAT/GST/business identification number of this type. */ __( 'CPF', 'woocommerce-pos' ),
			'store_tax_id_label_br_cnpj'         => /* translators: Receipt label for a store tax/VAT/GST/business identification number of this type. */ __( 'CNPJ', 'woocommerce-pos' ),
			'store_tax_id_label_in_gst'          => /* translators: Receipt label for a store tax/VAT/GST/business identification number of this type. */ __( 'GSTIN', 'woocommerce-pos' ),
			'store_tax_id_label_it_cf'           => /* translators: Receipt label for a store tax/VAT/GST/business identification number of this type. */ __( 'Codice Fiscale', 'woocommerce-pos' ),
			'store_tax_id_label_it_piva'         => /* translators: Receipt label for a store tax/VAT/GST/business identification number of this type. */ __( 'P.IVA', 'woocommerce-pos' ),
			'store_tax_id_label_es_nif'          => /* translators: Receipt label for a store tax/VAT/GST/business identification number of this type. */ __( 'NIF', 'woocommerce-pos' ),
			'store_tax_id_label_ar_cuit'         => /* translators: Receipt label for a store tax/VAT/GST/business identification number of this type. */ __( 'CUIT', 'woocommerce-pos' ),
			'store_tax_id_label_ca_gst_hst'      => /* translators: Receipt label for a store tax/VAT/GST/business identification number of this type. */ __( 'GST/HST No.', 'woocommerce-pos' ),
			'store_tax_id_label_us_ein'          => /* translators: Receipt label for a store tax/VAT/GST/business identification number of this type. */ __( 'EIN', 'woocommerce-pos' ),
			'store_tax_id_label_de_ust_id'       => /* translators: Receipt label for a store tax/VAT/GST/business identification number of this type. */ __( 'USt-IdNr.', 'woocommerce-pos' ),
			'store_tax_id_label_de_steuernummer' => /* translators: Receipt label for a store tax/VAT/GST/business identification number of this type. */ __( 'Steuernummer', 'woocommerce-pos' ),
			'store_tax_id_label_de_hrb'          => /* translators: Receipt label for a store tax/VAT/GST/business identification number of this type. */ __( 'HRB', 'woocommerce-pos' ),
			'store_tax_id_label_nl_kvk'          => /* translators: Receipt label for a store tax/VAT/GST/business identification number of this type. */ __( 'KVK', 'woocommerce-pos' ),
			'store_tax_id_label_fr_siret'        => /* translators: Receipt label for a store tax/VAT/GST/business identification number of this type. */ __( 'SIRET', 'woocommerce-pos' ),
			'store_tax_id_label_fr_siren'        => /* translators: Receipt label for a store tax/VAT/GST/business identification number of this type. */ __( 'SIREN', 'woocommerce-pos' ),
			'store_tax_id_label_gb_company'      => /* translators: Receipt label for a store tax/VAT/GST/business identification number of this type. */ __( 'Company No.', 'woocommerce-pos' ),
			'store_tax_id_label_ch_uid'          => /* translators: Receipt label for a store tax/VAT/GST/business identification number of this type. */ __( 'UID', 'woocommerce-pos' ),
			'store_tax_id_label_other'           => /* translators: Receipt label for a store tax/VAT/GST/business identification number of this type. */ __( 'Tax ID', 'woocommerce-pos' ),

			// Customer tax-ID labels — mirror the store-side keys so customer.tax_ids[]
			// renders with the same per-type labels (ABN, VAT ID, NIF, GSTIN, etc.) the
			// receipt header uses for the store. Resolved by Receipt_Data_Builder via
			// the shared tax-id-label helper. Stores in the wild may want to translate
			// these differently for the bill-to block (e.g. "Customer ABN") — splitting
			// the namespace lets them.
			'customer_tax_id_label_eu_vat'          => /* translators: Receipt label for a customer tax/VAT/GST/business identification number of this type. */ __( 'VAT ID', 'woocommerce-pos' ),
			'customer_tax_id_label_gb_vat'          => /* translators: Receipt label for a customer tax/VAT/GST/business identification number of this type. */ __( 'VAT No.', 'woocommerce-pos' ),
			'customer_tax_id_label_sa_vat'          => /* translators: Receipt label for a customer tax/VAT/GST/business identification number of this type. */ __( 'VAT No.', 'woocommerce-pos' ),
			'customer_tax_id_label_au_abn'          => /* translators: Receipt label for a customer tax/VAT/GST/business identification number of this type. */ __( 'ABN', 'woocommerce-pos' ),
			'customer_tax_id_label_br_cpf'          => /* translators: Receipt label for a customer tax/VAT/GST/business identification number of this type. */ __( 'CPF', 'woocommerce-pos' ),
			'customer_tax_id_label_br_cnpj'         => /* translators: Receipt label for a customer tax/VAT/GST/business identification number of this type. */ __( 'CNPJ', 'woocommerce-pos' ),
			'customer_tax_id_label_in_gst'          => /* translators: Receipt label for a customer tax/VAT/GST/business identification number of this type. */ __( 'GSTIN', 'woocommerce-pos' ),
			'customer_tax_id_label_it_cf'           => /* translators: Receipt label for a customer tax/VAT/GST/business identification number of this type. */ __( 'Codice Fiscale', 'woocommerce-pos' ),
			'customer_tax_id_label_it_piva'         => /* translators: Receipt label for a customer tax/VAT/GST/business identification number of this type. */ __( 'P.IVA', 'woocommerce-pos' ),
			'customer_tax_id_label_es_nif'          => /* translators: Receipt label for a customer tax/VAT/GST/business identification number of this type. */ __( 'NIF', 'woocommerce-pos' ),
			'customer_tax_id_label_ar_cuit'         => /* translators: Receipt label for a customer tax/VAT/GST/business identification number of this type. */ __( 'CUIT', 'woocommerce-pos' ),
			'customer_tax_id_label_ca_gst_hst'      => /* translators: Receipt label for a customer tax/VAT/GST/business identification number of this type. */ __( 'GST/HST No.', 'woocommerce-pos' ),
			'customer_tax_id_label_us_ein'          => /* translators: Receipt label for a customer tax/VAT/GST/business identification number of this type. */ __( 'EIN', 'woocommerce-pos' ),
			'customer_tax_id_label_de_ust_id'       => /* translators: Receipt label for a customer tax/VAT/GST/business identification number of this type. */ __( 'USt-IdNr.', 'woocommerce-pos' ),
			'customer_tax_id_label_de_steuernummer' => /* translators: Receipt label for a customer tax/VAT/GST/business identification number of this type. */ __( 'Steuernummer', 'woocommerce-pos' ),
			'customer_tax_id_label_de_hrb'          => /* translators: Receipt label for a customer tax/VAT/GST/business identification number of this type. */ __( 'HRB', 'woocommerce-pos' ),
			'customer_tax_id_label_nl_kvk'          => /* translators: Receipt label for a customer tax/VAT/GST/business identification number of this type. */ __( 'KVK', 'woocommerce-pos' ),
			'customer_tax_id_label_fr_siret'        => /* translators: Receipt label for a customer tax/VAT/GST/business identification number of this type. */ __( 'SIRET', 'woocommerce-pos' ),
			'customer_tax_id_label_fr_siren'        => /* translators: Receipt label for a customer tax/VAT/GST/business identification number of this type. */ __( 'SIREN', 'woocommerce-pos' ),
			'customer_tax_id_label_gb_company'      => /* translators: Receipt label for a customer tax/VAT/GST/business identification number of this type. */ __( 'Company No.', 'woocommerce-pos' ),
			'customer_tax_id_label_ch_uid'          => /* translators: Receipt label for a customer tax/VAT/GST/business identification number of this type. */ __( 'UID', 'woocommerce-pos' ),
			'customer_tax_id_label_other'           => /* translators: Receipt label for a customer tax/VAT/GST/business identification number of this type. */ __( 'Tax ID', 'woocommerce-pos' ),

			'prepared_for'           => /* translators: Receipt/quote label for the customer or recipient the document was prepared for. */ __( 'Prepared For', 'woocommerce-pos' ),
			'processed_by'           => /* translators: Receipt label for the staff member/cashier who processed the order. */ __( 'Processed by', 'woocommerce-pos' ),

			// Addresses.
			'bill_to'                => /* translators: Receipt/invoice address heading for the billing customer. */ __( 'Bill To', 'woocommerce-pos' ),
			'ship_to'                => /* translators: Receipt/invoice address heading for the shipping recipient. */ __( 'Ship To', 'woocommerce-pos' ),
			'billed_to'              => /* translators: Receipt/invoice address heading for the customer being billed. */ __( 'Billed To', 'woocommerce-pos' ),
			'billing_address'        => /* translators: Receipt/invoice address heading for the billing address. */ __( 'Billing Address', 'woocommerce-pos' ),

			// Table headers.
			'item'                   => /* translators: Receipt table column heading for product/item name. */ __( 'Item', 'woocommerce-pos' ),
			'sku'                    => /* translators: Receipt table column heading for product SKU/reference code. */ __( 'SKU', 'woocommerce-pos' ),
			'qty'                    => /* translators: Receipt table column heading for quantity. */ __( 'Qty', 'woocommerce-pos' ),
			'unit_price'             => /* translators: Receipt table column heading for unit price. */ __( 'Unit Price', 'woocommerce-pos' ),
			'unit_excl'              => /* translators: Receipt table column heading for unit price excluding tax/VAT/GST. */ __( 'Unit (excl.)', 'woocommerce-pos' ),
			'total_excl'             => /* translators: Receipt table column heading for line total excluding tax/VAT/GST. */ __( 'Total (excl.)', 'woocommerce-pos' ),
			'discount'               => /* translators: Receipt label for discount amount or discount line. */ __( 'Discount', 'woocommerce-pos' ),
			'packed'                 => /* translators: Packing slip label indicating an item/order has been packed. */ __( 'Packed', 'woocommerce-pos' ),
			'packed_by'              => /* translators: Standalone label used in printed receipt templates — heading above a signature block where the warehouse picker signs/dates the slip. */ __( 'Packed By', 'woocommerce-pos' ),
			'signed_name'            => /* translators: Standalone label used in printed receipt templates — sublabel under a blank signature line indicating the printed name field. */ __( 'Name', 'woocommerce-pos' ),

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
			'subtotal'               => /* translators: Receipt total-row label for the order subtotal before final total adjustments. */ __( 'Subtotal', 'woocommerce-pos' ),
			'subtotal_excl_tax'      => /* translators: Receipt total-row label for the subtotal excluding tax/VAT/GST. */ __( 'Subtotal (excl. tax)', 'woocommerce-pos' ),
			'total'                  => /* translators: Receipt total-row label for the final amount due/paid for the order. */ __( 'Total', 'woocommerce-pos' ),
			'total_tax'              => /* translators: Receipt total-row label for the total tax/VAT/GST amount. */ __( 'Total Tax', 'woocommerce-pos' ),
			'included_tax'           => /* translators: Standalone label used in printed receipt templates for tax amounts already included in displayed prices. */ __( 'Tax included', 'woocommerce-pos' ),
			'total_incl_tax'         => /* translators: Receipt total-row label for the order total including tax/VAT/GST. */ __( 'Total (incl. tax)', 'woocommerce-pos' ),
			'grand_total_incl_tax'   => /* translators: Receipt total-row label for the grand total including tax/VAT/GST. */ __( 'Grand Total (incl. tax)', 'woocommerce-pos' ),
			'tax'                    => /* translators: Receipt label for tax/VAT/GST amount, not a tax rate percentage. */ __( 'Tax', 'woocommerce-pos' ),
			'paid'                   => /* translators: Receipt payment-section heading for amounts already paid by the customer. */ __( 'Paid', 'woocommerce-pos' ),
			'paid_via'               => /* translators: Receipt label prefix shown before a payment method, for example "Paid via Cash". */ __( 'Paid via', 'woocommerce-pos' ),
			'tendered'               => /* translators: Standalone receipt/payment label meaning money received from the customer at checkout. Use the target-language equivalent of "Received" as a concise label; do not use wording that means "Amount paid/deposited", and do not use procurement tender/bid language. */ __( 'Tendered', 'woocommerce-pos' ),
			'change'                 => /* translators: Receipt/payment label for cash returned to the customer when the received amount is greater than the total; not "change" meaning modify. */ __( 'Change', 'woocommerce-pos' ),

			// Tax summary.
			'tax_summary'            => /* translators: Receipt section heading for the tax/VAT/GST breakdown. */ __( 'Tax Summary', 'woocommerce-pos' ),
			'taxable_excl'           => /* translators: Receipt tax summary label for taxable amount excluding tax/VAT/GST. */ __( 'Taxable (excl.)', 'woocommerce-pos' ),
			'tax_amount'             => /* translators: Receipt tax summary label for tax/VAT/GST amount, not a tax rate percentage. */ __( 'Tax Amount', 'woocommerce-pos' ),
			'taxable_incl'           => /* translators: Receipt tax summary label for taxable amount including tax/VAT/GST. */ __( 'Taxable (incl.)', 'woocommerce-pos' ),

			// Document titles.
			'invoice'                => /* translators: Document title printed at the top of an invoice template. */ __( 'Invoice', 'woocommerce-pos' ),
			'tax_invoice'            => /* translators: Document title printed at the top of a tax invoice/VAT invoice template. */ __( 'Tax Invoice', 'woocommerce-pos' ),
			'quote'                  => /* translators: Document title for a quotation/estimate, not a receipt or completed purchase. */ __( 'Quote', 'woocommerce-pos' ),
			'receipt'                => /* translators: Document title printed at the top of a completed sale receipt. */ __( 'Receipt', 'woocommerce-pos' ),
			'gift_receipt'           => /* translators: Document title for a gift receipt that usually hides prices. */ __( 'Gift Receipt', 'woocommerce-pos' ),
			'credit_note'            => /* translators: Document title for a credit note/refund document. */ __( 'Credit Note', 'woocommerce-pos' ),
			'packing_slip'           => /* translators: Document title for a packing slip used to pick/pack shipped items. */ __( 'Packing Slip', 'woocommerce-pos' ),

			// Returned items.
			'returned_items'         => /* translators: Receipt section heading for products returned/refunded from the order. */ __( 'Returned Items', 'woocommerce-pos' ),
			'amount'                 => /* translators: Generic receipt column/field label for a monetary amount. */ __( 'Amount', 'woocommerce-pos' ),
			'total_refunded'         => /* translators: Receipt total-row label for the total amount refunded to the customer. */ __( 'Total Refunded', 'woocommerce-pos' ),
			'refunded'               => /* translators: Receipt label for an amount refunded to the customer. */ __( 'Refunded', 'woocommerce-pos' ),
			'net_total'              => /* translators: Receipt total-row label for total after subtracting refunds. */ __( 'Net Total', 'woocommerce-pos' ),

			// Section labels.
			'customer_note'          => /* translators: Receipt section heading for a note/message supplied by or about the customer. */ __( 'Customer Note', 'woocommerce-pos' ),
			'terms_and_conditions'   => /* translators: Receipt section heading for sale terms and conditions text. */ __( 'Terms & Conditions', 'woocommerce-pos' ),
			'a_message_for_you'      => /* translators: Receipt section heading for a short merchant message to the customer. */ __( 'A message for you', 'woocommerce-pos' ),
			'details'                => /* translators: Standalone label used in printed receipt templates — column heading for invoice meta (issued date, cashier, currency). */ __( 'Details', 'woocommerce-pos' ),
			'issued'                 => /* translators: Standalone label used in printed receipt templates — date the invoice was issued. */ __( 'Issued', 'woocommerce-pos' ),

			// Payment instructions — used by the Invoice template to render a
			// "How to pay" panel guarded by {{#order.needs_payment}}.
			'amount_due'             => /* translators: Standalone label used in printed receipt templates — large amount-due heading on unpaid invoices. */ __( 'Amount due', 'woocommerce-pos' ),
			'how_to_pay'             => /* translators: Standalone label used in printed receipt templates — section heading for payment instructions on unpaid invoices. */ __( 'How to pay', 'woocommerce-pos' ),
			'bank_transfer'          => /* translators: Standalone label used in printed receipt templates — subheading for the bank-transfer column under "How to pay". */ __( 'Bank transfer', 'woocommerce-pos' ),
			'pay_online'             => /* translators: Standalone label used in printed receipt templates — subheading for the online-payment column under "How to pay". */ __( 'Pay online', 'woocommerce-pos' ),
			'scan_qr_or_visit'       => /* translators: Standalone label used in printed receipt templates — instruction shown next to a pay-now QR code, followed by the payment URL. */ __( 'Scan the QR code, or visit:', 'woocommerce-pos' ),
			'account'                => /* translators: Standalone label used in printed receipt templates — bank-transfer field label for the account name. */ __( 'Account', 'woocommerce-pos' ),

			// Footers.
			'thank_you'              => /* translators: Short receipt footer message thanking the customer. */ __( 'Thank you!', 'woocommerce-pos' ),
			'thank_you_purchase'     => /* translators: Receipt footer message thanking the customer for a completed purchase. */ __( 'Thank you for your purchase!', 'woocommerce-pos' ),
			'thank_you_shopping'     => /* translators: Receipt footer message thanking the customer for shopping at the store. */ __( 'Thank you for shopping with us!', 'woocommerce-pos' ),
			'thank_you_business'     => /* translators: Receipt footer message thanking the customer for their business. */ __( 'Thank you for your business.', 'woocommerce-pos' ),
			'gift_return_policy'     => /* translators: Gift receipt footer explaining the return/exchange period. */ __( 'Items may be returned or exchanged within 30 days with this receipt.', 'woocommerce-pos' ),
			'quote_validity'         => /* translators: Quote document footer explaining validity period and that the quote is not a receipt. */ __( 'This quote is valid for 30 days from the date of issue. Prices are subject to change after the validity period. This is not a receipt or confirmation of purchase.', 'woocommerce-pos' ),
			'quote_not_receipt'      => /* translators: Quote document notice clarifying it is not a completed-sale receipt. */ __( 'This is a quote, not a receipt', 'woocommerce-pos' ),
			'return_retain_receipt'  => /* translators: Receipt footer asking the customer to keep the receipt for returns/records. */ __( 'Please retain this receipt for your records.', 'woocommerce-pos' ),

			// Thermal / kitchen.
			'kitchen'                => /* translators: Kitchen ticket document label for food/drink preparation; keep concise and uppercase if natural in the target language. */ __( 'KITCHEN', 'woocommerce-pos' ),

			// Fiscal.
			'signature'              => /* translators: Receipt label for a customer or staff signature line. */ __( 'Signature', 'woocommerce-pos' ),
			'document_type'          => /* translators: Fiscal receipt label for document type/category. */ __( 'Document Type', 'woocommerce-pos' ),
			'copy'                   => /* translators: Fiscal receipt label for a printed copy of a document. */ __( 'Copy', 'woocommerce-pos' ),
			'copy_number'            => /* translators: Fiscal receipt label for copy number/sequence. */ __( 'Copy No.', 'woocommerce-pos' ),

			// Order meta + footer (used by detailed-receipt header / order column / footer).
			'status'                 => /* translators: Receipt label for order/document status. */ __( 'Status', 'woocommerce-pos' ),
			'completed'              => /* translators: Receipt status value meaning the order/document is completed. */ __( 'Completed', 'woocommerce-pos' ),
			'printed'                => /* translators: Receipt status value meaning the document has been printed. */ __( 'Printed', 'woocommerce-pos' ),
			'opening_hours'          => /* translators: Receipt label for the store business opening hours. */ __( 'Opening Hours', 'woocommerce-pos' ),
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
			$translated = self::normalize_contact_label_phrase( $english, $translated );

			$content = str_replace( $english, $translated, $content );
		}

		return $content;
	}

	/**
	 * Keep receipt contact labels consistent after copy-time translation.
	 *
	 * @param string $english    Source phrase.
	 * @param string $translated Translated phrase.
	 * @return string
	 */
	private static function normalize_contact_label_phrase( string $english, string $translated ): string {
		if ( 'Email: {{store.email}}' === $english ) {
			return preg_replace( '/\s*:\s*/', ': ', $translated, 1 ) ?? $translated;
		}

		if ( 'Phone: {{store.phone}}' === $english ) {
			return preg_replace( '/\s*:\s*/', ': ', $translated, 1 ) ?? $translated;
		}

		return $translated;
	}
}
