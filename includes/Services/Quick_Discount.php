<?php
/**
 * Request-scoped quick discount coupons.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WCPOS\WooCommercePOS\Sync\Meta_Entry;

/**
 * Makes cashier intent available to WooCommerce only during a POS order write.
 * Never install these filters globally: storefront customers must not be able
 * to redeem a till's virtual coupon. Callers must clear in a finally block.
 *
 * Accepted risk: an app-chosen code can share a real coupon's code; the virtual
 * coupon wins during this write. Negative fees remain accepted, unchanged.
 *
 * @see https://github.com/wcpos/roadmap/issues/91
 * @see https://github.com/wcpos/woocommerce-pos/issues/1504
 */
final class Quick_Discount {
	/** Coupon-line metadata carrying cashier intent. */
	public const META_KEY = '_wcpos_quick_discount';

	/**
	 * Intent indexed by normalized coupon code, never shared between writes.
	 *
	 * @var array<string, array{discount_type:string, amount:string}>
	 */
	private $intents = array();

	/**
	 * Read and validate array or JSON intent, distinguishing absent from invalid.
	 *
	 * @param array $line Requested coupon line.
	 * @return array{discount_type:string, amount:string}|null|\WP_Error
	 */
	public static function intent_from_line( array $line ) {
		$intent = null;
		foreach ( $line['meta_data'] ?? array() as $entry ) {
			if ( self::META_KEY !== Meta_Entry::key( $entry ) ) {
				continue;
			}
			$value = Meta_Entry::value( $entry );
			$value = is_string( $value ) ? json_decode( $value, true ) : $value;
			$type  = is_array( $value ) ? ( $value['discount_type'] ?? null ) : null;
			$amount = is_array( $value ) ? ( $value['amount'] ?? null ) : null;
			if ( ! in_array( $type, array( 'percent', 'fixed_cart' ), true )
				|| ! is_string( $amount ) || ! preg_match( '/^(?:[0-9]+\.?[0-9]*|\.[0-9]+)$/D', $amount )
				|| ! is_finite( (float) $amount ) || (float) $amount <= 0
				|| ( 'percent' === $type && (float) $amount > 100 ) ) {
				return new \WP_Error( 'woocommerce_pos_rest_invalid_quick_discount', __( 'Quick discount requires a positive decimal amount and type percent (up to 100) or fixed_cart.', 'woocommerce-pos' ), array( 'status' => 400 ) );
			}
			// Canonicalize without rounding away percent precision or using locale.
			$amount = ltrim( $amount, '0' );
			$amount = false !== strpos( $amount, '.' ) ? rtrim( rtrim( $amount, '0' ), '.' ) : $amount;
			$intent = array(
				'discount_type' => $type,
				'amount' => 0 === strpos( $amount, '.' ) ? '0' . $amount : $amount,
			);
		}
		return $intent;
	}

	/**
	 * Read persisted intent; an ordinary or invalid historical item has none.
	 *
	 * @param \WC_Order_Item_Coupon $item Coupon item.
	 * @return array{discount_type:string, amount:string}|null
	 */
	public static function intent_from_item( \WC_Order_Item_Coupon $item ): ?array {
		$intent = self::intent_from_line(
			array(
				'meta_data' => array(
					array(
						'key' => self::META_KEY,
						'value' => $item->get_meta( self::META_KEY, true ),
					),
				),
			)
		);
		return is_array( $intent ) ? $intent : null;
	}

	/**
	 * Compare code AND normalized intent so same-code edits are not skipped.
	 *
	 * @param string     $code   Coupon code.
	 * @param array|null $intent Normalized intent, or null for an ordinary coupon.
	 * @return string
	 */
	public static function set_key( string $code, ?array $intent ): string {
		return self::normalize_code( $code ) . '|' . wp_json_encode( $intent );
	}

	/**
	 * Register only codes with valid intent, installing filters on first use.
	 *
	 * @param array $coupon_lines Requested coupon lines.
	 * @return \WP_Error|null Invalid intent, or null on success.
	 */
	public function register_from_lines( array $coupon_lines ) {
		foreach ( $coupon_lines as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$intent = self::intent_from_line( $line );
			if ( is_wp_error( $intent ) ) {
				return $intent;
			}
			if ( null === $intent || ! is_string( $line['code'] ?? null ) || '' === trim( $line['code'] ) ) {
				continue;
			}
			if ( ! $this->intents ) {
				add_filter( 'woocommerce_get_shop_coupon_data', array( $this, 'filter_data' ), 10, 2 );
				add_filter( 'woocommerce_order_recalculate_coupons_coupon_object', array( $this, 'filter_recalculation' ), 10, 2 );
			}
			$this->intents[ self::normalize_code( $line['code'] ) ] = $intent;
		}
		return null;
	}

	/**
	 * Supply manual coupon data only for registered string codes, never IDs.
	 * All omitted properties retain WC_Coupon's unrestricted defaults.
	 *
	 * @param array|false $data Existing manual coupon data.
	 * @param mixed       $code Constructor argument (code or ID).
	 * @return array|false
	 */
	public function filter_data( $data, $code ) {
		$intent = is_string( $code ) ? ( $this->intents[ self::normalize_code( $code ) ] ?? null ) : null;
		return null === $intent ? $data : array_merge(
			$intent,
			array(
				'individual_use' => false,
				'exclude_sale_items' => false,
				'free_shipping' => false,
			)
		);
	}

	/**
	 * Keep apply_coupon's own recalculation on the requested type and amount.
	 *
	 * @param \WC_Coupon|false $coupon WooCommerce's rebuilt coupon.
	 * @param string           $code   Coupon code.
	 * @return \WC_Coupon|false
	 */
	public function filter_recalculation( $coupon, string $code ) {
		return $this->coupon( $code ) ?? $coupon;
	}

	/**
	 * Get a virtual coupon through WooCommerce's manual-coupon mechanism.
	 *
	 * @param string $code Coupon code.
	 * @return \WC_Coupon|null
	 */
	public function coupon( string $code ): ?\WC_Coupon {
		$code = self::normalize_code( $code );
		return isset( $this->intents[ $code ] ) ? new \WC_Coupon( $code ) : null;
	}

	/**
	 * Persist intent and replace WooCommerce's empty ID-lookup coupon_info.
	 * set_coupon_discount_amounts() looks up a post ID, losing virtual coupon
	 * type/amount. Overwriting its short info lets later wp-admin Recalculate
	 * rebuild the discount correctly after these request filters are gone.
	 *
	 * @param \WC_Order $order Written order.
	 * @return void
	 */
	public function persist( \WC_Order $order ): void {
		foreach ( $order->get_coupons() as $item ) {
			$code   = self::normalize_code( $item->get_code() );
			$coupon = $this->coupon( $code );
			if ( $coupon ) {
				$item->update_meta_data( 'coupon_info', $coupon->get_short_info() );
				$item->update_meta_data( self::META_KEY, $this->intents[ $code ] );
				$item->save();
			}
		}
	}

	/**
	 * Remove this write's filters and registry, including after a failure.
	 *
	 * @return void
	 */
	public function clear(): void {
		remove_filter( 'woocommerce_get_shop_coupon_data', array( $this, 'filter_data' ), 10 );
		remove_filter( 'woocommerce_order_recalculate_coupons_coupon_object', array( $this, 'filter_recalculation' ), 10 );
		$this->intents = array();
	}

	/**
	 * Use the same code normalization at every registration and lookup seam.
	 *
	 * @param string $code Coupon code.
	 * @return string
	 */
	private static function normalize_code( string $code ): string {
		return wc_strtolower( wc_format_coupon_code( wc_clean( $code ) ) );
	}
}
