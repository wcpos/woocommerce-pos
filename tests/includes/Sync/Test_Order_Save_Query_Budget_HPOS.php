<?php
/**
 * Rows-examined budget for saving a `pos-open` order under HPOS storage.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;
use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * The same gate, on the custom orders tables.
 *
 * HPOS was never affected by #1725, and that is exactly why it needs its own budget
 * rather than sharing the CPT one. `wc_orders_meta` carries a composite
 * `(meta_key, meta_value)` index, so the uuid lookup is a real seek and the whole
 * save costs a fraction of the CPT figure. Handed the CPT ceiling, this class would
 * pass with 25x of slack and gate nothing at all.
 *
 * Expect this pair to be GREEN on both sides of the #1725 fix — that is the point.
 * The bug lived on the datastore whose only coverage was on the other datastore, and
 * a budget that only exists where the bug happened to land repeats the mistake.
 *
 * Note also that HPOS is safe here BY DATA, not by code: the same query shape runs on
 * both branches, and it is WooCommerce's index that saves it. If that index ever goes
 * away, this budget is what notices.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Pos_Uuid::get_order_ids_by_uuid
 */
class Test_Order_Save_Query_Budget_HPOS extends Test_Order_Save_Query_Budget {
	use HPOSToggleTrait;

	/**
	 * Absolute ceiling on rows examined while saving one `pos-open` order under HPOS,
	 * at ANY fixture size.
	 *
	 * Measured on this fixture:
	 *
	 *   orders | before the #1725 fix | after
	 *   -------|----------------------|-------
	 *      128 |                   58 |    57
	 *      512 |                   58 |    57
	 *
	 * Dead flat in store size, and all but unchanged by the fix — which is the
	 * measured proof that HPOS was never affected, and the reason it cannot share the
	 * CPT bound: handed a ceiling of 1,500 this class would pass with 25x of slack and
	 * gate nothing. 500 leaves ~8x headroom for WooCommerce-version drift while
	 * sitting far below the 15,872 meta rows a scan of the 512-order fixture would
	 * touch — so if the composite index ever goes away, this is what notices.
	 */
	protected const ROW_BUDGET = 500;

	/**
	 * Provision the HPOS tables, THEN seed into them.
	 *
	 * This class declares its own `wpSetUpBeforeClass()`, which shadows both the
	 * parent's and HPOSToggleTrait's (a trait method takes precedence over an
	 * inherited one), so it has to drive both halves explicitly — the trait's docblock
	 * calls this out.
	 *
	 * @param mixed $factory WP unit-test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- WP test-framework hook name.
		static::provision_cot_tables();
		parent::wpSetUpBeforeClass( $factory );
	}

	/**
	 * Enable HPOS for each test.
	 */
	public function setUp(): void {
		parent::setUp();

		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		$this->setup_cot();
		$this->toggle_cot_feature_and_usage( true );
	}

	/**
	 * Restore posts storage.
	 */
	public function tearDown(): void {
		$this->toggle_cot_feature_and_usage( false );
		$this->clean_up_cot_setup();
		remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );

		parent::tearDown();
	}

	/**
	 * Seed and measure against the custom orders tables.
	 */
	protected static function uses_hpos(): bool {
		return true;
	}

	/**
	 * The budget above is only an HPOS budget if HPOS is genuinely active.
	 */
	public function test_hpos_storage_is_active(): void {
		$this->assertTrue( OrderUtil::custom_orders_table_usage_is_enabled() );
	}
}
