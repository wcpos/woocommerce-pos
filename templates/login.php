<?php
/**
 * POS Login template. This replicates the WP Login, but for JWT rather than relying on cookies.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce-pos/login.php.
 * HOWEVER, this is not recommended , don't be surprised if your POS breaks
 */
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<title>WooCommerce POS Login</title>
	<meta http-equiv="Content-Type" content="<?php bloginfo( 'html_type' ); ?>; charset=<?php bloginfo( 'charset' ); ?>" />
	<?php
		wp_enqueue_style( 'login' );
		do_action( 'login_enqueue_scripts' );
		do_action( 'login_head' );
		$login_header_url = apply_filters( 'login_headerurl', __( 'https://wordpress.org/', 'login' ) );
		$login_header_title = apply_filters( 'login_headertitle', __( 'Powered by WordPress', 'login' ) );
	$classes = array( 'login-action-login', 'wp-core-ui' );
	if ( is_rtl() ) {
			$classes[] = 'rtl';
	}
	$classes[] = ' locale-' . sanitize_html_class( strtolower( str_replace( '_', '-', get_locale() ) ) );
	$classes = apply_filters( 'login_body_class', $classes, 'login' );
	?>
</head>
<body class="login no-js <?php echo esc_attr( implode( ' ', $classes ) ); ?>">
<script type="text/javascript">
	document.body.className = document.body.className.replace('no-js','js');
</script>
<?php do_action( 'login_header' ); ?>
<div id="login">
	<h1><a href="<?php echo esc_url( $login_header_url ); ?>" title="<?php echo esc_attr( $login_header_title ); ?>" tabindex="-1"><?php bloginfo( 'name' ); ?></a></h1>

	<?php
	// Login message filter.
	$message = apply_filters( 'login_messages', '' );

	if ( ! empty( $message ) ) {
		echo '<p class="message">' . $message . '</p>' . "\n";
	}

	if ( ! empty( $error_string ) ) {
		echo '<div id="login_error">' . $error_string . '</div>';
	}
	?>
	<form name="loginform" id="loginform" action="" method="post">
			<p>
				<label for="user_login"><?php _e( 'Username or Email Address' ); ?></label>
				<input type="text" name="log" id="user_login" class="input" value="" size="20" autocapitalize="off" autocomplete="username" required="required" />
			</p>

			<div class="user-pass-wrap">
				<label for="user_pass"><?php _e( 'Password' ); ?></label>
				<div class="wp-pwd">
					<input type="password" name="pwd" id="user_pass" class="input password-input" value="" size="20" autocomplete="current-password" spellcheck="false" required="required" />
					<button type="button" class="button button-secondary wp-hide-pw hide-if-no-js" data-toggle="0" aria-label="<?php esc_attr_e( 'Show password' ); ?>">
						<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
					</button>
				</div>
			</div>
		<?php do_action( 'login_form' ); ?>
		<?php wp_nonce_field( 'woocommerce_pos_login' ); ?>
		<p class="submit">
			<input type="submit" name="wp-submit" id="wp-submit" class="button button-primary button-large" value="Log In">
		</p>
	</form>

	<?php do_action( 'login_footer' ); ?>
</div>

<div class="clear"></div>
</body>

</html>
