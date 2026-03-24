<?php
/**
 * Templates_Controller.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\API;

use Ramsey\Uuid\Uuid;
use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Services\Preview_Receipt_Builder;
use WCPOS\WooCommercePOS\Services\Receipt_Data_Builder;
use WCPOS\WooCommercePOS\Services\Receipt_Data_Schema;
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
					'type'            => array(
						'description'       => __( 'Template type for ordering.', 'woocommerce-pos' ),
						'type'              => 'string',
						'default'           => 'receipt',
						'enum'              => array( 'receipt', 'report' ),
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'update'          => array(
						'description' => __( 'Array of templates to update.', 'woocommerce-pos' ),
						'type'        => 'array',
						'required'    => false,
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'id'          => array(
									'type'     => 'integer',
									'required' => true,
								),
								'status'      => array(
									'type' => 'string',
									'enum' => array( 'publish', 'draft' ),
								),
								'menu_order'  => array( 'type' => 'integer' ),
								'tax_display' => array(
									'type' => 'string',
									'enum' => array( 'default', 'incl', 'excl' ),
								),
							),
						),
					),
					'order'           => array(
						'description' => __( 'Ordered array of all template IDs (int for database, string for virtual).', 'woocommerce-pos' ),
						'type'        => 'array',
						'items'       => array(
							'type' => array( 'integer', 'string' ),
						),
					),
					'disable_virtual' => array(
						'description' => __( 'Array of virtual template IDs to disable.', 'woocommerce-pos' ),
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
					),
					'enable_virtual'  => array(
						'description' => __( 'Array of virtual template IDs to enable.', 'woocommerce-pos' ),
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
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
					'id'          => array(
						'type' => 'integer',
						'required' => true,
					),
					'status'      => array(
						'type' => 'string',
						'enum' => array( 'publish', 'draft' ),
					),
					'menu_order'  => array( 'type' => 'integer' ),
					'tax_display' => array(
						'type' => 'string',
						'enum' => array( 'default', 'incl', 'excl' ),
					),
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
				'permission_callback' => array( $this, 'preview_item_permissions_check' ),
				'args'                => array(
					'id' => array(
						'description' => __( 'Template ID to preview.', 'woocommerce-pos' ),
						'type'        => 'string',
						'required'    => true,
					),
				),
			)
		);

		// 10. DELETE /templates/{id} (regex).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_item' ),
				'permission_callback' => array( $this, 'update_item_permissions_check' ),
				'args'                => array(
					'id' => array(
						'description'       => __( 'Template ID to delete.', 'woocommerce-pos' ),
						'type'              => 'integer',
						'required'          => true,
						'validate_callback' => function ( $value ) {
							return is_numeric( $value );
						},
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

		$store_id = $request->get_param( 'store_id' );
		if ( $store_id ) {
			$resolved  = TemplatesManager::resolve_templates( (int) $store_id, $type );
			$active_id = ! empty( $resolved ) ? $resolved[0]['id'] : null;
			$data      = array();
			foreach ( $resolved as $template ) {
				$template['is_active'] = ( null !== $active_id && (string) $template['id'] === (string) $active_id );
				$data[]                = $this->prepare_item_for_response( $template, $request );
			}
			return rest_ensure_response( $data );
		}

		$search         = $request->get_param( 'search' );
		$category       = $request->get_param( 'category' );
		$modified_after = $request->get_param( 'modified_after' );
		$per_page       = (int) ( $request->get_param( 'per_page' ) ?? -1 );
		$page           = max( 1, (int) ( $request->get_param( 'page' ) ?? 1 ) );
		$has_filters    = $search || $category || $modified_after;
		$templates      = array();

		// Compute the active template ID once from the final enabled order.
		$enabled = TemplatesManager::get_enabled_templates( $type );
		$active_template_id = ! empty( $enabled ) ? $enabled[0]['id'] : null;

		// Get virtual (filesystem) templates first, but skip when filters are active.
		if ( ! $has_filters ) {
			$virtual_templates = TemplatesManager::detect_filesystem_templates( $type );
			foreach ( $virtual_templates as $template ) {
				$template['is_active'] = ( null !== $active_template_id && (string) $template['id'] === (string) $active_template_id );
				$templates[]           = $this->prepare_item_for_response( $template, $request );
			}
		}

		// Get database templates.
		$args = array(
			'post_type'      => 'wcpos_template',
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => ( ! $has_filters && $per_page > 0 ) ? -1 : $per_page,
			'paged'          => ( ! $has_filters && $per_page > 0 ) ? 1 : $page,
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

		// Search filter (title/content OR description meta).
		if ( $search ) {
			$matching_ids = $this->get_search_matching_template_ids( $search, $args );
			$args['post__in'] = empty( $matching_ids ) ? array( 0 ) : $matching_ids;
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

		$db_templates = array();
		foreach ( $query->posts as $post ) {
			$template = TemplatesManager::get_template( $post->ID );
			if ( $template ) {
				$template['is_active'] = ( null !== $active_template_id && (string) $template['id'] === (string) $active_template_id );
				$db_templates[]        = $this->prepare_item_for_response( $template, $request );
			}
		}

		if ( ! $has_filters ) {
			$all_templates = array_merge( $templates, $db_templates );

			// Sort by stored order.
			$stored_order = TemplatesManager::get_template_order( $type );
			if ( ! empty( $stored_order ) ) {
				$all_templates = $this->sort_by_stored_order( $all_templates, $stored_order );
			}

			$total_items = \count( $all_templates );

			if ( $per_page > 0 ) {
				$offset      = ( $page - 1 ) * $per_page;
				$templates   = \array_slice( $all_templates, $offset, $per_page );
				$total_pages = $total_items > 0 ? (int) \ceil( $total_items / $per_page ) : 1;
			} else {
				$templates   = $all_templates;
				$total_pages = 1;
			}
		} else {
			$templates   = $db_templates;
			$total_items = (int) $query->found_posts;
			$total_pages = (int) max( 1, $query->max_num_pages );
		}

		$response = rest_ensure_response( $templates );
		$response->header( 'X-WP-Total', (string) $total_items );
		$response->header( 'X-WP-TotalPages', (string) max( 1, $total_pages ) );

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

		$enabled = TemplatesManager::get_enabled_templates( $template['type'] ?? 'receipt' );
		$active_id = ! empty( $enabled ) ? $enabled[0]['id'] : null;
		$template['is_active'] = ( null !== $active_id && (string) $template['id'] === (string) $active_id );

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

		$enabled = TemplatesManager::get_enabled_templates( $template['type'] ?? 'receipt' );
		$active_id = ! empty( $enabled ) ? $enabled[0]['id'] : null;
		$template['is_active'] = ( null !== $active_id && (string) $template['id'] === (string) $active_id );

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
		$type = $request->get_param( 'type' ) ?? 'receipt';

		// Handle order.
		$order = $request->get_param( 'order' );
		if ( \is_array( $order ) ) {
			TemplatesManager::save_template_order( $order, $type );
		}

		// Handle disable_virtual.
		$disable_virtual = $request->get_param( 'disable_virtual' );
		if ( \is_array( $disable_virtual ) ) {
			foreach ( $disable_virtual as $vid ) {
				if ( \is_string( $vid ) ) {
					TemplatesManager::set_virtual_template_disabled( $vid, true, $type );
				}
			}
		}

		// Handle enable_virtual.
		$enable_virtual = $request->get_param( 'enable_virtual' );
		if ( \is_array( $enable_virtual ) ) {
			foreach ( $enable_virtual as $vid ) {
				if ( \is_string( $vid ) ) {
					TemplatesManager::set_virtual_template_disabled( $vid, false, $type );
				}
			}
		}

		// Handle update (existing logic for database templates).
		$updates = $request->get_param( 'update' );
		$results = array();

		if ( \is_array( $updates ) ) {
			foreach ( $updates as $index => $item ) {
				if ( ! \is_array( $item ) || empty( $item['id'] ) || ! \is_numeric( $item['id'] ) ) {
					$results[] = array(
						'id'    => \is_array( $item ) ? ( $item['id'] ?? null ) : null,
						'error' => array(
							'code'    => 'wcpos_template_missing_id',
							/* translators: %d: batch item index. */
							'message' => sprintf( __( 'Batch item %d must include a numeric id.', 'woocommerce-pos' ), $index + 1 ),
						),
					);
					continue;
				}

				$item_id = (int) $item['id'];

				$item_request = new WP_REST_Request( 'PATCH' );
				$item_request->set_body_params( $item );
				$item_request->set_url_params( array( 'id' => $item_id ) );

				$result = $this->update_item( $item_request );

				if ( is_wp_error( $result ) ) {
					$results[] = array(
						'id'    => $item_id,
						'error' => array(
							'code'    => $result->get_error_code(),
							'message' => $result->get_error_message(),
						),
					);
				} else {
					$results[] = $result->get_data();
				}
			}
		}

		// Build response.
		$response_data = array();
		if ( ! empty( $results ) ) {
			$response_data['update'] = $results;
		}
		if ( \is_array( $order ) ) {
			$response_data['order'] = TemplatesManager::get_template_order( $type );
		}
		if ( \is_array( $disable_virtual ) || \is_array( $enable_virtual ) ) {
			$response_data['disabled_virtual'] = TemplatesManager::get_disabled_virtual_templates( $type );
		}

		$response = rest_ensure_response( $response_data );

		// Return 400 only when the request contained nothing but update items and every one failed.
		$has_non_update_ops = \is_array( $order ) || \is_array( $disable_virtual ) || \is_array( $enable_virtual );
		if ( ! empty( $results ) && ! $has_non_update_ops ) {
			$has_success = false;
			foreach ( $results as $result_item ) {
				if ( ! isset( $result_item['error'] ) ) {
					$has_success = true;
					break;
				}
			}

			if ( ! $has_success ) {
				$response->set_status( 400 );
			}
		}

		return $response;
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

		// Bypass wp_kses for offline-capable engines — it strips unknown HTML/XML tags.
		$engine = $template['engine'] ?? 'legacy-php';
		if ( \in_array( $engine, TemplatesManager::OFFLINE_CAPABLE_ENGINES, true ) ) {
			if ( ! TemplatesManager::save_raw_post_content( $new_post_id, $source_post->post_content ) ) {
				wp_delete_post( $new_post_id, true );

				return new WP_Error(
					'wcpos_template_copy_failed',
					__( 'Failed to save copied template content.', 'woocommerce-pos' ),
					array( 'status' => 500 )
				);
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
	 * Delete a custom template.
	 *
	 * Virtual (filesystem) templates cannot be deleted.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|WP_REST_Response Response object on success, or WP_Error object on failure.
	 */
	public function delete_item( $request ) {
		$id       = (int) $request['id'];
		$template = TemplatesManager::get_template( $id );

		if ( ! $template ) {
			return new WP_Error(
				'wcpos_template_invalid_id',
				__( 'Invalid template ID.', 'woocommerce-pos' ),
				array( 'status' => 404 )
			);
		}

		if ( ! empty( $template['is_virtual'] ) ) {
			return new WP_Error(
				'wcpos_template_cannot_delete',
				__( 'Built-in templates cannot be deleted.', 'woocommerce-pos' ),
				array( 'status' => 403 )
			);
		}

		$deleted = wp_delete_post( $id, true );

		if ( ! $deleted ) {
			return new WP_Error(
				'wcpos_template_delete_failed',
				__( 'Failed to delete template.', 'woocommerce-pos' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'deleted' => true,
				'id'      => $id,
			)
		);
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
			$status = $this->get_wp_error_status( $result, 400 );
			$result->add_data( array( 'status' => $status ) );
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

		// Validate the template exists (database, virtual, or gallery).
		if ( is_numeric( $id ) ) {
			$template = TemplatesManager::get_template( (int) $id );
		} else {
			$template = TemplatesManager::get_virtual_template( $id, $type );
			if ( ! $template ) {
				$template = TemplatesManager::get_gallery_template_by_key( $id );
			}
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
		if ( empty( $order_ids ) ) {
			$fallback_query = new \WC_Order_Query(
				array(
					'limit'   => 1,
					'orderby' => 'date',
					'order'   => 'DESC',
					'return'  => 'ids',
				)
			);
			$order_ids      = $fallback_query->get_orders();
		}

		$order_id  = ! empty( $order_ids ) ? (int) $order_ids[0] : 0;
		$order     = $order_id ? wc_get_order( $order_id ) : null;
		$order_key = $order ? $order->get_order_key() : '';

		// Determine engine.
		$engine = $template['engine'] ?? 'legacy-php';

		// Thermal templates return raw content + data for client-side rendering.
		if ( 'thermal' === $engine ) {
			$content = $template['content'] ?? '';

			if ( $order ) {
				$receipt_data = ( new Receipt_Data_Builder() )->build( $order, 'live' );
			} else {
				$receipt_data = ( new Preview_Receipt_Builder() )->build();
			}

			$currency       = $receipt_data['meta']['currency'] ?? 'USD';
			$formatted_data = Receipt_Data_Schema::format_money_fields( $receipt_data, $currency );

			// Safety net for unresolved {{#t}}...{{/t}} markers in gallery templates.
			$formatted_data['t'] = true;

			return rest_ensure_response(
				array(
					'engine'           => 'thermal',
					'template_content' => $content,
					'receipt_data'     => $formatted_data,
					'order_id'         => $order_id,
					'template_id'      => $id,
				)
			);
		}

		// Non-thermal with a real order: return an iframe preview URL.
		if ( $order ) {
			$preview_url = add_query_arg(
				array(
					'key'                    => $order_key,
					'wcpos_preview_template' => $id,
				),
				get_home_url( null, '/wcpos-checkout/wcpos-receipt/' . $order_id )
			);

			return rest_ensure_response(
				array(
					'preview_url' => $preview_url,
					'order_id'    => $order_id,
					'template_id' => $id,
				)
			);
		}

		// Non-thermal without an order: render server-side with preview data.
		$receipt_data   = ( new Preview_Receipt_Builder() )->build();
		$currency       = $receipt_data['meta']['currency'] ?? 'USD';
		$formatted_data = Receipt_Data_Schema::format_money_fields( $receipt_data, $currency );

		// Add boolean helpers for array sections so templates can gate wrappers.
		$formatted_data['has_tax_summary'] = ! empty( $formatted_data['tax_summary'] );

		$banner = $this->get_preview_banner_html();

		if ( 'logicless' === $engine ) {
			$html = $this->render_logicless_preview( $template, $formatted_data );

			return rest_ensure_response(
				array(
					'preview_html' => $banner . $html,
					'order_id'     => 0,
					'template_id'  => $id,
				)
			);
		}

		// Legacy-php and other engines cannot be rendered server-side without a real order.
		return rest_ensure_response(
			array(
				'preview_html' => $banner . '<div style="padding: 40px; text-align: center; font-family: -apple-system, BlinkMacSystemFont, sans-serif; color: #666;">'
					. esc_html__( 'Create a POS order to preview this template.', 'woocommerce-pos' )
					. '</div>',
				'order_id'     => 0,
				'template_id'  => $id,
			)
		);
	}

	/**
	 * Build the preview banner HTML for sample receipt previews.
	 *
	 * @return string Banner HTML markup.
	 */
	private function get_preview_banner_html(): string {
		$text = esc_html__( 'Preview — Sample receipt with demo data', 'woocommerce-pos' );

		return '<div style="background: #f59e0b; color: #fff; text-align: center; padding: 6px 12px; font-family: -apple-system, BlinkMacSystemFont, sans-serif; font-size: 11px; font-weight: 600; letter-spacing: 1px; text-transform: uppercase; margin-bottom: 8px;">' . $text . '</div>';
	}

	/**
	 * Render a logicless template with preview data and return the HTML string.
	 *
	 * Uses output buffering to capture the Logicless_Renderer output
	 * without requiring a real WC_Abstract_Order.
	 *
	 * @param array $template       Template metadata including content.
	 * @param array $formatted_data Receipt data with money fields pre-formatted.
	 *
	 * @return string Rendered HTML.
	 */
	private function render_logicless_preview( array $template, array $formatted_data ): string {
		$content = isset( $template['content'] ) && \is_string( $template['content'] ) ? $template['content'] : '';

		if ( '' === $content ) {
			return '<!-- Empty logicless receipt template -->';
		}

		// Strip HTML comments — wp_kses_post removes the delimiters but leaves the text.
		$content = preg_replace( '/<!--.*?-->/s', '', $content );

		// Safety net for unresolved {{#t}}...{{/t}} markers in gallery templates.
		$formatted_data['t'] = true;

		$flags    = ENT_QUOTES | ENT_SUBSTITUTE;
		$mustache = new \Mustache\Engine(
			array(
				'entity_flags' => $flags,
				'escape'       => function ( $value ) use ( $flags ) {
					if ( \is_array( $value ) ) {
						return '';
					}

					return htmlspecialchars( (string) $value, $flags, 'UTF-8' );
				},
			)
		);

		$output = $mustache->render( $content, $formatted_data );

		return wp_kses_post( $output );
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

		// Strip internal content_file and add UUID to gallery templates.
		$templates = array_map(
			function ( $template ) {
				unset( $template['content_file'] );
				$type              = $template['type'] ?? 'receipt';
				$id                = $template['key'] ?? $template['id'] ?? '';
				$template['uuid']  = $this->get_virtual_template_uuid( (string) $id, $type );
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

		// Add UUID.
		$template['uuid'] = $this->get_template_uuid( $template );

		// Add computed fields.
		$template['offline_capable'] = in_array( $engine, TemplatesManager::OFFLINE_CAPABLE_ENGINES, true );
		$template['menu_order']      = isset( $template['menu_order'] ) ? (int) $template['menu_order'] : 0;

		// Normalize date_modified_gmt to ISO-like format (Y-m-d\TH:i:s) for consistency with other endpoints.
		if ( isset( $template['date_modified_gmt'] ) && preg_match( '/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $template['date_modified_gmt'] ) ) {
			$template['date_modified_gmt'] = preg_replace( '/(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})/', '$1T$2', $template['date_modified_gmt'] );
		}

		// Content handling:
		// - In 'edit' context: always include content (for admin editor)
		// - In 'view' context: include content for offline-capable engines (logicless, thermal)
		// - PHP templates: strip content in view context (can't be rendered client-side).
		if ( 'edit' !== $context ) {
			if ( ! in_array( $engine, TemplatesManager::OFFLINE_CAPABLE_ENGINES, true ) ) {
				unset( $template['content'] );
			}
		}

		// Add is_disabled for virtual templates (scoped by type).
		if ( ! empty( $template['is_virtual'] ) ) {
			$type = $request->get_param( 'type' ) ?? 'receipt';
			$template['is_disabled'] = TemplatesManager::is_virtual_template_disabled( (string) $template['id'], $type );
		}

		return $template;
	}

	/**
	 * Get or create a UUID for a template.
	 *
	 * Database templates store a random UUID v4 in postmeta.
	 * Virtual templates use a deterministic UUID v5 derived from the template ID.
	 *
	 * @param array $template Template data.
	 *
	 * @return string UUID string.
	 */
	private function get_template_uuid( array $template ): string {
		if ( ! empty( $template['is_virtual'] ) ) {
			$type = $template['type'] ?? 'receipt';
			return $this->get_virtual_template_uuid( (string) $template['id'], $type );
		}

		return $this->get_database_template_uuid( (int) $template['id'] );
	}

	/**
	 * Generate a deterministic UUID v5 for a virtual template.
	 *
	 * Uses the URL namespace with a wcpos-specific prefix so the same
	 * template ID + type always produces the same UUID across installations.
	 * Type is included because the same ID (e.g. 'plugin-core') can exist
	 * for both 'receipt' and 'report' template types.
	 *
	 * @param string $template_id Virtual template ID (e.g. 'plugin-core').
	 * @param string $type        Template type (e.g. 'receipt', 'report').
	 *
	 * @return string UUID v5 string.
	 */
	private function get_virtual_template_uuid( string $template_id, string $type ): string {
		try {
			return Uuid::uuid5( Uuid::NAMESPACE_URL, 'https://wcpos.com/template/' . $type . '/' . $template_id )->toString();
		} catch ( \Exception $e ) {
			Logger::error( 'Virtual template UUID generation failed: ' . $e->getMessage() );
			return '';
		}
	}

	/**
	 * Get or create a UUID v4 for a database template.
	 *
	 * Stores the UUID in postmeta with the standard _woocommerce_pos_uuid key.
	 *
	 * @param int $post_id Template post ID.
	 *
	 * @return string UUID v4 string.
	 */
	private function get_database_template_uuid( int $post_id ): string {
		$uuid = get_post_meta( $post_id, '_woocommerce_pos_uuid', true );

		if ( is_string( $uuid ) && '' !== $uuid && Uuid::isValid( $uuid ) ) {
			return $uuid;
		}

		try {
			$uuid = Uuid::uuid4()->toString();
		} catch ( \Exception $e ) {
			Logger::error( 'Database template UUID generation failed: ' . $e->getMessage() );
			return '';
		}

		if ( add_post_meta( $post_id, '_woocommerce_pos_uuid', $uuid, true ) ) {
			return $uuid;
		}

		// Another request may have written a UUID concurrently — use it.
		$persisted_uuid = get_post_meta( $post_id, '_woocommerce_pos_uuid', true );
		if ( is_string( $persisted_uuid ) && '' !== $persisted_uuid && Uuid::isValid( $persisted_uuid ) ) {
			return $persisted_uuid;
		}

		Logger::error( 'Database template UUID persistence failed.', array( 'post_id' => $post_id ) );
		return '';
	}

	/**
	 * Sort templates by stored order, appending unordered templates at the end.
	 *
	 * @param array $templates    Templates to sort.
	 * @param array $stored_order Ordered array of template IDs.
	 *
	 * @return array Sorted templates.
	 */
	private function sort_by_stored_order( array $templates, array $stored_order ): array {
		// Build a position map from stored order.
		$position_map = array();
		foreach ( $stored_order as $index => $id ) {
			$key                  = \is_int( $id ) ? $id : (string) $id;
			$position_map[ $key ] = $index;
		}

		$ordered   = array();
		$unordered = array();

		foreach ( $templates as $template ) {
			$id  = $template['id'];
			$key = \is_int( $id ) ? $id : (string) $id;

			if ( isset( $position_map[ $key ] ) ) {
				$ordered[ $position_map[ $key ] ] = $template;
			} else {
				$unordered[] = $template;
			}
		}

		ksort( $ordered );

		return array_merge( array_values( $ordered ), $unordered );
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
				'sanitize_callback' => array( $this, 'sanitize_per_page_param' ),
				'validate_callback' => array( $this, 'validate_per_page_param' ),
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
			'store_id'       => array(
				'description'       => __( 'Limit results to templates resolved for a specific store.', 'woocommerce-pos' ),
				'type'              => 'integer',
				'default'           => 0,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * Get template IDs matching title/content search OR description meta search.
	 *
	 * @param string $search Search term.
	 * @param array  $args   Base query args (without search constraints).
	 *
	 * @return int[] Matching template IDs.
	 */
	private function get_search_matching_template_ids( string $search, array $args ): array {
		$base_query_args = $args;
		unset( $base_query_args['post__in'] );
		$base_query_args['fields']        = 'ids';
		$base_query_args['posts_per_page'] = -1;
		$base_query_args['paged']         = 1;
		$base_query_args['no_found_rows'] = true;

		$title_query_args      = $base_query_args;
		$title_query_args['s'] = $search;
		$title_query           = new WP_Query( $title_query_args );

		$description_query_args               = $base_query_args;
		$description_query_args['meta_query'] = array(
			array(
				'key'     => '_template_description',
				'value'   => $search,
				'compare' => 'LIKE',
			),
		);
		$description_query                    = new WP_Query( $description_query_args );

		return array_values( array_unique( array_merge( $title_query->posts, $description_query->posts ) ) );
	}

	/**
	 * Preserve -1 for "all items", otherwise sanitize as a positive integer.
	 *
	 * @param mixed $value Requested per_page value.
	 *
	 * @return int
	 */
	public function sanitize_per_page_param( $value ): int {
		$value = (int) $value;
		return -1 === $value ? -1 : absint( $value );
	}

	/**
	 * Validate per_page as either -1 or a positive integer.
	 *
	 * @param mixed $value Requested per_page value.
	 *
	 * @return bool
	 */
	public function validate_per_page_param( $value ): bool {
		$value = (int) $value;
		return -1 === $value || $value > 0;
	}

	/**
	 * Get a valid HTTP status code from WP_Error data.
	 *
	 * @param WP_Error $error         Error object.
	 * @param int      $fallback_code Fallback status code.
	 *
	 * @return int
	 */
	private function get_wp_error_status( WP_Error $error, int $fallback_code = 400 ): int {
		$error_data = $error->get_error_data();
		$status     = null;

		if ( \is_array( $error_data ) && isset( $error_data['status'] ) ) {
			$status = $error_data['status'];
		} elseif ( \is_numeric( $error_data ) ) {
			$status = $error_data;
		}

		if ( null === $status ) {
			return $fallback_code;
		}

		$status = (int) $status;
		return ( $status >= 100 && $status <= 599 ) ? $status : $fallback_code;
	}

	/**
	 * Check if a given request has access to read templates.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return bool|WP_Error True if the request has access, WP_Error object otherwise.
	 */
	public function get_items_permissions_check( $request ) {
		if ( ! current_user_can( 'access_woocommerce_pos' ) ) {
			return new WP_Error(
				'wcpos_rest_cannot_view',
				__( 'Sorry, you cannot list templates.', 'woocommerce-pos' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		// The 'edit' context exposes full template content (including PHP); require manage capability.
		if ( 'edit' === $request->get_param( 'context' ) && ! current_user_can( 'manage_woocommerce_pos' ) ) {
			return new WP_Error(
				'wcpos_rest_cannot_edit',
				__( 'Sorry, you are not allowed to edit templates.', 'woocommerce-pos' ),
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
		if ( ! current_user_can( 'access_woocommerce_pos' ) ) {
			return new WP_Error(
				'wcpos_rest_cannot_view',
				__( 'Sorry, you cannot view this template.', 'woocommerce-pos' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		// The 'edit' context exposes full template content (including PHP); require manage capability.
		if ( 'edit' === $request->get_param( 'context' ) && ! current_user_can( 'manage_woocommerce_pos' ) ) {
			return new WP_Error(
				'wcpos_rest_cannot_edit',
				__( 'Sorry, you are not allowed to edit this template.', 'woocommerce-pos' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Check if a given request has access to preview a template.
	 *
	 * Preview returns order data, so it requires the stricter manage capability.
	 *
	 * @param WP_REST_Request $_request Full details about the request.
	 *
	 * @return bool|WP_Error True if the request has access, WP_Error object otherwise.
	 */
	public function preview_item_permissions_check( $_request ) {
		if ( ! current_user_can( 'manage_woocommerce_pos' ) ) {
			return new WP_Error(
				'wcpos_rest_cannot_view',
				__( 'Sorry, you cannot preview this template.', 'woocommerce-pos' ),
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
