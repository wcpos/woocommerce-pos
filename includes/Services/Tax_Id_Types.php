<?php
/**
 * Tax ID Types.
 *
 * Defines the canonical set of customer/order tax-ID types supported by WCPOS.
 * Modelled on Stripe's Tax IDs API (https://docs.stripe.com/api/tax_ids), with the
 * type encoded as `<country>_<kind>` where applicable, and a generic `other`
 * escape hatch for the long tail.
 *
 * This class is pure logic (no I/O, no WP dependencies) and is safe to call from
 * any context.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * Tax_Id_Types class.
 */
class Tax_Id_Types {
	const TYPE_EU_VAT          = 'eu_vat';
	const TYPE_GB_VAT          = 'gb_vat';
	const TYPE_AU_ABN          = 'au_abn';
	const TYPE_BR_CPF          = 'br_cpf';
	const TYPE_BR_CNPJ         = 'br_cnpj';
	const TYPE_IN_GST          = 'in_gst';
	const TYPE_IT_CF           = 'it_cf';
	const TYPE_IT_PIVA         = 'it_piva';
	const TYPE_ES_NIF          = 'es_nif';
	const TYPE_AR_CUIT         = 'ar_cuit';
	const TYPE_SA_VAT          = 'sa_vat';
	const TYPE_CA_GST_HST      = 'ca_gst_hst';
	const TYPE_US_EIN          = 'us_ein';
	const TYPE_DE_UST_ID       = 'de_ust_id';
	const TYPE_DE_STEUERNUMMER = 'de_steuernummer';
	const TYPE_DE_HRB          = 'de_hrb';
	const TYPE_NL_KVK          = 'nl_kvk';
	const TYPE_FR_SIRET        = 'fr_siret';
	const TYPE_FR_SIREN        = 'fr_siren';
	const TYPE_GB_COMPANY      = 'gb_company';
	const TYPE_CH_UID          = 'ch_uid';
	const TYPE_OTHER           = 'other';

	/**
	 * EU member-state VAT country codes (note: Greece uses EL not GR for VAT).
	 *
	 * @var string[]
	 */
	const EU_VAT_COUNTRY_CODES = array(
		'AT',
		'BE',
		'BG',
		'CY',
		'CZ',
		'DE',
		'DK',
		'EE',
		'EL',
		'ES',
		'FI',
		'FR',
		'HR',
		'HU',
		'IE',
		'IT',
		'LT',
		'LU',
		'LV',
		'MT',
		'NL',
		'PL',
		'PT',
		'RO',
		'SE',
		'SI',
		'SK',
	);

	/**
	 * All known tax-ID types.
	 *
	 * @return string[]
	 */
	public static function all_types(): array {
		return array(
			self::TYPE_EU_VAT,
			self::TYPE_GB_VAT,
			self::TYPE_AU_ABN,
			self::TYPE_BR_CPF,
			self::TYPE_BR_CNPJ,
			self::TYPE_IN_GST,
			self::TYPE_IT_CF,
			self::TYPE_IT_PIVA,
			self::TYPE_ES_NIF,
			self::TYPE_AR_CUIT,
			self::TYPE_SA_VAT,
			self::TYPE_CA_GST_HST,
			self::TYPE_US_EIN,
			self::TYPE_DE_UST_ID,
			self::TYPE_DE_STEUERNUMMER,
			self::TYPE_DE_HRB,
			self::TYPE_NL_KVK,
			self::TYPE_FR_SIRET,
			self::TYPE_FR_SIREN,
			self::TYPE_GB_COMPANY,
			self::TYPE_CH_UID,
			self::TYPE_OTHER,
		);
	}

	/**
	 * Whether the given type string is a known tax-ID type.
	 *
	 * @param string $type Type identifier.
	 *
	 * @return bool
	 */
	public static function is_valid_type( string $type ): bool {
		return \in_array( $type, self::all_types(), true );
	}

	/**
	 * Derive an ISO 3166-1 alpha-2 country code from the type.
	 *
	 * Returns null for `eu_vat` (multi-country) and `other` (unspecified).
	 *
	 * @param string $type Type identifier.
	 *
	 * @return null|string
	 */
	public static function country_for_type( string $type ) {
		switch ( $type ) {
			case self::TYPE_GB_VAT:
				return 'GB';

			case self::TYPE_AU_ABN:
				return 'AU';

			case self::TYPE_BR_CPF:
			case self::TYPE_BR_CNPJ:
				return 'BR';

			case self::TYPE_IN_GST:
				return 'IN';

			case self::TYPE_IT_CF:
			case self::TYPE_IT_PIVA:
				return 'IT';

			case self::TYPE_ES_NIF:
				return 'ES';

			case self::TYPE_AR_CUIT:
				return 'AR';

			case self::TYPE_SA_VAT:
				return 'SA';

			case self::TYPE_CA_GST_HST:
				return 'CA';

			case self::TYPE_US_EIN:
				return 'US';

			case self::TYPE_DE_UST_ID:
			case self::TYPE_DE_STEUERNUMMER:
			case self::TYPE_DE_HRB:
				return 'DE';

			case self::TYPE_NL_KVK:
				return 'NL';

			case self::TYPE_FR_SIRET:
			case self::TYPE_FR_SIREN:
				return 'FR';

			case self::TYPE_GB_COMPANY:
				return 'GB';

			case self::TYPE_CH_UID:
				return 'CH';

			case self::TYPE_EU_VAT:
			case self::TYPE_OTHER:
			default:
				return null;
		}
	}

	/**
	 * Default human-readable label for the type.
	 *
	 * Translatable; consumers may override per-tax-id with a custom `label`.
	 *
	 * @param string $type Type identifier.
	 *
	 * @return string
	 */
	public static function default_label( string $type ): string {
		switch ( $type ) {
			case self::TYPE_EU_VAT:
			case self::TYPE_GB_VAT:
			case self::TYPE_SA_VAT:
				return __( 'VAT Number', 'woocommerce-pos' );

			case self::TYPE_AU_ABN:
				return __( 'ABN', 'woocommerce-pos' );

			case self::TYPE_BR_CPF:
				return __( 'CPF', 'woocommerce-pos' );

			case self::TYPE_BR_CNPJ:
				return __( 'CNPJ', 'woocommerce-pos' );

			case self::TYPE_IN_GST:
				return __( 'GSTIN', 'woocommerce-pos' );

			case self::TYPE_IT_CF:
				return __( 'Codice Fiscale', 'woocommerce-pos' );

			case self::TYPE_IT_PIVA:
				return __( 'Partita IVA', 'woocommerce-pos' );

			case self::TYPE_ES_NIF:
				return __( 'NIF', 'woocommerce-pos' );

			case self::TYPE_AR_CUIT:
				return __( 'CUIT', 'woocommerce-pos' );

			case self::TYPE_CA_GST_HST:
				return __( 'GST/HST', 'woocommerce-pos' );

			case self::TYPE_US_EIN:
				return __( 'EIN', 'woocommerce-pos' );

			case self::TYPE_DE_UST_ID:
				return __( 'USt-IdNr.', 'woocommerce-pos' );

			case self::TYPE_DE_STEUERNUMMER:
				return __( 'Steuernummer', 'woocommerce-pos' );

			case self::TYPE_DE_HRB:
				return __( 'HRB', 'woocommerce-pos' );

			case self::TYPE_NL_KVK:
				return __( 'KVK', 'woocommerce-pos' );

			case self::TYPE_FR_SIRET:
				return __( 'SIRET', 'woocommerce-pos' );

			case self::TYPE_FR_SIREN:
				return __( 'SIREN', 'woocommerce-pos' );

			case self::TYPE_GB_COMPANY:
				return __( 'Company No.', 'woocommerce-pos' );

			case self::TYPE_CH_UID:
				return __( 'UID', 'woocommerce-pos' );

			case self::TYPE_OTHER:
			default:
				return __( 'Tax ID', 'woocommerce-pos' );
		}
	}

	/**
	 * Whether the given country code is an EU VAT-member country.
	 *
	 * @param string $country_code ISO 3166-1 alpha-2 country code (uppercase).
	 *
	 * @return bool
	 */
	public static function is_eu_vat_country( string $country_code ): bool {
		return \in_array( strtoupper( $country_code ), self::EU_VAT_COUNTRY_CODES, true );
	}
}
