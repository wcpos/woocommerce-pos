<?php
/**
 * Register fixtures.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Register_Store;

/** One register through Free's option, or many through an in-memory repository. */
trait Register_Fixture {
	/**
	 * The bound in-memory repository, once a test asks for more than one register.
	 *
	 * @var Array_Register_Repository|null
	 */
	private $register_repository;

	/** Bind an in-memory repository for this test; WP_UnitTestCase restores hooks afterwards. */
	private function use_multi_register_repository(): Array_Register_Repository {
		if ( null === $this->register_repository ) {
			$repository = new Array_Register_Repository();
			add_filter(
				'wcpos_register_repository',
				static function () use ( $repository ) {
					return $repository;
				}
			);
			$this->register_repository = $repository;
		}
		return $this->register_repository;
	}

	/**
	 * A new register in the bound in-memory repository, or Free's one register (named as asked).
	 *
	 * @param array $fields Register fields.
	 */
	private function create_register( array $fields = array() ): array {
		$store = new Register_Store();
		if ( null !== $this->register_repository ) {
			return $store->create( $fields + array( 'name' => 'Fixture register' ) );
		}
		$row = $store->list( array() )[0];
		$edit = array_intersect_key( $fields, array_flip( array( 'name', 'default_float' ) ) );
		return $edit ? $store->update( $row['id'], $edit ) : $row;
	}
}
