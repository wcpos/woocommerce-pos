<?php
/**
 * Tax IDs Settings Section.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services\Settings;

use WCPOS\WooCommercePOS\Services\Tax_Id_Types;

/**
 * The Tax IDs Settings Section: per-type meta-key write overrides. Empty by
 * default — the composed write_map (defaults + plugin detection + scan) is
 * used when no override exists.
 */
class Tax_Ids_Section extends Abstract_Section {
	/**
	 * Section id.
	 */
	public function id(): string {
		return 'tax_ids';
	}

	/**
	 * Section defaults.
	 */
	public function defaults(): array {
		return array(
			'write_map' => array(),
		);
	}

	/**
	 * Migrate write_map from its legacy home inside the general option, in memory only.
	 *
	 * @param array $raw Raw option value.
	 */
	protected function migrate( array $raw ): array {
		if ( ! \array_key_exists( 'write_map', $raw ) ) {
			$legacy_general = get_option( self::DB_PREFIX . 'general', array() );
			if (
				\is_array( $legacy_general )
				&& isset( $legacy_general['tax_ids']['write_map'] )
				&& \is_array( $legacy_general['tax_ids']['write_map'] )
			) {
				$raw['write_map'] = $legacy_general['tax_ids']['write_map'];
			}
		}

		return $raw;
	}

	/**
	 * Write_map is intentionally a full replacement (not deep-merged) so
	 * users can remove entries by sending the trimmed map.
	 *
	 * @param array $existing Existing settings view.
	 * @param array $patch    Incoming partial payload.
	 */
	public function merge( array $existing, array $patch ): array {
		$settings = array_replace_recursive( $existing, $patch );
		if ( isset( $patch['write_map'] ) && \is_array( $patch['write_map'] ) ) {
			$settings['write_map'] = $patch['write_map'];
		}

		return $settings;
	}

	/**
	 * REST endpoint args. Moved verbatim from
	 * API\Settings::get_tax_ids_endpoint_args().
	 */
	public function endpoint_args(): array {
		return array(
			'write_map' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					if ( ! \is_array( $param ) ) {
						return false;
					}
					foreach ( $param as $type => $meta_key ) {
						if ( ! \is_string( $type ) || ! Tax_Id_Types::is_valid_type( $type ) ) {
							return false;
						}
						if ( ! \is_string( $meta_key ) ) {
							return false;
						}
					}

					return true;
				},
			),
		);
	}
}
