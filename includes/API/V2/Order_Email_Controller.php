<?php
/**
 * WCPOS REST API v2 service pass-through.
 *
 * @package WCPOS\WooCommercePOS\API\V2
 */

namespace WCPOS\WooCommercePOS\API\V2;

use WP_REST_Server;

/**
 * The email ACTION promotes; the data routes stay frozen at v1.
 */
class Order_Email_Controller extends \WCPOS\WooCommercePOS\API\V1\Orders_Controller {
	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wcpos/v2';

	/**
	 * Register the order-email action only.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<order_id>[\d]+)/email',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'wcpos_send_email' ),
					'permission_callback' => array( $this, 'wcpos_send_email_permissions_check' ),
					'args'                => array_merge(
						$this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
						array(
							'email'   => array(
								'type'        => 'string',
								'description' => /* translators: REST API schema field label or error message. */ __( 'Email address', 'woocommerce-pos' ),
								'required'    => true,
							),
							'save_to' => array(
								'type'        => 'string',
								'description' => __( 'Save email to order', 'woocommerce-pos' ),
								'required'    => false,
							),
						)
					),
				),
				'schema' => array(),
			)
		);
	}
}
