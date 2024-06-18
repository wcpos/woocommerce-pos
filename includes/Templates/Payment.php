<?php
/**
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS\Templates;

use Exception;
use WCPOS\WooCommercePOS\Services\Settings;

/**
 *
 */
class Payment {
	/**
	 * The order ID.
	 *
	 * @var int
	 */
	private $order_id;

	/**
	 * The gateway ID.
	 *
	 * @var string
	 */
	private $gateway_id;

	/**
	 * The order.
	 *
	 * @var \WC_Order
	 */
	private $order;

	/**
	 * The coupon nonce.
	 *
	 * @var string
	 */
	private $coupon_nonce;

	/**
	 * The troubleshooting form nonce.
	 *
	 * @var string
	 */
	private $troubleshooting_form_nonce;

		/**
		 * Disable wp_head setting.
		 *
		 * @var bool
		 */
	private $disable_wp_head;

	/**
	 * Disable wp_footer setting.
	 *
	 * @var bool
	 */
	private $disable_wp_footer;

	/**
	 * Constructor.
	 */
	public function __construct( int $order_id ) {
		$this->order_id   = $order_id;
		$this->check_troubleshooting_form_submission();
		// $this->gateway_id = isset( $_GET['gateway'] ) ? sanitize_key( wp_unslash( $_GET['gateway'] ) ) : '';

		$settings_service = Settings::instance();
		$this->disable_wp_head = (bool) $settings_service->get_settings( 'checkout', 'disable_wp_head' );
		$this->disable_wp_footer = (bool) $settings_service->get_settings( 'checkout', 'disable_wp_footer' );

		// this is a checkout page
		add_filter( 'woocommerce_is_checkout', '__return_true' );
		// remove the terms and conditions checkbox
		add_filter( 'woocommerce_checkout_show_terms', '__return_false' );

		// remove junk from head
		add_filter( 'show_admin_bar', '__return_false' );
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'wp_head', 'feed_links', 2 );
		remove_action( 'wp_head', 'index_rel_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'feed_links_extra', 3 );
		remove_action( 'wp_head', 'start_post_rel_link', 10, 0 );
		remove_action( 'wp_head', 'parent_post_rel_link', 10, 0 );
		remove_action( 'wp_head', 'adjacent_posts_rel_link', 10, 0 );
		remove_action( 'wp_head', 'wp_shortlink_wp_head', 10, 0 );
		remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head', 10, 0 );

		add_action( 'wp_enqueue_scripts', array( $this, 'remove_scripts_and_styles' ), 100 );
	}

	/**
	 * Each theme will apply its own styles to the checkout page.
	 * I want to keep it simple, so we remove all styles and scripts associated with the active theme.
	 * NOTE: This is not perfect, we don't know the theme handle, so we just take a guess from the source URL.
	 *
	 * @return void
	 */
	/**
	 * Remove enqueued scripts and styles.
	 *
	 * This function dequeues all scripts and styles that are not specified in the WooCommerce POS settings,
	 * unless they are specifically included by the 'woocommerce_pos_payment_template_dequeue_script_handles'
	 * and 'woocommerce_pos_payment_template_dequeue_style_handles' filters.
	 *
	 * @since 1.3.0
	 */
	public function remove_scripts_and_styles(): void {
		global $wp_styles, $wp_scripts;

		/**
		 * List of script handles to exclude from the payment template.
		 *
		 * @since 1.3.0
		 */
		$script_exclude_list = apply_filters(
			'woocommerce_pos_payment_template_dequeue_script_handles',
			woocommerce_pos_get_settings( 'checkout', 'dequeue_script_handles' )
		);

		/**
		 * List of style handles to exclude from the payment template.
		 *
		 * @since 1.3.0
		 */
		$style_exclude_list = apply_filters(
			'woocommerce_pos_payment_template_dequeue_style_handles',
			woocommerce_pos_get_settings( 'checkout', 'dequeue_style_handles' )
		);

		// Loop through all enqueued styles and dequeue those that are in the exclusion list
		if ( \is_array( $style_exclude_list ) ) {
			foreach ( $wp_styles->queue as $handle ) {
				if ( \in_array( $handle, $style_exclude_list, true ) ) {
					wp_dequeue_style( $handle );
				}
			}
		}

		// Loop through all enqueued scripts and dequeue those that are in the exclusion list
		if ( \is_array( $script_exclude_list ) ) {
			foreach ( $wp_scripts->queue as $handle ) {
				if ( \in_array( $handle, $script_exclude_list, true ) ) {
					wp_dequeue_script( $handle );
				}
			}
		}
	}


	/**
	 * @return void
	 */
	public function get_template(): void {
		if ( ! \defined( 'WOOCOMMERCE_CHECKOUT' ) ) {
			\define( 'WOOCOMMERCE_CHECKOUT', true );
		}

		// if ( ! $this->gateway_id ) {
		// wp_die( esc_html__( 'No gateway selected', 'woocommerce-pos' ) );
		// }

		do_action( 'woocommerce_pos_before_pay' );

		try {
			// initialize order and nonces before the user is switched to customer
			$this->initialize_order_and_nonces();

			/*
			 * The wp_set_current_user() function changes the global user object but it does not authenticate the user
			 * for the current session. This means that it will not affect nonce creation or validation because WordPress
			 * nonces are tied to the user's session.
			 *
			 * @TODO - is this the best way to do this?
			 */
			wp_set_current_user( $this->order->get_customer_id() );
			add_filter( 'nonce_user_logged_out', array( $this, 'nonce_user_logged_out' ), 10, 2 );

			// create nonce for customer
			// $nonce_field = '<input type="hidden" id="woocommerce-pay-nonce" name="woocommerce-pay-nonce" value="' . $this->create_customer_nonce() . '" />';

			// Logged in customer trying to pay for someone else's order.
			if ( ! current_user_can( 'pay_for_order', $this->order_id ) ) {
				wp_die( esc_html__( 'This order cannot be paid for. Please contact us if you need assistance.', 'woocommerce-pos' ) );
			}

			// We need to reload the gateways here to use the current customer details.
			WC()->payment_gateways()->init();
			$available_gateways = WC()->payment_gateways->get_available_payment_gateways();

			// if ( isset( $available_gateways[ $this->gateway_id ] ) ) {
			// $gateway         = $available_gateways[ $this->gateway_id ];
			// $gateway->chosen = true;
			// }

			$order_button_text = apply_filters( 'woocommerce_pay_order_button_text', __( 'Pay for order', 'woocommerce-pos' ) );

			include woocommerce_pos_locate_template( 'payment.php' );
		} catch ( Exception $e ) {
			wc_print_notice( $e->getMessage(), 'error' );
		}
	}

	/**
	 * Initialize the order and nonce properties.
	 */
	private function initialize_order_and_nonces(): void {
		$this->order = wc_get_order( $this->order_id );

		if ( ! $this->order || $this->order->get_id() !== $this->order_id ) {
			wp_die( esc_html__( 'Sorry, this order is invalid and cannot be paid for.', 'woocommerce-pos' ) );
		}

		if ( $this->order->is_paid() ) {
			wp_die( esc_html__( 'Sorry, this order has already been paid for.', 'woocommerce-pos' ) );
		}

		$this->coupon_nonce = wp_create_nonce( 'pos_coupon_action' );
		$this->troubleshooting_form_nonce = wp_create_nonce( 'troubleshooting_form_nonce' );
	}

	/**
	 * Save the settings from the troubleshooting form.
	 *
	 * @return void
	 */
	private function check_troubleshooting_form_submission(): void {
		// Check if our form has been submitted
		if ( isset( $_POST['troubleshooting_form_nonce'] ) ) {
			// Verify the nonce
			if ( ! wp_verify_nonce( $_POST['troubleshooting_form_nonce'], 'troubleshooting_form_nonce' ) ) {
				// Nonce doesn't verify, we should stop execution here
				die( 'Nonce value cannot be verified.' );
			}

			// This will hold your sanitized data
			$sanitized_data = array();

			// Sanitize all_styles array
			if ( isset( $_POST['all_styles'] ) && \is_array( $_POST['all_styles'] ) ) {
				$sanitized_data['all_styles'] = array_map( 'sanitize_text_field', $_POST['all_styles'] );
			}

			// Sanitize styles array
			if ( isset( $_POST['styles'] ) && \is_array( $_POST['styles'] ) ) {
				$sanitized_data['styles'] = array_map( 'sanitize_text_field', $_POST['styles'] );
			} else {
				$sanitized_data['styles'] = array();  // consider all styles unchecked if 'styles' is not submitted
			}

			// Sanitize all_scripts array
			if ( isset( $_POST['all_scripts'] ) && \is_array( $_POST['all_scripts'] ) ) {
				$sanitized_data['all_scripts'] = array_map( 'sanitize_text_field', $_POST['all_scripts'] );
			}

			// Sanitize scripts array
			if ( isset( $_POST['scripts'] ) && \is_array( $_POST['scripts'] ) ) {
				$sanitized_data['scripts'] = array_map( 'sanitize_text_field', $_POST['scripts'] );
			} else {
				$sanitized_data['scripts'] = array();  // consider all scripts unchecked if 'scripts' is not submitted
			}

			// Calculate unchecked styles and scripts
			$unchecked_styles  = isset( $sanitized_data['all_styles'] ) ? array_diff( $sanitized_data['all_styles'], $sanitized_data['styles'] ) : array();
			$unchecked_scripts = isset( $sanitized_data['all_scripts'] ) ? array_diff( $sanitized_data['all_scripts'], $sanitized_data['scripts'] ) : array();

			// Sanitize disable_wp_head and disable_wp_footer options
			$disable_wp_head = isset( $_POST['disable_wp_head'] ) ? (bool) $_POST['disable_wp_head'] : false;
			$disable_wp_footer = isset( $_POST['disable_wp_footer'] ) ? (bool) $_POST['disable_wp_footer'] : false;

			// @TODO - the save settings function should allow saving by key
			$checkout_settings = woocommerce_pos_get_settings( 'checkout' );
			$new_settings      = array_merge(
				$checkout_settings,
				array(
					'disable_wp_head' => $disable_wp_head,
					'disable_wp_footer' => $disable_wp_footer,
					'dequeue_style_handles'  => $unchecked_styles,
					'dequeue_script_handles' => $unchecked_scripts,
				)
			);

			$settings_service = Settings::instance();
			$settings_service->save_settings( 'checkout', $new_settings );
		}
	}

	/**
	 * Render the troubleshooting form HTML.
	 *
	 * @return string
	 */
	public function get_troubleshooting_form_html(): string {
			global $wp_styles, $wp_scripts;
			$styleHandles  = $wp_styles->queue;
			$scriptHandles = $wp_scripts->queue;

			$style_exclude_list = apply_filters(
				'woocommerce_pos_payment_template_dequeue_style_handles',
				woocommerce_pos_get_settings( 'checkout', 'dequeue_style_handles' )
			);

			$script_exclude_list = apply_filters(
				'woocommerce_pos_payment_template_dequeue_script_handles',
				woocommerce_pos_get_settings( 'checkout', 'dequeue_script_handles' )
			);

			$mergedStyleHandles  = array_unique( array_merge( $styleHandles, $style_exclude_list ) );
			$mergedScriptHandles = array_unique( array_merge( $scriptHandles, $script_exclude_list ) );

			ob_start();
		?>
			<div class="woocommerce-pos-troubleshooting">
				<div style="text-align:right">
					<button type="button" class="open-troubleshooting-modal"><?php _e( 'Checkout Settings', 'woocommerce-pos' ); ?></button>
				</div>
				<div id="troubleshooting-modal" class="troubleshooting-modal" style="display: none;">
					<div class="troubleshooting-modal-content">
						<span class="close-troubleshooting-modal">&times;</span>
						<form id="troubleshooting-form" method="POST">
							<p class="woocommerce-info"><?php _e( 'Scripts and styles may interfere with the custom payment template, use this form to dequeue any problematic scripts.', 'woocommerce-pos' ); ?></p>
							<div style="margin-bottom: 20px;">
								<h3><?php _e( 'Disable All Styles and Scripts', 'woocommerce-pos' ); ?></h3>
								<label for="disable_wp_head">
									<input type="checkbox" id="disable_wp_head" name="disable_wp_head" value="1" <?php checked( $this->disable_wp_head ); ?>>
									<?php _e( 'Disable wp_head', 'woocommerce-pos' ); ?>
								</label>
								<br>
								<label for="disable_wp_footer">
									<input type="checkbox" id="disable_wp_footer" name="disable_wp_footer" value="1" <?php checked( $this->disable_wp_footer ); ?>>
									<?php _e( 'Disable wp_footer', 'woocommerce-pos' ); ?>
								</label>
							</div>
							<div style="display: flex; justify-content: space-between;margin-bottom:20px;">
								<div style="flex: 1;">
									<h3><?php _e( 'Disable Selected Styles', 'woocommerce-pos' ); ?></h3>
									<?php
									foreach ( $mergedStyleHandles as $handle ) {
										$checked = ! in_array( $handle, $style_exclude_list, true ) ? 'checked' : '';
										?>
										<input type="checkbox" id="<?php echo esc_attr( $handle ); ?>" name="styles[]" value="<?php echo esc_attr( $handle ); ?>" <?php echo esc_attr( $checked ); ?>>
										<label for="<?php echo esc_attr( $handle ); ?>"><?php echo esc_html( $handle ); ?></label>
										<input type="hidden" name="all_styles[]" value="<?php echo esc_attr( $handle ); ?>"><br>
									<?php } ?>
								</div>
								<div style="flex: 1;">
									<h3><?php _e( 'Disable Selected Scripts', 'woocommerce-pos' ); ?></h3>
									<?php
									foreach ( $mergedScriptHandles as $handle ) {
										$checked = ! in_array( $handle, $script_exclude_list, true ) ? 'checked' : '';
										?>
										<input type="checkbox" id="<?php echo esc_attr( $handle ); ?>" name="scripts[]" value="<?php echo esc_attr( $handle ); ?>" <?php echo esc_attr( $checked ); ?>>
										<label for="<?php echo esc_html( $handle ); ?>"><?php echo esc_html( $handle ); ?></label>
										<input type="hidden" name="all_scripts[]" value="<?php echo esc_attr( $handle ); ?>"><br>
									<?php } ?>
								</div>
							</div>
							<input type="hidden" name="troubleshooting_form_nonce" value="<?php echo esc_attr( $this->troubleshooting_form_nonce ); ?>" />
							<button type="submit"><?php _e( 'Submit', 'woocommerce-pos' ); ?></button>
						</form>
					</div>
				</div>
			</div>

			<script>
				document.querySelector('.open-troubleshooting-modal').addEventListener('click', () => {
					document.getElementById('troubleshooting-modal').style.display = 'block';
				});
				document.querySelector('.close-troubleshooting-modal').addEventListener('click', () => {
					document.getElementById('troubleshooting-modal').style.display = 'none';
				});
				window.addEventListener('click', event => {
					if (event.target === document.getElementById('troubleshooting-modal')) {
						document.getElementById('troubleshooting-modal').style.display = 'none';
					}
				});
			</script>
			<?php
			return ob_get_clean();
	}

	/**
	 * Render the cashier details HTML.
	 *
	 * @return string
	 */
	public function get_cashier_details_html(): string {
		$cashier = $this->order->get_meta( '_pos_user', true );
		$cashier = get_user_by( 'id', $cashier );

		ob_start();
		?>
		<div class="cashier">
			<span><?php esc_html_e( 'Cashier', 'woocommerce-pos' ); ?>: </span>
			<span class="cashier-name"><?php echo esc_html( $cashier->display_name ); ?></span>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the address fields HTML.
	 *
	 * @return string
	 */
	public function get_paying_customer_details_html(): string {
		$customer = wp_get_current_user();
		ob_start();
		?>
		<div class="current-user">
			<span><?php esc_html_e( 'Paying as customer', 'woocommerce-pos' ); ?>: </span>
						<span class="user-name"><?php echo 0 === $customer->ID ? esc_html__( 'Guest', 'woocommerce-pos' ) : esc_html( $customer->display_name ); ?></span>
		</div>
		<div class="address-fields" style="display: none;">
			<section class="woocommerce-customer-details">

				<section class="woocommerce-columns woocommerce-columns--2 woocommerce-columns--addresses col2-set addresses">
					<div class="woocommerce-column woocommerce-column--1 woocommerce-column--billing-address col-1">
						<h2 class="woocommerce-column__title"><?php esc_html_e( 'Billing address', 'woocommerce' ); ?></h2>
						<address>
							<?php echo wp_kses_post( $this->order->get_formatted_billing_address( esc_html__( 'N/A', 'woocommerce' ) ) ); ?>
							<?php if ( $this->order->get_billing_phone() ) { ?>
								<p class="woocommerce-customer-details--phone"><?php echo esc_html( $this->order->get_billing_phone() ); ?></p>
							<?php } ?>
							<?php if ( $this->order->get_billing_email() ) { ?>
								<p class="woocommerce-customer-details--email"><?php echo esc_html( $this->order->get_billing_email() ); ?></p>
							<?php } ?>
						</address>
					</div><!-- /.col-1 -->

					<div class="woocommerce-column woocommerce-column--2 woocommerce-column--shipping-address col-2">
						<h2 class="woocommerce-column__title"><?php esc_html_e( 'Shipping address', 'woocommerce' ); ?></h2>
						<address>
							<?php echo wp_kses_post( $this->order->get_formatted_shipping_address( esc_html__( 'N/A', 'woocommerce' ) ) ); ?>
							<?php if ( $this->order->get_shipping_phone() ) { ?>
								<p class="woocommerce-customer-details--phone"><?php echo esc_html( $this->order->get_shipping_phone() ); ?></p>
							<?php } ?>
						</address>
					</div><!-- /.col-2 -->

				</section><!-- /.col2-set -->

				<?php do_action( 'woocommerce_order_details_after_customer_details', $this->order ); ?>

			</section>
		</div>
		<script>
			document.querySelector('.current-user .user-name').addEventListener('click', () => {
				const addressFields = document.querySelector('.address-fields');
				addressFields.style.display = addressFields.style.display === 'none' ? 'block' : 'none';
			});
		</script>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the coupon form HTML.
	 *
	 * @param \WC_Order $order
	 * @param string    $coupon_nonce
	 * @return string
	 */
	public function get_coupon_form_html(): string {
		ob_start();
		?>
		<div class="coupons">
				<form method="post" action="">
						<input type="hidden" name="pos_coupon_nonce" value="<?php echo esc_attr( $this->coupon_nonce ); ?>" />
						<input type="text" name="pos_coupon_code" class="input-text" placeholder="<?php esc_attr_e( 'Coupon code', 'woocommerce' ); ?>" id="pos_coupon_code" value="" />
						<button type="submit" class="button" name="pos_apply_coupon" value="<?php esc_attr_e( 'Apply coupon', 'woocommerce' ); ?>">
								<?php esc_html_e( 'Apply coupon', 'woocommerce' ); ?>
						</button>

						<?php
						$coupons = $this->order->get_items( 'coupon' );
						if ( $coupons ) {
								echo '<h3>' . __( 'Applied coupons', 'woocommerce' ) . '</h3>';
								echo '<ul>';
							foreach ( $coupons as $coupon ) {
									echo '<li>' . esc_html( $coupon->get_code() ) . ' <button type="submit" class="button" name="pos_remove_coupon" value="' . esc_attr( $coupon->get_code() ) . '">' . esc_html__( 'Remove', 'woocommerce' ) . '</button></li>';
							}
								echo '</ul>';
						}
						?>
				</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Fix: when checking out as Guest on the desktop application, WordPress gets a $uid from the
	 * session, eg: 't_8b04f8283e7edc5aeee2867c89dd06'. This causes the nonce check to fail.
	 */
	public function nonce_user_logged_out( $uid, $action ) {
		if ( $action === 'woocommerce-pay' ) {
			return 0;
		}
		return $uid;
	}

	/**
	 * Custom version of wp_create_nonce that uses the customer ID.
	 */
	private function create_customer_nonce() {
		$user = wp_get_current_user();
		$uid  = (int) $user->ID;
		// if ( ! $uid ) {
		// ** This filter is documented in wp-includes/pluggable.php */
		// $uid = apply_filters( 'nonce_user_logged_out', $uid, $action );
		// }

		$token = '';
		$i     = wp_nonce_tick();

		return substr( wp_hash( $i . '|woocommerce-pay|' . $uid . '|' . $token, 'nonce' ), - 12, 10 );
	}
}
