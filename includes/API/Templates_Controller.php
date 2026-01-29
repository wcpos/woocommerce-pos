<?php
/**
 * Templates_Controller.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\API;

use WCPOS\WooCommercePOS\Templates as TemplatesManager;
use WP_Error;
use WP_Query;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use const WCPOS\WooCommercePOS\SHORT_NAME;

/**
 * Class Templates REST API Controller.
 *
 * Returns both virtual (filesystem) templates and custom (database) templates.
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
		// List all templates (virtual + database).
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

		// Get single template (supports numeric and string IDs).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\w-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => array( $this, 'get_item_permissions_check' ),
				'args'                => array(
					'id' => array(
						'description' => __( 'Unique identifier for the template (numeric for database, string for virtual).', 'woocommerce-pos' ),
						'type'        => 'string',
						'required'    => true,
					),
				),
			)
		);

		// Get active template for a type.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/active',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_active' ),
				'permission_callback' => array( $this, 'get_item_permissions_check' ),
				'args'                => array(
					'type' => array(
						'description' => __( 'Template type.', 'woocommerce-pos' ),
						'type'        => 'string',
						'default'     => 'receipt',
						'enum'        => array( 'receipt', 'report' ),
					),
				),
			)
		);
	}

	/**
	 * Get a collection of templates.
	 * Returns virtual templates first, then database templates.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|WP_REST_Response Response object on success, or WP_Error object on failure.
	 */
	public function get_items( $request ) {
		$type      = $request->get_param( 'type' ) ?? 'receipt';
		$templates = array();

		// Get virtual (filesystem) templates first.
		$virtual_templates = TemplatesManager::detect_filesystem_templates( $type );
		foreach ( $virtual_templates as $template ) {
			$template['is_active'] = TemplatesManager::is_active_template( $template['id'], $type );
			$templates[]           = $this->prepare_item_for_response( $template, $request );
		}

		// Get database templates.
		$args = array(
			'post_type'      => 'wcpos_template',
			'post_status'    => 'publish',
			'posts_per_page' => $request->get_param( 'per_page' ) ?? -1,
			'paged'          => $request->get_param( 'page' ) ?? 1,
		);

		if ( $type ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => 'wcpos_template_type',
					'field'    => 'slug',
					'terms'    => $type,
				),
			);
		}

		$query = new WP_Query( $args );

		foreach ( $query->posts as $post ) {
			$template = TemplatesManager::get_template( $post->ID );
			if ( $template ) {
				$template['is_active'] = TemplatesManager::is_active_template( $post->ID, $template['type'] );
				$templates[]           = $this->prepare_item_for_response( $template, $request );
			}
		}

		$total_items = \count( $virtual_templates ) + $query->found_posts;

		$response = rest_ensure_response( $templates );
		$response->header( 'X-WP-Total', $total_items );
		$response->header( 'X-WP-TotalPages', max( 1, $query->max_num_pages ) );

		return $response;
	}

	/**
	 * Get a single template.
	 * Supports both numeric IDs (database) and string IDs (virtual).
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|WP_REST_Response Response object on success, or WP_Error object on failure.
	 */
	public function get_item( $request ) {
		$id   = $request['id'];
		$type = $request->get_param( 'type' ) ?? 'receipt';

		// Check if it's a numeric ID (database template).
		if ( is_numeric( $id ) ) {
			$template = TemplatesManager::get_template( (int) $id );
		} else {
			// It's a virtual template ID.
			$template = TemplatesManager::get_virtual_template( $id, $type );
		}

		if ( ! $template ) {
			return new WP_Error(
				'wcpos_template_invalid_id',
				__( 'Invalid template ID.', 'woocommerce-pos' ),
				array( 'status' => 404 )
			);
		}

		$template['is_active'] = TemplatesManager::is_active_template( $template['id'], $template['type'] );

		return rest_ensure_response( $this->prepare_item_for_response( $template, $request ) );
	}

	/**
	 * Get the active template for a type.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|WP_REST_Response Response object on success, or WP_Error object on failure.
	 */
	public function get_active( $request ) {
		$type     = $request->get_param( 'type' ) ?? 'receipt';
		$template = TemplatesManager::get_active_template( $type );

		if ( ! $template ) {
			return new WP_Error(
				'wcpos_no_active_template',
				__( 'No active template found.', 'woocommerce-pos' ),
				array( 'status' => 404 )
			);
		}

		$template['is_active'] = true;

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
		// Remove content from listing to reduce payload size.
		$context = $request->get_param( 'context' ) ?? 'view';
		if ( 'edit' !== $context && isset( $template['content'] ) ) {
			unset( $template['content'] );
		}

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
				'default'           => 'receipt',
				'enum'              => array( 'receipt', 'report' ),
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'context'  => array(
				'description'       => __( 'Scope under which the request is made.', 'woocommerce-pos' ),
				'type'              => 'string',
				'default'           => 'view',
				'enum'              => array( 'view', 'edit' ),
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
