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
	/** Object-cache group for the per-request memo of the raw option. Non-persistent. */
	const READ_CACHE_GROUP = 'wcpos_visibility_read';

	/**
	 * Registered section providing the visibility storage surface.
	 *
	 * @var null|Settings_Section_Interface
	 */
	private $storage;

	/**
	 * Flipped id set per list, with the filtered ids array it was built from.
	 *
	 * @var array<string, array{ids: array, set: array<int, int>}>
	 */
	private $id_sets = array();

	/**
	 * Constructor.
	 *
	 * @param null|Settings_Section_Interface $storage Registered visibility section override.
	 */
	public function __construct( ?Settings_Section_Interface $storage = null ) {
		$this->storage = $storage;
	}

	/**
	 * Register the hooks that drop the memo when the option changes. Called once from Init.
	 */
	public static function register_read_cache_hooks(): void {
		$option = Abstract_Section::DB_PREFIX . 'visibility';
		wp_cache_add_non_persistent_groups( self::READ_CACHE_GROUP );
		add_action( "add_option_{$option}", array( self::class, 'flush_read_cache' ), 0, 0 );
		add_action( "update_option_{$option}", array( self::class, 'flush_read_cache' ), 0, 0 );
		add_action( "delete_option_{$option}", array( self::class, 'flush_read_cache' ), 0, 0 );
	}

	/** Drop the memo. Hook callback; also usable by tests. */
	public static function flush_read_cache(): void {
		wp_cache_delete( Abstract_Section::DB_PREFIX . 'visibility', self::READ_CACHE_GROUP );
	}

	/** {@inheritDoc} Memoized in the object cache until the option changes. */
	protected function read_raw(): array {
		$found  = false;
		$cached = wp_cache_get( $this->option_name(), self::READ_CACHE_GROUP, false, $found );
		if ( $found && \is_array( $cached ) ) {
			return $cached;
		}

		$raw = parent::read_raw();
		wp_cache_set( $this->option_name(), $raw, self::READ_CACHE_GROUP );
		return $raw;
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
		 * @hook wcpos_product_visibility_settings
		 */
		$filtered = apply_filters( 'wcpos_product_visibility_settings', $settings['products'][ $scope ], $scope );
		return apply_filters_deprecated( 'woocommerce_pos_product_visibility_settings', array( $filtered, $scope ), '2.0.0', 'wcpos_product_visibility_settings' );
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
		 * @hook wcpos_pos_only_product_visibility_settings
		 */
		$filtered = apply_filters( 'wcpos_pos_only_product_visibility_settings', $settings['pos_only'], $scope );
		return apply_filters_deprecated( 'woocommerce_pos_pos_only_product_visibility_settings', array( $filtered, $scope ), '2.0.0', 'wcpos_pos_only_product_visibility_settings' );
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
		 * @hook wcpos_online_only_product_visibility_settings
		 */
		$filtered = apply_filters( 'wcpos_online_only_product_visibility_settings', $settings['online_only'], $scope );
		return apply_filters_deprecated( 'woocommerce_pos_online_only_product_visibility_settings', array( $filtered, $scope ), '2.0.0', 'wcpos_online_only_product_visibility_settings' );
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
		 * @hook wcpos_variations_visibility_settings
		 */
		$filtered = apply_filters( 'wcpos_variations_visibility_settings', $settings['variations'][ $scope ], $scope );
		return apply_filters_deprecated( 'woocommerce_pos_variations_visibility_settings', array( $filtered, $scope ), '2.0.0', 'wcpos_variations_visibility_settings' );
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
		 * @hook wcpos_pos_only_variations_visibility_settings
		 */
		$filtered = apply_filters( 'wcpos_pos_only_variations_visibility_settings', $settings['pos_only'], $scope );
		return apply_filters_deprecated( 'woocommerce_pos_pos_only_variations_visibility_settings', array( $filtered, $scope ), '2.0.0', 'wcpos_pos_only_variations_visibility_settings' );
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
		 * @hook wcpos_online_only_variations_visibility_settings
		 */
		$filtered = apply_filters( 'wcpos_online_only_variations_visibility_settings', $settings['online_only'], $scope );
		return apply_filters_deprecated( 'woocommerce_pos_online_only_variations_visibility_settings', array( $filtered, $scope ), '2.0.0', 'wcpos_online_only_variations_visibility_settings' );
	}

	/**
	 * Check if a product is POS only.
	 *
	 * @param int|string $product_id The product ID.
	 *
	 * @return bool
	 */
	public function is_product_pos_only( $product_id ) {
		$settings = $this->get_pos_only_product_visibility_settings();

		return $this->list_has_id( 'product_pos_only', $settings['ids'], (int) $product_id );
	}

	/**
	 * Check if a product is Online only.
	 *
	 * @param int|string $product_id The product ID.
	 *
	 * @return bool
	 */
	public function is_product_online_only( $product_id ) {
		$settings = $this->get_online_only_product_visibility_settings();

		return $this->list_has_id( 'product_online_only', $settings['ids'], (int) $product_id );
	}

	/**
	 * Check if a variation is POS only.
	 *
	 * @param int|string $variation_id The variation ID.
	 *
	 * @return bool
	 */
	public function is_variation_pos_only( $variation_id ) {
		$settings = $this->get_pos_only_variations_visibility_settings();

		return $this->list_has_id( 'variation_pos_only', $settings['ids'], (int) $variation_id );
	}

	/**
	 * Check if a variation is Online only.
	 *
	 * @param int|string $variation_id The variation ID.
	 *
	 * @return bool
	 */
	public function is_variation_online_only( $variation_id ) {
		$settings = $this->get_online_only_variations_visibility_settings();

		return $this->list_has_id( 'variation_online_only', $settings['ids'], (int) $variation_id );
	}

	/**
	 * Whether a filtered id list holds an id; `===` on an unchanged array is a pointer check, so a hit costs O(1).
	 *
	 * @param string $list Fixed per-method key for the list.
	 * @param mixed  $ids  The filtered ids value.
	 * @param int    $id   The id to look up.
	 */
	private function list_has_id( string $list, $ids, int $id ): bool {
		$ids = (array) $ids;
		if ( ! isset( $this->id_sets[ $list ] ) || $this->id_sets[ $list ]['ids'] !== $ids ) {
			$this->id_sets[ $list ] = array(
				'ids' => $ids,
				'set' => array_flip( array_map( 'intval', $ids ) ),
			);
		}

		return isset( $this->id_sets[ $list ]['set'][ $id ] );
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
