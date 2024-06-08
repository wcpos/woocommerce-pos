<?php
/**
 * Login template
 * NOTE: This is the modal login template, ie: JWT expired, not the web login
 *
 * @package WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Templates;

use WCPOS\WooCommercePOS\Services\Auth;
use WP_User;

/**
 * Login template
 */
class Login {
	/**
	 * Constructor.
	 */
	public function __construct() {
		remove_action( 'login_init', 'send_frame_options_header', 10 );
	}

	/**
	 * @return void
	 */
	public function get_template(): void {
		$login_attempt = isset( $_POST['_wpnonce'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'woocommerce_pos_login' );

		// Login attempt detected
		$error_string = '';
		if ( $login_attempt ) {
			$creds = array();
			$creds['user_login'] = $_POST['log'];
			$creds['user_password'] = $_POST['pwd'];
			// $creds['remember'] = isset( $_POST['rememberme'] );

			$user = wp_signon( $creds, false );

			if ( is_wp_error( $user ) ) {
				foreach ( $user->errors as $error ) {
					$error_string .= '<p class="error">' . $error[0] . '</p>';
				}
			} else {
				wp_set_current_user( $user->ID );
				$this->login_success( $user );
				exit;
			}
		}

		do_action( 'login_init' );

		do_action( 'login_form_login' );

		include woocommerce_pos_locate_template( 'login.php' );
		exit;
	}

	/**
	 * Login success
	 *
	 * @param WP_User $user
	 *
	 * @return void
	 */
	private function login_success( WP_User $user ) {
		$auth_service = Auth::instance();
		$user_data = $auth_service->get_user_data( $user );
		$stores = array_map(
			function ( $store ) {
				return $store->get_data();
			},
			wcpos_get_stores()
		);
		$user_data['stores'] = $stores;
		$credentials = wp_json_encode( $user_data );

		echo '<script>
	(function() {
        var credentials = ' . $credentials . " 

        // Check if postMessage function exists for window.top
        if (typeof window.top.postMessage === 'function') {
            window.top.postMessage({
                action: 'wcpos-wp-credentials',
                payload: credentials
            }, '*');
        }

        // Check if ReactNativeWebView object and postMessage function exists
        if (typeof window.ReactNativeWebView !== 'undefined' && typeof window.ReactNativeWebView.postMessage === 'function') {
            window.ReactNativeWebView.postMessage(JSON.stringify({
                action: 'wcpos-wp-credentials',
                payload: credentials
            }));
        }
    })();
</script>" . "\n";
	}
}
