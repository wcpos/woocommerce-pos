<?php

/**
 * Add a POS settings on the permalink admin page.
 *
 * @author   Paul Kilmurray <paul@kilbot.com.au>
 *
 * @see     http://www.wcpos.com
 */

namespace WCPOS\WooCommercePOS\Admin;

use const WCPOS\WooCommercePOS\PLUGIN_NAME;

class Permalink {
	public const DB_KEY = 'woocommerce_pos_settings_permalink';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->init();
		$this->save();
	}

	/**
	 * Output the POS field.
	 */
	public function pos_slug_input(): void {
		$slug = self::get_slug();
		if ( 'pos' == $slug ) {
			$slug = ''; // use placeholder
		}
		echo '<input name="woocommerce_pos_permalink" type="text" class="regular-text code" value="' . esc_attr( $slug ) . '" placeholder="pos" />';
		wp_nonce_field( 'wcpos-permalinks', 'wcpos-permalinks-nonce' );
	}

	/**
	 * Watch for $_POST and save POS setting
	 * - sanitize field and remove slash from start and end.
	 */
	public function save(): void {
		if ( isset( $_POST['woocommerce_pos_permalink'], $_POST['wcpos-permalinks-nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['wcpos-permalinks-nonce'] ), 'wcpos-permalinks' ) ) {
			$permalink = trim( sanitize_text_field( wp_unslash( $_POST['woocommerce_pos_permalink'] ) ), '/\\' );
			update_option( self::DB_KEY, $permalink );
		}
	}

	/**
	 * Return the custom slug, defaults to 'pos'.
	 *
	 * @return string
	 */
	public static function get_slug(): string {
		$slug = get_option( self::DB_KEY );

		return empty( $slug ) ? 'pos' : sanitize_text_field( $slug );
	}

	/**
	 * Hook into the permalinks setting api.
	 */
	private function init(): void {
		add_settings_field(
			'woocommerce-pos-permalink',
			_x( 'POS base', 'Permalink setting, eg: /pos', PLUGIN_NAME ),
			array( $this, 'pos_slug_input' ),
			'permalink',
			'optional'
		);
	}
}
