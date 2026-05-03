/**
 * Shape of the tax_ids settings subtree returned by /settings/tax_ids.
 */
export interface TaxIdsSettings {
	enabled: boolean;
	capture_on_customer: boolean;
	capture_on_cart: boolean;
	show_on_receipt: boolean;
	b2b_required_threshold: null | {
		country: string;
		amount: number;
		currency: string;
	};
	write_map: Record<string, string>;
}

/**
 * Shape of the /settings/tax_ids/detection response.
 */
export interface TaxIdsDetection {
	plugins: string[];
	default_write_map: Record<string, string>;
	composed_write_map: Record<string, string>;
	types: string[];
}

/**
 * Friendly labels for tax-ID types. Matches Tax_Id_Types constants on the
 * server. Keep in sync with includes/Services/Tax_Id_Types.php.
 */
export const TAX_ID_TYPE_LABELS: Record<string, string> = {
	eu_vat: 'EU VAT',
	gb_vat: 'GB VAT',
	sa_vat: 'SA VAT',
	au_abn: 'AU ABN',
	br_cpf: 'BR CPF',
	br_cnpj: 'BR CNPJ',
	in_gst: 'IN GSTIN',
	it_cf: 'IT Codice Fiscale',
	it_piva: 'IT Partita IVA',
	es_nif: 'ES NIF',
	ar_cuit: 'AR CUIT',
	ca_gst_hst: 'CA GST/HST',
	us_ein: 'US EIN',
	other: 'Other',
};

/**
 * Friendly labels for detected plugin ids. Keep in sync with
 * Tax_Id_Detector::PLUGINS keys.
 */
export const PLUGIN_LABELS: Record<string, string> = {
	wc_eu_vat_number: 'WooCommerce EU VAT Number',
	aelia_eu_vat: 'Aelia EU VAT Assistant',
	wpfactory_eu_vat: 'WPFactory EU/UK VAT Manager',
	germanized: 'WooCommerce Germanized',
	br_market: 'Brazilian Market on WooCommerce',
	es_nif: 'NIF/CIF Spain for WooCommerce',
};
