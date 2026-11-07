<?php
/**
 * Stand-in for templates/payment.php: prints one price the way the pay page does.
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates
 */

echo wc_price( 1234.5, array( 'currency' => 'EUR' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wc_price() returns escaped markup.
