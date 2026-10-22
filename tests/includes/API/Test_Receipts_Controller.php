<?php
/**
 * Tests for receipts REST controller.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\API\V1\Receipts_Controller;
use WCPOS\WooCommercePOS\Payments\Contract\Completion_Stamp;
use WCPOS\WooCommercePOS\Services\Fiscal_Record_Store;
use WCPOS\WooCommercePOS\Services\Receipt_I18n_Labels;
use WCPOS\WooCommercePOS\Tests\Services\Completion_Fixture;
use WCPOS\WooCommercePOS\Tests\Services\Register_Fixture;

/**
 * Test_Receipts_Controller class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Receipts_Controller extends WCPOS_REST_Unit_Test_Case {
	use Register_Fixture;

	/**
	 * Endpoint instance.
	 *
	 * @var Receipts_Controller
	 */
	protected $endpoint;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->endpoint = new Receipts_Controller();
	}

	/**
	 * Test route registration.
	 */
	public function test_register_routes(): void {
		$routes = $this->server->get_routes();

		$this->assertArrayHasKey( '/wcpos/v1/receipts/(?P<order_id>[\d]+)', $routes );
		$this->assertArrayHasKey( '/wcpos/v1/receipts/(?P<order_id>[\d]+)/pdf', $routes );
	}

	/**
	 * Test fiscal mode returns snapshot payload.
	 */
	public function test_get_item_fiscal_mode_returns_snapshot(): void {
		$order = OrderHelper::create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->save();
		\WCPOS\WooCommercePOS\Tests\Services\Completion_Fixture::sale( $order );

		$request = $this->wp_rest_get_request( '/wcpos/v1/receipts/' . $order->get_id() );
		$request->set_param( 'mode', 'fiscal' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();

		$this->assertEquals( 'fiscal', $data['mode'] );
		$this->assertTrue( $data['has_snapshot'] );
		$this->assertArrayNotHasKey( 'receipt', $data['data'] );
		$this->assertNotEmpty( $data['data']['fiscal']['receipt_number'] );
	}

	/** Fiscal reads thaw labels and no longer expose submission state. */
	public function test_get_item_fiscal_mode_returns_document_with_labels(): void {
		$order = OrderHelper::create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->save();
		\WCPOS\WooCommercePOS\Tests\Services\Completion_Fixture::sale( $order );
		$request = $this->wp_rest_get_request( '/wcpos/v2/receipts/' . $order->get_id() );
		$request->set_param( 'mode', 'fiscal' );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		$this->assertTrue( $body['has_snapshot'] );
		$this->assertArrayNotHasKey( 'submission_status', $body );
		$this->assertSame( Receipt_I18n_Labels::get_labels( $body['data']['presentation_hints']['locale'] ), $body['data']['i18n'] );
		$unpaid = OrderHelper::create_order();
		$request = $this->wp_rest_get_request( '/wcpos/v2/receipts/' . $unpaid->get_id() );
		$request->set_param( 'mode', 'fiscal' );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'wcpos_receipt_snapshot_missing', $response->get_data()['code'] );
		$request->set_param( 'mode', 'live' );
		$this->assertFalse( $this->server->dispatch( $request )->get_data()['has_snapshot'] );
	}

	/** Reprint labels use the stored locale, not stale sale-time labels. */
	public function test_document_thaws_labels_for_the_stored_locale(): void {
		global $wpdb;
		$order = OrderHelper::create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->save();
		\WCPOS\WooCommercePOS\Tests\Services\Completion_Fixture::sale( $order );
		$store = new Fiscal_Record_Store();
		$sale = $store->find_sale( $order->get_id() );
		$payload = $sale['payload'];
		$payload['presentation_hints']['locale'] = 'fr_FR';
		$payload['i18n'] = array( 'order' => 'Stale label' );
		$wpdb->update( $store->table_name(), array( 'payload' => wp_json_encode( $payload ) ), array( 'id' => $sale['id'] ) );
		$request = $this->wp_rest_get_request( '/wcpos/v2/receipts/' . $order->get_id() );
		$request->set_param( 'mode', 'fiscal' );
		// Advertise the test locale without downloading a language pack, and make
		// labels visibly locale-dependent so an English fallback cannot pass.
		global $wp_locale_switcher;
		$languages = new \ReflectionProperty( \WP_Locale_Switcher::class, 'available_languages' );
		$languages->setAccessible( true );
		$available = $languages->getValue( $wp_locale_switcher );
		$languages->setValue( $wp_locale_switcher, array_merge( $available, array( 'fr_FR' ) ) );
		$translate = static function () {
			return 'Label:' . get_locale();
		};
		add_filter( 'gettext_woocommerce-pos', $translate );
		try {
			$response = $this->server->dispatch( $request );
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( 'Label:fr_FR', $response->get_data()['data']['i18n']['order'] );
			$this->assertSame( Receipt_I18n_Labels::get_labels( 'fr_FR' ), $response->get_data()['data']['i18n'] );
		} finally {
			remove_filter( 'gettext_woocommerce-pos', $translate );
			$languages->setValue( $wp_locale_switcher, $available );
		}
	}

	/**
	 * Test live mode returns recalculated payload.
	 */
	public function test_get_item_live_mode_returns_payload(): void {
		$order = OrderHelper::create_order();

		$request = $this->wp_rest_get_request( '/wcpos/v1/receipts/' . $order->get_id() );
		$request->set_param( 'mode', 'live' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'live', $data['mode'] );
		$this->assertArrayHasKey( 'totals', $data['data'] );
	}

	/**
	 * A live receipt after a reopen and a second completion at another register names that
	 * completion's register, counter and sale time, as its fiscal record does (ADR 0040 rule 1c).
	 */
	public function test_live_receipt_after_a_second_completion_names_its_register(): void {
		// Arrange: completed at register A (the write-once till tuple), reopened, completed again at B.
		$this->use_multi_register_repository();
		$a = $this->create_register(
			array(
				'name'     => 'Register A',
				'store_id' => 12,
			)
		);
		$b = $this->create_register(
			array(
				'name'     => 'Register B',
				'store_id' => 12,
			)
		);
		$order = OrderHelper::create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->update_meta_data( '_wcpos_register', $a['id'] );
		$order->update_meta_data( '_wcpos_sale_counter', '1' );
		$order->update_meta_data( '_wcpos_sale_time', '2026-10-20T12:00:00+00:00' );
		$order->update_meta_data( '_wcpos_sale_tz', 'UTC' );
		$order->save();
		Completion_Fixture::sale( $order );
		Completion_Fixture::reopen( $order );
		Completion_Fixture::seed( $order );
		$history                 = Completion_Stamp::read( $order );
		$history[1]['register']  = $b['id'];
		$history[1]['counter']   = '7';
		$history[1]['sale_time'] = '2026-10-21T12:00:00+00:00';
		$history[1]['sale_tz']   = 'UTC';
		$order->update_meta_data( Completion_Stamp::META_KEY, wp_json_encode( $history ) );
		$order->save_meta_data();
		$request = $this->wp_rest_get_request( '/wcpos/v1/receipts/' . $order->get_id() );
		$request->set_param( 'mode', 'live' );

		// Act.
		$response = $this->server->dispatch( $request );

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data()['data'];
		$this->assertSame( $b['id'], $data['register']['id'] );
		$this->assertSame( 'Register B', $data['register']['name'] );
		$this->assertSame( 7, $data['fiscal']['sale_counter'] );
		$this->assertSame( '2026-10-21', $data['fiscal']['sale_time']['date_ymd'] );
	}

	/**
	 * Test mode validation rejects invalid values.
	 */
	public function test_get_item_rejects_invalid_mode(): void {
		$order = OrderHelper::create_order();

		$request = $this->wp_rest_get_request( '/wcpos/v1/receipts/' . $order->get_id() );
		$request->set_param( 'mode', 'broken' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * Test endpoint requires auth.
	 */
	public function test_get_item_requires_authentication(): void {
		$order = OrderHelper::create_order();
		wp_set_current_user( 0 );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/receipts/' . $order->get_id() );
		$response = $this->server->dispatch( $request );
		$this->assertEquals( 401, $response->get_status() );
	}

	/**
	 * Test endpoint returns a permission error for users without POS access.
	 */
	public function test_get_item_returns_permission_error_when_user_cannot_access_pos(): void {
		$order         = OrderHelper::create_order();
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$request  = $this->wp_rest_get_request( '/wcpos/v1/receipts/' . $order->get_id() );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 403, $response->get_status() );
		$this->assertEquals( 'wcpos_rest_insufficient_permissions', $data['code'] );
	}

	/**
	 * Test cashiers can retrieve live receipts.
	 */
	public function test_get_item_allows_cashier(): void {
		$order      = OrderHelper::create_order();
		$cashier_id = $this->factory->user->create( array( 'role' => 'cashier' ) );
		wp_set_current_user( $cashier_id );

		$request = $this->wp_rest_get_request( '/wcpos/v1/receipts/' . $order->get_id() );
		$request->set_param( 'mode', 'live' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * Test PDF endpoint returns downloadable PDF bytes.
	 */
	public function test_get_receipt_pdf_returns_pdf_bytes(): void {
		$order       = OrderHelper::create_order();
		$template_id = $this->create_receipt_template();

		$request = $this->wp_rest_get_request( '/wcpos/v1/receipts/' . $order->get_id() . '/pdf' );
		$request->set_param( 'template_id', (string) $template_id );
		$response = $this->server->dispatch( $request );
		$headers  = $response->get_headers();
		$body     = $this->serve_raw_response_body( $response, $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 'application/pdf', $headers['Content-Type'] );
		$this->assertEquals( 'attachment; filename="receipt-' . $order->get_id() . '.pdf"', $headers['Content-Disposition'] );
		$this->assertStringStartsWith( '%PDF-', $body );
		$this->assertEquals( (string) \strlen( $body ), $headers['Content-Length'] );
	}


	/**
	 * Test PDF endpoint returns WP Overnight's native PDF bytes for integration templates.
	 */
	public function test_get_receipt_pdf_wp_overnight_invoice_uses_native_pdf_bytes(): void {
		add_filter( 'woocommerce_pos_wp_overnight_pdf_templates_enabled', '__return_true' );

		$native_pdf = "%PDF-1.4\n% native wp overnight invoice from POS download\n";
		$callback   = static function () use ( $native_pdf ) {
			return new class( $native_pdf ) {
				private $pdf;

				public function __construct( string $pdf ) {
					$this->pdf = $pdf;
				}

				public function get_pdf(): string {
					return $this->pdf;
				}
			};
		};
		add_filter( 'woocommerce_pos_wp_overnight_pdf_document', $callback, 10, 3 );

		try {
			$order = OrderHelper::create_order();

			$request = $this->wp_rest_get_request( '/wcpos/v1/receipts/' . $order->get_id() . '/pdf' );
			$request->set_param( 'template_id', 'wp-overnight-invoice' );
			$response = $this->server->dispatch( $request );
			$headers  = $response->get_headers();
			$body     = $this->serve_raw_response_body( $response, $request );

			$this->assertEquals( 200, $response->get_status() );
			$this->assertEquals( 'application/pdf', $headers['Content-Type'] );
			$this->assertEquals( 'attachment; filename="receipt-' . $order->get_id() . '.pdf"', $headers['Content-Disposition'] );
			$this->assertSame( $native_pdf, $body );
			$this->assertEquals( (string) \strlen( $native_pdf ), $headers['Content-Length'] );
		} finally {
			remove_filter( 'woocommerce_pos_wp_overnight_pdf_document', $callback, 10 );
			remove_filter( 'woocommerce_pos_wp_overnight_pdf_templates_enabled', '__return_true' );
		}
	}

	/**
	 * Test PDF endpoint sets no-store cache header.
	 */
	public function test_get_receipt_pdf_sets_no_store_cache_header(): void {
		$order       = OrderHelper::create_order();
		$template_id = $this->create_receipt_template();

		$request = $this->wp_rest_get_request( '/wcpos/v1/receipts/' . $order->get_id() . '/pdf' );
		$request->set_param( 'template_id', (string) $template_id );
		$response = $this->server->dispatch( $request );
		$headers  = $response->get_headers();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 'no-store', $headers['Cache-Control'] );
	}

	/**
	 * Test PDF endpoint returns 404 for unknown order.
	 */
	public function test_get_receipt_pdf_unknown_order_returns_404(): void {
		$template_id = $this->create_receipt_template();

		$request = $this->wp_rest_get_request( '/wcpos/v1/receipts/999999/pdf' );
		$request->set_param( 'template_id', (string) $template_id );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 404, $response->get_status() );
	}

	/**
	 * Test PDF endpoint returns 404 for unknown template.
	 */
	public function test_get_receipt_pdf_unknown_template_returns_404(): void {
		$order = OrderHelper::create_order();

		$request = $this->wp_rest_get_request( '/wcpos/v1/receipts/' . $order->get_id() . '/pdf' );
		$request->set_param( 'template_id', '999999' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 404, $response->get_status() );
	}

	/**
	 * Test PDF endpoint rejects an empty template id.
	 */
	public function test_get_receipt_pdf_empty_template_id_returns_400(): void {
		$order = OrderHelper::create_order();

		$request = $this->wp_rest_get_request( '/wcpos/v1/receipts/' . $order->get_id() . '/pdf' );
		$request->set_param( 'template_id', '   ' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * Test PDF endpoint requires POS access.
	 */
	public function test_get_receipt_pdf_requires_capability(): void {
		$order         = OrderHelper::create_order();
		$template_id   = $this->create_receipt_template();
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$request = $this->wp_rest_get_request( '/wcpos/v1/receipts/' . $order->get_id() . '/pdf' );
		$request->set_param( 'template_id', (string) $template_id );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Create a minimal receipt template for PDF endpoint tests.
	 *
	 * @return int Template post ID.
	 */
	private function create_receipt_template(): int {
		$post_id = $this->factory->post->create(
			array(
				'post_type'    => 'wcpos_template',
				'post_status'  => 'publish',
				'post_title'   => 'PDF Receipt',
			)
		);

		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			array( 'post_content' => '<receipt paper-width="48"><text>Order {{order.number}}</text></receipt>' ),
			array( 'ID' => $post_id ),
			array( '%s' ),
			array( '%d' )
		);
		clean_post_cache( $post_id );

		wp_set_object_terms( $post_id, 'receipt', 'wcpos_template_type' );
		update_post_meta( $post_id, '_template_engine', 'thermal' );
		update_post_meta( $post_id, '_template_output_type', 'html' );
		update_post_meta( $post_id, '_template_language', 'xml' );
		update_post_meta( $post_id, '_template_tax_display', 'default' );

		return $post_id;
	}

	/**
	 * Capture raw REST bytes emitted through rest_pre_serve_request.
	 *
	 * @param \WP_REST_Response $response Response object.
	 * @param \WP_REST_Request  $request  Request object.
	 *
	 * @return string
	 */
	private function serve_raw_response_body( \WP_REST_Response $response, \WP_REST_Request $request ): string {
		ob_start();
		apply_filters( 'rest_pre_serve_request', false, $response, $request, $this->server );
		$body = ob_get_clean();

		return false === $body ? '' : $body;
	}

	/** Count only explicit prints, across live and immutable fiscal payloads. */
	public function test_print_intent_counts_without_marking_plain_reads_or_snapshot(): void {
		foreach ( array( 'live', 'fiscal' ) as $mode ) {
			$order = OrderHelper::create_order();
			$order->set_created_via( 'woocommerce-pos' );
			$order->save();
			$store = new Fiscal_Record_Store();
			\WCPOS\WooCommercePOS\Tests\Services\Completion_Fixture::sale( $order );
			$snapshot = $store->find_sale( $order->get_id() )['payload'];
			$route = '/wcpos/v2/receipts/' . $order->get_id();
			foreach ( array( array( 'print', false, 0, 1 ), array( null, false, 0, 1 ), array( 'print', true, 1, 2 ), array( null, false, 0, 2 ) ) as $case ) {
				$request = $this->wp_rest_get_request( $route );
				$request->set_param( 'mode', $mode );
				if ( null !== $case[0] ) {
					$request->set_param( 'intent', $case[0] );
				}
				$response = $this->server->dispatch( $request );
				$this->assertSame( 200, $response->get_status() );
				$fiscal = $response->get_data()['data']['fiscal'];
				$this->assertSame( $case[1], $fiscal['is_reprint'] );
				$this->assertSame( $case[2], $fiscal['reprint_count'] );
				$this->assertSame( $case[3], (int) wc_get_order( $order->get_id() )->get_meta( '_wcpos_receipt_print_count' ) );
			}
			$response = $this->server->dispatch( $this->wp_rest_post_request( $route . '/print' ) );
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( array( 'print_count' => 3, 'is_reprint' => true, 'reprint_count' => 2 ), $response->get_data() );
			$this->assertSame( $snapshot, $store->find_sale( $order->get_id() )['payload'] );
		}
	}

	/** The base registration also exposes counting in the v2 namespace. */
	public function test_v2_print_route_counts(): void {
		$order = OrderHelper::create_order();
		$response = $this->server->dispatch( $this->wp_rest_post_request( '/wcpos/v2/receipts/' . $order->get_id() . '/print' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'print_count' => 1, 'is_reprint' => false, 'reprint_count' => 0 ), $response->get_data() );
	}

	/** PDF downloads count only when explicitly requested as prints. */
	public function test_pdf_print_intent_counts(): void {
		$order = OrderHelper::create_order();
		$template_id = $this->create_receipt_template();
		$order->set_created_via( 'woocommerce-pos' );
		$order->save();
		\WCPOS\WooCommercePOS\Tests\Services\Completion_Fixture::sale( $order );
		foreach ( array( 'live', 'fiscal' ) as $index => $mode ) {
			$request = $this->wp_rest_get_request( '/wcpos/v2/receipts/' . $order->get_id() . '/pdf' );
			$request->set_param( 'template_id', (string) $template_id );
			$request->set_param( 'mode', $mode );
			$request->set_param( 'intent', 'print' );
			$response = $this->server->dispatch( $request );
			$this->assertSame( 200, $response->get_status() );
			$this->assertStringStartsWith( '%PDF-', $this->serve_raw_response_body( $response, $request ) );
			$this->assertSame( $index + 1, (int) wc_get_order( $order->get_id() )->get_meta( '_wcpos_receipt_print_count' ) );
		}
		$request->set_param( 'intent', null );
		$this->assertSame( 200, $this->server->dispatch( $request )->get_status() );
		$this->assertSame( 2, (int) wc_get_order( $order->get_id() )->get_meta( '_wcpos_receipt_print_count' ) );
	}

	/** Invalid intent and unauthorized counting must not update the order. */
	public function test_print_routes_reject_invalid_intent_and_missing_capability(): void {
		$order = OrderHelper::create_order();
		$route = '/wcpos/v2/receipts/' . $order->get_id();
		$request = $this->wp_rest_get_request( $route );
		$request->set_param( 'intent', 'preview' );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 400, $response->get_status(), wp_json_encode( $response->get_data() ) );
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->server->dispatch( $this->wp_rest_post_request( $route . '/print' ) )->get_status() );
		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( '_wcpos_receipt_print_count' ) );
	}

	public function test_refund_document_routes_return_frozen_payload_and_pdf(): void {
		$order = OrderHelper::create_order();
		$refund = new \WC_Order_Refund();
		$refund->set_parent_id( $order->get_id() );
		$refund->set_amount( 5 );
		$refund->save();
		$payload = ( new \WCPOS\WooCommercePOS\Services\Receipt_Data_Builder() )->build_refund_document( $order, $refund, 1, null );
		$payload['order']['number'] = 'FROZEN-REFUND';
		$record = ( new \WCPOS\WooCommercePOS\Services\Fiscal_Record_Store() )->record( array( 'type' => 'refund', 'order_id' => $order->get_id(), 'refund_id' => $refund->get_id(), 'payload' => $payload ) );
		$order->set_customer_note( 'Edited after refund' );
		$order->save();
		foreach ( array( 'v1', 'v2' ) as $lane ) {
			$request = $this->wp_rest_get_request( '/wcpos/' . $lane . '/receipts/' . $order->get_id() );
			$request->set_param( 'document', 'refund:' . $refund->get_id() );
			$request->set_param( 'mode', 'ignored' );
			$response = $this->server->dispatch( $request );
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( 'fiscal', $response->get_data()['mode'] );
			$this->assertSame( ( new Fiscal_Record_Store() )->document( $record ), $response->get_data()['data'] );
			$this->assertSame( Receipt_I18n_Labels::get_labels( $record['payload']['presentation_hints']['locale'] ), $response->get_data()['data']['i18n'] );
		}
		$template_id = $this->create_receipt_template();
		// A live rebuild must not be consulted by either rendering engine.
		$reject_live = static function () { throw new \RuntimeException( 'Unexpected live build' ); };
		add_filter( 'woocommerce_pos_receipt_data', $reject_live );
		try {
			foreach ( array( 'thermal', 'logicless' ) as $engine ) {
				update_post_meta( $template_id, '_template_engine', $engine );
				$request = $this->wp_rest_get_request( '/wcpos/v2/receipts/' . $order->get_id() . '/pdf' );
				$request->set_param( 'template_id', (string) $template_id );
				$request->set_param( 'document', 'refund:' . $refund->get_id() );
				$request->set_param( 'mode', 'live' );
				$response = $this->server->dispatch( $request );
				$this->assertSame( 200, $response->get_status() );
				$this->assertStringStartsWith( '%PDF-', $this->serve_raw_response_body( $response, $request ) );
			}
		} finally {
			remove_filter( 'woocommerce_pos_receipt_data', $reject_live );
		}
		$wrong_order = OrderHelper::create_order();
		foreach ( array( '' , '/pdf' ) as $suffix ) {
			foreach ( array( 'refund:' . $refund->get_id() => 404, 'refund:99999999' => 404, 'sale:1' => 400, 'refund:0' => 400, 'refund:1junk' => 400, '' => 400 ) as $document => $status ) {
				$request = $this->wp_rest_get_request( '/wcpos/v2/receipts/' . $wrong_order->get_id() . $suffix );
				$request->set_param( 'template_id', (string) $template_id );
				$request->set_param( 'document', $document );
				$response = $this->server->dispatch( $request );
				$this->assertSame( $status, $response->get_status(), $document . $suffix );
			}
		}
	}

}
