<?php
/**
 * Cloud print settings REST tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

/**
 * Settings_CloudPrint_Test class.
 */
class Settings_CloudPrint_Test extends WCPOS_REST_Unit_Test_Case {
	/**
	 * It never exposes stored token hashes on GET.
	 */
	public function test_get_cloud_print_returns_printers_without_token_hash(): void {
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers'    => array(
					array(
						'id'              => 'p1',
						'protocol'        => 'star-cloudprnt',
						'poll_token_hash' => 'abc',
					),
				),
				'assignments' => array(),
			)
		);

		$data = rest_do_request( $this->wp_rest_get_request( '/wcpos/v1/settings/cloud-print' ) )->get_data();

		$this->assertEquals( 'p1', $data['printers'][0]['id'] );
		$this->assertEquals( false, isset( $data['printers'][0]['poll_token_hash'] ) );
	}

	/**
	 * It returns a one-time plaintext token but persists only its hash.
	 */
	public function test_update_generates_token_once_and_stores_only_hash(): void {
		$request = $this->wp_rest_post_request( '/wcpos/v1/settings/cloud-print' );
		$request->set_body_params(
			array(
				'printers'    => array(
					array(
						'id'       => 'kitchen',
						'name'     => 'Kitchen',
						'protocol' => 'star-cloudprnt',
					),
				),
				'assignments' => array(
					array(
						'printer_id' => 'kitchen',
						'scope'      => 'pos',
						'format'     => 'starprnt',
					),
				),
			)
		);

		$data  = rest_do_request( $request )->get_data();
		$saved = get_option( 'woocommerce_pos_settings_cloud_print' );

		$this->assertNotEmpty( $data['generated']['kitchen'] );
		$this->assertNotEmpty( $saved['printers'][0]['poll_token_hash'] );
		$this->assertEquals( false, isset( $saved['printers'][0]['poll_token'] ) );
		$this->assertEquals( 'pos', $saved['assignments'][0]['scope'] );
	}
}
