<?php
/**
 * Manual delivery of the cashier's selected receipt.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WCPOS\WooCommercePOS\Templates;
use WC_Order;
use WP_Error;

/** Delivers a published, store-enabled receipt through the WooCommerce mail transport. */
class Receipt_Email_Service {
	/**
	 * Resolve the currently enabled design, then hand its PDF to the shop's mailer.
	 *
	 * @param WC_Order $order Receipt order.
	 * @param int      $store_id Authorized picker store.
	 * @param string   $template_id Selected receipt ID.
	 * @param string   $email Single recipient.
	 * @param bool     $save_billing Whether to save this address to the order.
	 *
	 * @return WP_Error|\WP_REST_Response
	 */
	public function send( WC_Order $order, int $store_id, string $template_id, string $email, bool $save_billing ) {
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'wcpos_receipt_invalid_email', __( 'Enter a valid email address.', 'woocommerce-pos' ), array( 'status' => 400 ) );
		}
		$template = null;
		foreach ( Templates::resolve_templates( $store_id, 'receipt' ) as $candidate ) {
			if ( (string) $candidate['id'] === $template_id && 'receipt' === $candidate['type'] ) {
				$template = $candidate;
				break;
			}
		}
		if ( null === $template ) {
			return new WP_Error( 'wcpos_receipt_template_not_found', __( 'This receipt template is no longer available.', 'woocommerce-pos' ), array( 'status' => 404 ) );
		}

		$update_billing = $save_billing && $order->get_billing_email() !== $email;
		try {
			if ( $update_billing ) {
				$order->set_billing_email( $email );
			}
			// The sale preview uses live receipt data. Building it does not count a print.
			$data = ( new Receipt_Data_Builder() )->build( $order, 'live' );
			$renderer = new Template_Pdf_Service();
			if ( ! $renderer->is_native( $template ) && ( ! in_array( $template['engine'], array( 'thermal', 'logicless', 'legacy-php' ), true )
				|| ( '' === trim( (string) ( $template['content'] ?? '' ) ) && ! is_readable( $template['file_path'] ?? '' ) ) ) ) {
				return $this->render_error();
			}
			$pdf = $renderer->render( $template, $order, $data );
			if ( 0 !== strpos( $pdf, '%PDF-' ) ) {
				return $this->render_error();
			}
		} catch ( \Throwable $e ) {
			return $this->render_error();
		}

		// A private directory keeps the attachment filename readable on older WordPress versions.
		$directory = trailingslashit( get_temp_dir() ) . 'wcpos-' . wp_generate_uuid4();
		if ( ! mkdir( $directory, 0700 ) ) {
			return $this->mail_error();
		}
		$path = $directory . '/receipt-' . $order->get_id() . '.pdf';
		try {
			if ( file_put_contents( $path, $pdf ) !== strlen( $pdf ) ) {
				return $this->mail_error();
			}
			if ( $update_billing ) {
				$order->save();
			}
			/* translators: %s: order number. */
			$subject = sprintf( __( 'Receipt for order #%s', 'woocommerce-pos' ), $order->get_order_number() );
			$mailer = WC()->mailer();
			$message = $mailer->wrap_message( esc_html( $subject ), esc_html__( 'Your receipt is attached.', 'woocommerce-pos' ) );
			$transport = new \WC_Email();
			try {
				$accepted = $transport->send( $email, $subject, $message, "Content-Type: text/html\r\n", array( $path ) );
			} finally {
				// WC_Email::send removes these after a normal return. Its transport filter may throw.
				remove_filter( 'wp_mail_from', array( $transport, 'get_from_address' ) );
				remove_filter( 'wp_mail_from_name', array( $transport, 'get_from_name' ) );
				remove_filter( 'wp_mail_content_type', array( $transport, 'get_content_type' ) );
				// A manual send owns this WC_Email instance, including its constructor hooks.
				remove_action( 'phpmailer_init', array( $transport, 'handle_multipart' ) );
				remove_action( 'woocommerce_update_options_email_' . $transport->id, array( $transport, 'process_admin_options' ) );
				remove_filter( 'wp_get_attachment_image_attributes', array( $transport, 'prevent_lazy_loading_on_attachment' ), 1 );
			}
			if ( ! $accepted ) {
				return $this->mail_error();
			}
		} catch ( \Throwable $e ) {
			return $this->mail_error();
		} finally {
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
			rmdir( $directory );
		}
		return rest_ensure_response(
			array(
				'success' => true,
				'template_id' => $template_id,
			)
		);
	}

	/** The selected template cannot produce a receipt. */
	private function render_error(): WP_Error {
		return new WP_Error( 'wcpos_receipt_render_failed', __( 'Unable to render the selected receipt.', 'woocommerce-pos' ), array( 'status' => 422 ) );
	}

	/** The transport failed before acknowledging the send. */
	private function mail_error(): WP_Error {
		return new WP_Error( 'wcpos_receipt_email_failed', __( 'Your store could not send the receipt email.', 'woocommerce-pos' ), array( 'status' => 500 ) );
	}
}
