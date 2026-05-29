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
						'provider'        => 'star-cloudprnt',
						'poll_token_hash' => Cloud_Print_Registry::hash_token( 'secret-1' ),
					),
				),
			)
		);

		$registry = new Cloud_Print_Registry();

		$this->assertEquals( 'star-cloudprnt', $registry->get_printer( 'p1' )['provider'] );
		$this->assertEquals( true, $registry->verify_token( 'p1', 'secret-1' ) );
		$this->assertEquals( false, $registry->verify_token( 'p1', 'wrong' ) );
		$this->assertEquals( null, $registry->get_printer( 'missing' ) );
	}

	/**
	 * It slugifies a printer name into a stable id and dedupes against existing ids.
	 */
	public function test_derive_id_slugifies_name_and_dedupes(): void {
		$this->assertEquals( 'kitchen-printer', Cloud_Print_Registry::derive_id( 'Kitchen Printer', array() ) );
		$this->assertEquals( 'kitchen-printer-2', Cloud_Print_Registry::derive_id( 'Kitchen Printer!', array( 'kitchen-printer' ) ) );
		$this->assertEquals( 'kitchen-printer-3', Cloud_Print_Registry::derive_id( 'Kitchen Printer', array( 'kitchen-printer', 'kitchen-printer-2' ) ) );
		$this->assertEquals( 'printer', Cloud_Print_Registry::derive_id( '', array() ) );
	}

	/**
	 * It records and reads back a printer's last-seen timestamp.
	 */
	public function test_record_and_get_seen_roundtrip(): void {
		$registry = new Cloud_Print_Registry();
		$this->assertEquals( 0, $registry->get_seen( 'kitchen' ) );
		$registry->record_seen( 'kitchen' );
		$this->assertGreaterThan( 0, $registry->get_seen( 'kitchen' ) );
	}
}
