<?php
/**
 * Cloud print registry tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Cloud_Print_Registry;
use WP_UnitTestCase;

/**
 * Cloud_Print_Registry_Test class.
 */
class Cloud_Print_Registry_Test extends WP_UnitTestCase {
	/**
	 * It returns printers by id and validates poll tokens.
	 */
	public function test_get_printer_matches_id_and_validates_token(): void {
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers' => array(
					array(
						'id'              => 'p1',
						'protocol'        => 'star-cloudprnt',
						'poll_token_hash' => Cloud_Print_Registry::hash_token( 'secret-1' ),
					),
				),
			)
		);

		$registry = new Cloud_Print_Registry();

		$this->assertEquals( 'star-cloudprnt', $registry->get_printer( 'p1' )['protocol'] );
		$this->assertEquals( true, $registry->verify_token( 'p1', 'secret-1' ) );
		$this->assertEquals( false, $registry->verify_token( 'p1', 'wrong' ) );
		$this->assertEquals( null, $registry->get_printer( 'missing' ) );
	}
}
