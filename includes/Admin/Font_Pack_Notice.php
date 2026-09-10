<?php
/**
 * Receipt font installation failure notice.
 *
 * @package WCPOS\WooCommercePOS\Admin
 */

namespace WCPOS\WooCommercePOS\Admin;

use WCPOS\WooCommercePOS\Services\Font_Pack_Loader;

/** Show cached font failures to store administrators. */
class Font_Pack_Notice {
	/** Queue on admin_init so the notice exists before Notices renders on admin_notices. */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'admin_init' ), 20 );
	}

	/** Warn store managers when the pack is missing and the last download failed. */
	public function admin_init(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$loader = new Font_Pack_Loader();
		if ( ! $loader->installed() && $loader->failed() ) {
			$message = get_transient( 'wcpos_font_pack_failed_map' )
				? __( 'WCPOS could not publish its receipt font map. Receipts will use a basic Latin-only font until the installation succeeds. WCPOS retries automatically every hour. Check that the uploads directory is writable.', 'woocommerce-pos' )
				: __( 'WCPOS could not download its receipt fonts from cdn.jsdelivr.net. Receipts will use a basic Latin-only font until the download succeeds. WCPOS retries automatically every hour. If your host blocks outgoing HTTP requests, ask them to allow cdn.jsdelivr.net.', 'woocommerce-pos' );
			Notices::add(
				$message,
				'warning'
			);
		}
	}
}
