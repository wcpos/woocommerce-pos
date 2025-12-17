<?php

namespace WCPOS\WooCommercePOS\API;

use const WCPOS\WooCommercePOS\SHORT_NAME;
use WCPOS\WooCommercePOS\Templates as TemplatesManager;
use WP_Error;
use WP_Query;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Class Templates REST API Controller.
 */
class Templates_Controller extends WP_REST_Controller {
	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = SHORT_NAME . '/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'templates';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// List all templates
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
				'args'                => $this->get_collection_params(),
			)
		);

		// Get single template
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => array( $this, 'get_item_permissions_check' ),
				'args'                => array(
					'id' => array(
						'description' => __( 'Unique identifier for the template.', 'woocommerce-pos' ),
						'type'        => 'integer',
						'required'    => true,
					),
				),
			)
		);
	}

	/**
	 * Get a collection of templates.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|WP_REST_Response Response object on success, or WP_Error object on failure.
	 */
	public function get_items( $request ) {
		$args = array(
			'post_type'      => 'wcpos_template',
			'post_status'    => 'publish',
			'posts_per_page' => $request->get_param( 'per_page' ) ?? -1,
			'paged'          => $request->get_param( 'page' )     ?? 1,
		);

		// Filter by template type
		$type = $request->get_param( 'type' );
		if ( $type ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => 'wcpos_template_type',
					'field'    => 'slug',
					'terms'    => $type,
				),
			);
		}

		$query     = new WP_Query( $args );
		$templates = array();

		foreach ( $query->posts as $post ) {
			$template = TemplatesManager::get_template( $post->ID );
			if ( $template ) {
				$templates[] = $this->prepare_item_for_response( $template, $request );
			}
		}

		$response = rest_ensure_response( $templates );
		$response->header( 'X-WP-Total', $query->found_posts );
		$response->header( 'X-WP-TotalPages', $query->max_num_pages );

		return $response;
	}

	/**
	 * Get a single template.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|WP_REST_Response Response object on success, or WP_Error object on failure.
	 */
	public function get_item( $request ) {
		$id       = (int) $request['id'];
		$template = TemplatesManager::get_template( $id );

		if ( ! $template ) {
			return new WP_Error(
				'wcpos_template_invalid_id',
				__( 'Invalid template ID.', 'woocommerce-pos' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $this->prepare_item_for_response( $template, $request ) );
	}

	/**
	 * Prepare template for response.
	 *
	 * @param array           $template Template data.
	 * @param WP_REST_Request $request  Request object.
	 *
	 * @return array Prepared template data.
	 */
	public function prepare_item_for_response( $template, $request ) {
		return $template;
	}

	/**
	 * Get collection parameters.
	 *
	 * @return array Collection parameters.
	 */
	public function get_collection_params() {
		return array(
			'page'     => array(
				'description'       => __( 'Current page of the collection.', 'woocommerce-pos' ),
				'type'              => 'integer',
				'default'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'per_page' => array(
				'description'       => __( 'Maximum number of items to be returned in result set.', 'woocommerce-pos' ),
				'type'              => 'integer',
				'default'           => -1,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'type'     => array(
				'description'       => __( 'Filter by template type.', 'woocommerce-pos' ),
				'type'              => 'string',
				'enum'              => array( 'receipt', 'report' ),
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * Check if a given request has access to read templates.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return bool|WP_Error True if the request has access, WP_Error object otherwise.
	 */
	public function get_items_permissions_check( $request ) {
		if ( ! current_user_can( 'manage_woocommerce_pos' ) ) {
			return new WP_Error(
				'wcpos_rest_cannot_view',
				__( 'Sorry, you cannot list templates.', 'woocommerce-pos' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Check if a given request has access to read a specific template.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return bool|WP_Error True if the request has access, WP_Error object otherwise.
	 */
	public function get_item_permissions_check( $request ) {
		if ( ! current_user_can( 'manage_woocommerce_pos' ) ) {
			return new WP_Error(
				'wcpos_rest_cannot_view',
				__( 'Sorry, you cannot view this template.', 'woocommerce-pos' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}
}
