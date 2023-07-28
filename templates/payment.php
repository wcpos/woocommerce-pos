<?php
/**
 * POS Order Pay template.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce-pos/payment.php.
 * HOWEVER, this is not recommended , don't be surprised if your POS breaks
 */

defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>"/>
	<meta name="viewport" content="width=device-width, initial-scale=1"/>
	<?php wp_head(); ?>
	<style>
		html, body, ul, li, fieldset, address {
			font-family: sans-serif;
			font-size: 14px;
			margin: 0 !important;
			padding: 0 !important;
		}
		h1, h2, h3, h4, h5, h6 {
			margin: 0;
			padding: 0;
			font-weight: 600;
			line-height: 1.3;
		}
		h1 {
			font-size: 18px;
			margin-bottom: 15px;
		}
		h2 {
			font-size: 16px;
			margin-bottom: 12px;
		}
		h3 {
			font-size: 14px;
			margin-bottom: 10px;
		}
		h4 {
			font-size: 14px;
			margin-bottom: 8px;
		}
		h5 {
			font-size: 14px;
			margin-bottom: 6px;
		}
		h6 {
			font-size: 12px;
			margin-bottom: 4px;
		}

    .woocommerce-pos-troubleshooting {
        border-left: 4px solid #007cba; /* For the blue vertical line */
        padding: 5px 10px; /* Padding around the text */
        background: #fff; /* White background */
        box-shadow: 0 1px 1px rgba(0,0,0,.04); /* Subtle shadow effect */
    }

		.woocommerce-pos-troubleshooting p.link {
				margin: 0;
		}

    .woocommerce-pos-troubleshooting h3 {
        margin: 0.5em 0;
        padding: 0;
        font-size: 1em;
        font-weight: 600;
    }

    .woocommerce-pos-troubleshooting div {
        flex: 1;
        margin-right: 20px;
    }

    .woocommerce-pos-troubleshooting input[type="checkbox"] {
        margin-right: 5px;
    }

    .woocommerce-pos-troubleshooting button {
        margin-top: 20px;
        background: #007cba;
        border: none;
        color: #fff;
        padding: 10px 15px;
        border-radius: 3px;
        cursor: pointer;
    }

    .woocommerce-pos-troubleshooting button:hover {
        background: #005a87;
    }

    .woocommerce-error {
			background-color: #f8d7da;
			color: #721c24;
			border-color: #f5c6cb;
			padding: 5px;
			margin-bottom: 20px;
		}

		.cashier {
			padding: 5px;
		}

		.cashier .cashier-name {
			font-size: 14px;
			font-weight: bold;
		}

		.current-user {
			padding: 5px;
			margin-bottom: 20px;
		}

		.current-user .user-name {
			font-size: 14px;
			font-weight: bold;
			cursor: pointer;
			color: #007acc; /* Change this color to your preferred link color */
			text-decoration: underline;
		}

		.coupons {
			text-align: right;
		}
		.coupons h3 {
			margin: 10px 0;
		}

		/* Style for Customer Details */
		.woocommerce-customer-details {
			margin-bottom: 20px;
		}
		.woocommerce-columns {
			display: flex;
			justify-content: space-between;
			margin-bottom: 10px;
		}
		.woocommerce-column {
			flex: 0 0 calc(50% - 10px);
			padding: 5px;
		}
		address {
			font-size: 12px;
			line-height: 1.4;
		}
		address p {
			margin-bottom: 3px;
		}
		.woocommerce-customer-details--phone,
		.woocommerce-customer-details--email {
			font-size: 12px;
			margin-bottom: 3px;
		}

		/* Style for Order Details Table */
		table.shop_table {
			border-collapse: collapse !important;
			width: 100% !important;
			margin-bottom: 20px;
		}
		table.shop_table thead tr th,
		table.shop_table tbody tr td,
		table.shop_table tfoot tr td {
			border: none;
			padding: 5px;
			text-align: left;
		}
		table.shop_table thead tr th {
			font-weight: bold;
			border-bottom: 2px solid #ddd;
		}
		table.shop_table tbody tr td {
			border-bottom: 1px solid #ddd;
		}
		table.shop_table tfoot tr th {
			padding: 5px;
			text-align: right;
		}
		table.shop_table ul.wc-item-meta {
			padding: 0;
			list-style: none;
			margin-top: 5px !important;
		}
		table.shop_table ul.wc-item-meta li {
			font-size: 12px;
		}
		table.shop_table ul.wc-item-meta p {
			display: inline-block;
			margin: 0;
		}

		/* Style for Payment List Accordion */
		.wc_payment_methods ul {
			padding: 0;
			margin: 0;
		}
		.wc_payment_methods li {
			border-top: 1px solid #ddd;
			margin-bottom: 10px;
			list-style: none;
		}
		.wc_payment_methods li label {
			display: inline-block;
			padding: 10px;
			cursor: pointer;
			margin-bottom: 0;
		}
		.wc_payment_methods li div.payment_box {
			display: none;
			padding: 10px;
		}
		.wc_payment_methods li input[type="radio"] {
			display: inline-block;
			margin-right: 10px;
			vertical-align: middle;
		}
		.wc_payment_methods li input[type="radio"] + label {
			display: inline-block;
			vertical-align: middle;
		}
		.wc_payment_methods li input[type="radio"]:checked + label {
			font-weight: bold;
		}
		.wc_payment_methods li input[type="radio"]:checked + label + div.payment_box {
			display: block;
			padding-left: 42px;
			background-color: #f7f7f7;
		}
		.wc_payment_methods li fieldset {
			border: none;
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
					const event = new MouseEvent('click', {
						bubbles: true,
						cancelable: true,
						view: window
					});

					button.dispatchEvent(event);
				}
			}
		}, false);
	</script>
</head>

<body <?php body_class(); ?>>
<div class="woocommerce"><?php
    global $wp_styles, $wp_scripts;
    $styleHandles = $wp_styles->queue;
    $scriptHandles = $wp_scripts->queue;

    $style_exclude_list = apply_filters(
        'woocommerce_pos_payment_template_dequeue_style_handles',
        woocommerce_pos_get_settings( 'checkout', 'dequeue_style_handles' )
    );

    $script_exclude_list = apply_filters(
        'woocommerce_pos_payment_template_dequeue_script_handles',
        woocommerce_pos_get_settings( 'checkout', 'dequeue_script_handles' )
    );

    $mergedStyleHandles = array_unique(array_merge($styleHandles, $style_exclude_list));
    $mergedScriptHandles = array_unique(array_merge($scriptHandles, $script_exclude_list));
	?>

	<div class="woocommerce-pos-troubleshooting notice notice-warning is-dismissible">
		<p class="link">
			<a href="#" class="toggle-troubleshooting"><?php _e('Having problems with the checkout?', 'woocommerce-pos'); ?></a>
		</p>
		<form id="troubleshooting-form" method="POST" style="display: none;">
			<p><?php _e('Scripts and styles may interfere with the custom payment template, use this form to dequeue any problematic scripts.', 'woocommerce-pos'); ?></p>
			<div style="display: flex; justify-content: space-between;">
				<div>
					<h3><?php _e('Styles', 'woocommerce-pos'); ?></h3>
            <?php foreach ($mergedStyleHandles as $handle) :
                $checked = !in_array($handle, $style_exclude_list) ? 'checked' : '';
                ?>
							<input type="checkbox" id="<?php echo $handle; ?>" name="styles[]" value="<?php echo $handle; ?>" <?php echo $checked; ?>>
							<label for="<?php echo $handle; ?>"><?php echo $handle; ?></label>
							<input type="hidden" name="all_styles[]" value="<?php echo $handle; ?>"><br>
            <?php endforeach; ?>
				</div>
				<div>
					<h3><?php _e('Scripts', 'woocommerce-pos'); ?></h3>
            <?php foreach ($mergedScriptHandles as $handle) :
                $checked = !in_array($handle, $script_exclude_list) ? 'checked' : '';
                ?>
							<input type="checkbox" id="<?php echo $handle; ?>" name="scripts[]" value="<?php echo $handle; ?>" <?php echo $checked; ?>>
							<label for="<?php echo $handle; ?>"><?php echo $handle; ?></label>
							<input type="hidden" name="all_scripts[]" value="<?php echo $handle; ?>"><br>
            <?php endforeach; ?>
				</div>
			</div>
			<input type="hidden" name="troubleshooting_form_nonce" value="<?php echo $troubleshooting_form_nonce; ?>" />
			<button type="submit"><?php _e('Submit', 'woocommerce-pos'); ?></button>
			<a href="#" class="toggle-troubleshooting"><?php _e('Close', 'woocommerce-pos'); ?></a>
		</form>
	</div>

	<?php woocommerce_output_all_notices(); ?>

	<div class="cashier">
		<span><?php esc_html_e( 'Cashier: ', 'woocommerce-pos' ); ?></span>
		<span class="cashier-name"><?php echo esc_html( $cashier->display_name ); ?></span>
	</div>

	<div class="current-user">
		<span><?php esc_html_e( 'Paying as customer: ', 'woocommerce-pos' ); ?></span>
		<?php $woocommerce_pos_customer = wp_get_current_user(); ?>
		<span class="user-name"><?php echo 0 === $woocommerce_pos_customer->ID ? esc_html__( 'Guest', 'woocommerce-pos' ) : esc_html( $woocommerce_pos_customer->display_name ); ?></span>
	</div>

	<div class="address-fields" style="display: none;">
		<section class="woocommerce-customer-details">

			<section class="woocommerce-columns woocommerce-columns--2 woocommerce-columns--addresses col2-set addresses">
				<div class="woocommerce-column woocommerce-column--1 woocommerce-column--billing-address col-1">


					<h2 class="woocommerce-column__title"><?php esc_html_e( 'Billing address', 'woocommerce' ); ?></h2>

					<address>
						<?php echo wp_kses_post( $order->get_formatted_billing_address( esc_html__( 'N/A', 'woocommerce' ) ) ); ?>

						<?php if ( $order->get_billing_phone() ) : ?>
							<p class="woocommerce-customer-details--phone"><?php echo esc_html( $order->get_billing_phone() ); ?></p>
						<?php endif; ?>

						<?php if ( $order->get_billing_email() ) : ?>
							<p class="woocommerce-customer-details--email"><?php echo esc_html( $order->get_billing_email() ); ?></p>
						<?php endif; ?>
					</address>

				</div><!-- /.col-1 -->

				<div class="woocommerce-column woocommerce-column--2 woocommerce-column--shipping-address col-2">
					<h2 class="woocommerce-column__title"><?php esc_html_e( 'Shipping address', 'woocommerce' ); ?></h2>
					<address>
						<?php echo wp_kses_post( $order->get_formatted_shipping_address( esc_html__( 'N/A', 'woocommerce' ) ) ); ?>

						<?php if ( $order->get_shipping_phone() ) : ?>
							<p class="woocommerce-customer-details--phone"><?php echo esc_html( $order->get_shipping_phone() ); ?></p>
						<?php endif; ?>
					</address>
				</div><!-- /.col-2 -->

			</section><!-- /.col2-set -->



			<?php do_action( 'woocommerce_order_details_after_customer_details', $order ); ?>

		</section>
	</div>

	<div class="coupons">
		<form method="post" action="">
			<input type="hidden" name="pos_coupon_nonce" value="<?php echo $coupon_nonce; ?>" />
			<input type="text" name="pos_coupon_code" class="input-text" placeholder="<?php esc_attr_e( 'Coupon code', 'woocommerce' ); ?>" id="pos_coupon_code" value="" />
			<button type="submit" class="button" name="pos_apply_coupon" value="<?php esc_attr_e( 'Apply coupon', 'woocommerce' ); ?>">
				<?php esc_html_e( 'Apply coupon', 'woocommerce' ); ?>
			</button>

			<?php
			$coupons = $order->get_items( 'coupon' );
			if ( $coupons ) {
				echo '<h3>' . __('Applied coupons', 'woocommerce') . '</h3>';
				echo '<ul>';
				foreach ( $coupons as $coupon ) {
					echo '<li>' . esc_html( $coupon->get_code() ) . ' <button type="submit" class="button" name="pos_remove_coupon" value="' . esc_attr( $coupon->get_code() ) . '">' . esc_html__( 'Remove', 'woocommerce' ) . '</button></li>';
				}
				echo '</ul>';
			}
			?>
		</form>
	</div>

	<?php wc_get_template( 'checkout/form-pay.php', array( 'order' => $order, 'available_gateways' => $available_gateways, 'order_button_text' => $order_button_text ) ); ?>

</div>

<?php wp_footer(); ?>

<script>
	document.querySelector('.current-user .user-name').addEventListener('click', () => {
		const addressFields = document.querySelector('.address-fields');
		addressFields.style.display = addressFields.style.display === 'none' ? 'block' : 'none';
	});
	document.querySelectorAll('.toggle-troubleshooting').forEach(item => {
		item.addEventListener('click', event => {
			event.preventDefault();
			const form = document.getElementById('troubleshooting-form');
			form.style.display = form.style.display === 'none' ? 'block' : 'none';
		});
	});
</script>

</body>
</html>
