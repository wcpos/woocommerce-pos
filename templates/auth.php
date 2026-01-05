<?php
/**
 * WCPOS Auth template: posts to JWT endpoint and redirects.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce-pos/auth.php.
 * HOWEVER, this is not recommended , don't be surprised if your POS breaks
 */

\defined( 'ABSPATH' ) || exit;

// Get Auth instance from global scope (set by Templates class)
global $wcpos_auth_instance;

// Fallback if no instance is available
if ( ! $wcpos_auth_instance ) {
	$wcpos_auth_instance = new WCPOS\WooCommercePOS\Templates\Auth();
}

$redirect_uri = $wcpos_auth_instance->get_redirect_uri();
$error        = $wcpos_auth_instance->get_error();
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<title><?php _e('WooCommerce POS Login'); ?></title>
	<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel='stylesheet' id='dashicons-css' href='<?php echo includes_url( 'css/dashicons.min.css' ); ?>?ver=<?php echo get_bloginfo( 'version' ); ?>' media='all' />
	<link rel='stylesheet' id='buttons-css' href='<?php echo includes_url( 'css/buttons.min.css' ); ?>?ver=<?php echo get_bloginfo( 'version' ); ?>' media='all' />
	<link rel='stylesheet' id='forms-css' href='<?php echo admin_url( 'css/forms.min.css' ); ?>?ver=<?php echo get_bloginfo( 'version' ); ?>' media='all' />
	<link rel='stylesheet' id='l10n-css' href='<?php echo admin_url( 'css/l10n.min.css' ); ?>?ver=<?php echo get_bloginfo( 'version' ); ?>' media='all' />
	<link rel='stylesheet' id='login-css' href='<?php echo admin_url( 'css/login.min.css' ); ?>?ver=<?php echo get_bloginfo( 'version' ); ?>' media='all' />
</head>
<body class="login no-js login-action-login wp-core-ui locale-<?php echo str_replace('_', '-', get_locale()); ?>">
<script type="text/javascript">
	document.body.className = document.body.className.replace('no-js','js');
</script>
<div id="login">
	<div style="text-align: center;">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" title="<?php echo esc_attr( get_bloginfo( 'name', 'display' ) ); ?>" tabindex="-1">
			<img src="<?php echo esc_attr( WCPOS\WooCommercePOS\PLUGIN_URL ); ?>assets/img/wcpos-icon.svg" alt="WooCommerce POS" style="width: 100px; height: 100px;"/>
		</a>
	</div>

	<form name="wcpos-loginform" id="wcpos-loginform" action="" method="post">
		<?php if ( $error ) { ?>
			<div id="login_error" style="color: #CD2C24; padding-bottom: 10px;"><?php echo wp_kses( $error, array( 'strong' => array(), 'em' => array(), 'a' => array( 'href' => array() ) ) ); ?></div>
		<?php } ?>

		<p>
			<label for="wcpos-user-login"><?php _e('Username or Email Address'); ?></label>
			<input type="text" name="wcpos-log" id="wcpos-user-login" class="input" value="" size="20" autocapitalize="off" autocomplete="username" required="required" />
		</p>

		<div class="user-pass-wrap">
			<label for="wcpos-user-pass"><?php _e('Password'); ?></label>
			<div class="wp-pwd">
				<input type="password" name="wcpos-pwd" id="wcpos-user-pass" class="input password-input" value="" size="20" autocomplete="current-password" spellcheck="false" required="required" />
				<button type="button" class="button button-secondary wp-hide-pw hide-if-no-js" data-toggle="0" aria-label="<?php esc_attr_e('Show password'); ?>">
					<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
				</button>
			</div>
		</div>
		
		<?php wp_nonce_field( 'wcpos_auth', '_wpnonce' ); ?>
		
		<!-- Auth session token for expiring auth URLs -->
		<input type="hidden" name="auth_session" value="<?php echo esc_attr( $wcpos_auth_instance->get_auth_session() ); ?>" />
		
		<!-- Honeypot field - should remain empty, hidden from users via CSS -->
		<div style="position: absolute; left: -9999px;" aria-hidden="true">
			<label for="wcpos-website">Website</label>
			<input type="text" name="wcpos_website" id="wcpos-website" value="" tabindex="-1" autocomplete="off" />
		</div>
		
		<p class="submit">
			<input type="submit" name="wcpos-submit" id="wcpos-submit" class="button button-primary button-large" value="<?php esc_attr_e('Log In'); ?>">
		</p>
	</form>

</div>

<div class="clear"></div>

<script type="text/javascript">
(function() {
	// Check if we're on demo domains and auto-populate credentials
	var hostname = window.location.hostname;
	var isDemoDomain = hostname === 'demo.wcpos.com' || hostname === 'wcposdev.wpengine.com';
	
	if (isDemoDomain) {
		// Wait for DOM to be ready
		document.addEventListener('DOMContentLoaded', function() {
			var usernameField = document.getElementById('wcpos-user-login');
			var passwordField = document.getElementById('wcpos-user-pass');
			
			if (usernameField && passwordField) {
				usernameField.value = 'demo';
				passwordField.value = 'demo';
			}
		});
	}
})();
</script>
</body>
</html>