<?php
/**
 * Visibility Settings Section.
 *
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Services\Settings;

use WP_Error;
use WCPOS\WooCommercePOS\Interfaces\Settings_Section_Interface;

/**
 * The Visibility Settings Section: POS-only / online-only id lists for
 * products and variations, per scope.
 */
class Visibility_Section extends Abstract_Section {
	/**
	 * Registered section providing the visibility storage surface.
	 *
	 * @var null|Settings_Section_Interface
	 */
	private $storage;

	/**
	 * Constructor.
	 *
	 * @param null|Settings_Section_Interface $storage Registered visibility section override.
	 */
	public function __construct( ?Settings_Section_Interface $storage = null ) {
		$this->storage = $storage;
	}

	/** {@inheritDoc} */
	public function read(): array {
		return $this->storage ? $this->storage->read() : parent::read();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $settings The full visibility settings array to persist.
	 */
	public function write( array $settings ) {
		return $this->storage ? $this->storage->write( $settings ) : parent::write( $settings );
	}

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

	/**
	 * POS Visibility settings.
	 *
	 * @return array
	 */
	public function get_visibility_settings(): array {
		return $this->read();
	}

	/**
	 * Update visibility settings.
	 *
	 * @param array $args The visibility settings to update.
	 *
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function update_visibility_settings( array $args ) {
		// Validate and normalize arguments.
		$defaults = $this->defaults();
		if (
			empty( $args['post_type'] )
			|| ! \is_string( $args['post_type'] )
			|| ! isset( $defaults[ $args['post_type'] ] )
			|| ! isset( $args['ids'] )
		) {
			return new WP_Error(
				'woocommerce_pos_settings_error',
				/* translators: Error message shown when invalid arguments are provided. */
				__( 'Invalid arguments provided', 'woocommerce-pos' ),
				array( 'status' => 400 )
			);
		}

		// Define valid visibility options.
		$valid_options = array( 'pos_only', 'online_only', '' );

		// Check if visibility is set and valid.
		if ( ! isset( $args['visibility'] ) || ! \in_array( $args['visibility'], $valid_options, true ) ) {
			return new WP_Error(
				'woocommerce_pos_settings_error',
				__( 'Invalid visibility option provided', 'woocommerce-pos' ),
				array( 'status' => 400 )
			);
		}

		$post_type  = $args['post_type'];
		$scope      = $args['scope'] ?? 'default';
		$visibility = $args['visibility'];
		$ids        = \is_array( $args['ids'] ) ? $args['ids'] : array( $args['ids'] );
		$ids        = array_filter( array_map( 'intval', $ids ) ); // Force to array of integers.

		// Get the current visibility settings.
		$current_settings = $this->get_visibility_settings();
		$current_settings[ $post_type ][ $scope ] = array_replace_recursive(
			$defaults[ $post_type ]['default'],
			$current_settings[ $post_type ][ $scope ] ?? array()
		);

		// Define the opposite visibility type.
		$opposite_visibility = ( 'pos_only' === $visibility ) ? 'online_only' : 'pos_only';

		// Add or remove IDs based on the visibility type.
		foreach ( $ids as $id ) {
			if ( '' === $visibility ) {
				// Remove from both pos_only and online_only.
				$current_settings[ $post_type ][ $scope ]['pos_only']['ids'] = $this->remove_id_from_visibility(
					$current_settings[ $post_type ][ $scope ]['pos_only']['ids'],
					$id
				);
				$current_settings[ $post_type ][ $scope ]['online_only']['ids'] = $this->remove_id_from_visibility(
					$current_settings[ $post_type ][ $scope ]['online_only']['ids'],
					$id
				);
			} else {
				// Add to the specified visibility type.
				$current_settings[ $post_type ][ $scope ][ $visibility ]['ids'] = $this->add_id_to_visibility(
					$current_settings[ $post_type ][ $scope ][ $visibility ]['ids'],
					$id
				);
				// Remove from the opposite visibility type.
				$current_settings[ $post_type ][ $scope ][ $opposite_visibility ]['ids'] = $this->remove_id_from_visibility(
					$current_settings[ $post_type ][ $scope ][ $opposite_visibility ]['ids'],
					$id
				);
			}
		}

		return $this->write( $current_settings );
	}

	/**
	 * Get product visibility settings.
	 *
	 * @param string $scope The scope of the settings to get. 'default' or store ID.
	 *
	 * @return array $settings The product visibility settings, eg: { pos_only: { ids: [1, 2, 3] }, online_only: { ids: [4, 5, 6] }
	 */
	public function get_product_visibility_settings( $scope = 'default' ) {
		$settings = $this->get_visibility_settings();

		/*
		 * Filters the product visibility settings.
		 *
		 * @param {array} $settings
		 * @returns {array} $settings
		 * @since 1.0.0
		 * @hook woocommerce_pos_product_visibility_settings
		 */
		return apply_filters( 'woocommerce_pos_product_visibility_settings', $settings['products'][ $scope ], $scope );
	}

	/**
	 * Get product visibility settings.
	 *
	 * @param string $scope The scope of the settings to get. 'default' or store ID.
	 *
	 * @return array $settings The product visibility settings, eg: { ids: [1, 2, 3] }
	 */
	public function get_pos_only_product_visibility_settings( $scope = 'default' ) {
		$settings = $this->get_product_visibility_settings( $scope );

		/*
		 * Filters the product visibility settings.
		 *
		 * @param {array} $settings
		 * @returns {array} $settings
		 * @since 1.0.0
		 * @hook woocommerce_pos_product_visibility_settings
		 */
		return apply_filters( 'woocommerce_pos_pos_only_product_visibility_settings', $settings['pos_only'], $scope );
	}

	/**
	 * Get product visibility settings.
	 *
	 * @param string $scope The scope of the settings to get. 'default' or store ID.
	 *
	 * @return array $settings The product visibility settings, eg: { ids: [1, 2, 3] }
	 */
	public function get_online_only_product_visibility_settings( $scope = 'default' ) {
		$settings = $this->get_product_visibility_settings( $scope );

		/*
		 * Filters the product visibility settings.
		 *
		 * @param {array} $settings
		 * @returns {array} $settings
		 * @since 1.0.0
		 * @hook woocommerce_pos_product_visibility_settings
		 */
		return apply_filters( 'woocommerce_pos_online_only_product_visibility_settings', $settings['online_only'], $scope );
	}

	/**
	 * Get product visibility settings.
	 *
	 * @param string $scope The scope of the settings to get. 'default' or store ID.
	 *
	 * @return array $settings The product visibility settings, eg: { pos_only: { ids: [1, 2, 3] }, online_only: { ids: [4, 5, 6] }
	 */
	public function get_variations_visibility_settings( $scope = 'default' ) {
		$settings = $this->get_visibility_settings();

		/*
		 * Filters the product visibility settings.
		 *
		 * @param {array} $settings
		 * @returns {array} $settings
		 * @since 1.0.0
		 * @hook woocommerce_pos_product_visibility_settings
		 */
		return apply_filters( 'woocommerce_pos_variations_visibility_settings', $settings['variations'][ $scope ], $scope );
	}

	/**
	 * Get product visibility settings.
	 *
	 * @param string $scope The scope of the settings to get. 'default' or store ID.
	 *
	 * @return array $settings The product visibility settings, eg: { ids: [1, 2, 3] }
	 */
	public function get_pos_only_variations_visibility_settings( $scope = 'default' ) {
		$settings = $this->get_variations_visibility_settings( $scope );

		/*
		 * Filters the product visibility settings.
		 *
		 * @param {array} $settings
		 * @returns {array} $settings
		 * @since 1.0.0
		 * @hook woocommerce_pos_product_visibility_settings
		 */
		return apply_filters( 'woocommerce_pos_pos_only_variations_visibility_settings', $settings['pos_only'], $scope );
	}

	/**
	 * Get product visibility settings.
	 *
	 * @param string $scope The scope of the settings to get. 'default' or store ID.
	 *
	 * @return array $settings The product visibility settings, eg: { ids: [1, 2, 3] }
	 */
	public function get_online_only_variations_visibility_settings( $scope = 'default' ) {
		$settings = $this->get_variations_visibility_settings( $scope );

		/*
		 * Filters the product visibility settings.
		 *
		 * @param {array} $settings
		 * @returns {array} $settings
		 * @since 1.0.0
		 * @hook woocommerce_pos_product_visibility_settings
		 */
		return apply_filters( 'woocommerce_pos_online_only_variations_visibility_settings', $settings['online_only'], $scope );
	}

	/**
	 * Check if a product is POS only.
	 *
	 * @param int|string $product_id The product ID.
	 *
	 * @return bool
	 */
	public function is_product_pos_only( $product_id ) {
		$product_id   = (int) $product_id;
		$settings     = $this->get_pos_only_product_visibility_settings();
		$pos_only_ids = array_map( 'intval', (array) $settings['ids'] );

		return \in_array( $product_id, $pos_only_ids, true );
	}

	/**
	 * Check if a product is Online only.
	 *
	 * @param int|string $product_id The product ID.
	 *
	 * @return bool
	 */
	public function is_product_online_only( $product_id ) {
		$product_id      = (int) $product_id;
		$settings        = $this->get_online_only_product_visibility_settings();
		$online_only_ids = array_map( 'intval', (array) $settings['ids'] );

		return \in_array( $product_id, $online_only_ids, true );
	}

	/**
	 * Check if a variation is POS only.
	 *
	 * @param int|string $variation_id The variation ID.
	 *
	 * @return bool
	 */
	public function is_variation_pos_only( $variation_id ) {
		$variation_id = (int) $variation_id;
		$settings     = $this->get_pos_only_variations_visibility_settings();
		$pos_only_ids = array_map( 'intval', (array) $settings['ids'] );

		return \in_array( $variation_id, $pos_only_ids, true );
	}

	/**
	 * Check if a variation is Online only.
	 *
	 * @param int|string $variation_id The variation ID.
	 *
	 * @return bool
	 */
	public function is_variation_online_only( $variation_id ) {
		$variation_id    = (int) $variation_id;
		$settings        = $this->get_online_only_variations_visibility_settings();
		$online_only_ids = array_map( 'intval', (array) $settings['ids'] );

		return \in_array( $variation_id, $online_only_ids, true );
	}

	/**
	 * Add an ID to a visibility type if it doesn't already exist.
	 *
	 * @param array $ids The current array of IDs.
	 * @param int   $id  The ID to add.
	 *
	 * @return array The updated array of IDs.
	 */
	private function add_id_to_visibility( array $ids, int $id ): array {
		if ( ! \in_array( $id, $ids, true ) ) {
			$ids[] = $id;
		}

		return $ids;
	}

	/**
	 * Remove an ID from a visibility type if it exists.
	 *
	 * @param array $ids The current array of IDs.
	 * @param int   $id  The ID to remove.
	 *
	 * @return array The updated array of IDs.
	 */
	private function remove_id_from_visibility( array $ids, int $id ): array {
		return array_filter(
			$ids,
			function ( $existing_id ) use ( $id ) {
				return $existing_id !== $id;
			}
		);
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
