<?php
/**
 * Auth template
 * NOTE: This is for authentication via JWT, used by mobile/desktop apps.
 */

namespace WCPOS\WooCommercePOS\Templates;

use WCPOS\WooCommercePOS\Services\Auth as AuthService;

/**
 * Auth template.
 */
class Auth {
	/**
	 * The redirect URI.
	 *
	 * @var string
	 */
	private $redirect_uri;

	/**
	 * Error message.
	 *
	 * @var string
	 */
	private $error;

	/**
	 * Constructor.
	 */
	public function __construct() {
		remove_action( 'login_init', 'send_frame_options_header', 10 );
		add_filter( 'show_admin_bar', '__return_false' );

		// Initialize properties
		$this->redirect_uri = esc_url( $_REQUEST['redirect_uri'] ?? '', array( 'https', 'http', 'wcpos' ) );
		$this->error        = '';

		// Handle form submission
		$this->handle_form_submission();
	}

	/**
	 * Get the redirect URI.
	 *
	 * @return string
	 */
	public function get_redirect_uri(): string {
		return $this->redirect_uri;
	}

	/**
	 * Get the error message.
	 *
	 * @return string
	 */
	public function get_error(): string {
		return $this->error;
	}

	/**
	 * @return void
	 */
	public function get_template(): void {
		do_action( 'login_init' );
		do_action( 'woocommerce_pos_auth_template_redirect' );

		// Make this instance available to the template
		global $wcpos_auth_instance;
		$wcpos_auth_instance = $this;

		include woocommerce_pos_locate_template( 'auth.php' );
		exit;
	}

	/**
	 * Handle form submission.
	 *
	 * @return void
	 */
	private function handle_form_submission(): void {
		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}

		// Verify nonce for security
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'wcpos_auth' ) ) {
			$this->error = 'Security check failed. Please try again.';

			return;
		}

		$username = sanitize_user( $_POST['wcpos-log'] ?? '' );
		$password = $_POST['wcpos-pwd'] ?? '';

		// Authenticate user using WordPress
		$user = wp_authenticate( $username, $password );

		if ( is_wp_error( $user ) ) {
			$this->error = $user->get_error_message();

			return;
		}

		// Check if user has access to POS
		if ( ! user_can( $user, 'access_woocommerce_pos' ) ) {
			$this->error = 'You do not have permission to access the POS.';

			return;
		}

		// Generate JWT token using Services/Auth
		$auth_service  = AuthService::instance();
		$redirect_data = $auth_service->get_redirect_data( $user );

		if ( empty( $redirect_data ) ) {
			$this->error = 'Failed to generate authentication tokens.';

			return;
		}

		// On success, redirect back to app (or fallback to dashboard)
		$target = $this->redirect_uri
				? add_query_arg( array(
					'access_token'  => rawurlencode( $redirect_data['access_token'] ),
					'refresh_token' => rawurlencode( $redirect_data['refresh_token'] ),
					'token_type'    => rawurlencode( $redirect_data['token_type'] ),
					'expires_in'    => \intval( $redirect_data['expires_in'] ),
					'user_id'       => \intval( $redirect_data['user_id'] ),
				), $this->redirect_uri )
				: admin_url();

		wp_redirect( $target );
		exit;
	}
}
