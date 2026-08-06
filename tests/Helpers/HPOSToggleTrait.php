<?php

namespace Automattic\WooCommerce\RestApi\UnitTests\Helpers;

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;

/**
 * Trait HPOSToggleTrait.
 *
 * Provides methods to toggle the HPOS feature on and off.
 *
 * The HPOS tables are provisioned ONCE PER CLASS, from `wpSetUpBeforeClass()`, and
 * `setup_cot()` only flips options. This split is load-bearing, not tidiness:
 *
 * `WP_UnitTestCase_Base::start_transaction()` runs `SET autocommit = 0; START
 * TRANSACTION;` in every `setUp()`, and `tear_down()` rolls that transaction back —
 * that rollback is the ONLY thing isolating a test's fixtures. MySQL implicitly
 * COMMITs on DDL, so a `DROP TABLE`/`CREATE TABLE` inside `setUp()` (as this trait
 * used to run, per test) ends that transaction mid-setup: every fixture created
 * before it becomes permanent, and because autocommit is 0 a *fresh* transaction
 * opens immediately, so the later `tear_down()` ROLLBACK undoes only the work that
 * came after the DDL. Any cleanup a subclass writes in its own `tearDown()` lands in
 * that second transaction and is rolled back too — which is why a `DELETE` of the
 * leaked rows looks correct and changes nothing.
 *
 * Concretely, `Test_Rest_Dispatch_Order_Taxes_HPOS` leaked 15 tax rates per run
 * (5 inherited tests x 3 rates created by the parent `setUp()`), which then broke
 * count-based assertions in later suites such as `Test_Taxes_Controller`.
 *
 * `wpSetUpBeforeClass()` is the correct seam: WordPress calls it before any per-test
 * transaction exists, with the temporary-table `query` filters unregistered
 * (`tear_down()` removes them), and COMMITs straight afterwards — so the DDL is real,
 * expected, and commits nothing but itself.
 *
 * NOTE: a consuming class that declares its own `wpSetUpBeforeClass()` shadows the
 * one below and must call `static::provision_cot_tables()` itself.
 */
trait HPOSToggleTrait {

	/**
	 * Provision the HPOS tables once per class, before any test transaction starts.
	 *
	 * @param mixed $factory WP unit-test factory (unused).
	 *
	 * @return void
	 */
	public static function wpSetUpBeforeClass( $factory ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- WP test-framework hook name.
		self::provision_cot_tables();
	}

	/**
	 * Drop and recreate the HPOS tables as real (non-temporary) tables.
	 *
	 * Only ever call this outside a test transaction — it runs DDL. See the trait
	 * docblock for why that matters.
	 *
	 * @return void
	 */
	public static function provision_cot_tables(): void {
		OrderHelper::delete_order_custom_tables();
		OrderHelper::create_order_custom_table_if_not_exist();
	}

	/**
	 * Call in setUp to enable COT/HPOS.
	 *
	 * Deliberately DDL-free: the tables already exist (see wpSetUpBeforeClass), so
	 * `create_order_custom_table_if_not_exist()` short-circuits on its existence
	 * check and the per-test transaction survives intact. It is kept as a fallback
	 * for a consuming class that shadows the class-level hook.
	 *
	 * @return void
	 */
	public function setup_cot() {
		// Remove the Test Suite’s use of temporary tables https://wordpress.stackexchange.com/a/220308,
		// so the fallback below would create real tables rather than temporary ones.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		OrderHelper::create_order_custom_table_if_not_exist();

		$this->toggle_cot_feature_and_usage( true );
	}

	/**
	 * Call in teardown to disable COT/HPOS.
	 */
	public function clean_up_cot_setup(): void {
		$this->toggle_cot_feature_and_usage( false );

		// Add back removed filter.
		add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	/**
	 * Enables or disables the custom orders table feature, and sets the orders table as authoritative, across WP temporarily.
	 *
	 * @param boolean $enabled TRUE to enable COT or FALSE to disable.
	 * @return void
	 */
	private function toggle_cot_feature_and_usage( bool $enabled ): void {
		OrderHelper::toggle_cot_feature_and_usage( $enabled );
	}

	/**
	 * Set the orders table or the posts table as the authoritative table to store orders.
	 *
	 * @param bool $cot_authoritative True to set the orders table as authoritative, false to set the posts table as authoritative.
	 */
	protected function toggle_cot_authoritative( bool $cot_authoritative ) {
		OrderHelper::toggle_cot_feature_and_usage( $cot_authoritative );
	}

	/**
	 * Helper function to enable COT <> Posts sync.
	 */
	private function enable_cot_sync() {
		$hook_name = 'pre_option_' . DataSynchronizer::ORDERS_DATA_SYNC_ENABLED_OPTION;
		remove_all_actions( $hook_name );
		add_filter(
			$hook_name,
			function () {
				return 'yes';
			}
		);
	}

	/**
	 * Helper function to disable COT <> Posts sync.
	 */
	private function disable_cot_sync() {
		$hook_name = 'pre_option_' . DataSynchronizer::ORDERS_DATA_SYNC_ENABLED_OPTION;
		remove_all_actions( $hook_name );
		add_filter(
			$hook_name,
			function () {
				return 'no';
			}
		);
	}
}
