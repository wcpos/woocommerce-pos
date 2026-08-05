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

	/**
	 * Merge visibility settings.
	 *
	 * @param array $existing Existing settings view.
	 * @param array $patch    Incoming partial payload.
	 */
	public function merge( array $existing, array $patch ): array {
		$settings = parent::merge( $existing, $patch );
		foreach ( $patch as $post_type => $scopes ) {
			foreach ( (array) $scopes as $scope => $visibilities ) {
				foreach ( (array) $visibilities as $visibility => $values ) {
					$current = $settings[ $post_type ][ $scope ][ $visibility ] ?? array();
					$settings[ $post_type ][ $scope ][ $visibility ] = array_replace( (array) $current, (array) $values );
				}
			}
		}
		return $settings;
	}

	/** {@inheritDoc} */
	public function endpoint_args(): array {
		$validate = static function ( $param ): bool {
			return is_array( $param ) && ! array_filter(
				$param,
				static fn( $scope ): bool => ! is_array( $scope ) || (bool) array_filter(
					(array) $scope,
					static fn( $visibility ): bool => ! is_array( $visibility ) || ! is_array( $visibility['ids'] ?? null )
				)
			);
		};
		return array_fill_keys( array( 'products', 'variations' ), array( 'validate_callback' => $validate ) );
	}
}
