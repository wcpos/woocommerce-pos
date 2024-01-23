<?php
/**
 * Global registry for WooCommerce POS.
 *
 * - stores instances of classes that may be required to remove_action or remove_filter.
 *
 * @author  Paul Kilmurray <paul@kilbot.com>
 * @see     https://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS;

/**
 *
 */
class Registry {
	/**
	 * Singleton instance.
	 *
	 * @var Registry
	 */
	private static $instance = null;

	/**
	 * Storage for the registry.
	 *
	 * @var array
	 */
	private $storage = array();

	/**
	 * Gets the singleton instance of the registry.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers a new instance.
	 *
	 * @param string $key
	 * @param object $object
	 */
	public function set( $key, $object ) {
		$this->storage[ $key ] = $object;
	}

	/**
	 * Retrieves an instance by key.
	 *
	 * @param string $key
	 */
	public function get( $key ) {
		return isset( $this->storage[ $key ] ) ? $this->storage[ $key ] : null;
	}
}
