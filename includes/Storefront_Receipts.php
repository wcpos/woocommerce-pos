<?php
/**
 * Storefront receipt links.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS;

use WC_Abstract_Order;

/**
 * Registers storefront receipt links and order actions.
 */
class Storefront_Receipts {
	/**
	 * Register storefront hooks.
	 */
	public function __construct() {
		add_shortcode( 'wcpos_receipt', array( $this, 'receipt_shortcode' ) );

		if ( ! woocommerce_pos_request() ) {
			add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'add_my_account_action' ), 10, 2 );
		}
	}

	/**
	 * Render a receipt link.
	 *
	 * @param array|string $attributes Shortcode attributes.
	 *
	 * @return string Receipt link or an empty string when no permitted order is available.
	 */
	public function receipt_shortcode( $attributes ): string {
		$attributes = shortcode_atts(
			array(
				'order_id' => '',
				'link_text' => __( 'Download receipt (PDF)', 'woocommerce-pos' ),
				'format'    => 'pdf',
				'template'  => '',
				'id'        => '',
				'class'     => 'wcpos-receipt-link',
			),
			$attributes,
			'wcpos_receipt'
		);

		$explicit_order_id = '' !== trim( (string) $attributes['order_id'] );
		if ( $explicit_order_id ) {
			$order_id = absint( $attributes['order_id'] );
			if ( ! $order_id || ! is_user_logged_in() || ! current_user_can( 'view_order', $order_id ) ) {
				return '';
			}

			$order = wc_get_order( $order_id );
		} else {
			$order = $this->get_context_order();
		}

		if ( ! $order ) {
			return '';
		}

		$format   = 'html' === sanitize_key( (string) $attributes['format'] ) ? 'html' : 'pdf';
		$template = sanitize_text_field( (string) $attributes['template'] );
		$url      = self::get_receipt_url( $order, $format, $template );
		$id       = '' !== (string) $attributes['id'] ? ' id="' . esc_attr( (string) $attributes['id'] ) . '"' : '';

		return sprintf(
			'<a href="%1$s"%2$s class="%3$s">%4$s</a>',
			esc_url( $url ),
			$id,
			esc_attr( (string) $attributes['class'] ),
			esc_html( (string) $attributes['link_text'] )
		);
	}

	/**
	 * Add the receipt download to My Account order actions.
	 *
	 * @param array<string,array<string,string>> $actions Existing order actions.
	 * @param WC_Abstract_Order                  $order   Order object.
	 *
	 * @return array<string,array<string,string>> Filtered order actions.
	 */
	public function add_my_account_action( array $actions, WC_Abstract_Order $order ): array {
		/*
		 * Filters whether the My Account receipt button is enabled.
		 *
		 * @param bool              $enabled Whether the button is enabled.
		 * @param WC_Abstract_Order $order   Order object.
		 *
		 * @returns bool Whether the button is enabled.
		 *
		 * @since 1.9.11
		 *
		 * @hook woocommerce_pos_storefront_receipt_my_account_button
		 */
		$enabled = (bool) apply_filters( 'woocommerce_pos_storefront_receipt_my_account_button', true, $order );
		if ( ! $enabled || null === Templates::get_active_template_id( 'receipt' ) ) {
			return $actions;
		}

		/*
		 * Filters the order statuses that expose a storefront receipt.
		 *
		 * @param string[]          $statuses Eligible order statuses.
		 * @param WC_Abstract_Order $order    Order object.
		 *
		 * @returns string[] Eligible order statuses.
		 *
		 * @since 1.9.11
		 *
		 * @hook woocommerce_pos_storefront_receipt_order_statuses
		 */
		$statuses = (array) apply_filters(
			'woocommerce_pos_storefront_receipt_order_statuses',
			array( 'processing', 'completed', 'refunded' ),
			$order
		);
		if ( ! $order->has_status( $statuses ) ) {
			return $actions;
		}

		$actions['wcpos-receipt'] = array(
			'url'        => self::get_receipt_url( $order, 'pdf' ),
			'name'       => __( 'Receipt', 'woocommerce-pos' ),
			/* translators: %s: order number. */
			'aria-label' => sprintf( __( 'Download receipt for order #%s', 'woocommerce-pos' ), $order->get_order_number() ),
		);

		return $actions;
	}

	/**
	 * Resolve an order from a supported WooCommerce page context.
	 *
	 * @return WC_Abstract_Order|null Context order or null.
	 */
	private function get_context_order(): ?WC_Abstract_Order {
		if ( is_order_received_page() ) {
			$order = wc_get_order( absint( get_query_var( 'order-received' ) ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';

			return $order && hash_equals( $order->get_order_key(), $key ) ? $order : null;
		}

		if ( is_user_logged_in() && is_account_page() && is_wc_endpoint_url( 'view-order' ) ) {
			$order_id = absint( get_query_var( 'view-order' ) );
			if ( $order_id && current_user_can( 'view_order', $order_id ) ) {
				$order = wc_get_order( $order_id );

				return $order ? $order : null;
			}
		}

		return null;
	}

	/**
	 * Build a key-gated receipt URL.
	 *
	 * @param WC_Abstract_Order $order    Order object.
	 * @param string            $format   Receipt format.
	 * @param string            $template Optional template ID.
	 *
	 * @return string Receipt URL.
	 */
	private static function get_receipt_url( WC_Abstract_Order $order, string $format = 'pdf', string $template = '' ): string {
		$args = array( 'key' => $order->get_order_key() );

		if ( 'pdf' === $format ) {
			$args['format'] = 'pdf';
		}

		if ( '' !== $template ) {
			$args['template'] = $template;
		}

		return add_query_arg( $args, wcpos_checkout_url( 'wcpos-receipt/' . $order->get_id() ) );
	}
}
