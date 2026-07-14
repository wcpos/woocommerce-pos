<?php
/**
 * WCPOS sync read surface.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.

/**
 * POS visibility contract (ADR 0014 phase 3, WP-M5) — the "servable to POS" definition.
 *
 * Source of truth, verified against woocommerce-pos origin/main (Services/Settings.php, c16d540d): a SINGLE
 * wp_option `woocommerce_pos_settings_visibility` holding ID LISTS — NOT the per-record `_pos_visibility`
 * postmeta that older versions used (that was removed to avoid spamming postmeta). Shape:
 *
 *   woocommerce_pos_settings_visibility = array(
 *     'products'   => array( <scope> => array(
 *        'pos_only'    => array( 'ids' => array( 1, 2, 3 ) ),   // hidden from the WEB store
 *        'online_only' => array( 'ids' => array( 4, 5, 6 ) ),   // hidden from the POS  <-- what we filter on
 *     ) ),
 *     'variations' => array( <scope> => array( 'pos_only' => ..., 'online_only' => ... ) ),
 *   )
 *
 * `<scope>` is `'default'` or a store id (multi-store, per-store visibility). The POS servable set is every
 * product/variation EXCEPT the `online_only` ids for the scope; `pos_only` is a web-store concern, not ours.
 * An empty/absent option means nothing is hidden — the safe default (feature simply off).
 */

final class Pos_Visibility {
	/**
	 * The single wp_option the POS app writes (db_prefix `woocommerce_pos_settings_` + `visibility`).
	 */
	public const OPTION = 'woocommerce_pos_settings_visibility';

	/**
	 * wooProductIds hidden from the POS for the given scope (the servable-set exclusion list).
	 */
	public function online_only_product_ids( string $scope = 'default' ): array {
		return $this->online_only_ids( 'products', $scope );
	}

	/**
	 * wooVariationIds hidden from the POS for the given scope.
	 */
	public function online_only_variation_ids( string $scope = 'default' ): array {
		return $this->online_only_ids( 'variations', $scope );
	}

	/**
	 * The `online_only` id list for one post-type + scope, sanitized to unique positive ints. Every level of
	 * the option can be missing (POS app not installed, scope never configured, key absent) → empty list, so
	 * a missing option can never accidentally hide the whole catalog.
	 */
	private function online_only_ids( string $post_type, string $scope ): array {
		$settings = get_option( self::OPTION, array() );
		if ( ! \is_array( $settings ) ) {
			return array();
		}
		$ids = $settings[ $post_type ][ $scope ]['online_only']['ids'] ?? array();
		if ( ! \is_array( $ids ) ) {
			return array();
		}
		$ids = array_filter(
			array_map( 'intval', $ids ),
			static function ( $id ) {
				return $id > 0;
			}
		);

		return array_values( array_unique( $ids ) );
	}
}
