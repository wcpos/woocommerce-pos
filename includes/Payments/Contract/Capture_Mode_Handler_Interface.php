<?php
/**
 * Payment capture-mode contract.
 *
 * @package WCPOS\WooCommercePOS\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Payments\Contract;

\defined( 'ABSPATH' ) || die;

/** Contract implemented by payment capture-mode handlers. */
interface Capture_Mode_Handler_Interface {
	/**
	 * Describe a gateway's capture behavior.
	 *
	 * @param \WC_Payment_Gateway $gateway Gateway instance.
	 */
	public function describe( \WC_Payment_Gateway $gateway ): array;

	/**
	 * Bootstrap a payment provider.
	 *
	 * @param \WC_Payment_Gateway $gateway Gateway instance.
	 * @param array               $context Payment context.
	 *
	 * @return array|\WP_Error
	 */
	public function bootstrap( \WC_Payment_Gateway $gateway, array $context );

	/**
	 * Create or update an intent.
	 *
	 * @param array $row     Payment row.
	 * @param array $context Payment context.
	 *
	 * @return array|\WP_Error
	 */
	public function intent( array $row, array $context );

	/**
	 * Capture a payment.
	 *
	 * @param array $row     Payment row.
	 * @param array $context Payment context.
	 *
	 * @return array|\WP_Error
	 */
	public function capture( array $row, array $context );

	/**
	 * Refresh payment status.
	 *
	 * @param array $row Payment row.
	 *
	 * @return array|\WP_Error
	 */
	public function status( array $row );

	/**
	 * Void a payment.
	 *
	 * @param array  $row    Payment row.
	 * @param string $reason Void reason.
	 *
	 * @return array|\WP_Error
	 */
	public function void( array $row, string $reason );

	/**
	 * Refund a payment.
	 *
	 * @param array  $row       Payment row.
	 * @param int    $refund_id Refund ID.
	 * @param string $amount    Refund amount.
	 *
	 * @return array|\WP_Error
	 */
	public function refund( array $row, int $refund_id, string $amount );
}
