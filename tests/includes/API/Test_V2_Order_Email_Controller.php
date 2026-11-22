<?php
/**
 * Tests for the wcpos/v2 order-email service.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;

/**
 * The wcpos/v2 order-email registration and route-boundary tests.
 */
class Test_V2_Order_Email_Controller extends WCPOS_REST_Unit_Test_Case {
	use HPOSToggleTrait;

	private $cot_enabled = false;
	/**
	 * Enable the sync surface before REST routes are registered.
	 */
	public function setUp(): void {
		parent::setUp();
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		if ( $this->cot_enabled ) {
			$this->clean_up_cot_setup();
			remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		}
		parent::tearDown();
	}

	/**
	 * The mail transport receives the chosen design and its order data.
	 *
	 * @dataProvider order_storage
	 * @param bool $hpos Whether to use order tables.
	 */
	public function test_selected_design_reaches_mail_as_pdf( bool $hpos ): void {
		if ( $hpos ) {
			add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
			$this->setup_cot();
			$this->cot_enabled = true;
		}
		$order = OrderHelper::create_order();
		$selected = $this->factory->post->create(
			array(
				'post_type' => 'wcpos_template',
				'post_status' => 'publish',
				'post_title' => 'Second design',
				'post_content' => '<p>AMBER-SLIP {{order.number}}</p>',
			)
		);
		wp_set_object_terms( $selected, 'receipt', 'wcpos_template_type' );
		update_post_meta( $selected, '_template_engine', 'logicless' );
		$pdf = '';
		$path = '';
		$capture = static function ( $result, $message ) use ( &$pdf, &$path ) {
			$path = reset( $message['attachments'] );
			$pdf = file_get_contents( $path );
			return true;
		};
		add_filter( 'pre_wp_mail', $capture, 10, 2 );
		try {
			$request = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/receipt-email' );
			$request->set_body_params(
				array(
					'email' => 'fixture@example.org',
					'template_id' => (string) $selected,
				)
			);
			$response = $this->server->dispatch( $request );
		} finally {
			remove_filter( 'pre_wp_mail', $capture, 10 );
		}
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringStartsWith( '%PDF-', $pdf );
		preg_match_all( '/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams );
		$text = '';
		foreach ( $streams[1] as $stream ) {
			$decoded = @gzuncompress( $stream );
			$text .= false === $decoded ? $stream : $decoded;
		}
		$this->assertStringContainsString( mb_convert_encoding( 'AMBER-SLIP ' . $order->get_order_number(), 'UTF-16BE', 'UTF-8' ), $text );
		$this->assertFileDoesNotExist( $path );
	}

	/** Both order storage backends supported by WooCommerce. */
	public function order_storage(): array {
		return array(
			'posts' => array( false ),
			'HPOS' => array( true ),
		);
	}

	/** A selected-template send cannot use unpublished, other-type, or malformed designs. */
	public function test_unavailable_and_invalid_designs_do_not_send(): void {
		$order = OrderHelper::create_order();
		$template = $this->factory->post->create(
			array(
				'post_type' => 'wcpos_template',
				'post_status' => 'draft',
				'post_content' => 'invalid thermal document',
			)
		);
		update_post_meta( $template, '_template_engine', 'thermal' );
		wp_set_object_terms( $template, 'receipt', 'wcpos_template_type' );
		$request = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/receipt-email' );
		$request->set_body_params(
			array(
				'template_id' => (string) $template,
				'email' => 'one@example.org,two@example.org',
			)
		);
		$this->assertSame( 400, $this->server->dispatch( $request )->get_status() );
		$request->set_param( 'email', 'fixture@example.org' );
		$this->assertSame( 404, $this->server->dispatch( $request )->get_status() );
		wp_set_object_terms( $template, 'display', 'wcpos_template_type' );
		wp_update_post(
			array(
				'ID' => $template,
				'post_status' => 'publish',
			)
		);
		$this->assertSame( 404, $this->server->dispatch( $request )->get_status() );
		wp_set_object_terms( $template, 'receipt', 'wcpos_template_type' );
		$this->assertSame( 422, $this->server->dispatch( $request )->get_status() );
	}

	/** An order's removed store cannot supply a receipt design. */
	public function test_order_with_missing_store_denies_send(): void {
		$order = OrderHelper::create_order();
		$order->update_meta_data( '_pos_store', '999999' );
		$order->save();
		$request = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/receipt-email' );
		$request->set_body_params(
			array(
				'template_id' => 'missing',
				'email' => 'fixture@example.org',
			)
		);
		$this->assertSame( 403, $this->server->dispatch( $request )->get_status() );
	}

	/** The picker store and order store must both be within the cashier's reach. */
	public function test_selected_receipt_checks_both_stores(): void {
		$order = OrderHelper::create_order();
		$order->update_meta_data( '_pos_store', '31' );
		$order->save();
		$request = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/receipt-email' );
		$request->set_body_params(
			array(
				'template_id' => 'removed',
				'email' => 'fixture@example.org',
				'store_id' => 32,
			)
		);
		$stores = static function () {
			$store = new \WCPOS\WooCommercePOS\Abstracts\Store();
			$store->set_id( 31 );
			return array( $store );
		};
		add_filter( 'wcpos_cashier_accessible_stores', $stores );
		try {
			$this->assertSame( 403, $this->server->dispatch( $request )->get_status() );
			$request->set_param( 'store_id', 31 );
			$this->assertSame( 'wcpos_receipt_template_not_found', $this->server->dispatch( $request )->get_data()['code'] );
			wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );
			$this->assertSame( 403, $this->server->dispatch( $request )->get_status() );
		} finally {
			remove_filter( 'wcpos_cashier_accessible_stores', $stores );
		}
	}

	/** A transport refusal or exception leaves neither the attachment nor sender hooks behind. */
	public function test_mail_failure_cleans_up_receipt(): void {
		$order = OrderHelper::create_order();
		$template = $this->factory->post->create(
			array(
				'post_type' => 'wcpos_template',
				'post_status' => 'publish',
				'post_content' => '<p>Example</p>',
			)
		);
		wp_set_object_terms( $template, 'receipt', 'wcpos_template_type' );
		update_post_meta( $template, '_template_engine', 'logicless' );
		$request = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/receipt-email' );
		$request->set_body_params(
			array(
				'template_id' => (string) $template,
				'email' => 'fixture@example.org',
			)
		);
		foreach ( array( false, true ) as $throws ) {
			$path = '';
			$sender = null;
			$reject = static function ( $callback, $email ) use ( &$path, &$sender, $throws ) {
				$sender = $email;
				return static function ( $to, $subject, $body, $headers, $attachments ) use ( &$path, $throws ) {
					$path = reset( $attachments );
					if ( $throws ) {
						throw new \RuntimeException( 'Transport refused.' );
					}
					return false;
				};
			};
			add_filter( 'woocommerce_mail_callback', $reject, 10, 2 );
			try {
				$this->assertSame( 'wcpos_receipt_email_failed', $this->server->dispatch( $request )->get_data()['code'] );
			} finally {
				remove_filter( 'woocommerce_mail_callback', $reject, 10 );
			}
			$this->assertNotEmpty( $path );
			$this->assertFileDoesNotExist( $path );
			$this->assertFalse( has_filter( 'wp_mail_from', array( $sender, 'get_from_address' ) ) );
		}
	}

	/**
	 * Losing a selected design before the queue drains must leave the order and mail alone.
	 */
	public function test_deleted_selected_template_refuses_send_and_preserves_billing(): void {
		$order = OrderHelper::create_order();
		$recipient = 'new-address@example.org';
		$before = $order->get_billing_email();
		$messages = array();
		$capture = static function ( $result, $message ) use ( &$messages ) {
			$messages[] = $message;
			return true;
		};
		add_filter( 'pre_wp_mail', $capture, 10, 2 );
		try {
			$request = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/receipt-email' );
			$request->set_body_params(
				array(
					'email' => $recipient,
					'template_id' => 'removed-template',
					'save_to' => 'billing',
				)
			);
			$response = $this->server->dispatch( $request );
		} finally {
			remove_filter( 'pre_wp_mail', $capture, 10 );
		}

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'wcpos_receipt_template_not_found', $response->get_data()['code'] );
		$this->assertSame( array(), $messages );
		$this->assertSame( $before, wc_get_order( $order->get_id() )->get_billing_email() );
	}

	/**
	 * A manual invoice to a different address must not redirect a later order.
	 */
	public function test_manual_invoice_override_leaves_the_next_order_recipient_alone(): void {
		$order = OrderHelper::create_order();
		$next = OrderHelper::create_order();
		$next->set_billing_email( 'next-order@example.org' );
		$next->save();

		$recipients = array();
		$capture = static function ( $result, $message ) use ( &$recipients ) {
			$recipients[] = $message['to'];
			return true;
		};
		add_filter( 'pre_wp_mail', $capture, 10, 2 );
		try {
			$request = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/email' );
			$request->set_body_params( array( 'email' => 'manual@example.org' ) );
			$this->assertSame( 200, $this->server->dispatch( $request )->get_status() );
			WC()->mailer()->customer_invoice( $next );
		} finally {
			remove_filter( 'pre_wp_mail', $capture, 10 );
		}

		$this->assertSame( array( 'manual@example.org', 'next-order@example.org' ), $recipients );
	}

	/**
	 * The promoted email action registers and matches the authorized v1 gate.
	 */
	public function test_v2_order_email_route_matches_v1_authorized_status(): void {
		$route_pattern = 'orders/(?P<order_id>[\d]+)/email';
		$this->assertArrayHasKey( '/wcpos/v1/' . $route_pattern, $this->server->get_routes( 'wcpos/v1' ) );
		$this->assertArrayHasKey( '/wcpos/v2/' . $route_pattern, $this->server->get_routes( 'wcpos/v2' ) );

		$email_attempted = false;
		$email_callback  = function () use ( &$email_attempted ): void {
			$email_attempted = true;
		};
		add_action( 'woocommerce_before_resend_order_emails', $email_callback );

		$order       = OrderHelper::create_order();
		$v1_request  = $this->wp_rest_post_request( '/wcpos/v1/orders/' . $order->get_id() . '/email' );
		$v1_request->set_body_params( array( 'email' => 'v2-service-test@example.com' ) );
		$v1_response  = $this->server->dispatch( $v1_request );
		$v1_attempted = $email_attempted;

		$email_attempted = false;
		$v2_request      = $this->wp_rest_post_request( '/wcpos/v2/orders/' . $order->get_id() . '/email' );
		$v2_request->set_body_params( array( 'email' => 'v2-service-test@example.com' ) );
		$v2_response  = $this->server->dispatch( $v2_request );
		$v2_attempted = $email_attempted;

		remove_action( 'woocommerce_before_resend_order_emails', $email_callback );

		$this->assertSame( 200, $v1_response->get_status() );
		$this->assertSame( $v1_response->get_status(), $v2_response->get_status() );
		$this->assertTrue( $v1_attempted );
		$this->assertTrue( $v2_attempted );
	}

	/**
	 * The email routes declare a callable route schema, so the `help` index renders.
	 *
	 * WordPress calls `call_user_func( $options['schema'] )` for every registered
	 * route whenever a namespace index is requested with `context=help`. A route
	 * registered with `'schema' => array()` passes `isset()` and then fatals with a
	 * TypeError, taking the whole `/wcpos/v2` (and `/wcpos/v1`) index down.
	 */
	public function test_order_email_routes_render_help_index_with_route_schema(): void {
		// The help index is keyed by the raw registered pattern, not the `{order_id}` form.
		$route_pattern = '/orders/(?P<order_id>[\d]+)/email';

		foreach ( array( 'wcpos/v2', 'wcpos/v1' ) as $namespace ) {
			$request = $this->wp_rest_get_request( '/' . $namespace );
			$request->set_query_params( array( 'context' => 'help' ) );

			$response = $this->server->dispatch( $request );
			$data     = $response->get_data();

			$this->assertSame( 200, $response->get_status(), $namespace . ' help index' );
			$this->assertArrayHasKey( '/' . $namespace . $route_pattern, $data['routes'], $namespace . ' email route' );

			$schema = $data['routes'][ '/' . $namespace . $route_pattern ]['schema'];
			$this->assertSame( 'object', $schema['type'], $namespace . ' schema type' );
			$this->assertSame( 'boolean', $schema['properties']['success']['type'], $namespace . ' success property' );
		}
	}

	/**
	 * The v2 orders collection stays on sync; legacy data routes stay absent.
	 */
	public function test_v2_orders_keeps_sync_routes_without_legacy_data_pattern(): void {
		$routes = $this->server->get_routes( 'wcpos/v2' );

		$this->assertArrayHasKey( '/wcpos/v2/orders', $routes );
		$this->assertArrayNotHasKey( '/wcpos/v2/orders/(?P<id>[\d]+)', $routes );
		$this->assertArrayHasKey( '/wcpos/v2/orders/(?P<order_id>[\d]+)/email', $routes );
	}
}
