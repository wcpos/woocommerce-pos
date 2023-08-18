<?php

namespace WCPOS\WooCommercePOS;

class AJAX {

	/**
	 * WooCommerce AJAX actions that we need to hook into on the Product admin pages.
	 *
	 * @var string[]
	 */
	private $single_product_actions = array(
		'woocommerce_load_variations',
		'woocommerce_save_variations',
	);

    /**
     * WooCommerce AJAX actions that we need to hook into on the Product admin pages.
     *
     * @var string[]
     */
    private $list_products_actions = array(
        // 'inline-save', // this is a core WordPress action and it won't call our hook
    );

	/**
	 * WooCommerce AJAX actions that we need to hook into on the Order admin pages.
	 *
	 * @var string[]
	 */
	private $order_actions = array(
		'woocommerce_add_order_item',
		'woocommerce_add_order_fee',
		'woocommerce_add_order_shipping',
		'woocommerce_add_order_tax',
		'woocommerce_add_coupon_discount',
		'woocommerce_remove_order_coupon',
		'woocommerce_remove_order_item',
		'woocommerce_remove_order_tax',
		'woocommerce_calc_line_taxes',
		'woocommerce_save_order_items',
		'woocommerce_load_order_items',
		'woocommerce_get_order_details',
	);

	/**
	 *
	 */
	public function __construct() {
        if ( ! isset( $_REQUEST['action'] ) ) {
            return;
        }

        if ( $_POST['action'] == 'heartbeat' ) {
            return;
        }

        foreach ( $this->single_product_actions as $ajax_event ) {
            add_action( 'wp_ajax_' . $ajax_event, array( $this, 'load_single_product_class' ), 9 );
        }
        foreach ( $this->list_products_actions as $ajax_event ) {
            add_action( 'wp_ajax_' . $ajax_event, array( $this, 'load_list_products_class' ), 9 );
        }
        foreach ( $this->order_actions as $ajax_event ) {
            add_action( 'wp_ajax_' . $ajax_event, array( $this, 'load_orders_class' ), 9 );
        }

        // we need to hook into these actions to save our custom fields via AJAX
        add_action( 'woocommerce_product_quick_edit_save',
            array(
				'\WCPOS\WooCommercePOS\Admin\Products\List_Products',
				'quick_edit_save',
            )
        );
    }

	/**
	 * The Admin\Products\Single_Product class is not loaded for AJAX requests.
	 * We need to load it manually here.
	 */
	public function load_single_product_class() {
		new Admin\Products\Single_Product();
	}

    /**
     * The Admin\Products\List_Products class is not loaded for AJAX requests.
     * We need to load it manually here.
     */
    public function load_list_products_class() {
        //
    }

	/**
	 * The Admin\Orders class is not loaded for AJAX requests.
	 * We need to load it manually here.
	 */
	public function load_orders_class() {
		new Admin\Orders();
	}

}
