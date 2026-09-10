<?php
/** Register store tests. @package WCPOS\WooCommercePOS\Tests\Services */
namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Register_Store;
use WP_UnitTestCase;

class Test_Register_Store extends WP_UnitTestCase {
	public function test_install_upsert_and_admin_update_preserve_ownership(): void {
		global $wpdb;
		$store = new Register_Store();
		$store->install();
		$this->assertSame( $store->table_name(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $store->table_name() ) ) ) );
		$id = wp_generate_uuid4();
		$first = $store->upsert( array( 'id' => $id, 'name' => 'Front', 'store_id' => 12, 'platform' => 'ios', 'app_version' => 'test' ) );
		$this->assertTrue( $store->exists( $id ) );
		$this->assertSame( 12, $first['store_id'] );
		$this->assertNull( $first['default_float'] );
		$this->assertSame( 'active', $first['status'] );
		$this->assertSame( $first['created_at_gmt'], $first['last_seen_at_gmt'] );
		$edited = $store->update( $id, array( 'name' => 'Admin', 'default_float' => '20.5', 'status' => 'retired', 'store_id' => 13 ) );
		$this->assertSame( '20.5000', $edited['default_float'] );
		$this->assertSame( 'Admin', $edited['name'] );
		$wpdb->update( $store->table_name(), array( 'last_seen_at_gmt' => '2000-01-01 00:00:00' ), array( 'id' => $id ) );
		$again = $store->upsert( array( 'id' => $id, 'name' => 'Back', 'store_id' => 99, 'default_float' => '99', 'status' => 'active', 'platform' => 'web', 'app_version' => 'next' ) );
		$this->assertSame( 'Admin', $again['name'] );
		$this->assertSame( 'web', $again['platform'] );
		$this->assertSame( 'next', $again['app_version'] );
		$this->assertSame( 13, $again['store_id'] );
		$this->assertSame( '20.5000', $again['default_float'] );
		$this->assertSame( 'retired', $again['status'] );
		$this->assertSame( $first['created_at_gmt'], $again['created_at_gmt'] );
		$this->assertNotSame( '2000-01-01T00:00:00Z', $again['last_seen_at_gmt'] );
		$this->assertNull( $store->update( wp_generate_uuid4(), array( 'name' => 'Missing' ) ) );
	}

	public function test_list_filters_and_orders_by_name(): void {
		$store = new Register_Store();
		$store->install();
		$b = $store->upsert( array( 'id' => wp_generate_uuid4(), 'name' => 'B', 'store_id' => 987 ) );
		$a = $store->upsert( array( 'id' => wp_generate_uuid4(), 'name' => 'A', 'store_id' => 987 ) );
		$store->update( $b['id'], array( 'status' => 'retired' ) );
		$this->assertSame( array( $a['id'], $b['id'] ), array_column( $store->list( array( 'store_id' => 987 ) ), 'id' ) );
		$this->assertSame( array( $a['id'] ), array_column( $store->list( array( 'store_id' => 987, 'status' => 'active' ) ), 'id' ) );
		$this->assertSame( array(), $store->list( array( 'store_id' => 988 ) ) );
	}
}
