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
	 * Fixed paths must be registered before regex patterns to avoid
	 * the wildcard (?P<id>[\w-]+) capturing "active", "gallery", etc.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// 1. GET /templates (collection).
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

		// 2. GET /templates/active (fixed path).
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

		// 3. GET /templates/gallery (fixed path).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/gallery',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_gallery_items' ),
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
				'args'                => array(
					'type'     => array(
						'description'       => __( 'Filter by template type.', 'woocommerce-pos' ),
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'category' => array(
						'description'       => __( 'Filter by template category slug.', 'woocommerce-pos' ),
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);

		// 4. POST /templates/batch (fixed path).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/batch',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'batch_items' ),
				'permission_callback' => array( $this, 'update_item_permissions_check' ),
				'args'                => array(
					'update' => array(
						'description' => __( 'Array of templates to update.', 'woocommerce-pos' ),
						'type'        => 'array',
						'required'    => true,
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'id'          => array( 'type' => 'integer', 'required' => true ),
								'status'      => array( 'type' => 'string', 'enum' => array( 'publish', 'draft' ) ),
								'menu_order'  => array( 'type' => 'integer' ),
								'tax_display' => array( 'type' => 'string', 'enum' => array( 'default', 'incl', 'excl' ) ),
							),
						),
					),
				),
			)
		);

		// 5. POST /templates/install (fixed path).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/install',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'install_gallery_item' ),
				'permission_callback' => array( $this, 'update_item_permissions_check' ),
				'args'                => array(
					'gallery_key' => array(
						'description'       => __( 'Gallery template key to install.', 'woocommerce-pos' ),
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);

		// 6. GET /templates/{id} (regex).
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

		// 7. PATCH /templates/{id} (regex).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_item' ),
				'permission_callback' => array( $this, 'update_item_permissions_check' ),
				'args'                => array(
					'id'          => array( 'type' => 'integer', 'required' => true ),
					'status'      => array( 'type' => 'string', 'enum' => array( 'publish', 'draft' ) ),
					'menu_order'  => array( 'type' => 'integer' ),
					'tax_display' => array( 'type' => 'string', 'enum' => array( 'default', 'incl', 'excl' ) ),
				),
			)
		);

		// 8. POST /templates/{id}/copy (regex).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/copy',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'copy_item' ),
				'permission_callback' => array( $this, 'update_item_permissions_check' ),
				'args'                => array(
					'id' => array(
						'description' => __( 'Template ID to copy.', 'woocommerce-pos' ),
						'type'        => 'integer',
						'required'    => true,
					),
				),
			)
		);

		// 9. GET /templates/{id}/preview (regex).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\w-]+)/preview',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'preview_item' ),
				'permission_callback' => array( $this, 'get_item_permissions_check' ),
				'args'                => array(
					'id' => array(
						'description' => __( 'Template ID to preview.', 'woocommerce-pos' ),
						'type'        => 'string',
						'required'    => true,
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
		$type           = $request->get_param( 'type' ) ?? 'receipt';
		$search         = $request->get_param( 'search' );
		$category       = $request->get_param( 'category' );
		$modified_after = $request->get_param( 'modified_after' );
		$has_filters    = $search || $category || $modified_after;
		$templates      = array();

		// Get virtual (filesystem) templates first, but skip when filters are active.
		if ( ! $has_filters ) {
			$virtual_templates = TemplatesManager::detect_filesystem_templates( $type );
			foreach ( $virtual_templates as $template ) {
				$template['is_active'] = TemplatesManager::is_active_template( $template['id'], $type );
				$templates[]           = $this->prepare_item_for_response( $template, $request );
			}
		}

		// Get database templates.
		$args = array(
			'post_type'      => 'wcpos_template',
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => $request->get_param( 'per_page' ) ?? -1,
			'paged'          => $request->get_param( 'page' ) ?? 1,
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
		);

		// Type filter (always applied via tax_query).
		$tax_query = array();
		if ( $type ) {
			$tax_query[] = array(
				'taxonomy' => 'wcpos_template_type',
				'field'    => 'slug',
				'terms'    => $type,
			);
		}

		// Category filter.
		if ( $category ) {
			$tax_query[] = array(
				'taxonomy' => 'wcpos_template_category',
				'field'    => 'slug',
				'terms'    => $category,
			);
		}

		if ( ! empty( $tax_query ) ) {
			if ( \count( $tax_query ) > 1 ) {
				$tax_query['relation'] = 'AND';
			}
			$args['tax_query'] = $tax_query;
		}

		// Search filter.
		if ( $search ) {
			$args['s'] = $search;
		}

		// Modified after filter.
		if ( $modified_after ) {
			$args['date_query'] = array(
				array(
					'column' => 'post_modified',
					'after'  => $modified_after,
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

		$virtual_count = $has_filters ? 0 : ( isset( $virtual_templates ) ? \count( $virtual_templates ) : 0 );
		$total_items   = $virtual_count + $query->found_posts;

		$response = rest_ensure_response( $templates );
		$response->header( 'X-WP-Total', (string) $total_items );
		$response->header( 'X-WP-TotalPages', (string) max( 1, $query->max_num_pages ) );

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
	 * Update a single template.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|WP_REST_Response Response object on success, or WP_Error object on failure.
	 */
	public function update_item( $request ) {
		$id   = (int) $request['id'];
		$post = get_post( $id );

		if ( ! $post || 'wcpos_template' !== $post->post_type ) {
			return new WP_Error(
				'wcpos_template_invalid_id',
				__( 'Invalid template ID.', 'woocommerce-pos' ),
				array( 'status' => 404 )
			);
		}

		$update_args = array( 'ID' => $id );
		$needs_update = false;

		// Update status.
		$status = $request->get_param( 'status' );
		if ( null !== $status ) {
			$update_args['post_status'] = $status;
			$needs_update = true;
		}

		// Update menu_order.
		$menu_order = $request->get_param( 'menu_order' );
		if ( null !== $menu_order ) {
			$update_args['menu_order'] = (int) $menu_order;
			$needs_update = true;
		}

		if ( $needs_update ) {
			$result = wp_update_post( $update_args, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		// Update tax_display meta.
		$tax_display = $request->get_param( 'tax_display' );
		if ( null !== $tax_display ) {
			update_post_meta( $id, '_template_tax_display', $tax_display );
		}

		$template = TemplatesManager::get_template( $id );
		if ( ! $template ) {
			return new WP_Error(
				'wcpos_template_not_found',
				__( 'Template not found after update.', 'woocommerce-pos' ),
				array( 'status' => 500 )
			);
		}

		$template['is_active'] = TemplatesManager::is_active_template( $id, $template['type'] );

		return rest_ensure_response( $this->prepare_item_for_response( $template, $request ) );
	}

	/**
	 * Batch update templates.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|WP_REST_Response Response object on success, or WP_Error object on failure.
	 */
	public function batch_items( $request ) {
		$updates = $request->get_param( 'update' );
		$results = array();

		if ( ! \is_array( $updates ) ) {
			return new WP_Error(
				'wcpos_invalid_batch',
				__( 'The update parameter must be an array.', 'woocommerce-pos' ),
				array( 'status' => 400 )
			);
		}

		foreach ( $updates as $item ) {
			$item_request = new WP_REST_Request( 'PATCH' );
			$item_request->set_body_params( $item );
			$item_request->set_url_params( array( 'id' => $item['id'] ) );

			$result = $this->update_item( $item_request );

			if ( is_wp_error( $result ) ) {
				$results[] = array(
					'id'    => $item['id'],
					'error' => array(
						'code'    => $result->get_error_code(),
						'message' => $result->get_error_message(),
					),
				);
			} else {
				$results[] = $result->get_data();
			}
		}

		return rest_ensure_response( array( 'update' => $results ) );
	}

	/**
	 * Copy a template.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|WP_REST_Response Response object on success, or WP_Error object on failure.
	 */
	public function copy_item( $request ) {
		$id       = (int) $request['id'];
		$template = TemplatesManager::get_template( $id );

		if ( ! $template ) {
			return new WP_Error(
				'wcpos_template_invalid_id',
				__( 'Invalid template ID.', 'woocommerce-pos' ),
				array( 'status' => 404 )
			);
		}

		$source_post = get_post( $id );

		// Create the copy.
		$new_post_id = wp_insert_post(
			array(
				/* translators: %s: original template title */
				'post_title'   => sprintf( __( 'Copy of %s', 'woocommerce-pos' ), $source_post->post_title ),
				'post_content' => $source_post->post_content,
				'post_status'  => 'draft',
				'post_type'    => 'wcpos_template',
				'menu_order'   => $source_post->menu_order,
			),
			true
		);

		if ( is_wp_error( $new_post_id ) ) {
			return $new_post_id;
		}

		// Copy taxonomies.
		$taxonomies = get_object_taxonomies( 'wcpos_template' );
		foreach ( $taxonomies as $taxonomy ) {
			$terms = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'slugs' ) );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				wp_set_object_terms( $new_post_id, $terms, $taxonomy );
			}
		}

		// Copy meta fields.
		$meta_keys = array(
			'_template_description',
			'_template_language',
			'_template_engine',
			'_template_output_type',
			'_template_tax_display',
			'_template_paper_width',
		);

		foreach ( $meta_keys as $meta_key ) {
			$value = get_post_meta( $id, $meta_key, true );
			if ( '' !== $value ) {
				update_post_meta( $new_post_id, $meta_key, $value );
			}
		}

		$new_template = TemplatesManager::get_template( $new_post_id );
		if ( ! $new_template ) {
			return new WP_Error(
				'wcpos_template_copy_failed',
				__( 'Failed to retrieve copied template.', 'woocommerce-pos' ),
				array( 'status' => 500 )
			);
		}

		$new_template['is_active'] = false;

		$response = rest_ensure_response( $this->prepare_item_for_response( $new_template, $request ) );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Install a gallery template.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|WP_REST_Response Response object on success, or WP_Error object on failure.
	 */
	public function install_gallery_item( $request ) {
		$gallery_key = $request->get_param( 'gallery_key' );
		$result      = TemplatesManager::install_gallery_template( $gallery_key );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		$template = TemplatesManager::get_template( $result );
		if ( ! $template ) {
			return new WP_Error(
				'wcpos_template_install_failed',
				__( 'Template was installed but could not be retrieved.', 'woocommerce-pos' ),
				array( 'status' => 500 )
			);
		}

		$template['is_active'] = false;

		$response = rest_ensure_response( $this->prepare_item_for_response( $template, $request ) );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Preview a template.
	 *
	 * Returns a preview URL for the template rendered with a sample POS order.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|WP_REST_Response Response object on success, or WP_Error object on failure.
	 */
	public function preview_item( $request ) {
		$id   = $request['id'];
		$type = $request->get_param( 'type' ) ?? 'receipt';

		// Validate the template exists.
		if ( is_numeric( $id ) ) {
			$template = TemplatesManager::get_template( (int) $id );
		} else {
			$template = TemplatesManager::get_virtual_template( $id, $type );
		}

		if ( ! $template ) {
			return new WP_Error(
				'wcpos_template_invalid_id',
				__( 'Invalid template ID.', 'woocommerce-pos' ),
				array( 'status' => 404 )
			);
		}

		// Find a recent POS order for preview data.
		$order_query = new \WC_Order_Query(
			array(
				'limit'      => 1,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'created_via' => 'woocommerce-pos',
				'return'     => 'ids',
			)
		);

		$order_ids = $order_query->get_orders();
		$order_id  = ! empty( $order_ids ) ? $order_ids[0] : 0;

		// Build preview URL with a nonce for security.
		$preview_url = add_query_arg(
			array(
				'wcpos_template_preview' => 1,
				'template_id'            => $id,
				'order_id'               => $order_id,
				'_wpnonce'               => wp_create_nonce( 'wcpos_template_preview_' . $id ),
			),
			site_url( '/' )
		);

		return rest_ensure_response(
			array(
				'preview_url' => $preview_url,
				'order_id'    => $order_id,
				'template_id' => $id,
			)
		);
	}

	/**
	 * Get gallery templates.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_REST_Response Response object on success.
	 */
	public function get_gallery_items( $request ) {
		$type     = $request->get_param( 'type' );
		$category = $request->get_param( 'category' );

		$templates = TemplatesManager::get_gallery_templates( $type, $category );

		// Strip internal content_file from the response.
		$templates = array_map(
			function ( $template ) {
				unset( $template['content_file'] );
				return $template;
			},
			$templates
		);

		return rest_ensure_response( $templates );
	}

	/**
	 * Prepare template for response.
	 *
	 * @param array           $template Template data.
	 * @param WP_REST_Request $request  Request object.
	 *
	 * @return array|WP_REST_Response Prepared template data.
	 */
	public function prepare_item_for_response( $template, $request ) {
		$context = $request->get_param( 'context' ) ?? 'view';
		$engine  = $template['engine'] ?? 'legacy-php';

		// Add computed fields.
		$template['offline_capable'] = 'logicless' === $engine;
		$template['menu_order']      = isset( $template['menu_order'] ) ? (int) $template['menu_order'] : 0;

		// Content handling:
		// - In 'edit' context: always include content (for admin editor)
		// - In 'view' context: include content only for logicless templates (POS needs it for offline rendering)
		// - PHP templates: strip content in view context (can't be rendered client-side).
		if ( 'edit' !== $context ) {
			if ( 'logicless' !== $engine ) {
				unset( $template['content'] );
			}
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
			'page'           => array(
				'description'       => __( 'Current page of the collection.', 'woocommerce-pos' ),
				'type'              => 'integer',
				'default'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'per_page'       => array(
				'description'       => __( 'Maximum number of items to be returned in result set.', 'woocommerce-pos' ),
				'type'              => 'integer',
				'default'           => -1,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'type'           => array(
				'description'       => __( 'Filter by template type.', 'woocommerce-pos' ),
				'type'              => 'string',
				'default'           => 'receipt',
				'enum'              => array( 'receipt', 'report' ),
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'context'        => array(
				'description'       => __( 'Scope under which the request is made.', 'woocommerce-pos' ),
				'type'              => 'string',
				'default'           => 'view',
				'enum'              => array( 'view', 'edit' ),
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'search'         => array(
				'description'       => __( 'Search templates by title or description.', 'woocommerce-pos' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'category'       => array(
				'description'       => __( 'Filter by template category slug.', 'woocommerce-pos' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'modified_after' => array(
				'description'       => __( 'Limit to templates modified after this ISO 8601 date.', 'woocommerce-pos' ),
				'type'              => 'string',
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

	/**
	 * Check if a given request has access to update templates.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return bool|WP_Error True if the request has access, WP_Error object otherwise.
	 */
	public function update_item_permissions_check( $request ) {
		if ( ! current_user_can( 'manage_woocommerce_pos' ) ) {
			return new WP_Error(
				'wcpos_rest_cannot_update',
				__( 'Sorry, you cannot update templates.', 'woocommerce-pos' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}
}
