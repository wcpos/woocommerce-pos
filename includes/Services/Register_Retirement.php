<?php
/**
 * Optional register retirement contract.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

/** Repositories supporting retirement keep names for history. */
interface Register_Retirement {
	/** Retire a register: it leaves the list and keeps its name for history.
	 *
	 * @param string $id Register UUID.
	 */
	public function retire( string $id ): void;
}
