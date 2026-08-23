<?php
/**
 * Product_Brands_Controller.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\API\V1;

\defined( 'ABSPATH' ) || die;

if ( ! class_exists( 'WC_REST_Product_Brands_Controller' ) ) {
	return;
}

use WC_REST_Product_Brands_Controller;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Product Brands controller class.
 *
 * @NOTE: methods not prefixed with wcpos_ will override WC_REST_Product_Brands_Controller methods
 */
class Product_Brands_Controller extends WC_REST_Product_Brands_Controller {
	use Traits\Term_Controller;
	use Traits\Uuid_Handler;
	use Traits\WCPOS_REST_API;

	/**
	 * Taxonomy served by this controller; also builds both WC hook names.
	 */
	protected const WCPOS_TAXONOMY = 'product_brand';

	/**
	 * Method registered on woocommerce_rest_prepare_product_brand.
	 */
	protected const WCPOS_RESPONSE_FILTER = 'wcpos_product_brands_response';

	/**
	 * Method registered on woocommerce_rest_product_brand_query.
	 */
	protected const WCPOS_QUERY_FILTER = 'wcpos_product_brand_query';

	/**
	 * Collection label used in bulk-ID fast-path error messages.
	 */
	protected const WCPOS_ID_ERROR_LABEL = 'product brand';

	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wcpos/v1';

	/**
	 * Filter the brand response.
	 *
	 * Kept as a named method because third-party code detaches it by this exact
	 * callback tuple; the behaviour lives in Traits\Term_Controller.
	 *
	 * @param WP_REST_Response $response The response object.
	 * @param object           $item     The original term object.
	 * @param WP_REST_Request  $request  Request object.
	 *
	 * @return WP_REST_Response $response The response object.
	 */
	public function wcpos_product_brands_response( WP_REST_Response $response, object $item, WP_REST_Request $request ): WP_REST_Response {
		return $this->wcpos_term_response( $response, $item );
	}

	/**
	 * Filter the brand query.
	 *
	 * Kept as a named method because third-party code detaches it by this exact
	 * callback tuple; the behaviour lives in Traits\Term_Controller.
	 *
	 * @param array           $args    Query arguments.
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return array
	 */
	public function wcpos_product_brand_query( array $args, WP_REST_Request $request ): array {
		return $this->wcpos_term_query( $args );
	}
}
