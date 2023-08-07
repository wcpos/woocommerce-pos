<?php

namespace WCPOS\WooCommercePOS\Templates;

use WCPOS\WooCommercePOS\Services\Auth;
use WP_User;

class Login {
    /**
     *
     */
    protected $auth_service;


    public function __construct() {
        $this->auth_service = new Auth();

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
			//            $creds['remember'] = isset( $_POST['rememberme'] );

            $user = wp_signon( $creds, false );

            if ( is_wp_error( $user ) ) {
                foreach ( $user->errors as $error ) {
                    $error_string .= '<p class="error">' . $error[0] . '</p>';
                }
            } else {
                $this->login_success( $user );
                exit;
            }
        }

        //
        do_action( 'login_init' );

        //
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
        $user_data = $this->auth_service->get_user_data( $user );
        $credentials = wp_json_encode( $user_data );

        echo '<script>
	(function() {
        // Parse the order JSON from PHP
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
