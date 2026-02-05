<?php

namespace WCPOS\WooCommercePOS\Tests\Helpers;

use WC_Order_Item_Product;
use WC_Product;
use WC_Product_Variation;

class POSLineItemHelper {
	/**
	 * Build a _woocommerce_pos_data meta entry for use in REST API meta_data arrays.
	 *
	 * @param array $data {
	 *     @type string $price         Current price. Required.
	 *     @type string $regular_price Regular price. Defaults to $price.
	 *     @type string $tax_status    Tax status. Default 'taxable'.
	 * }
	 *
	 * @return array Array with 'key' and 'value' keys.
	 */
	public static function pos_data_meta( array $data ): array {
		if ( isset( $data['price'] ) && ! isset( $data['regular_price'] ) ) {
			$data['regular_price'] = $data['price'];
		}

		return array(
			'key'   => '_woocommerce_pos_data',
			'value' => wp_json_encode(
				array(
					'price'         => (string) ( $data['price'] ?? '0' ),
					'regular_price' => (string) ( $data['regular_price'] ?? '0' ),
					'tax_status'    => $data['tax_status'] ?? 'taxable',
				)
			),
		);
	}

	/**
	 * Build a REST API line item array for a miscellaneous product (product_id=0).
	 *
	 * @param array $args {
	 *     @type string $name          Item name. Default 'Miscellaneous'.
	 *     @type string $price         Item price. Default '10.00'.
	 *     @type string $regular_price Regular price. Defaults to $price.
	 *     @type int    $quantity      Quantity. Default 1.
	 *     @type string $tax_status    Tax status. Default 'taxable'.
	 *     @type string $sku           Optional SKU.
	 * }
	 *
	 * @return array Line item array for REST API request body params.
	 */
	public static function misc_line_item( array $args = array() ): array {
		$args = wp_parse_args(
			$args,
			array(
				'name'          => 'Miscellaneous',
				'price'         => '10.00',
				'regular_price' => null,
				'quantity'      => 1,
				'tax_status'    => 'taxable',
				'sku'           => '',
			)
		);

		if ( null === $args['regular_price'] ) {
			$args['regular_price'] = $args['price'];
		}

		$line_item = array(
			'product_id' => 0,
			'name'       => $args['name'],
			'quantity'   => $args['quantity'],
			'price'      => $args['price'],
			'meta_data'  => array(
				self::pos_data_meta(
					array(
						'price'         => (string) $args['price'],
						'regular_price' => (string) $args['regular_price'],
						'tax_status'    => $args['tax_status'],
					)
				),
			),
		);

		if ( ! empty( $args['sku'] ) ) {
			$line_item['sku'] = $args['sku'];
		}

		return $line_item;
	}

	/**
	 * Build a REST API line item array for a regular product.
	 *
	 * @param WC_Product $product   The product to add.
	 * @param array      $overrides {
	 *     @type int    $quantity      Quantity. Default 1.
	 *     @type string $price         Override price. Default product price.
	 *     @type string $regular_price Override regular price. Default product regular price.
	 *     @type string $tax_status    Tax status. Default 'taxable'.
	 * }
	 *
	 * @return array Line item array for REST API request body params.
	 */
	public static function product_line_item( WC_Product $product, array $overrides = array() ): array {
		$price         = $overrides['price'] ?? $product->get_price();
		$regular_price = $overrides['regular_price'] ?? $product->get_regular_price();
		$tax_status    = $overrides['tax_status'] ?? 'taxable';
		$quantity      = $overrides['quantity'] ?? 1;

		if ( empty( $regular_price ) ) {
			$regular_price = $price;
		}

		$line_item = array(
			'product_id' => $product->get_id(),
			'quantity'   => $quantity,
			'meta_data'  => array(
				self::pos_data_meta(
					array(
						'price'         => (string) $price,
						'regular_price' => (string) $regular_price,
						'tax_status'    => $tax_status,
					)
				),
			),
		);

		if ( $product instanceof WC_Product_Variation ) {
			$line_item['variation_id'] = $product->get_id();
			$line_item['product_id']   = $product->get_parent_id();
		}

		return $line_item;
	}

	/**
	 * Add _woocommerce_pos_data meta directly to a WC_Order_Item_Product.
	 *
	 * For tests that create items directly rather than through the REST API.
	 *
	 * @param WC_Order_Item_Product $item The order item.
	 * @param array                 $data {
	 *     @type string $price         Current price. Required.
	 *     @type string $regular_price Regular price. Defaults to $price.
	 *     @type string $tax_status    Tax status. Default 'taxable'.
	 * }
	 *
	 * @return WC_Order_Item_Product The item with meta added.
	 */
	public static function add_pos_data_to_item( WC_Order_Item_Product $item, array $data ): WC_Order_Item_Product {
		if ( isset( $data['price'] ) && ! isset( $data['regular_price'] ) ) {
			$data['regular_price'] = $data['price'];
		}

		$item->add_meta_data(
			'_woocommerce_pos_data',
			wp_json_encode(
				array(
					'price'         => (string) ( $data['price'] ?? '0' ),
					'regular_price' => (string) ( $data['regular_price'] ?? '0' ),
					'tax_status'    => $data['tax_status'] ?? 'taxable',
				)
			)
		);

		return $item;
	}
}
