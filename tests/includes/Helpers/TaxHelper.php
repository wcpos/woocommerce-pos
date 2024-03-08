<?php

namespace WCPOS\WooCommercePOS\Tests\Helpers;

use WC_Tax;

class TaxHelper {
	/**
	 * Create a WooCommerce tax rate.
	 *
	 * @param array $args Tax rate arguments.
	 * @param mixed $data
	 *
	 * @return int|WP_Error The newly created tax rate ID or a WP_Error object.
	 */
	public static function create_tax_rate( $data ): int {
		$tax_id = WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'      => $data['country'] ?? '',
				'tax_rate_state'        => $data['state'] ?? '',
				'tax_rate'              => $data['rate'],
				'tax_rate_name'         => $data['name'],
				'tax_rate_priority'     => $data['priority'] ?? 1,
				'tax_rate_compound'     => $data['compound'] ? 1 : 0,
				'tax_rate_shipping'     => $data['shipping'] ? 1 : 0,
				'tax_rate_order'        => $data['order'] ?? 1,
				'tax_rate_class'        => $data['class'] ?? '',
			)
		);

		if ( isset( $data['postcode'] ) ) {
			WC_Tax::_update_tax_rate_postcodes( $tax_id, $data['postcode'] );
		}

		if ( isset( $data['city'] ) ) {
			WC_Tax::_update_tax_rate_cities( $tax_id, $data['city'] );
		}

		return $tax_id;
	}



	/**
	 * Delete a WooCommerce tax rate.
	 *
	 * @param int $tax_rate_id The tax rate ID.
	 *
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function delete_tax_rate( int $tax_rate_id ) {
		return WC_Tax::_delete_tax_rate( $tax_rate_id );
	}

	/**
	 * Match the sample data given by WooCommerce.
	 */
	public static function create_sample_tax_rates_GB(): array {
		$id1 = self::create_tax_rate(
			array(
				'country'  => 'GB',
				'rate'     => '20.0000',
				'name'     => 'VAT',
				'priority' => 1,
				'compound' => true,
				'shipping' => true,
				'class'    => '',
			)
		);
		$id2 = self::create_tax_rate(
			array(
				'country'  => 'GB',
				'rate'     => '5.0000',
				'name'     => 'VAT',
				'priority' => 1,
				'compound' => true,
				'shipping' => true,
				'class'    => 'reduced-rate',
			)
		);
		$id3 = self::create_tax_rate(
			array(
				'country'  => 'GB',
				'rate'     => '0.0000',
				'name'     => 'VAT',
				'priority' => 1,
				'compound' => true,
				'shipping' => true,
				'class'    => 'zero-rate',
			)
		);

		return array( $id1, $id2, $id3 );
	}

	public static function create_sample_tax_rates_US(): array {
		$id1 = self::create_tax_rate(
			array(
				'country'  => 'US',
				'rate'     => '10.0000',
				'name'     => 'US',
				'priority' => 1,
				'compound' => true,
				'shipping' => true,
				'class'    => '',
			)
		);
		$id2 = self::create_tax_rate(
			array(
				'country'  => 'US',
				'state'    => 'AL',
				'postcode' => '12345; 123456',
				'rate'     => '2.0000',
				'name'     => 'US AL',
				'priority' => 2,
				'compound' => true,
				'shipping' => true,
				'class'    => '',
			)
		);

		return array( $id1, $id2 );
	}
}
