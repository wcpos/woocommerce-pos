<?php
/**
 * Coupon types contract controller.
 *
 * @package WCPOS\WooCommercePOS\API\V2
 */

namespace WCPOS\WooCommercePOS\API\V2;

\defined( 'ABSPATH' ) || die;

use WC_REST_Controller;
use WCPOS\WooCommercePOS\Logger;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/** Exposes the coupon types declared to the till. */
class Coupon_Types_Controller extends WC_REST_Controller {
	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wcpos/v2';

	/**
	 * REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'coupon-types';

	/** Register the coupon types collection route. */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Check access to the coupon types contract.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		return current_user_can( 'access_woocommerce_pos' )
			? true
			: new WP_Error( 'rest_forbidden', __( 'Sorry, you cannot view coupon types.', 'woocommerce-pos' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/**
	 * Return the declared coupon types.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ) {
		return rest_ensure_response(
			array(
				'schema'       => 1,
				'coupon_types' => $this->descriptors(),
			)
		);
	}

	/** Validate declarations and return only the public descriptor fields. */
	private function descriptors(): array {
		/**
		 * Filters the coupon types a plugin declares to the till (`GET wcpos/v2/coupon-types`).
		 *
		 * By declaring a type with `calc: "store"`, a plugin promises that:
		 * (1) on every order write that adds or changes a coupon of that type, WooCommerce's own totals
		 *     (`coupon_lines[].discount`, `discount_tax`, `discount_total`, `total`) carry the store's amount,
		 *     capped at what the order allows;
		 * (2) the balance behind it is settled when the order reaches a paid status, and at no other time;
		 * (3) it answers `wcpos_payment_pre_capture` whenever the amount it would settle is no longer the
		 *     amount on the order.
		 * A plugin that cannot keep all three must not declare the type.
		 *
		 * Each entry is `array( 'type' => string, 'calc' => 'store', 'offline' => 'none', 'provider' => slug )`.
		 * Core drops an entry whose type is `percent`, `fixed_cart` or `fixed_product`, is empty or not a string,
		 * or repeats an earlier type (the first wins), and one whose `calc` is not `store` or whose `offline`
		 * is not `none`. Each drop is logged; the route never answers an error for it.
		 *
		 * @since 2.0.7
		 *
		 * @param array $descriptors The declared coupon types. Default empty.
		 */
		$declared = apply_filters( 'wcpos_coupon_type_descriptors', array() );
		if ( ! is_array( $declared ) ) {
			Logger::log( 'WCPOS coupon types: the wcpos_coupon_type_descriptors filter did not return a list; no types declared.' );
			return array();
		}

		$kept = array();
		foreach ( $declared as $entry ) {
			$type     = is_array( $entry ) && is_string( $entry['type'] ?? null ) ? $entry['type'] : null;
			$provider = is_array( $entry ) && is_string( $entry['provider'] ?? null ) ? $entry['provider'] : null;
			if ( ! is_array( $entry ) ) {
				$message = 'WCPOS coupon types: dropped a declared entry that is not an object.';
			} elseif ( null === $type || '' === $type ) {
				$message = 'WCPOS coupon types: dropped an entry with no type.';
			} elseif ( in_array( $type, array( 'percent', 'fixed_cart', 'fixed_product' ), true ) ) {
				$message = 'WCPOS coupon types: dropped a core coupon type, which cannot be redeclared.';
			} elseif ( 'store' !== ( $entry['calc'] ?? null ) || 'none' !== ( $entry['offline'] ?? null ) ) {
				$message = 'WCPOS coupon types: dropped a type whose calc or offline value is not supported.';
			} elseif ( isset( $kept[ $type ] ) ) {
				$message = 'WCPOS coupon types: dropped a repeated type; the first declaration wins.';
			} else {
				$kept[ $type ] = array(
					'type'     => $type,
					'calc'     => 'store',
					'offline'  => 'none',
					'provider' => $provider ?? '',
				);
				continue;
			}

			Logger::log(
				$message,
				array(
					'type'     => $type,
					'provider' => $provider,
				)
			);
		}
		return array_values( $kept );
	}

	/** Return the coupon types envelope schema. */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wcpos_coupon_types',
			'type'       => 'object',
			'properties' => array(
				'schema'       => array( 'type' => 'integer' ),
				'coupon_types' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'type'     => array( 'type' => 'string' ),
							'calc'     => array(
								'type' => 'string',
								'enum' => array( 'store' ),
							),
							'offline'  => array(
								'type' => 'string',
								'enum' => array( 'none' ),
							),
							'provider' => array( 'type' => 'string' ),
						),
					),
				),
			),
		);
	}
}
