<?php

namespace WCPOS\WooCommercePOS\Services;

class Store {
	/**
	 * @var int Store ID, 0 for default store
	 *
	 * Note: Pro version extends this class and overrides this property.
	 */
	protected int $store_id = 0;

	/**
	 * Get Store Name.
	 *
	 * @return string The Store Name
	 */
	public function get_name(): string {
		return get_bloginfo('name');
	}

	/**
	 * Get the store ID.
	 *
	 * @return int The store ID.
	 */
	public function get_id(): int {
		return $this->store_id;
	}
}
