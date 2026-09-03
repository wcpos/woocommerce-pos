<?php
/**
 * Base payment capture-mode handler.
 *
 * @package WCPOS\WooCommercePOS\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Payments\Contract;

\defined( 'ABSPATH' ) || die;

/** Supplies the common unsupported and no-op handler behavior. */
abstract class Abstract_Capture_Mode_Handler implements Capture_Mode_Handler_Interface {
	/**
	 * Unsupported bootstrap.
	 *
	 * @param \WC_Payment_Gateway $gateway Gateway instance.
	 * @param array               $context Payment context.
	 *
	 * @return array|\WP_Error
	 */
	public function bootstrap( \WC_Payment_Gateway $gateway, array $context ) {
		return $this->unsupported();
	}

	/**
	 * Unsupported intent.
	 *
	 * @param array $row     Payment row.
	 * @param array $context Payment context.
	 *
	 * @return array|\WP_Error
	 */
	public function intent( array $row, array $context ) {
		return $this->unsupported();
	}

	/**
	 * Unsupported capture.
	 *
	 * @param array $row     Payment row.
	 * @param array $context Payment context.
	 *
	 * @return array|\WP_Error
	 */
	public function capture( array $row, array $context ) {
		return $this->unsupported();
	}

	/**
	 * Status is unchanged by default.
	 *
	 * @param array $row Payment row.
	 *
	 * @return array|\WP_Error
	 */
	public function status( array $row ) {
		return $row;
	}

	/**
	 * Void a row by default.
	 *
	 * @param array  $row    Payment row.
	 * @param string $reason Void reason.
	 *
	 * @return array|\WP_Error
	 */
	public function void( array $row, string $reason ) {
		$row['status'] = 'voided';
		return $row;
	}

	/**
	 * Unsupported refund.
	 *
	 * @param array  $row       Payment row.
	 * @param int    $refund_id Refund ID.
	 * @param string $amount    Refund amount.
	 *
	 * @return array|\WP_Error
	 */
	public function refund( array $row, int $refund_id, string $amount ) {
		return $this->unsupported();
	}

	/** Build the standard unsupported response. */
	private function unsupported(): \WP_Error {
		return new \WP_Error(
			'wcpos_capture_mode_unsupported',
			__( 'This payment capture mode does not support that operation.', 'woocommerce-pos' ),
			array( 'status' => 501 )
		);
	}
}
