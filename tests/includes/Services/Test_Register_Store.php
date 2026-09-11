<?php
/** Register store tests. @package WCPOS\WooCommercePOS\Tests\Services */
namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Register_Store;
use WP_UnitTestCase;

class Test_Register_Store extends WP_UnitTestCase {
	/** Create initializes the row; updates ignore till telemetry. */
	public function test_install_create_and_admin_update_preserve_ownership(): void {
		global $wpdb;
		$store = new Register_Store();
		$store->install();
		$this->assertSame( $store->table_name(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $store->table_name() ) ) ) );
		$first = $store->create(
			array(
				'name' => 'Front',
				'store_id' => 12,
			)
		);
		$id = $first['id'];
		$this->assertTrue( $store->exists( $id ) );
		$this->assertSame( 12, $first['store_id'] );
		$this->assertNull( $first['default_float'] );
		$this->assertSame( 'active', $first['status'] );
		$this->assertSame( $first['created_at_gmt'], $first['last_seen_at_gmt'] );
		$edited = $store->update( $id, array( 'name' => 'Admin', 'default_float' => '20.5', 'status' => 'retired', 'store_id' => 13 ) );
		$this->assertSame( '20.5000', $edited['default_float'] );
		$this->assertSame( 'Admin', $edited['name'] );
		$again = $store->update(
			$id,
			array(
				'platform' => 'web',
				'app_version' => 'next',
				'last_seen_at_gmt' => '2000-01-01 00:00:00',
			)
		);
		$this->assertSame( $edited, $again );
		$this->assertSame( '', $again['platform'] );
		$this->assertSame( '', $again['app_version'] );
		$this->assertSame( $first['last_seen_at_gmt'], $again['last_seen_at_gmt'] );
		$this->assertNull( $store->update( wp_generate_uuid4(), array( 'name' => 'Missing' ) ) );
	}

	public function test_list_filters_and_orders_by_name(): void {
		$store = new Register_Store();
		$store->install();
		$b = $store->create(
			array(
				'name' => 'B',
				'store_id' => 987,
			)
		);
		$a = $store->create(
			array(
				'name' => 'A',
				'store_id' => 987,
			)
		);
		$store->update( $b['id'], array( 'status' => 'retired' ) );
		$this->assertSame( array( $a['id'], $b['id'] ), array_column( $store->list( array( 'store_id' => 987 ) ), 'id' ) );
		$this->assertSame( array( $a['id'] ), array_column( $store->list( array( 'store_id' => 987, 'status' => 'active' ) ), 'id' ) );
		$this->assertSame( array(), $store->list( array( 'store_id' => 988 ) ) );
	}
	/** Seeding is idempotent and retired rows do not count as active. */
	public function test_ensure_default_empty_and_retired_only_tables_get_one_active_register(): void {
		global $wpdb;
		$store = new Register_Store();
		$store->install();
		$wpdb->query( 'DELETE FROM ' . $store->table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Test table cleanup.
		$store->ensure_default();
		$rows = $store->list( array( 'status' => 'active' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'Register', $rows[0]['name'] );
		$this->assertNull( $rows[0]['store_id'] );
		$store->ensure_default();
		$this->assertSame( $rows, $store->list( array( 'status' => 'all' ) ) );
		$store->update( $rows[0]['id'], array( 'status' => 'retired' ) );
		$store->ensure_default();
		$this->assertCount( 2, $store->list( array( 'status' => 'all' ) ) );
		$active = $store->list( array( 'status' => 'active' ) );
		$this->assertCount( 1, $active );
		$this->assertSame( 'Register', $active[0]['name'] );
		$this->assertNull( $active[0]['store_id'] );
	}
}
