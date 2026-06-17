<?php
/**
 * General Settings Section.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services\Settings;

use WCPOS\WooCommercePOS\Services\Store_Defaults;

/**
 * The General Settings Section: store identity, POS behaviour toggles, and
 * structured store tax IDs.
 */
class General_Section extends Abstract_Section {
	/**
	 * Section id.
	 */
	public function id(): string {
		return 'general';
	}

	/**
	 * Section defaults.
	 */
	public function defaults(): array {
		return array(
			'pos_only_products'           => false,
			'decimal_qty'                 => false,
			'force_ssl'                   => true,
			'default_customer'            => 0,
			'default_customer_is_cashier' => false,
			'barcode_field'               => '_sku',
			'generate_username'           => true,
			'restore_stock_on_delete'     => true,
			'tracking_consent'            => 'undecided',
			'store_name'                  => '',
			'store_phone'                 => '',
			'store_email'                 => '',
			'policies_and_conditions'     => '',
			'store_tax_ids'               => array(),
		);
	}

	/**
	 * Migrate tracking_consent from the legacy `tools` option if it was set
	 * there before being moved to `general`. In-memory only — an explicit
	 * general-level choice always wins, and the database is never written
	 * from a read path.
	 *
	 * @param array $raw Raw option value.
	 */
	protected function migrate( array $raw ): array {
		if ( ! \array_key_exists( 'tracking_consent', $raw ) ) {
			$legacy_tools = get_option( self::DB_PREFIX . 'tools', array() );
			if ( \is_array( $legacy_tools ) && \array_key_exists( 'tracking_consent', $legacy_tools ) ) {
				$raw['tracking_consent'] = $legacy_tools['tracking_consent'];
			}
		}

		return $raw;
	}

	/**
	 * Normalize store_tax_ids and expose resolved fallbacks so the React UI
	 * can render placeholders for store_name / store_phone / store_email /
	 * policies_and_conditions when the user has not entered a value.
	 *
	 * @param array $settings Merged settings.
	 */
	protected function compose( array $settings ): array {
		$settings['store_tax_ids']  = self::sanitize_store_tax_ids( $settings['store_tax_ids'] );
		$settings['store_defaults'] = Store_Defaults::fallbacks();

		return $settings;
	}

	/**
	 * Sanitize general settings before persisting.
	 *
	 * @param array $settings General settings.
	 */
	protected function sanitize( array $settings ): array {
		if ( \array_key_exists( 'store_tax_ids', $settings ) ) {
			$settings['store_tax_ids'] = self::sanitize_store_tax_ids( $settings['store_tax_ids'] );
		}

		foreach ( array( 'store_name', 'store_phone' ) as $key ) {
			if ( \array_key_exists( $key, $settings ) ) {
				$settings[ $key ] = \is_string( $settings[ $key ] )
					? sanitize_text_field( $settings[ $key ] )
					: '';
			}
		}

		if ( \array_key_exists( 'store_email', $settings ) ) {
			$email                   = \is_string( $settings['store_email'] ) ? trim( $settings['store_email'] ) : '';
			$settings['store_email'] = ( '' !== $email && is_email( $email ) ) ? sanitize_email( $email ) : '';
		}

		if ( \array_key_exists( 'policies_and_conditions', $settings ) ) {
			$settings['policies_and_conditions'] = \is_string( $settings['policies_and_conditions'] )
				? sanitize_textarea_field( $settings['policies_and_conditions'] )
				: '';
		}

		// store_defaults is a read-only computed field for the UI; never persist it.
		unset( $settings['store_defaults'] );

		return $settings;
	}

	/**
	 * Store_tax_ids is a full replacement on PATCH so users can remove rows
	 * by sending the trimmed list.
	 *
	 * @param array $existing Existing settings view.
	 * @param array $patch    Incoming partial payload.
	 */
	public function merge( array $existing, array $patch ): array {
		$settings = array_replace_recursive( $existing, $patch );
		if ( isset( $patch['store_tax_ids'] ) && \is_array( $patch['store_tax_ids'] ) ) {
			$settings['store_tax_ids'] = $patch['store_tax_ids'];
		}

		return $settings;
	}

	/**
	 * REST endpoint args for the general update route. Moved verbatim from
	 * API\Settings::get_general_endpoint_args().
	 */
	public function endpoint_args(): array {
		return array(
			'pos_only_products'          => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_bool( $param );
				},
			),
			'decimal_qty'                => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_bool( $param );
				},
			),
			'force_ssl'                  => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_bool( $param );
				},
			),
			'default_customer'           => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_integer( $param );
				},
			),
			'default_customer_is_cashier' => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_bool( $param );
				},
			),
			'barcode_field'              => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_string( $param );
				},
			),
			'generate_username'          => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_bool( $param );
				},
			),
			'restore_stock_on_delete'    => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_bool( $param );
				},
			),
			'tracking_consent'           => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_string( $param ) && \in_array( $param, array( 'allowed', 'denied', 'undecided' ), true );
				},
			),
			'store_tax_ids'              => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_array( $param );
				},
			),
			'store_name'                 => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_string( $param );
				},
			),
			'store_phone'                => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_string( $param );
				},
			),
			'store_email'                => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_string( $param );
				},
			),
			'policies_and_conditions'    => array(
				'validate_callback' => function ( $param, $request, $key ) {
					return \is_string( $param );
				},
			),
		);
	}

	/**
	 * Sanitize the additional free-store tax IDs entered in General settings.
	 *
	 * Drops malformed rows and keeps optional country/label fields only when
	 * non-empty. Values are preserved verbatim apart from normal text-field
	 * sanitization and surrounding whitespace.
	 *
	 * @param mixed $tax_ids Raw tax IDs.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function sanitize_store_tax_ids( $tax_ids ): array {
		if ( ! \is_array( $tax_ids ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( $tax_ids as $tax_id ) {
			if ( ! \is_array( $tax_id ) ) {
				continue;
			}

			$type  = isset( $tax_id['type'] ) && \is_string( $tax_id['type'] )
				? sanitize_key( $tax_id['type'] )
				: '';
			$value = isset( $tax_id['value'] ) && \is_string( $tax_id['value'] )
				? trim( sanitize_text_field( $tax_id['value'] ) )
				: '';

			if ( '' === $type || '' === $value ) {
				continue;
			}

			$entry = array(
				'type'  => $type,
				'value' => $value,
			);

			$country = isset( $tax_id['country'] ) && \is_string( $tax_id['country'] )
				? strtoupper( trim( sanitize_text_field( $tax_id['country'] ) ) )
				: '';
			if ( '' !== $country ) {
				$entry['country'] = $country;
			}

			$label = isset( $tax_id['label'] ) && \is_string( $tax_id['label'] )
				? trim( sanitize_text_field( $tax_id['label'] ) )
				: '';
			if ( '' !== $label ) {
				$entry['label'] = $label;
			}

			$sanitized[] = $entry;
		}

		return $sanitized;
	}
}
