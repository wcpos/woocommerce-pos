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
	 * It lets POS users read server-owned Cloud Printer targets.
	 */
	public function test_get_cloud_print_allows_pos_access_users_without_settings_management(): void {
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers'    => array(
					array(
						'id'       => 'printnode-test',
						'name'     => 'PrintNode Test',
						'provider' => 'printnode',
					),
				),
				'assignments' => array(),
			)
		);

		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_user_by( 'id', $user_id );
		$user->add_cap( 'access_woocommerce_pos' );
		wp_set_current_user( $user_id );

		$response = rest_do_request( $this->wp_rest_get_request( '/wcpos/v1/settings/cloud-print' ) );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 'printnode-test', $response->get_data()['printers'][0]['id'] );
		wp_delete_user( $user_id );
	}

	/**
	 * It keeps Cloud Print settings writes manager-only.
	 */
	public function test_update_cloud_print_rejects_pos_access_users_without_settings_management(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_user_by( 'id', $user_id );
		$user->add_cap( 'access_woocommerce_pos' );
		wp_set_current_user( $user_id );

		$request = $this->wp_rest_post_request( '/wcpos/v1/settings/cloud-print' );
		$request->set_body_params(
			array(
				'printers'    => array(),
				'assignments' => array(),
			)
		);
		$response = rest_do_request( $request );

		$this->assertEquals( 403, $response->get_status() );
		wp_delete_user( $user_id );
	}

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
	 * It prunes runtime last-seen entries for printers removed on save.
	 */
	public function test_save_prunes_runtime_for_removed_printers(): void {
		$registry = new \WCPOS\WooCommercePOS\Services\Cloud_Print_Registry();
		$registry->record_seen( 'kitchen' );
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers'    => array(
					array(
						'id' => 'kitchen',
						'name' => 'Kitchen',
						'provider' => 'star-cloudprnt',
					),
				),
				'assignments' => array(),
			)
		);

		$req = $this->wp_rest_post_request( '/wcpos/v1/settings/cloud-print' );
		$req->set_body_params(
			array(
				'printers' => array(),
				'assignments' => array(),
			)
		);
		rest_do_request( $req );

		$this->assertEquals( 0, $registry->get_seen( 'kitchen' ) );
	}

	/**
	 * It derives an immutable id and persists the provider for new printers.
	 */
	public function test_new_printer_gets_derived_id_and_provider(): void {
		$req = $this->wp_rest_post_request( '/wcpos/v1/settings/cloud-print' );
		$req->set_body_params(
			array(
				'printers'    => array(
					array(
						'name' => 'Kitchen Printer',
						'provider' => 'star-cloudprnt',
					),
				),
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
				'printers'    => array(
					array(
						'id' => 'kitchen-printer',
						'name' => 'Back Kitchen',
						'provider' => 'star-cloudprnt',
					),
				),
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

	/**
	 * It normalizes stored languages that no Star CloudPRNT printer decodes.
	 *
	 * Two broken shapes exist in the wild: rows saved before the encoding
	 * contract (no language key) and rows where earlier releases materialized
	 * the old 'esc-pos' default into the option. Both must be served
	 * 'star-prnt'; an explicit 'star-line' (Line Mode-only models) survives.
	 */
	public function test_get_settings_normalizes_stored_languages_to_star_prnt(): void {
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers'    => array(
					array(
						'id'       => 'legacy',
						'name'     => 'Legacy Star',
						'provider' => 'star-cloudprnt',
						'store_id' => 0,
					),
					array(
						'id'       => 'materialized',
						'name'     => 'Saved on 1.9.10-1.9.12',
						'provider' => 'star-cloudprnt',
						'store_id' => 0,
						'language' => 'esc-pos',
					),
					array(
						'id'       => 'linemode',
						'name'     => 'TSP650II',
						'provider' => 'star-cloudprnt',
						'store_id' => 0,
						'language' => 'star-line',
					),
				),
				'assignments' => array(),
			)
		);

		$data = rest_do_request( $this->wp_rest_get_request( '/wcpos/v1/settings/cloud-print' ) )->get_data();

		$this->assertEquals( 'star-prnt', $data['printers'][0]['language'] );
		$this->assertEquals( 'star-prnt', $data['printers'][1]['language'] );
		$this->assertEquals( 'star-line', $data['printers'][2]['language'] );
	}

	/**
	 * It honours a star-line fallback override for Line Mode-only fleets.
	 *
	 * The woocommerce_pos_cloud_printer_default_language filter must be able
	 * to rescue a Line Mode-only printer whose stored language is a
	 * materialized esc-pos default.
	 */
	public function test_get_settings_accepts_star_line_filter_override(): void {
		add_filter(
			'woocommerce_pos_cloud_printer_default_language',
			static function (): string {
				return 'star-line';
			}
		);
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers'    => array(
					array(
						'id'       => 'linemode',
						'name'     => 'TSP650II',
						'provider' => 'star-cloudprnt',
						'store_id' => 0,
						'language' => 'esc-pos',
					),
				),
				'assignments' => array(),
			)
		);

		$data = rest_do_request( $this->wp_rest_get_request( '/wcpos/v1/settings/cloud-print' ) )->get_data();

		$this->assertEquals( 'star-line', $data['printers'][0]['language'] );
	}

	/**
	 * It rejects an unsupported filtered fallback and serves star-prnt.
	 */
	public function test_get_settings_rejects_invalid_language_filter_override(): void {
		add_filter(
			'woocommerce_pos_cloud_printer_default_language',
			static function (): string {
				return 'esc-pos';
			}
		);
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers'    => array(
					array(
						'id'       => 'legacy',
						'name'     => 'Legacy Star',
						'provider' => 'star-cloudprnt',
						'store_id' => 0,
					),
				),
				'assignments' => array(),
			)
		);

		$data = rest_do_request( $this->wp_rest_get_request( '/wcpos/v1/settings/cloud-print' ) )->get_data();

		$this->assertEquals( 'star-prnt', $data['printers'][0]['language'] );
	}

	/**
	 * It includes server-owned encoding fields for Star CloudPRNT printers.
	 */
	public function test_star_cloudprnt_encoding_fields_round_trip_with_defaults(): void {
		$req = $this->wp_rest_post_request( '/wcpos/v1/settings/cloud-print' );
		$req->set_body_params(
			array(
				'printers'    => array(
					array(
						'id'                => 'kitchen',
						'name'              => 'Kitchen',
						'provider'          => 'star-cloudprnt',
						'columns'           => 48,
						'language'          => 'esc-pos',
						'autoCut'           => 'false',
						'fullReceiptRaster' => 'true',
					),
					array(
						'id'                => 'bar',
						'name'              => 'Bar',
						'provider'          => 'star-cloudprnt',
						'language'          => 'not-a-language',
						'autoCut'           => 'true',
						'fullReceiptRaster' => 'false',
					),
					array(
						'id'       => 'counter',
						'name'     => 'Counter',
						'provider' => 'star-cloudprnt',
					),
				),
				'assignments' => array(),
			)
		);

		$post_data = rest_do_request( $req )->get_data();
		$saved     = get_option( 'woocommerce_pos_settings_cloud_print' );
		$get_data  = rest_do_request( $this->wp_rest_get_request( '/wcpos/v1/settings/cloud-print' ) )->get_data();

		$this->assertEquals( 48, $post_data['printers'][0]['columns'] );
		$this->assertEquals( 'star-prnt', $post_data['printers'][0]['language'] );
		$this->assertEquals( false, $post_data['printers'][0]['autoCut'] );
		$this->assertEquals( true, $post_data['printers'][0]['fullReceiptRaster'] );
		$this->assertEquals( 'star-prnt', $post_data['printers'][1]['language'] );
		$this->assertEquals( true, $post_data['printers'][1]['autoCut'] );
		$this->assertEquals( false, $post_data['printers'][1]['fullReceiptRaster'] );
		$this->assertEquals( 42, $post_data['printers'][2]['columns'] );
		$this->assertEquals( 'star-prnt', $post_data['printers'][2]['language'] );
		$this->assertEquals( true, $post_data['printers'][2]['autoCut'] );
		$this->assertEquals( false, $post_data['printers'][2]['fullReceiptRaster'] );
		$this->assertEquals( 'star-prnt', $saved['printers'][0]['language'] );
		$this->assertEquals( false, $saved['printers'][0]['autoCut'] );
		$this->assertEquals( true, $saved['printers'][0]['fullReceiptRaster'] );
		$this->assertEquals( true, $saved['printers'][1]['autoCut'] );
		$this->assertEquals( false, $saved['printers'][1]['fullReceiptRaster'] );
		$this->assertEquals( 48, $get_data['printers'][0]['columns'] );
		$this->assertEquals( 'star-prnt', $get_data['printers'][0]['language'] );
		$this->assertEquals( false, $get_data['printers'][0]['autoCut'] );
		$this->assertEquals( true, $get_data['printers'][0]['fullReceiptRaster'] );
		$this->assertEquals( 'star-prnt', $get_data['printers'][1]['language'] );
		$this->assertEquals( true, $get_data['printers'][1]['autoCut'] );
		$this->assertEquals( false, $get_data['printers'][1]['fullReceiptRaster'] );
		$this->assertEquals( 42, $get_data['printers'][2]['columns'] );
		$this->assertEquals( 'star-prnt', $get_data['printers'][2]['language'] );
		$this->assertEquals( true, $get_data['printers'][2]['autoCut'] );
		$this->assertEquals( false, $get_data['printers'][2]['fullReceiptRaster'] );
	}

	/**
	 * It round-trips printnode_format=raw through save and GET.
	 */
	public function test_printnode_format_raw_round_trips_through_save_and_get(): void {
		$req = $this->wp_rest_post_request( '/wcpos/v1/settings/cloud-print' );
		$req->set_body_params(
			array(
				'printers'    => array(
					array(
						'id'               => 'office',
						'name'             => 'Office',
						'provider'         => 'printnode',
						'printnode_format' => 'raw',
					),
				),
				'assignments' => array(),
			)
		);
		$post_data = rest_do_request( $req )->get_data();

		$get_data = rest_do_request( $this->wp_rest_get_request( '/wcpos/v1/settings/cloud-print' ) )->get_data();

		$this->assertEquals( 'raw', $post_data['printers'][0]['printnode_format'] );
		$this->assertEquals( 'raw', $get_data['printers'][0]['printnode_format'] );
	}

	/**
	 * It defaults an invalid or missing printnode_format to pdf.
	 */
	public function test_printnode_format_invalid_is_sanitized_to_pdf(): void {
		$req = $this->wp_rest_post_request( '/wcpos/v1/settings/cloud-print' );
		$req->set_body_params(
			array(
				'printers'    => array(
					array(
						'id'               => 'office',
						'name'             => 'Office',
						'provider'         => 'printnode',
						'printnode_format' => 'bogus',
					),
				),
				'assignments' => array(),
			)
		);
		$data = rest_do_request( $req )->get_data();

		$this->assertEquals( 'pdf', $data['printers'][0]['printnode_format'] );
	}

	/**
	 * It omits printnode_format for non-printnode printers.
	 */
	public function test_printnode_format_absent_for_non_printnode_printer(): void {
		$req = $this->wp_rest_post_request( '/wcpos/v1/settings/cloud-print' );
		$req->set_body_params(
			array(
				'printers'    => array(
					array(
						'id' => 'kitchen',
						'name' => 'Kitchen',
						'provider' => 'star-cloudprnt',
					),
				),
				'assignments' => array(),
			)
		);
		$data = rest_do_request( $req )->get_data();

		$this->assertArrayNotHasKey( 'printnode_format', $data['printers'][0] );
	}

	/**
	 * It strips printnode_api_key while retaining printnode_format on responses.
	 */
	public function test_printnode_api_key_stripped_but_format_retained(): void {
		$req = $this->wp_rest_post_request( '/wcpos/v1/settings/cloud-print' );
		$req->set_body_params(
			array(
				'printers'    => array(
					array(
						'id'               => 'office',
						'name'             => 'Office',
						'provider'         => 'printnode',
						'printnode_api_key' => 'secret-key',
						'printnode_format' => 'raw',
					),
				),
				'assignments' => array(),
			)
		);
		$post_data = rest_do_request( $req )->get_data();
		$get_data  = rest_do_request( $this->wp_rest_get_request( '/wcpos/v1/settings/cloud-print' ) )->get_data();

		$this->assertArrayNotHasKey( 'printnode_api_key', $post_data['printers'][0] );
		$this->assertEquals( 'raw', $post_data['printers'][0]['printnode_format'] );
		$this->assertArrayNotHasKey( 'printnode_api_key', $get_data['printers'][0] );
		$this->assertEquals( 'raw', $get_data['printers'][0]['printnode_format'] );
	}

	/**
	 * It preserves a stored printnode_api_key when a later save omits the key.
	 *
	 * The React app never receives the key on GET, so toggling another field
	 * (e.g. printnode_format) re-POSTs the printer without it. The stored key
	 * must survive so submissions keep working.
	 */
	public function test_printnode_api_key_preserved_when_omitted_on_resave(): void {
		// Arrange — first save establishes the key.
		$req = $this->wp_rest_post_request( '/wcpos/v1/settings/cloud-print' );
		$req->set_body_params(
			array(
				'printers'    => array(
					array(
						'id'                => 'office',
						'name'              => 'Office',
						'provider'          => 'printnode',
						'printnode_api_key' => 'SECRET',
						'printnode_format'  => 'pdf',
					),
				),
				'assignments' => array(),
			)
		);
		rest_do_request( $req );

		// Act — re-save the same printer without the key, toggling the format.
		$req2 = $this->wp_rest_post_request( '/wcpos/v1/settings/cloud-print' );
		$req2->set_body_params(
			array(
				'printers'    => array(
					array(
						'id'               => 'office',
						'name'             => 'Office',
						'provider'         => 'printnode',
						'printnode_format' => 'raw',
					),
				),
				'assignments' => array(),
			)
		);
		rest_do_request( $req2 );

		// Assert — read the option directly because GET strips the key.
		$saved = get_option( 'woocommerce_pos_settings_cloud_print' );
		$this->assertEquals( 'SECRET', $saved['printers'][0]['printnode_api_key'] );
		$this->assertEquals( 'raw', $saved['printers'][0]['printnode_format'] );
	}

	/**
	 * It lets a non-empty incoming key overwrite the stored key (rotation).
	 */
	public function test_printnode_api_key_rotation_overwrites_stored_key(): void {
		// Arrange.
		$req = $this->wp_rest_post_request( '/wcpos/v1/settings/cloud-print' );
		$req->set_body_params(
			array(
				'printers'    => array(
					array(
						'id'                => 'office',
						'name'              => 'Office',
						'provider'          => 'printnode',
						'printnode_api_key' => 'OLD',
					),
				),
				'assignments' => array(),
			)
		);
		rest_do_request( $req );

		// Act.
		$req2 = $this->wp_rest_post_request( '/wcpos/v1/settings/cloud-print' );
		$req2->set_body_params(
			array(
				'printers'    => array(
					array(
						'id'                => 'office',
						'name'              => 'Office',
						'provider'          => 'printnode',
						'printnode_api_key' => 'NEW',
					),
				),
				'assignments' => array(),
			)
		);
		rest_do_request( $req2 );

		// Assert.
		$saved = get_option( 'woocommerce_pos_settings_cloud_print' );
		$this->assertEquals( 'NEW', $saved['printers'][0]['printnode_api_key'] );
	}
	/**
	 * It saves a Star Online printer while stripping the secret key from responses.
	 */
	public function test_star_online_printer_saves_and_strips_key(): void {
		$request = $this->wp_rest_post_request( '/wcpos/v1/settings/cloud-print' );
		$request->set_body_params(
			array(
				'printers' => array(
					array(
						'name'               => 'Star Cloud',
						'provider'           => 'star-online',
						'star_api_key'       => 'KEY-1',
						'star_cloudprnt_url' => 'https://eu-device.stario.online/cloudprnt/kilbot',
						'star_device_id'     => 'abc',
					),
				),
				'assignments' => array(),
			)
		);
		$response = rest_do_request( $request );
		$this->assertEquals( 200, $response->get_status() );
		$this->assertArrayNotHasKey( 'star_api_key', $response->get_data()['printers'][0] );

		$get  = rest_do_request( $this->wp_rest_get_request( '/wcpos/v1/settings/cloud-print' ) );
		$data = $get->get_data();
		$this->assertArrayNotHasKey( 'star_api_key', $data['printers'][0] );
	}

	/**
	 * It rejects Star Online printers missing a device id.
	 */
	public function test_star_online_missing_device_id_is_rejected(): void {
		$request = $this->wp_rest_post_request( '/wcpos/v1/settings/cloud-print' );
		$request->set_body_params(
			array(
				'printers' => array(
					array(
						'name'               => 'Star Cloud',
						'provider'           => 'star-online',
						'star_api_key'       => 'KEY-1',
						'star_cloudprnt_url' => 'https://eu-device.stario.online/cloudprnt/kilbot',
						'star_device_id'     => '',
					),
				),
			)
		);
		$this->assertEquals( 400, rest_do_request( $request )->get_status() );
	}

	/**
	 * It rejects Star Online CloudPRNT URLs with non-allowlisted hosts.
	 */
	public function test_star_online_bad_host_is_rejected(): void {
		$request = $this->wp_rest_post_request( '/wcpos/v1/settings/cloud-print' );
		$request->set_body_params(
			array(
				'printers' => array(
					array(
						'name'               => 'Star Cloud',
						'provider'           => 'star-online',
						'star_api_key'       => 'KEY-1',
						'star_cloudprnt_url' => 'https://evil.example.com/cloudprnt/x',
						'star_device_id'     => 'abc',
					),
				),
			)
		);
		$this->assertEquals( 400, rest_do_request( $request )->get_status() );
	}

	/**
	 * It rejects Star Online CloudPRNT URLs without a non-empty group path.
	 */
	public function test_star_online_missing_group_path_is_rejected(): void {
		$request = $this->wp_rest_post_request( '/wcpos/v1/settings/cloud-print' );
		$request->set_body_params(
			array(
				'printers' => array(
					array(
						'name'               => 'Star Cloud',
						'provider'           => 'star-online',
						'star_api_key'       => 'KEY-1',
						'star_cloudprnt_url' => 'https://eu-device.stario.online/cloudprnt/',
						'star_device_id'     => 'abc',
					),
				),
			)
		);
		$this->assertEquals( 400, rest_do_request( $request )->get_status() );
	}

	/**
	 * It preserves a stored Star Online API key when a later save omits the key.
	 */
	public function test_star_online_api_key_preserved_when_omitted_on_resave(): void {
		$req = $this->wp_rest_post_request( '/wcpos/v1/settings/cloud-print' );
		$req->set_body_params(
			array(
				'printers' => array(
					array(
						'id'                 => 'star',
						'name'               => 'Star Cloud',
						'provider'           => 'star-online',
						'star_api_key'       => 'SECRET',
						'star_cloudprnt_url' => 'https://eu-device.stario.online/cloudprnt/kilbot',
						'star_device_id'     => 'abc',
					),
				),
				'assignments' => array(),
			)
		);
		rest_do_request( $req );

		$req2 = $this->wp_rest_post_request( '/wcpos/v1/settings/cloud-print' );
		$req2->set_body_params(
			array(
				'printers' => array(
					array(
						'id'                 => 'star',
						'name'               => 'Star Cloud',
						'provider'           => 'star-online',
						'star_cloudprnt_url' => 'https://eu-device.stario.online/cloudprnt/kilbot',
						'star_device_id'     => 'abc',
					),
				),
				'assignments' => array(),
			)
		);
		rest_do_request( $req2 );

		$saved = get_option( 'woocommerce_pos_settings_cloud_print' );
		$this->assertEquals( 'SECRET', $saved['printers'][0]['star_api_key'] );
	}

	/**
	 * Sanitize_cloud_assignment defaults store_id to 0 and casts a provided id to int.
	 */
	public function test_cloud_assignment_persists_store_id(): void {
		$method = new \ReflectionMethod( \WCPOS\WooCommercePOS\API\V1\Settings::class, 'sanitize_cloud_assignment' );
		$method->setAccessible( true );
		$settings = new \WCPOS\WooCommercePOS\API\V1\Settings();

		$default = $method->invoke(
			$settings,
			array(
				'printer_id'  => 'kitchen',
				'template_id' => '11',
			)
		);
		$this->assertEquals( 0, $default['store_id'] );

		$with_store = $method->invoke(
			$settings,
			array(
				'printer_id'  => 'kitchen',
				'template_id' => '11',
				'store_id'    => '12',
			)
		);
		$this->assertSame( 12, $with_store['store_id'] );
	}

	/**
	 * Assignment copies round-trip through the Cloud Print settings endpoint.
	 */
	public function test_cloud_assignment_with_copies_round_trips_copies(): void {
		// Arrange.
		$request = $this->wp_rest_post_request( '/wcpos/v1/settings/cloud-print' );
		$request->set_body_params(
			array(
				'printers'    => array(),
				'assignments' => array(
					array(
						'printer_id'  => 'kitchen',
						'scope'       => 'every',
						'template_id' => '11',
						'copies'      => 2,
					),
				),
			)
		);

		// Act.
		rest_do_request( $request );
		$data = rest_do_request( $this->wp_rest_get_request( '/wcpos/v1/settings/cloud-print' ) )->get_data();

		// Assert.
		$this->assertEquals( 2, $data['assignments'][0]['copies'] );
	}

	/**
	 * Assignment copies default to one when omitted.
	 */
	public function test_cloud_assignment_without_copies_sanitizes_to_one(): void {
		// Arrange.
		$request = $this->wp_rest_post_request( '/wcpos/v1/settings/cloud-print' );
		$request->set_body_params(
			array(
				'printers'    => array(),
				'assignments' => array(
					array(
						'printer_id'  => 'kitchen',
						'scope'       => 'every',
						'template_id' => '11',
					),
				),
			)
		);

		// Act.
		$data = rest_do_request( $request )->get_data();

		// Assert.
		$this->assertEquals( 1, $data['assignments'][0]['copies'] );
	}

	/**
	 * Assignment copies below the minimum clamp to one.
	 */
	public function test_cloud_assignment_with_zero_copies_clamps_to_one(): void {
		// Arrange.
		$request = $this->wp_rest_post_request( '/wcpos/v1/settings/cloud-print' );
		$request->set_body_params(
			array(
				'printers'    => array(),
				'assignments' => array(
					array(
						'printer_id'  => 'kitchen',
						'scope'       => 'every',
						'template_id' => '11',
						'copies'      => 0,
					),
				),
			)
		);

		// Act.
		$data = rest_do_request( $request )->get_data();

		// Assert.
		$this->assertEquals( 1, $data['assignments'][0]['copies'] );
	}

	/**
	 * Assignments stored before the copies field existed default to one on read.
	 */
	public function test_legacy_assignment_without_copies_reads_as_one_copy(): void {
		// Arrange: seed the raw option directly, as a pre-upgrade release wrote it.
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers'    => array(
					array(
						'id'       => 'kitchen',
						'name'     => 'Kitchen',
						'provider' => 'epson-sdp',
					),
				),
				'assignments' => array(
					array(
						'printer_id'  => 'kitchen',
						'store_id'    => 0,
						'scope'       => 'every',
						'template_id' => '11',
					),
				),
			)
		);

		// Act.
		$data = rest_do_request( $this->wp_rest_get_request( '/wcpos/v1/settings/cloud-print' ) )->get_data();

		// Assert.
		$this->assertEquals( 1, $data['assignments'][0]['copies'] );
	}

	/**
	 * A malformed top-level assignments value reads as an empty list.
	 */
	public function test_non_array_assignments_read_as_empty_list(): void {
		// Arrange.
		update_option(
			'woocommerce_pos_settings_cloud_print',
			array(
				'printers'    => array(),
				'assignments' => null,
			)
		);

		// Act.
		$data = rest_do_request( $this->wp_rest_get_request( '/wcpos/v1/settings/cloud-print' ) )->get_data();

		// Assert.
		$this->assertEquals( array(), $data['assignments'] );
	}

	/**
	 * Assignment copies above the maximum clamp to five.
	 */
	public function test_cloud_assignment_with_excessive_copies_clamps_to_five(): void {
		// Arrange.
		$request = $this->wp_rest_post_request( '/wcpos/v1/settings/cloud-print' );
		$request->set_body_params(
			array(
				'printers'    => array(),
				'assignments' => array(
					array(
						'printer_id'  => 'kitchen',
						'scope'       => 'every',
						'template_id' => '11',
						'copies'      => 99,
					),
				),
			)
		);

		// Act.
		$data = rest_do_request( $request )->get_data();

		// Assert.
		$this->assertEquals( 5, $data['assignments'][0]['copies'] );
	}
}
