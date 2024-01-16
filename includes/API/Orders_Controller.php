<?php

namespace WCPOS\WooCommercePOS\API;

\defined( 'ABSPATH' ) || die;

if ( ! class_exists( 'WC_REST_Orders_Controller' ) ) {
	return;
}

use Exception;
use WC_Email_Customer_Invoice;
use WC_Abstract_Order;
use WC_Order_Query;
use WC_Order_Item;
use WC_REST_Orders_Controller;
use WCPOS\WooCommercePOS\Logger;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use Automattic\WooCommerce\Utilities\OrderUtil;

use const WCPOS\WooCommercePOS\PLUGIN_NAME;

/**
 * Orders controller class.
 *
 * @NOTE: methods not prefixed with wcpos_ will override WC_REST_Products_Controller methods
 */
class Orders_Controller extends WC_REST_Orders_Controller {
	use Traits\Uuid_Handler;
	use Traits\WCPOS_REST_API;

	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wcpos/v1';

	/**
	 * Store the request object for use in lifecycle methods.
	 *
	 * @var WP_REST_Request
	 */
	protected $wcpos_request;

	/**
	 * Whether we are creating a new order.
	 *
	 * @var bool
	 */
	private $is_creating = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'woocommerce_pos_rest_dispatch_orders_request', array( $this, 'wcpos_dispatch_request' ), 10, 4 );

		if ( method_exists( parent::class, '__construct' ) ) {
			parent::__construct();
		}
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		parent::register_routes();

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
								'description' => __( 'Email address', 'woocommerce-pos' ),
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

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/statuses',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'wcpos_get_order_statuses' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
				'schema' => array( $this, 'wcpos_get_public_order_statuses_schema' ),
			)
		);
	}

	/**
	 * Add custom fields to the order schema.
	 */
	public function get_item_schema() {
		$schema = parent::get_item_schema();

		// Add barcode property to the schema
		$schema['properties']['barcode'] = array(
			'description' => __( 'Barcode', 'woocommerce-pos' ),
			'type'        => 'string',
			'context'     => array( 'view', 'edit' ),
			'readonly'    => false,
		);

		// Check and remove email format validation from the billing property
		if ( isset( $schema['properties']['billing']['properties']['email']['format'] ) ) {
			unset( $schema['properties']['billing']['properties']['email']['format'] );
		}

		// Modify line_items->parent_name to accept 'string' or 'null'
		if ( isset( $schema['properties']['line_items'] ) &&
			   \is_array( $schema['properties']['line_items']['items']['properties'] ) ) {
			$schema['properties']['line_items']['items']['properties']['parent_name']['type'] = array( 'string', 'null' );
		}

				// Check for 'stock_quantity' and allow decimal
		if ( $this->wcpos_allow_decimal_quantities() &&
			   isset( $schema['properties']['line_items'] ) &&
			   \is_array( $schema['properties']['line_items']['items']['properties'] ) ) {
			$schema['properties']['line_items']['items']['properties']['quantity']['type'] = array( 'number' );
		}

		return $schema;
	}


	/**
	 * Create a single order.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public function create_item( $request ) {
		$valid_email = $this->wcpos_validate_billing_email( $request );
		if ( is_wp_error( $valid_email ) ) {
			return $valid_email;
		}

		// Set the creating flag, used in woocommerce_before_order_object_save
		$this->is_creating = true;

		// Proceed with the parent method to handle the creation
		return parent::create_item( $request );
	}

	/**
	 * Update a single order.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public function update_item( $request ) {
		$valid_email = $this->wcpos_validate_billing_email( $request );
		if ( is_wp_error( $valid_email ) ) {
			return $valid_email;
		}

		// Proceed with the parent method to handle the creation
		return parent::update_item( $request );
	}

	/**
	 * Create or update a line item.
	 *
	 * @param array  $posted Line item data.
	 * @param string $action 'create' to add line item or 'update' to update it.
	 * @param object $item   Passed when updating an item. Null during creation.
	 *
	 * @throws WC_REST_Exception Invalid data, server error.
	 *
	 * @return WC_Order_Item_Product
	 */
	public function prepare_line_items( $posted, $action = 'create', $item = null ) {
		$item = parent::prepare_line_items( $posted, $action, $item );

		/**
		 * If you send a variation with meta_data, the meta_data will be duplicated
		 * WooCommerce attempts to delete the duped meta_data in $item->set_product( $variation )
		 * but later it gets added right back in $this->maybe_set_item_meta_data.
		 *
		 * To fix this we check for a variation_id and remove the meta_data before setting the product
		 */
		if ( $item->get_variation_id() ) {
			// Get the product variation
			$variation = wc_get_product( $item->get_variation_id() );
			if ( $variation ) {
				// Get the valid attribute keys and remove 'attribute_' prefix
				$valid_keys = array_map(
					function ( $attribute_name ) {
						return str_replace( 'attribute_', '', $attribute_name );
					},
					array_keys( $variation->get_variation_attributes() )
				);

				// Get existing meta data on the item
				$meta_data   = $item->get_meta_data();
				$unique_keys = array();

				// Iterate over meta data to find and remove duplicates
				foreach ( $meta_data as $index => $meta ) {
					$meta_id = $meta->id;
					$data    = $meta->get_data();
					$key     = $data['key'];

					if ( \in_array( $key, $valid_keys, true ) ) {
						// If the meta data doesn't have an ID, it's considered 'new' and can be replaced by one with an ID.
						if ( ! isset( $unique_keys[ $key ] ) || ( isset( $meta_id ) && ! isset( $unique_keys[ $key ]['id'] ) ) ) {
							$unique_keys[ $key ] = array(
								'index' => $index,
								'id' => $meta_id,
							);
						} else {
							// Remove the duplicate meta data.
							if ( $meta->id ) {
								$item->delete_meta_data_by_mid( $meta->id );
							} else {
								$meta->value = null;
							}
						}
					}
				}
			}
		}

		return $item;
	}

	/**
	 * Validate billing email.
	 * NOTE: we have removed the format check to allow empty email addresses.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return bool|WP_Error
	 */
	public function wcpos_validate_billing_email( WP_REST_Request $request ) {
		// Your custom validation logic for the request data
		$billing = $request['billing'] ?? null;
		$email   = \is_array( $billing ) ? ( $billing['email'] ?? null ) : null;

		if ( ! \is_null( $email ) && '' !== $email && ! is_email( $email ) ) {
			return new \WP_Error(
				'rest_invalid_param',
				// translators: Use default WordPress translation
				__( 'Invalid email address.' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}



	/**
	 * Modify the collection params.
	 */
	public function get_collection_params() {
		$params = parent::get_collection_params();

		if ( isset( $params['per_page'] ) ) {
			$params['per_page']['minimum'] = -1;
		}

		if ( isset( $params['orderby'] ) && \is_array( $params['orderby']['enum'] ) ) {
			$params['orderby']['enum'] = array_merge(
				$params['orderby']['enum'],
				array( 'status', 'customer_id', 'payment_method', 'total' )
			);
		}

		return $params;
	}

	/**
	 * Send order email, optionally add email address.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public function wcpos_send_email( WP_REST_Request $request ) {
		$this->wcpos_request = $request;
		$order               = wc_get_order( (int) $request['order_id'] );
		$email               = $request['email'];

		if ( ! $order || $this->post_type !== $order->get_type() ) {
			return new \WP_Error( 'woocommerce_rest_order_invalid_id', __( 'Invalid order ID.', 'woocommerce' ), array( 'status' => 404 ) );
		}

		if ( 'billing' == $request['save_to'] ) {
			$order->set_billing_email( $email );
			$order->save();
			$order->add_order_note( sprintf( __( 'Email address %s added to billing details from WooCommerce POS.', 'woocommerce-pos' ), $email ), false, true );
		}

		do_action( 'woocommerce_before_resend_order_emails', $order, 'customer_invoice' );
		add_filter( 'woocommerce_email_recipient_customer_invoice', array( $this, 'wcpos_recipient_email_address' ), 10, 3 );

		// Send the customer invoice email.
		WC()->payment_gateways();
		WC()->shipping();
		WC()->mailer()->customer_invoice( $order );

		// Note the event.
		$order->add_order_note( sprintf( __( 'Order details manually sent to %s from WooCommerce POS.', 'woocommerce-pos' ), $email ), false, true );

		do_action( 'woocommerce_after_resend_order_email', $order, 'customer_invoice' );

		$request->set_param( 'context', 'edit' );

		return rest_ensure_response( array( 'success' => true ) );

		// $response->set_status( 201 );
	}

	/**
	 * Send email permissions check.
	 */
	public function wcpos_send_email_permissions_check() {
		if ( ! wc_rest_check_post_permissions( $this->post_type, 'create' ) ) {
			return new WP_Error( 'woocommerce_rest_cannot_create', __( 'Sorry, you are not allowed to create resources.', 'woocommerce' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return true;
	}

	/**
	 * @param string                    $recipient.
	 * @param WC_Abstract_Order         $order.
	 * @param WC_Email_Customer_Invoice $WC_Email_Customer_Invoice.
	 *
	 * @return string
	 */
	public function wcpos_recipient_email_address( string $recipient, WC_Abstract_Order $order, WC_Email_Customer_Invoice $WC_Email_Customer_Invoice ) {
		return $this->wcpos_request['email'];
	}


	/**
	 * Dispatch request to parent controller, or override if needed.
	 *
	 * @param mixed           $dispatch_result Dispatch result, will be used if not empty.
	 * @param WP_REST_Request $request         Request used to generate the response.
	 * @param string          $route           Route matched for the request.
	 * @param array           $handler         Route handler used for the request.
	 */
	public function wcpos_dispatch_request( $dispatch_result, WP_REST_Request $request, $route, $handler ) {
		$this->wcpos_request = $request;
		$this->wcpos_register_wc_rest_api_hooks( $request );
		$params = $request->get_params();

		// Optimised query for getting all product IDs
		if ( isset( $params['posts_per_page'] ) && -1 == $params['posts_per_page'] && isset( $params['fields'] ) ) {
			$dispatch_result = $this->wcpos_get_all_posts( $params['fields'] );
		}

		return $dispatch_result;
	}

	/**
	 *
	 */
	public function wcpos_get_order_statuses() {
		$statuses = wc_get_order_statuses();
		$formatted_statuses = array();

		foreach ( $statuses as $status_key => $status_name ) {
				// Remove the 'wc-' prefix from the status key
				$status_id   = 'wc-' === substr( $status_key, 0, 3 ) ? substr( $status_key, 3 ) : $status_key;

				$formatted_statuses[] = array(
					'id'   => $status_id,
					'name' => $status_name,
				);
		}

		return rest_ensure_response( $formatted_statuses );
	}

	/**
	 *
	 */
	public function wcpos_get_public_order_statuses_schema() {
		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'order_status',
			'type'       => 'object',
			'properties' => array(
				'id' => array(
					'description' => __( 'Unique identifier for the order status.', 'woocommerce-pos-pro' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'name' => array(
					'description' => __( 'Display name of the order status.', 'woocommerce-pos-pro' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
			),
		);

		return $schema;
	}

	/**
	 * Register hooks to modify WC REST API response.
	 *
	 * @param WP_REST_Request $request
	 */
	public function wcpos_register_wc_rest_api_hooks( WP_REST_Request $request ): void {
		add_filter( 'woocommerce_rest_prepare_shop_order_object', array( $this, 'wcpos_order_response' ), 10, 3 );
		add_filter( 'woocommerce_order_get_items', array( $this, 'wcpos_order_get_items' ), 10, 3 );
		add_action( 'woocommerce_before_order_object_save', array( $this, 'wcpos_before_order_object_save' ), 10, 2 );
		add_filter( 'woocommerce_rest_shop_order_object_query', array( $this, 'wcpos_shop_order_query' ), 10, 2 );
	}

	/**
	 * @param WP_REST_Response  $response The response object.
	 * @param WC_Abstract_Order $order    Object data.
	 * @param WP_REST_Request   $request  Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function wcpos_order_response( WP_REST_Response $response, WC_Abstract_Order $order, WP_REST_Request $request ): WP_REST_Response {
		$data = $response->get_data();

		// Add UUID to order
		$this->maybe_add_post_uuid( $order );

		// Add payment link to the order.
		$pos_payment_url = add_query_arg(
			array(
				'pay_for_order' => true,
				'key'           => $order->get_order_key(),
			),
			get_home_url( null, '/wcpos-checkout/order-pay/' . $order->get_id() )
		);

		$response->add_link( 'payment', $pos_payment_url, array( 'foo' => 'bar' ) );

		// Add receipt link to the order.
		$pos_receipt_url = get_home_url( null, '/wcpos-checkout/wcpos-receipt/' . $order->get_id() );
		$response->add_link( 'receipt', $pos_receipt_url );

		// Make sure we parse the meta data before returning the response
		$order->save_meta_data(); // make sure the meta data is saved
		$data['meta_data'] = $this->wcpos_parse_meta_data( $order );

		$response->set_data( $data );
		// $this->log_large_rest_response( $response, $order->get_id() );

		return $response;
	}

	/**
	 * Add UUID to order items.
	 *
	 * NOTE: OrderRefund can also be passed
	 *
	 * @param WC_Order_Item[]   $items     The order items.
	 * @param WC_Abstract_Order $order     The order object.
	 * @param array             $item_type string[] ['line_item' | 'fee' | 'shipping' | 'tax' | 'coupon'].
	 *
	 * @return WC_Order_Item[]
	 */
	public function wcpos_order_get_items( array $items, WC_Abstract_Order $order, array $item_type ): array {
		foreach ( $items as $item ) {
			$this->maybe_add_order_item_uuid( $item );
		}

		return $items;
	}

	/**
	 * Add extra data for woocommerce pos orders.
	 * - Add custom 'created_via' prop for POS orders, used in WC Admin display.
	 *
	 * @param WC_Abstract_Order $order The object being saved.
	 *
	 * @throws WC_Data_Exception
	 */
	public function wcpos_before_order_object_save( WC_Abstract_Order $order ): void {
		if ( $this->is_creating ) {
			$order->set_created_via( PLUGIN_NAME );
		}

		/**
		 * Add cashier user id to order meta
		 * Note: There should only be one cashier per order, currently this will overwrite previous cashier id.
		 */
		$user_id    = get_current_user_id();
		$cashier_id = $order->get_meta( '_pos_user' );

		if ( ! $cashier_id ) {
			$order->update_meta_data( '_pos_user', $user_id );
		}
	}

	/**
	 * Filter the order query.
	 *
	 * @param array           $args    Query arguments.
	 * @param WP_REST_Request $request Request object.
	 */
	public function wcpos_shop_order_query( array $args, WP_REST_Request $request ) {
		// Check for wcpos_include/wcpos_exclude parameter.
		if ( isset( $request['wcpos_include'] ) || isset( $request['wcpos_exclude'] ) ) {
			// Add a custom WHERE clause to the query.
			add_filter( 'posts_where', array( $this, 'wcpos_posts_where_order_include_exclude' ), 10, 2 );
		}

		return $args;
	}

	/**
	 * Filter the WHERE clause of the query.
	 *
	 * @param string $where WHERE clause of the query.
	 * @param object $query The WP_Query instance.
	 *
	 * @return string
	 */
	public function wcpos_posts_where_order_include_exclude( string $where, $query ) {
		global $wpdb;

		// Handle 'wcpos_include'
		if ( ! empty( $this->wcpos_request['wcpos_include'] ) ) {
			$include_ids = array_map( 'intval', (array) $this->wcpos_request['wcpos_include'] );
			$ids_format = implode( ',', array_fill( 0, count( $include_ids ), '%d' ) );
			$where .= $wpdb->prepare( " AND {$wpdb->posts}.ID IN ($ids_format) ", $include_ids );
		}

		// Handle 'wcpos_exclude'
		if ( ! empty( $this->wcpos_request['wcpos_exclude'] ) ) {
			$exclude_ids = array_map( 'intval', (array) $this->wcpos_request['wcpos_exclude'] );
			$ids_format = implode( ',', array_fill( 0, count( $exclude_ids ), '%d' ) );
			$where .= $wpdb->prepare( " AND {$wpdb->posts}.ID NOT IN ($ids_format) ", $exclude_ids );
		}

		return $where;
	}

	/**
	 * Returns array of all order ids.
	 *
	 * @param array $fields
	 *
	 * @return array|WP_Error
	 */
	public function wcpos_get_all_posts( array $fields = array() ): array {
		$args = array(
			'limit'  => -1,
			'return' => 'ids',
			'status' => array_keys( wc_get_order_statuses() ), // Get valid order statuses
		);

		$order_query = new WC_Order_Query( $args );

		try {
			$order_ids = $order_query->get_orders();

			return array_map( array( $this, 'wcpos_format_id' ), $order_ids );
		} catch ( Exception $e ) {
			Logger::log( 'Error fetching order IDs: ' . $e->getMessage() );

			return new \WP_Error(
				'woocommerce_pos_rest_cannot_fetch',
				'Error fetching order IDs.',
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Filters all query clauses at once.
	 * Covers the fields (SELECT), JOIN, WHERE, GROUP BY, ORDER BY, and LIMIT clauses.
	 *
	 * @param string[]         $clauses {
	 *                                  Associative array of the clauses for the query.
	 *
	 * @var string The SELECT clause of the query.
	 * @var string The JOIN clause of the query.
	 * @var string The WHERE clause of the query.
	 * @var string The GROUP BY clause of the query.
	 * @var string The ORDER BY clause of the query.
	 * @var string The LIMIT clause of the query.
	 *             }
	 *
	 * @param OrdersTableQuery $query The OrdersTableQuery instance (passed by reference).
	 * @param array            $args  Query args.
	 *
	 * @return string[] $clauses
	 */
	public function wcpos_hpos_orderby_status_query( array $clauses, $query, $args ) {
		if ( isset( $clauses['orderby'] ) && '' === $clauses['orderby'] ) {
			$order              = $args['order'] ?? 'ASC';
			$clauses['orderby'] = $query->get_table_name( 'orders' ) . '.status ' . $order;
		}

		return $clauses;
	}

	/**
	 * Prepare objects query.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return array|WP_Error
	 */
	protected function prepare_objects_query( $request ) {
		$args = parent::prepare_objects_query( $request );

		// Add custom 'orderby' options
		if ( isset( $request['orderby'] ) ) {
			switch ( $request['orderby'] ) {
				case 'status':
					// NOTE: 'post_status' is not a valid orderby option for WC_Order_Query
					$args['orderby'] = 'post_status';

					break;
				case 'customer_id':
					$args['meta_key'] = '_customer_user';
					$args['orderby']  = 'meta_value_num';

					break;
				case 'payment_method':
					$args['meta_key'] = '_payment_method_title';
					$args['orderby']  = 'meta_value';

					break;
				case 'total':
					$args['meta_key'] = '_order_total';
					$args['orderby']  = 'meta_value';

					break;
			}

			// If HPOS is enabled and $args['orderby'] = 'post_status', we need to add a custom query clause
			if ( class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
				if ( 'status' === $request['orderby'] ) {
					add_filter( 'woocommerce_orders_table_query_clauses', array( $this, 'wcpos_hpos_orderby_status_query' ), 10, 3 );
				}
			}
		}

		return $args;
	}
}
