<?php
/**
 * Tax ID Settings.
 *
 * Centralises the Tax-ID-related settings: per-type write-map overrides plus
 * the capture/display toggles surfaced in the admin SPA. Values come from
 * WCPOS settings (the top-level `tax_ids` section, allowing the existing
 * settings service to handle persistence) and fall back to sensible defaults.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * Tax_Id_Settings class.
 */
class Tax_Id_Settings {
	/**
	 * Default per-type → meta-key write map.
	 *
	 * `_billing_vat_number` is the WC EU VAT Number current key and is read
	 * out-of-the-box by most VAT-aware plugins, so we use it as the catch-all
	 * for VAT-shaped types when no plugin and no override are present.
	 *
	 * @return array<string,string>
	 */
	public static function default_write_map(): array {
		return array(
			Tax_Id_Types::TYPE_EU_VAT     => '_billing_vat_number',
			Tax_Id_Types::TYPE_GB_VAT     => '_billing_vat_number',
			Tax_Id_Types::TYPE_SA_VAT     => '_billing_vat_number',
			Tax_Id_Types::TYPE_AU_ABN     => '_billing_vat_number',
			Tax_Id_Types::TYPE_CA_GST_HST => '_billing_vat_number',
			Tax_Id_Types::TYPE_US_EIN     => '_billing_vat_number',
			Tax_Id_Types::TYPE_OTHER      => '_billing_vat_number',
			Tax_Id_Types::TYPE_BR_CPF     => '_billing_cpf',
			Tax_Id_Types::TYPE_BR_CNPJ    => '_billing_cnpj',
			Tax_Id_Types::TYPE_IN_GST     => '_billing_gstin',
			Tax_Id_Types::TYPE_IT_CF      => '_billing_cf',
			Tax_Id_Types::TYPE_IT_PIVA    => '_billing_piva',
			Tax_Id_Types::TYPE_ES_NIF     => '_billing_nif',
			Tax_Id_Types::TYPE_AR_CUIT    => '_billing_cuit',
		);
	}

	/**
	 * User-configured per-type overrides for the write map.
	 *
	 * Reads from the `tax_ids.write_map` settings tree (top-level option
	 * `woocommerce_pos_settings_tax_ids`). Invalid types or empty keys are
	 * silently dropped.
	 *
	 * @return array<string,string>
	 */
	public static function get_overrides(): array {
		$tax_ids = (array) get_option( 'woocommerce_pos_settings_tax_ids', array() );
		$raw     = isset( $tax_ids['write_map'] ) && \is_array( $tax_ids['write_map'] ) ? $tax_ids['write_map'] : array();

		$out = array();
		foreach ( $raw as $type => $meta_key ) {
			if ( ! \is_string( $type ) || ! Tax_Id_Types::is_valid_type( $type ) ) {
				continue;
			}
			if ( ! \is_string( $meta_key ) || '' === $meta_key ) {
				continue;
			}
			$out[ $type ] = $meta_key;
		}

		return $out;
	}
}
