<?php
/**
 * Legacy PHP receipt renderer.
 *
 * @package WCPOS\WooCommercePOS\Templates\Renderers
 */

namespace WCPOS\WooCommercePOS\Templates\Renderers;

use WCPOS\WooCommercePOS\Interfaces\Receipt_Renderer_Interface;
use WC_Abstract_Order;

/**
 * Legacy_Php_Renderer class.
 */
class Legacy_Php_Renderer implements Receipt_Renderer_Interface {
	/**
	 * Render legacy PHP template.
	 *
	 * @param array             $template     Template metadata/content.
	 * @param WC_Abstract_Order $order        Order object.
	 * @param array             $receipt_data Canonical receipt payload.
	 *
	 * @throws \RuntimeException When the temporary template file cannot be created.
	 */
	public function render( array $template, WC_Abstract_Order $order, array $receipt_data ): void {
		if ( ! empty( $template['file_path'] ) && file_exists( $template['file_path'] ) ) {
			include $template['file_path'];
			return;
		}

		if ( empty( $template['content'] ) || ! \is_string( $template['content'] ) ) {
			echo '<!-- Empty legacy receipt template -->';
			return;
		}

		$temp_file = $this->create_temp_template_file( $template['content'] );
		if ( ! $temp_file ) {
			throw new \RuntimeException( esc_html__( 'Failed to create temporary template file.', 'woocommerce-pos' ) );
		}

		include $temp_file;
		@unlink( $temp_file );
	}

	/**
	 * Create a temporary template file.
	 *
	 * @param string $content Template content.
	 *
	 * @return false|string
	 */
	private function create_temp_template_file( string $content ) {
		$upload_dir = wp_upload_dir();
		$temp_dir   = trailingslashit( $upload_dir['basedir'] ) . 'wcpos-templates';

		if ( ! file_exists( $temp_dir ) ) {
			wp_mkdir_p( $temp_dir );
		}

		$temp_file = tempnam( $temp_dir, 'receipt_' );
		if ( ! $temp_file ) {
			return false;
		}

		$written = file_put_contents( $temp_file, $content );
		if ( false === $written ) {
			@unlink( $temp_file );
			return false;
		}

		return $temp_file;
	}
}
