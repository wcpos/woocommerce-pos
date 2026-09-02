<?php
/** Payment capture-mode contract. */

namespace WCPOS\WooCommercePOS\Payments\Contract;

\defined( 'ABSPATH' ) || die;

/** Contract implemented by payment capture-mode handlers. */
interface Capture_Mode_Handler_Interface {
	/** Describe a gateway's capture behavior. */
	public function describe( \WC_Payment_Gateway $gateway ): array;

	/** Bootstrap a payment provider. */
	public function bootstrap( \WC_Payment_Gateway $gateway, array $context );

	/** Create or update an intent. */
	public function intent( array $row, array $context );

	/** Capture a payment. */
	public function capture( array $row, array $context );

	/** Refresh payment status. */
	public function status( array $row );

	/** Void a payment. */
	public function void( array $row, string $reason );

	/** Refund a payment. */
	public function refund( array $row, int $refund_id, string $amount );
}
