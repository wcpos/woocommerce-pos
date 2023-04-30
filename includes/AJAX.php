<?php

namespace WCPOS\WooCommercePOS;

class AJAX {

	public function __construct() {
		if ( isset( $_POST['action'] ) && ( 'woocommerce_load_variations' == $_POST['action'] || 'woocommerce_save_variations' == $_POST['action'] ) ) {
			new Admin\Products();
		}
	}
}
