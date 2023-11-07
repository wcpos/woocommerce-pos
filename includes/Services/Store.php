<?php

namespace WCPOS\WooCommercePOS\Services;

class Store {
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
		return 0;
	}
}
