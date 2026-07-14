<?php
/**
 * WCPOS sync read surface.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

use WC_REST_Products_Controller;
use WCPOS\WooCommercePOS\Services\Settings;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- SQL allowlists are fixed class constants; values use placeholders.

/**
 * Barcode resolve endpoint (POS scan path).
 *
 * Why: a cashier scanning an unknown barcode needs one round trip that
 * answers product OR variation directly — no parent->child REST dance.
 * Discovery is raw SQL over ids only (ADR 0003) and hookable so plugins
 * that own barcode storage can override resolution entirely; the returned
 * payload is hydrated through the filtered REST serialization path.
 *
 * Not final: the woocommerce_pos_sync_resolve_barcode_matches filter is applied
 * through a protected seam that the unit harness subclasses (its
 * apply_filters stub is identity).
 */
class Resolve_Controller extends WP_REST_Controller {
	use Endpoint_Permissions;

	private const PRODUCT_POST_TYPES_SQL = "('product','product_variation')";
	private const BARCODE_META_KEYS_SQL  = "('_sku','_global_unique_id','_barcode')";


	public function register_routes(): void {
		register_rest_route(
			Api::ROUTE_NAMESPACE,
			'/' . Api::ROUTE_PREFIX . 'resolve/barcode',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'resolve_barcode' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'code' => array( 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);
	}

	/**
	 * GET /resolve/barcode?code=<string>.
	 *
	 * 200 always: not-found is a result, not an error — the POS must be able
	 * to distinguish "no such barcode" from a failed request.
	 */
	public function resolve_barcode( WP_REST_Request $request ) {
		$started = microtime( true );
		$code    = trim( (string) ( $request->get_param( 'code' ) ?? '' ) );

		if ( '' === $code ) {
			return new WP_Error( 'woocommerce_pos_sync_missing_code', 'Barcode resolve requires a non-empty code parameter', array( 'status' => 400 ) );
		}

		// Discovery: raw SQL finds candidate ids only (ADR 0003 — discovery
		// only, never values). Exact match against the known barcode-bearing
		// meta keys plus the merchant's configured field; GROUP BY collapses
		// records matching on several keys.
		global $wpdb;
		$barcode_field = Settings::instance()->barcode_field();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_type FROM {$wpdb->posts} p"
				. " INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID"
				. ' WHERE (pm.meta_key IN ' . self::BARCODE_META_KEYS_SQL . ' OR pm.meta_key = %s)'
				. ' AND pm.meta_value = %s'
				. ' AND p.post_type IN ' . self::PRODUCT_POST_TYPES_SQL
				. " AND p.post_status = 'publish'"
				. ' GROUP BY p.ID ORDER BY p.ID ASC',
				$barcode_field,
				$code
			),
			ARRAY_A
		);
		$rows = \is_array( $rows ) ? $rows : array();

		$matches = array();
		foreach ( $rows as $row ) {
			$matches[] = array(
				'id'   => (int) $row['ID'],
				'type' => 'product_variation' === (string) $row['post_type'] ? 'variation' : 'product',
			);
		}
		$matches = $this->apply_matches_filter( $matches, $code );
		$visibility        = new Pos_Visibility();
		$hidden_products   = $visibility->online_only_product_ids();
		$hidden_variations = $visibility->online_only_variation_ids();
		$matches = array_values(
			array_filter(
				$matches,
				static function ( $candidate ) use ( $hidden_products, $hidden_variations ): bool {
					$id         = (int) ( $candidate['id'] ?? 0 );
					$type       = (string) ( $candidate['type'] ?? 'product' );
					$hidden_ids = 'variation' === $type ? $hidden_variations : $hidden_products;

					return ! \in_array( $id, $hidden_ids, true );
				}
			)
		);

		$match = null;
		if ( array() !== $matches ) {
			$first   = $matches[0];
			$product = wc_get_product( (int) ( $first['id'] ?? 0 ) );
			if ( $product ) {
				// Hydrate only the first match, and only through the filtered
				// REST path — mirrors class-changes-controller.php
				// revision_hash; raw projections are never trusted (ADR 0003).
				$serialization_request = new WP_REST_Request( 'GET', '/' );
				$controller            = new WC_REST_Products_Controller();
				$response              = rest_ensure_response( $controller->prepare_object_for_response( $product, $serialization_request ) );
				$payload               = rest_get_server()->response_to_data( $response, false );
				$payload               = apply_filters( 'woocommerce_pos_sync_serialized_product', $payload, $product, $serialization_request );
				$type                  = (string) ( $first['type'] ?? 'product' );
				$match                 = array(
					'id'        => (int) ( $first['id'] ?? 0 ),
					'type'      => $type,
					'parent_id' => 'variation' === $type ? (int) $product->get_parent_id() : 0,
					'payload'   => $payload,
				);
			}
		}

		return rest_ensure_response(
			array(
				'code'      => $code,
				'found'     => null !== $match,
				'match'     => $match,
				'ambiguous' => array_values( \array_slice( $matches, 1 ) ),
				'meta'      => array(
					'duration_ms' => round( ( microtime( true ) - $started ) * 1000, 3 ),
					'candidates'  => \count( $matches ),
				),
			)
		);
	}

	/**
	 * Seam for woocommerce_pos_sync_resolve_barcode_matches: plugins that own
	 * barcode storage (ADR 0003: value-based lookups must be hookable) can
	 * replace discovery output entirely. Protected so the unit harness —
	 * whose apply_filters stub is identity — can subclass the override path.
	 */
	protected function apply_matches_filter( array $matches, string $code ): array {
		$filtered = apply_filters( 'woocommerce_pos_sync_resolve_barcode_matches', $matches, $code );

		return \is_array( $filtered ) ? array_values( $filtered ) : array();
	}
}
