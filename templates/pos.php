<?php
/**
 * POS template
 *
 * This template can be overridden by copying it to yourtheme/woocommerce-pos/pos.php.
 *
 * HOWEVER, this is not recommended , don't be surprised if your POS breaks
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html>
<head>
	<title><?php esc_attr_e( 'Point of Sale', 'woocommerce-pos' ); ?> - <?php esc_html( bloginfo( 'name' ) ); ?></title>
	<meta charset="utf-8"/>

	<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
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

	<link rel="stylesheet" type="text/css" href="https://csstools.github.io/sanitize.css/latest/sanitize.css">
	<style>
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

		html, body, #root {
			height: 100%;
		}

		#root {
			display: flex;
			flex-direction: column;
		}

		#splash {
			display: flex;
			flex-direction: column;
			justify-content: center;
			align-items: center;
			height: 100%;
		}

		#splash img {
			width: 150px;
		}
	</style>
	<script
		src="https://code.jquery.com/jquery-3.6.0.min.js"
		integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4="
		crossorigin="anonymous"></script>

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

<script>
	const host = '<?php echo esc_attr( WCPOS\WooCommercePOS\PLUGIN_URL ); ?>assets/';
	jQuery.getJSON(host + 'asset-manifest.json', ({files}) => {
		for (const i in Object.keys(files)) {
			const key = Object.keys(files)[i];

			if (key.indexOf('.js') !== -1 && key.indexOf('.js.map') === -1) {
				const path = host + files[key];
				console.log('getting script', path);
				jQuery.getScript(path);
			}
		}
	})
</script>
</html>
