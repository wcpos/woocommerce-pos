<?php
/**
 * POS Order Pay template.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce-pos/pay.php.
 *
 * HOWEVER, this is not recommended , don't be surprised if your POS breaks
 */

\defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>"/>
	<meta name="viewport" content="width=device-width, initial-scale=1"/>
	<?php wp_head(); ?>
	<style>
		html, body, ul, li, fieldset {
			margin: 0 !important;
			padding: 0 !important;
		}

		body {
			color: #212121;
			font-family: -apple-system,
			BlinkMacSystemFont,
			'Segoe UI',
			Roboto,
			Helvetica,
			Arial,
			sans-serif,
			'Apple Color Emoji',
			'Segoe UI Emoji',
			'Segoe UI Symbol';
		}

		.woocommerce-page.woocommerce-order-pay #payment {
			background: transparent;
			padding: 0;
			margin: 0;
		}

		.woocommerce-page .payment_box {
			background: transparent;
			padding: 0 !important;
			margin: 0 !important;
		}

		.woocommerce-page #payment button#place_order {
			display: none;
		}
	</style>
	<script>
		window.addEventListener("message", ({data}) => {
			if (data.action && data.action === "wcpos-process-payment") {
				// submit checkout form
				const button = document.getElementById('place_order');
				if (button) {
					button.click();
				}
			}
		}, false);
	</script>
</head>

<body <?php body_class(); ?>>
<div class="woocommerce">
	<?php woocommerce_output_all_notices(); ?>

	<form id="order_review" method="post"
		  action="<?php echo $_SERVER['REQUEST_URI']; ?>&key=<?php echo $order->get_order_key(); ?>">
		<div id="payment">
			<?php if ( $order->needs_payment() ) { ?>
				<ul class="wc_payment_methods payment_methods methods">
					<?php if ( ! empty( $gateway ) ) { ?>
						<li class="wc_payment_method payment_method_<?php echo esc_attr( $gateway->id ); ?>">
							<input id="payment_method_<?php echo esc_attr( $gateway->id ); ?>" type="radio"
								   class="input-radio" name="payment_method"
								   value="<?php echo esc_attr( $gateway->id ); ?>" <?php checked( $gateway->chosen, true ); ?>
								   data-order_button_text="<?php echo esc_attr( $gateway->order_button_text ); ?>"/>

							<?php if ( $gateway->has_fields() || $gateway->get_description() ) { ?>
								<div class="payment_box payment_method_<?php echo esc_attr( $gateway->id ); ?>">
									<?php $gateway->payment_fields(); ?>
								</div>
							<?php } ?>
						</li>
					<?php } else { ?>
						<li class="woocommerce-notice woocommerce-notice--info woocommerce-info">
							<?php
							echo apply_filters(
								'woocommerce_no_available_payment_methods_message',
								esc_html__( 'Sorry, it seems that there are no available
						payment methods for your location. Please contact us if you require assistance or wish to make alternate
						arrangements.', 'woocommerce' )
                            );
							?>
						</li>
					<?php } ?>
				</ul>
			<?php } ?>

			<input type="hidden" name="woocommerce_pay" value="1"/>
			<?php // wc_get_template( 'checkout/terms.php' ); ?>
			<?php do_action( 'woocommerce_pay_order_before_submit' ); ?>
			<?php echo apply_filters( 'woocommerce_pay_order_button_html', '<button type="submit" class="button alt" id="place_order" value="' . esc_attr( $order_button_text ) . '" data-value="' . esc_attr( $order_button_text ) . '">' . esc_html( $order_button_text ) . '</button>' ); ?>
			<?php do_action( 'woocommerce_pay_order_after_submit' ); ?>
			<?php wp_nonce_field( 'woocommerce-pay', 'woocommerce-pay-nonce' ); ?>
		</div>
	</form>
</div>

<?php wp_footer(); ?>

</body>
</html>

