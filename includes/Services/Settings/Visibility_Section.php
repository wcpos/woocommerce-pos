<?php
/**
 * Visibility Settings Section.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services\Settings;

/**
 * The Visibility Settings Section: POS-only / online-only id lists for
 * products and variations, per scope.
 */
class Visibility_Section extends Abstract_Section {
	/**
	 * Section id.
	 */
	public function id(): string {
		return 'visibility';
	}

	/**
	 * Section defaults.
	 */
	public function defaults(): array {
		return array(
			'products' => array(
				'default' => array(
					'pos_only' => array(
						'ids' => array(),
					),
					'online_only' => array(
						'ids' => array(),
					),
				),
			),
			'variations' => array(
				'default' => array(
					'pos_only' => array(
						'ids' => array(),
					),
					'online_only' => array(
						'ids' => array(),
					),
				),
			),
		);
	}
}
