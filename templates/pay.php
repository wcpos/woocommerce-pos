<?php
/**
 * POS Order Pay template
 *
 * This template can be overridden by copying it to yourtheme/woocommerce-pos/pay.php.
 *
 * HOWEVER, this is not recommended , don't be surprised if your POS breaks
 */

defined( 'ABSPATH' ) || exit;
?>
<?php get_header() ?>
<form id="order_review" method="post"
	  action="<?php echo $_SERVER['REQUEST_URI']; ?>&key=<?php echo $order->get_order_key(); ?>">
	<div id="payment">
		<?php if ( $order->needs_payment() ) : ?>
			<ul class="wc_payment_methods payment_methods methods">
				<?php
				if ( ! empty( $available_gateways ) ) {
					foreach ( $available_gateways as $gateway ) {
						wc_get_template( 'checkout/payment-method.php', array( 'gateway' => $gateway ) );
					}
				} else {
					echo '<li class="woocommerce-notice woocommerce-notice--info woocommerce-info">' . apply_filters( 'woocommerce_no_available_payment_methods_message', esc_html__( 'Sorry, it seems that there are no available payment methods for your location. Please contact us if you require assistance or wish to make alternate arrangements.', 'woocommerce' ) ) . '</li>'; // @codingStandardsIgnoreLine
				}
				?>
			</ul>
		<?php endif; ?>
		<input type="hidden" name="woocommerce_pay" value="1"/>

		<?php echo apply_filters( 'woocommerce_order_button_html', '<button type="submit" class="button alt" name="test" id="place_order" value="Place Order" data-value="place_order">Place Order</button>' ); // @codingStandardsIgnoreLine ?>

		<?php wp_nonce_field( 'woocommerce-pay', 'woocommerce-pay-nonce' ); ?>
	</div>
</form>
<?php get_footer() ?>
