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
						'provider'        => 'star-cloudprnt',
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
						'provider' => 'star-cloudprnt',
					),
				),
				'assignments' => array(
					array(
						'printer_id'  => 'kitchen',
						'scope'       => 'pos',
						'template_id' => 'starprnt',
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

	/**
	 * It preserves malformed stored printer rows without exposing token hashes.
	 */
	public function test_get_cloud_print_preserves_malformed_printer_rows(): void {
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers'    => array( 'legacy-row' ),
				'assignments' => array(),
			)
		);

		$data = rest_do_request( $this->wp_rest_get_request( '/wcpos/v1/settings/cloud-print' ) )->get_data();

		$this->assertEquals( 'legacy-row', $data['printers'][0] );
	}

	/**
	 * It rejects duplicate cloud printer ids.
	 */
	public function test_update_rejects_duplicate_printer_ids(): void {
		$request = $this->wp_rest_post_request( '/wcpos/v1/settings/cloud-print' );
		$request->set_body_params(
			array(
				'printers' => array(
					array( 'id' => 'kitchen' ),
					array( 'id' => 'kitchen' ),
				),
			)
		);

		$response = rest_do_request( $request );

		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'wcpos_cloud_print_duplicate_printer_id', $response->get_data()['code'] );
	}

	/**
	 * It derives an immutable id and persists the provider for new printers.
	 */
	public function test_new_printer_gets_derived_id_and_provider(): void {
		$req = $this->wp_rest_post_request( '/wcpos/v1/settings/cloud-print' );
		$req->set_body_params(
			array(
				'printers'    => array( array( 'name' => 'Kitchen Printer', 'provider' => 'star-cloudprnt' ) ),
				'assignments' => array(),
			)
		);
		$res  = rest_do_request( $req );
		$data = $res->get_data();

		$this->assertEquals( 200, $res->get_status() );
		$this->assertEquals( 'kitchen-printer', $data['printers'][0]['id'] );
		$this->assertEquals( 'star-cloudprnt', $data['printers'][0]['provider'] );
		$this->assertArrayNotHasKey( 'poll_token_hash', $data['printers'][0] );
		$this->assertArrayHasKey( 'kitchen-printer', $data['generated'] );
	}

	/**
	 * It preserves an existing printer id when only the name changes.
	 */
	public function test_existing_printer_id_is_preserved_on_rename(): void {
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers'    => array(
					array(
						'id'              => 'kitchen-printer',
						'name'            => 'Kitchen Printer',
						'provider'        => 'star-cloudprnt',
						'store_id'        => 0,
						'poll_token_hash' => \WCPOS\WooCommercePOS\Services\Cloud_Print_Registry::hash_token( 'tok' ),
					),
				),
				'assignments' => array(),
			)
		);
		$req = $this->wp_rest_post_request( '/wcpos/v1/settings/cloud-print' );
		$req->set_body_params(
			array(
				'printers'    => array( array( 'id' => 'kitchen-printer', 'name' => 'Back Kitchen', 'provider' => 'star-cloudprnt' ) ),
				'assignments' => array(),
			)
		);
		$data = rest_do_request( $req )->get_data();

		$this->assertEquals( 'kitchen-printer', $data['printers'][0]['id'] );
		$this->assertEquals( 'Back Kitchen', $data['printers'][0]['name'] );
		$this->assertArrayNotHasKey( 'kitchen-printer', $data['generated'] ?? array() );
	}

	/**
	 * It surfaces connection status and last_seen while stripping secrets.
	 */
	public function test_get_settings_includes_status_and_strips_secrets(): void {
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers'    => array(
					array(
						'id'              => 'kitchen',
						'name'            => 'Kitchen',
						'provider'        => 'star-cloudprnt',
						'store_id'        => 0,
						'poll_token_hash' => \WCPOS\WooCommercePOS\Services\Cloud_Print_Registry::hash_token( 'tok' ),
					),
				),
				'assignments' => array(),
			)
		);
		$data = rest_do_request( $this->wp_rest_get_request( '/wcpos/v1/settings/cloud-print' ) )->get_data();

		$this->assertEquals( 'waiting', $data['printers'][0]['status'] );
		$this->assertArrayHasKey( 'last_seen', $data['printers'][0] );
		$this->assertArrayNotHasKey( 'poll_token_hash', $data['printers'][0] );
	}

}
