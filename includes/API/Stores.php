<?php


namespace WCPOS\WooCommercePOS\API;

use WP_REST_Request;

class Stores {
	private $request;

	/**
	 * Stores constructor.
	 */
	public function __construct() {

	}

	/**
	 *
	 */
	public function get_stores() {

		$data = array(
			array(
				'id'         => 0,
				'name'       => 'Default Store',
				'accounting' => $this->accounting(),
			)
		);

		/** Let the user modify the data before sending it back */
		return apply_filters( 'woocommerce_pos_stores_before_dispatch', $data );
	}

	/**
	 * Get the accounting format from user settings
	 * POS uses a plugin to format currency: http://josscrowcroft.github.io/accounting.js/
	 *
	 * @return array $settings
	 */
	private function accounting(): array {
		$decimal   = get_option( 'woocommerce_price_decimal_sep' );
		$thousand  = get_option( 'woocommerce_price_thousand_sep' );
		$precision = get_option( 'woocommerce_price_num_decimals' );

		return array(
			'currency' => array(
				'decimal'   => $decimal,
				'format'    => $this->currency_format(),
				'precision' => intval( $precision ),
				'symbol'    => get_woocommerce_currency_symbol( get_woocommerce_currency() ),
				'thousand'  => $thousand,
			),
			'number'   => array(
				'decimal'   => $decimal,
				'precision' => intval( $precision ),
				'thousand'  => $thousand,
			),
		);
	}

	/**
	 * Get the currency format from user settings
	 *
	 * @return array $format
	 */
	private function currency_format(): array {
		$currency_pos = get_option( 'woocommerce_currency_pos' );

		if ( 'right' == $currency_pos ) {
			return array(
				'pos'  => '%v%s',
				'neg'  => '- %v%s',
				'zero' => '%v%s',
			);
		}

		if ( 'left_space' == $currency_pos ) {
			return array(
				'pos'  => '%s&nbsp;%v',
				'neg'  => '- %s&nbsp;%v',
				'zero' => '%s&nbsp;%v',
			);
		}

		if ( 'right_space' == $currency_pos ) {
			return array(
				'pos'  => '%v&nbsp;%s',
				'neg'  => '- %v&nbsp;%s',
				'zero' => '%v&nbsp;%s',
			);
		}

		// default = left
		return array(
			'pos'  => '%s%v',
			'neg'  => '- %s%v',
			'zero' => '%s%v',
		);
	}
}
