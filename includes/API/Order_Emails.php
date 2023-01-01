<?php

namespace WCPOS\WooCommercePOS\API;

use WC_Email_Customer_Invoice;
use WC_Order;
use WC_REST_Controller;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class Order_Emails extends WC_REST_Controller {
	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wc/v3';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'orders/(?P<order_id>[\d]+)/email';

	/**
	 * Post type.
	 *
	 * @var string
	 */
	protected $post_type = 'shop_order';

	/**
	 * Request.
	 *
	 * @var WP_REST_Request
	 */
	private $request;

	
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'send_email' ),
					'permission_callback' => array( $this, 'send_email_permissions_check' ),
					'args'                => array_merge($this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ), array(
						'email'   => array(
							'type'        => 'string',
							'description' => __( 'Email address', 'woocommerce-pos' ),
							'required'    => true,
						),
						'save_to' => array(
							'type'        => 'string',
							'description' => __( 'Save email to order', 'woocommerce-pos' ),
							'required'    => false,
						),
					)),
				),
				'schema' => array(),
			)
		);
	}

	/**
	 * Send order email, optionally add email address.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public function send_email( WP_REST_Request $request ) {
		$this->request = $request;
		$order         = wc_get_order( (int) $request['order_id'] );
		$email         = $request['email'];

		if ( ! $order || $this->post_type !== $order->get_type() ) {
			return new WP_Error( 'woocommerce_rest_order_invalid_id', __( 'Invalid order ID.', 'woocommerce' ), array( 'status' => 404 ) );
		}

		if ( 'billing' == $request['save_to'] ) {
			$order->set_billing_email( $email );
			$order->save();
			$order->add_order_note( sprintf( __( 'Email address %s added to billing details from WooCommerce POS.', 'woocommerce-pos' ), $email ), false, true );
		}

		do_action( 'woocommerce_before_resend_order_emails', $order, 'customer_invoice' );
		add_filter( 'woocommerce_email_recipient_customer_invoice', array( $this, 'recipient_email_address' ), 10, 3 );

		// Send the customer invoice email.
		WC()->payment_gateways();
		WC()->shipping();
		WC()->mailer()->customer_invoice( $order );

		// Note the event.
		$order->add_order_note( sprintf( __( 'Order details manually sent to %s from WooCommerce POS.', 'woocommerce-pos' ), $email ), false, true );

		do_action( 'woocommerce_after_resend_order_email', $order, 'customer_invoice' );

		$request->set_param( 'context', 'edit' );

		return rest_ensure_response( array( 'success' => true ) );

		//		$response->set_status( 201 );
	}

	
	public function send_email_permissions_check() {
		if ( ! wc_rest_check_post_permissions( $this->post_type, 'create' ) ) {
			return new WP_Error( 'woocommerce_rest_cannot_create', __( 'Sorry, you are not allowed to create resources.', 'woocommerce' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return true;
	}

	/**
	 * @param string                    $recipient
	 * @param WC_Order                  $order
	 * @param WC_Email_Customer_Invoice $WC_Email_Customer_Invoice
	 *
	 * @return string
	 */
	public function recipient_email_address( string $recipient, WC_Order $order, WC_Email_Customer_Invoice $WC_Email_Customer_Invoice ) {
		return $this->request['email'];
	}
}
