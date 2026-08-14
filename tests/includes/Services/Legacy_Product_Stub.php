<?php
/**
 * A product-like test double that predates WC 9.1.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

/**
 * A product-like object with NO global-unique-id accessors, which is exactly what
 * the method_exists() guards in Barcode_Field branch on. It also records how it
 * was persisted so tests can pin the save() vs save_meta_data() split.
 *
 * @internal
 */
class Legacy_Product_Stub {
	/**
	 * Meta written through update_meta_data().
	 *
	 * @var array<string, mixed>
	 */
	public $meta = array();

	/**
	 * Number of full save() calls.
	 *
	 * @var int
	 */
	public $saves = 0;

	/**
	 * Number of save_meta_data() calls.
	 *
	 * @var int
	 */
	public $meta_saves = 0;

	/**
	 * The SKU.
	 *
	 * @var string
	 */
	public $sku = '';

	/**
	 * Get the SKU.
	 *
	 * @return string
	 */
	public function get_sku() {
		return $this->sku;
	}

	/**
	 * Set the SKU.
	 *
	 * @param string $sku The SKU.
	 */
	public function set_sku( $sku ): void {
		$this->sku = $sku;
	}

	/**
	 * Get a meta value.
	 *
	 * @param string $key The meta key.
	 *
	 * @return mixed
	 */
	public function get_meta( $key ) {
		return $this->meta[ $key ] ?? '';
	}

	/**
	 * Write a meta value.
	 *
	 * @param string $key   The meta key.
	 * @param mixed  $value The meta value.
	 */
	public function update_meta_data( $key, $value ): void {
		$this->meta[ $key ] = $value;
	}

	/**
	 * Persist the object.
	 */
	public function save(): void {
		++$this->saves;
	}

	/**
	 * Persist only the meta.
	 */
	public function save_meta_data(): void {
		++$this->meta_saves;
	}
}
