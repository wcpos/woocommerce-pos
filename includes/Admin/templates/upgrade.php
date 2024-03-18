<?php
/**
 * Template for the Upgrade page.
 *
 * @author    Paul Kilmurray <paul@kilbot.com.au>
 *
 * @see      http://www.kilbot.com
 */
?>

<div class="wrap clear">

	<!--
	Little trick to get around WP js injection of admin notices
	WP js looks for first h2 in .wrap and appends notices, so we'll make the first one hidden
	https://github.com/WordPress/WordPress/blob/master/wp-admin/js/common.js
	-->
	<h2 style="display:none"></h2>

	<div id="woocommerce-pos-upgrade"></div>

</div>
