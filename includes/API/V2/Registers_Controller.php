<?php
/**
 * Register resource REST controller.
 *
 * @package WCPOS\WooCommercePOS\API\V2
 */

namespace WCPOS\WooCommercePOS\API\V2;

use WCPOS\WooCommercePOS\Services\Register_Store;
use WCPOS\WooCommercePOS\Sync\Pos_Uuid;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;

/** Till registration and admin edits. */
class Registers_Controller extends WP_REST_Controller {
	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wcpos/v2';
	/**
	 * Resource base.
	 *
	 * @var string
	 */
	protected $rest_base = 'registers';

	/** Register collection and item routes. */
	public function register_routes(): void {
		foreach ( array(
			'/' . $this->rest_base => array(
				'GET' => 'get_items',
				'POST' => 'create_item',
			),
			'/' . $this->rest_base . '/(?P<id>[0-9a-fA-F-]{36})' => array(
				'GET' => 'get_item',
				'PATCH' => 'update_item',
			),
		) as $route => $methods ) {
			$endpoints = array();
			foreach ( $methods as $method => $callback ) {
				$endpoints[] = array(
					'methods' => $method,
					'callback' => array( $this, $callback ),
					'permission_callback' => array( $this, 'registers_permissions_check' ),
				);
			}
			$endpoints['schema'] = array( $this, 'get_public_item_schema' );
			register_rest_route( $this->namespace, $route, $endpoints );
		}
	}

	/** Settings cookie requests carry no client protocol claim. */
	public function wcpos_route_classifications(): array {
		return array( 'protocol_exempt' => array( '/wcpos/v2/registers' ) );
	}

	/**
	 * Require POS access, or management for admin edits.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function registers_permissions_check( $request ) {
		$capability = 'PATCH' === $request->get_method() ? 'manage_woocommerce_pos' : 'access_woocommerce_pos';
		return current_user_can( $capability ) ? true : new WP_Error( 'rest_forbidden', __( 'Sorry, you cannot access registers.', 'woocommerce-pos' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/**
	 * Upsert the requesting till.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		if ( ! Pos_Uuid::is_uuid( $request['id'] ) ) {
			return $this->invalid( 'id' );
		}
		$fields = $this->validated_fields( $request, true );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$fields['id'] = strtolower( $request['id'] );
		/** Filter initial registration fields; Pro may set store_id. */
		$fields = apply_filters( 'woocommerce_pos_register_upsert_fields', $fields, $request );
		$store = new Register_Store();
		$exists = $store->exists( $fields['id'] );
		try {
			return new WP_REST_Response( $store->upsert( $fields ), $exists ? 200 : 201 );
		} catch ( \RuntimeException $error ) {
			return $this->write_error();
		}
	}

	/**
	 * List registers (active by default).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		$args = array( 'status' => $request['status'] ?? 'active' );
		if ( ! in_array( $args['status'], array( 'active', 'retired', 'all' ), true ) ) {
			return $this->invalid( 'status' );
		}
		if ( $request->has_param( 'store_id' ) ) {
			if ( ! is_scalar( $request['store_id'] ) || ! preg_match( '/^\d+$/D', (string) $request['store_id'] ) ) {
				return $this->invalid( 'store_id' );
			}
			$args['store_id'] = (int) $request['store_id'];
		}
		/** Filter authorized store scope for the register list. */
		$args = apply_filters( 'woocommerce_pos_registers_list_args', $args, $request );
		return new WP_REST_Response( ( new Register_Store() )->list( $args ) );
	}

	/**
	 * Fetch one register.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$row = ( new Register_Store() )->get( strtolower( $request['id'] ) );
		return $row ? new WP_REST_Response( $row ) : $this->not_found();
	}

	/**
	 * Edit admin fields without accepting Free store reassignment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$store = new Register_Store();
		$id = strtolower( $request['id'] );
		$row = $store->get( $id );
		if ( ! $row ) {
			return $this->not_found();
		}
		$fields = $this->validated_fields( $request, false );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		/** Filter admin fields; Pro may authorize store reassignment. */
		$fields = apply_filters( 'woocommerce_pos_register_update_fields', $fields, $row, $request );
		try {
			return new WP_REST_Response( $store->update( $id, $fields ) );
		} catch ( \RuntimeException $error ) {
			return $this->write_error();
		}
	}

	/**
	 * Validate only the fields owned by this operation.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param bool            $creating Till registration rather than admin edit.
	 * @return array|WP_Error
	 */
	private function validated_fields( WP_REST_Request $request, bool $creating ) {
		$fields = array();
		if ( $creating || $request->has_param( 'name' ) ) {
			$name = $request['name'];
			if ( ! is_string( $name ) || \strlen( $name ) > 191 || '' === trim( sanitize_text_field( $name ) ) ) {
				return $this->invalid( 'name' );
			}
			$fields['name'] = sanitize_text_field( $name );
		}
		if ( $creating ) {
			$fields['platform'] = $request['platform'] ?? '';
			$fields['app_version'] = $request['app_version'] ?? '';
			if ( ! in_array( $fields['platform'], array( '', 'ios', 'android', 'web', 'electron' ), true ) ) {
				return $this->invalid( 'platform' );
			}
			if ( ! is_string( $fields['app_version'] ) || \strlen( $fields['app_version'] ) > 64 ) {
				return $this->invalid( 'app_version' );
			}
		} else {
			if ( $request->has_param( 'status' ) ) {
				if ( ! in_array( $request['status'], array( 'active', 'retired' ), true ) ) {
					return $this->invalid( 'status' );
				}
				$fields['status'] = $request['status'];
			}
			if ( $request->has_param( 'default_float' ) ) {
				$value = $request['default_float'];
				if ( null !== $value && ( ! is_string( $value ) || ! preg_match( '/^\d+(?:\.\d+)?$/D', $value ) ) ) {
					return $this->invalid( 'default_float' );
				}
				$fields['default_float'] = null === $value ? null : wc_format_decimal( $value, 4 );
			}
		}
		return $fields;
	}

	/** Describe the register resource. */
	public function get_item_schema(): array {
		$properties = array(
			'id' => array(
				'type' => 'string',
				'format' => 'uuid',
				'readonly' => true,
			),
			'name' => array(
				'type' => 'string',
				'maxLength' => 191,
			),
			'store_id' => array(
				'type' => array( 'integer', 'null' ),
				'readonly' => true,
			),
			'default_float' => array( 'type' => array( 'string', 'null' ) ),
			'platform' => array(
				'type' => 'string',
				'enum' => array( '', 'ios', 'android', 'web', 'electron' ),
			),
			'app_version' => array(
				'type' => 'string',
				'maxLength' => 64,
			),
			'status' => array(
				'type' => 'string',
				'enum' => array( 'active', 'retired' ),
			),
		);
		foreach ( array( 'counters_started_at_gmt', 'created_at_gmt', 'last_seen_at_gmt' ) as $key ) {
			$properties[ $key ] = array(
				'type' => 'counters_started_at_gmt' === $key ? array( 'string', 'null' ) : 'string',
				'format' => 'date-time',
				'readonly' => true,
			);
		}
		return array(
			'$schema' => 'http://json-schema.org/draft-04/schema#',
			'title' => 'register',
			'type' => 'object',
			'properties' => $properties,
		);
	}

	/**
	 * Invalid register field.
	 *
	 * @param string $field Field name.
	 */
	private function invalid( string $field ): WP_Error {
		/* translators: %s: register field name. */
		return new WP_Error( 'rest_invalid_param', sprintf( __( 'Invalid register field: %s.', 'woocommerce-pos' ), $field ), array( 'status' => 400 ) );
	}

	/** Missing register response. */
	private function not_found(): WP_Error {
		return new WP_Error( 'wcpos_register_not_found', __( 'Register not found.', 'woocommerce-pos' ), array( 'status' => 404 ) );
	}

	/** Failed storage response. */
	private function write_error(): WP_Error {
		return new WP_Error( 'wcpos_register_write_failed', __( 'Register could not be saved.', 'woocommerce-pos' ), array( 'status' => 500 ) );
	}
}
