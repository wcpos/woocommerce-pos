<?php
/**
 * Add a POS settings on the permalink admin page.
 *
 * @author   Paul Kilmurray <paul@kilbot.com.au>
 *
 * @see     http://www.wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Admin;

use const WCPOS\WooCommercePOS\PLUGIN_NAME;

/**
 * Permalink class.
 */
class Permalink {
	public const DB_KEY = 'woocommerce_pos_settings_permalink';

	/** The slug served when the merchant never customised it. */
	public const DEFAULT_SLUG = 'pos';

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
		if ( self::DEFAULT_SLUG === $slug ) {
			$slug = ''; // use placeholder.
		}
		echo '<input name="woocommerce_pos_permalink" type="text" class="regular-text code" value="' . esc_attr( $slug ) . '" placeholder="' . esc_attr( self::DEFAULT_SLUG ) . '" />';
		wp_nonce_field( 'wcpos-permalinks', 'wcpos-permalinks-nonce' );
	}

	/**
	 * Watch for $_POST and save POS setting
	 * - sanitize field and remove slash from start and end.
	 */
	public function save(): void {
		if ( isset( $_POST['woocommerce_pos_permalink'], $_POST['wcpos-permalinks-nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['wcpos-permalinks-nonce'] ), 'wcpos-permalinks' ) ) {
			$permalink = trim( sanitize_text_field( wp_unslash( $_POST['woocommerce_pos_permalink'] ) ), '/\\' );
			// Autoloaded: Template_Router reads the slug on every request.
			update_option( self::DB_KEY, $permalink, true );
		}
	}

	/**
	 * Return the custom slug, defaults to 'pos'.
	 *
	 * @return string
	 */
	public static function get_slug(): string {
		$slug = get_option( self::DB_KEY );

		return empty( $slug ) ? self::DEFAULT_SLUG : sanitize_text_field( $slug );
	}

	/**
	 * Make sure the option row exists, autoloaded.
	 *
	 * Template_Router reads the slug on every request. An ABSENT option is not
	 * free: without a persistent object cache WordPress queries for it on every
	 * request (the notoptions cache does not survive the request), so a store
	 * that never customised the slug paid one query per page. Seeding an EMPTY
	 * row makes the read an alloptions hit while get_slug() keeps resolving the
	 * default from DEFAULT_SLUG — materialising 'pos' would freeze it and turn a
	 * future default change into a migration. A customised value is left alone.
	 */
	public static function ensure_default(): void {
		if ( false === get_option( self::DB_KEY ) ) {
			add_option( self::DB_KEY, '', '', true );
		}
	}

	/**
	 * Hook into the permalinks setting api.
	 */
	private function init(): void {
		add_settings_field(
			'woocommerce-pos-permalink',
			/* translators: Permalink settings label for the POS base URL. */
			_x( 'POS base', 'Permalink setting, eg: /pos', 'woocommerce-pos' ),
			array( $this, 'pos_slug_input' ),
			'permalink',
			'optional'
		);
	}
}
