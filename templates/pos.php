<?php
/**
 * POS template.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce-pos/pos.php.
 * HOWEVER, this is not recommended , don't be surprised if your POS breaks
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html>
<head>
	<title><?php esc_attr_e( 'Point of Sale', 'woocommerce-pos' ); ?> - <?php esc_html( bloginfo( 'name' ) ); ?></title>
	<meta charset="utf-8"/>

	<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
	<meta name="theme-color" content="#000000">
	<meta name="apple-mobile-web-app-capable" content="yes"/>

	<!-- For iPad with high-resolution Retina display running iOS ≥ 7: -->
	<link rel="apple-touch-icon-precomposed"
		  href="<?php echo esc_attr( WCPOS\WooCommercePOS\PLUGIN_URL ); ?>assets/favicon-152.png">
	<link rel="apple-touch-icon-precomposed" sizes="152x152"
		  href="<?php echo esc_attr( WCPOS\WooCommercePOS\PLUGIN_URL ); ?>assets/favicon-152.png">

	<!-- For iPad with high-resolution Retina display running iOS ≤ 6: -->
	<link rel="apple-touch-icon-precomposed" sizes="144x144"
		  href="<?php echo esc_attr( WCPOS\WooCommercePOS\PLUGIN_URL ); ?>assets/favicon-144.png">

	<!-- For iPhone with high-resolution Retina display running iOS ≥ 7: -->
	<link rel="apple-touch-icon-precomposed" sizes="120x120"
		  href="<?php echo esc_attr( WCPOS\WooCommercePOS\PLUGIN_URL ); ?>assets/favicon-120.png">

	<!-- For iPhone with high-resolution Retina display running iOS ≤ 6: -->
	<link rel="apple-touch-icon-precomposed" sizes="114x114"
		  href="<?php echo esc_attr( WCPOS\WooCommercePOS\PLUGIN_URL ); ?>assets/favicon-114.png">

	<!-- For first- and second-generation iPad: -->
	<link rel="apple-touch-icon-precomposed" sizes="72x72"
		  href="<?php echo esc_attr( WCPOS\WooCommercePOS\PLUGIN_URL ); ?>assets/favicon-72.png">

	<!-- For non-Retina iPhone, iPod Touch, and Android 2.1+ devices: -->
	<link rel="apple-touch-icon-precomposed"
		  href="<?php echo esc_attr( WCPOS\WooCommercePOS\PLUGIN_URL ); ?>assets/favicon-57.png">

	<!-- IE 10 Metro tile icon -->
	<meta name="msapplication-TileColor" content="#323A46">
	<meta name="msapplication-TileImage"
		  content="<?php echo esc_attr( WCPOS\WooCommercePOS\PLUGIN_URL ); ?>assets/favicon-144.png">

	<style>
		/**
		 * Matches Expo build

		 * Extend the react-native-web reset:
		 * https://necolas.github.io/react-native-web/docs/setup/#root-element
		 */
		html,
		body,
		#root {
			width: 100%;
			/* To smooth any scrolling behavior */
			-webkit-overflow-scrolling: touch;
			margin: 0px;
			padding: 0px;
			/* Allows content to fill the viewport and go beyond the bottom */
			min-height: 100%;
		}
		#root {
			flex-shrink: 0;
			flex-basis: auto;
			flex-grow: 1;
			display: flex;
			flex: 1;
		}

		html {
			scroll-behavior: smooth;
			/* Prevent text size change on orientation change https://gist.github.com/tfausak/2222823#file-ios-8-web-app-html-L138 */
			-webkit-text-size-adjust: 100%;
			height: calc(100% + env(safe-area-inset-top));
		}

		body {
			display: flex;
			/* Allows you to scroll below the viewport; default value is visible */
			overflow-y: auto;
			overscroll-behavior-y: none;
			text-rendering: optimizeLegibility;
			-webkit-font-smoothing: antialiased;
			-moz-osx-font-smoothing: grayscale;
			-ms-overflow-style: scrollbar;
		}
		/* Enable for apps that support dark-theme */
		/*@media (prefers-color-scheme: dark) {
		  body {
			background-color: black;
		  }
		}*/

		#splash {
			display: flex;
			flex-direction: column;
			justify-content: center;
			align-items: center;
			height: 100%;
			width: 100%;
			background-color: #fafbfc;
		}

		#splash img {
			width: 150px;
			height: 150px;
		}
	</style>

	<?php do_action( 'woocommerce_pos_head' ); ?>
</head>
<body>

<div id="root">
	<div id="splash">
		<img src="<?php echo esc_attr( WCPOS\WooCommercePOS\PLUGIN_URL ); ?>assets/img/wcpos-icon.svg"
			 alt="WooCommerce POS"/>
	</div>
</div>

</body>
<?php do_action( 'woocommerce_pos_footer' ); ?>

</html>
