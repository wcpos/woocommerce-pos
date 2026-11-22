<?php
/**
 * The published HTML design for completed POS sale notifications.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services;

use WCPOS\WooCommercePOS\Templates;
use WCPOS\WooCommercePOS\Templates\Renderers\Logicless_Renderer;

/** One event binding on the existing email template taxonomy term, per site. */
class Customer_Email_Design {
	private const META_KEY = '_wcpos_customer_completed_order_template_id';

	private static function published( int $id ): ?\WP_Post {
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || 'wcpos_template' !== $post->post_type || 'publish' !== $post->post_status
			|| ! has_term( Templates::TYPE_CUSTOMER_EMAIL, 'wcpos_template_type', $post )
			|| 'logicless' !== get_post_meta( $id, '_template_engine', true ) ) {
			return null;
		}
		return $post;
	}

	/** Resolve the current binding again at send time in case publication changed. */
	public static function active(): ?\WP_Post {
		$term = get_term_by( 'slug', Templates::TYPE_CUSTOMER_EMAIL, 'wcpos_template_type' );
		return $term ? self::published( (int) get_term_meta( $term->term_id, self::META_KEY, true ) ) : null;
	}

	/**
	 * Save the event binding. Zero restores the stock order details.
	 *
	 * @param int $id Published design ID, or zero.
	 * @return true|\WP_Error
	 */
	public static function select( int $id ) {
		if ( $id && ! self::published( $id ) ) {
			return new \WP_Error( 'wcpos_invalid_email_design', __( 'Choose a published HTML email design.', 'woocommerce-pos' ), array( 'status' => 400 ) );
		}

		$term = get_term_by( 'slug', Templates::TYPE_CUSTOMER_EMAIL, 'wcpos_template_type' );
		if ( $term ) {
			if ( (int) get_term_meta( $term->term_id, self::META_KEY, true ) === $id ) {
				return true;
			}
			$saved = $id ? update_term_meta( $term->term_id, self::META_KEY, $id ) : delete_term_meta( $term->term_id, self::META_KEY );
			if ( $saved && ! is_wp_error( $saved ) ) {
				return true;
			}
		}
		return new \WP_Error( 'wcpos_email_design_save_failed', __( 'Could not save the email design selection.', 'woocommerce-pos' ), array( 'status' => 500 ) );
	}

	/**
	 * Buffer the entire design before emitting it so a malformed design can fall back.
	 *
	 * @param \WP_Post  $design Published HTML design.
	 * @param \WC_Order $order  Completed POS order.
	 */
	public static function render( \WP_Post $design, \WC_Order $order ): string {
		$data = ( new Receipt_Data_Builder() )->build( $order, 'live' );
		ob_start();
		try {
			( new Logicless_Renderer() )->render( array( 'content' => $design->post_content ), $order, $data );
			return trim( (string) ob_get_contents() );
		} finally {
			ob_end_clean();
		}
	}
}
