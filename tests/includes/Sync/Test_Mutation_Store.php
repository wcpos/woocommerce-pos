<?php
/**
 * Storage-level tests for sync mutations.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Ported lab documentation is preserved verbatim.

use WCPOS\WooCommercePOS\Sync\Mutation_Store;

/**
 * Mutation reservation/finalization tests adapted from the lab.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Mutation_Store
 */
class Test_Mutation_Store extends Sync_Store_Test_Case {
	/** @var Mutation_Store */
	private $store;

	/**
	 * Install a clean mutation table.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->install_sync_tables_directly();
		$this->store = new Mutation_Store();
	}

	/**
	 * A mutation can be reserved, checkpointed, and finalized once.
	 */
	public function test_mutation_create_and_finalize_persists_done_row(): void {
		$this->assertTrue( $this->store->reserve( 'products', 'mutation-1', 'record-1', 'create' ) );
		$this->assertFalse( $this->store->reserve( 'products', 'mutation-1', 'record-1', 'create' ) );
		$this->assertSame( 'pending', $this->store->lookup( 'products', 'mutation-1' )['status'] );
		$this->assertTrue( $this->store->mark_applied( 'mutation-1', 42, 201 ) );
		$this->assertTrue( $this->store->finalize( 'mutation-1', 42 ) );

		$row = $this->store->lookup( 'products', 'mutation-1' );
		$this->assertSame( 'done', $row['status'] );
		$this->assertSame( '42', $row['remote_id'] );
	}

	/**
	 * Finalization is idempotent for the same id and rejects a different id.
	 */
	public function test_finalize_replay_requires_the_same_remote_id(): void {
		$this->store->reserve( 'products', 'mutation-1', 'record-1', 'create' );
		$this->store->mark_applied( 'mutation-1', 42, 201 );
		$this->store->finalize( 'mutation-1', 42 );

		$this->assertTrue( $this->store->finalize( 'mutation-1', 42 ) );
		$this->assertFalse( $this->store->finalize( 'mutation-1', 99 ) );
	}
}
