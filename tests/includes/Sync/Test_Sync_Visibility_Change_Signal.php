<?php
/**
 * Tests for POS-visibility scoping of the tier 1 sequence-log stream.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\ProductHelper;
use WCPOS\WooCommercePOS\API\V2\Changes_Controller;
use WCPOS\WooCommercePOS\Services\Settings;
use WCPOS\WooCommercePOS\Sync\Pos_Visibility;
use WCPOS\WooCommercePOS\Sync\Sync_Journal;
use WCPOS\WooCommercePOS\Sync\Visibility_Observer;
use WP_REST_Request;

/**
 * The catalogue change stream and the POS servable set.
 *
 * A record hidden from the POS is FOREIGN to the catalogue stream in exactly the
 * way an order row is: the catalog lane will never serve it, so announcing it
 * costs every till a targeted pull that comes back empty. The stream therefore
 * drops its update rows — but a TOMBSTONE for a hidden id is the one message
 * about it a client must still receive, and hiding a record emits one.
 *
 * @covers \WCPOS\WooCommercePOS\API\V2\Changes_Controller
 * @covers \WCPOS\WooCommercePOS\Sync\Visibility_Observer
 */
class Test_Sync_Visibility_Change_Signal extends Sync_REST_Store_Test_Case {
	use Sync_Observer_Unhook_Trait;

	/**
	 * The journal under test.
	 *
	 * @var Sync_Journal
	 */
	private $journal;

	/**
	 * The visibility observer under test.
	 *
	 * @var Visibility_Observer
	 */
	private $observer;

	/**
	 * Boot a journal and its visibility observer.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->journal  = new Sync_Journal();
		$this->observer = new Visibility_Observer( $this->journal );
	}

	/**
	 * Drop observer hooks and the visibility options.
	 */
	public function tearDown(): void {
		$this->remove_observer_callbacks( array( $this->observer, $this->journal ) );
		delete_option( Pos_Visibility::OPTION );
		delete_option( 'woocommerce_pos_settings_general' );
		parent::tearDown();
	}

	/**
	 * Build a GET request with query parameters.
	 *
	 * @param array $params Query parameters.
	 */
	private function request( array $params = array() ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/' );
		$request->set_query_params( $params );

		return $request;
	}

	/**
	 * Turn the pos_only_products feature on.
	 */
	private function enable_pos_only_products(): void {
		update_option( 'woocommerce_pos_settings_general', array( 'pos_only_products' => true ), false );
	}

	/**
	 * Write the online_only id lists directly, bypassing the observer.
	 *
	 * @param int[] $product_ids   Products hidden from the POS.
	 * @param int[] $variation_ids Variations hidden from the POS.
	 */
	private function set_online_only( array $product_ids = array(), array $variation_ids = array() ): void {
		update_option(
			Pos_Visibility::OPTION,
			array(
				'products'   => array(
					'default' => array( 'online_only' => array( 'ids' => $product_ids ) ),
				),
				'variations' => array(
					'default' => array( 'online_only' => array( 'ids' => $variation_ids ) ),
				),
			),
			false
		);
	}

	/**
	 * Read one sequence-log page of the unified catalogue stream.
	 *
	 * @param int $since Cursor to page from.
	 */
	private function sequence_log_changes( int $since = 0 ): array {
		$response = ( new Changes_Controller( $this->journal ) )->sequence_log(
			$this->request(
				array(
					'collection' => 'all',
					'since'      => $since,
					'limit'      => 100,
				)
			)
		);

		return $response->get_data()['changes'];
	}

	/**
	 * Update rows for POS-hidden products and variations never reach the stream.
	 */
	public function test_sequence_log_omits_update_rows_for_online_only_records(): void {
		// Arrange.
		$hidden_product   = ProductHelper::create_simple_product()->get_id();
		$hidden_variation = ProductHelper::create_simple_product()->get_id();
		$visible_product  = ProductHelper::create_simple_product()->get_id();
		$this->enable_pos_only_products();
		$this->set_online_only( array( $hidden_product ), array( $hidden_variation ) );
		$this->journal->record( 'product', $hidden_product, false, '', 'test', false );
		$this->journal->record( 'variation', $hidden_variation, false, '', 'test', false );
		$this->journal->record( 'product', $visible_product, false, '', 'test', false );

		// Act.
		$changes = $this->sequence_log_changes();

		// Assert.
		$this->assertSame( array( $visible_product ), array_column( $changes, 'id' ) );
	}

	/**
	 * A dropped row still advances the cursor, so the stream reaches head and 304s.
	 *
	 * Dropping rows without advancing the checkpoint past them would strand every
	 * cursor below head and force an empty 200 on every poll, forever — the same
	 * failure the stream-scoped head exists to prevent for order rows.
	 */
	public function test_sequence_log_hidden_rows_still_advance_the_cursor_to_head(): void {
		// Arrange.
		$hidden_product = ProductHelper::create_simple_product()->get_id();
		$this->enable_pos_only_products();
		$this->set_online_only( array( $hidden_product ) );
		$this->journal->record( 'product', $hidden_product, false, '', 'test', false );
		$controller = new Changes_Controller( $this->journal );

		// Act.
		$page = $controller->sequence_log(
			$this->request(
				array(
					'collection' => 'all',
					'since'      => 0,
					'limit'      => 100,
				)
			)
		);
		$data = $page->get_data();

		$next = $this->request(
			array(
				'collection' => 'all',
				'since'      => $data['checkpoint']['since'],
			)
		);
		$next->set_header( 'If-None-Match', $page->get_headers()['ETag'] );
		$idle = $controller->sequence_log( $next );

		// Assert.
		$this->assertSame( array(), $data['changes'] );
		$this->assertTrue( $data['complete'] );
		$this->assertSame( $data['checkpoint']['head'], $data['checkpoint']['since'] );
		$this->assertSame( 304, $idle->get_status() );
	}

	/**
	 * A tombstone for a hidden id is served — it is the one message about a
	 * hidden record the client must still receive.
	 */
	public function test_sequence_log_serves_tombstone_rows_for_online_only_records(): void {
		// Arrange.
		$hidden_product = ProductHelper::create_simple_product()->get_id();
		$this->enable_pos_only_products();
		$this->set_online_only( array( $hidden_product ) );
		$this->journal->record( 'product', $hidden_product, true, '', 'test', false );

		// Act.
		$changes = $this->sequence_log_changes();

		// Assert.
		$this->assertSame( array( $hidden_product ), array_column( $changes, 'id' ) );
		$this->assertSame( 1, $changes[0]['deleted'] );
	}

	/**
	 * The exclusion is scoped to the post id-space it came from.
	 *
	 * Tax rates, customers, coupons and terms number their rows in their OWN
	 * id-spaces, so a hidden product id collides with an unrelated record on
	 * every one of them. Filtering on the id alone would silently delete a
	 * whole collection's changes from the stream.
	 */
	public function test_sequence_log_hidden_product_id_does_not_filter_other_object_types(): void {
		// Arrange.
		$hidden_product = ProductHelper::create_simple_product()->get_id();
		$this->enable_pos_only_products();
		$this->set_online_only( array( $hidden_product ) );
		$this->journal->record( 'customer', $hidden_product, false, '', 'test', false );
		$this->journal->record( 'tax_rate', $hidden_product, false, '', 'test', false );

		// Act.
		$changes = $this->sequence_log_changes();

		// Assert.
		$this->assertSame(
			array( 'customers', 'tax_rates' ),
			array_column( $changes, 'collection' )
		);
	}

	/**
	 * Hiding a product appends a tombstone, exactly as trashing it would.
	 */
	public function test_hiding_a_product_records_a_tombstone(): void {
		// Arrange.
		$product_id = ProductHelper::create_simple_product()->get_id();
		$this->enable_pos_only_products();
		$this->observer->register_hooks();
		$cursor = $this->journal->head_sequence();

		// Act.
		Settings::instance()->update_visibility_settings(
			array(
				'post_type'  => 'products',
				'visibility' => 'online_only',
				'ids'        => array( $product_id ),
			)
		);

		// Assert.
		$rows = $this->journal->page( array( 'product' ), $cursor, 100 )['rows'];
		$this->assertSame( array( $product_id ), array_column( $rows, 'object_id' ) );
		$this->assertSame( 1, $rows[0]['deleted'] );
	}

	/**
	 * Un-hiding a product appends an ordinary update row, so the client pulls it back.
	 */
	public function test_unhiding_a_product_records_an_update(): void {
		// Arrange.
		$product_id = ProductHelper::create_simple_product()->get_id();
		$this->enable_pos_only_products();
		$this->set_online_only( array( $product_id ) );
		$this->observer->register_hooks();
		$cursor = $this->journal->head_sequence();

		// Act.
		Settings::instance()->update_visibility_settings(
			array(
				'post_type'  => 'products',
				'visibility' => '',
				'ids'        => array( $product_id ),
			)
		);

		// Assert.
		$rows = $this->journal->page( array( 'product' ), $cursor, 100 )['rows'];
		$this->assertSame( array( $product_id ), array_column( $rows, 'object_id' ) );
		$this->assertSame( 0, $rows[0]['deleted'] );
	}

	/**
	 * Hiding a variation tombstones it AND re-announces its parent product.
	 *
	 * The parent's served price range is built from its VISIBLE children, so
	 * hiding one changes the parent's representation — the same invariant every
	 * other variation write in the journal already carries.
	 */
	public function test_hiding_a_variation_records_a_tombstone_and_a_parent_update(): void {
		// Arrange.
		$product      = ProductHelper::create_variation_product();
		$variation_id = (int) $product->get_children()[0];
		$this->enable_pos_only_products();
		$this->observer->register_hooks();
		$cursor = $this->journal->head_sequence();

		// Act.
		Settings::instance()->update_visibility_settings(
			array(
				'post_type'  => 'variations',
				'visibility' => 'online_only',
				'ids'        => array( $variation_id ),
			)
		);

		// Assert.
		$rows = $this->journal->page( array( 'product', 'variation' ), $cursor, 100 )['rows'];
		$this->assertSame(
			array(
				array( 'variation', $variation_id, 1 ),
				array( 'product', $product->get_id(), 0 ),
			),
			array_map(
				static function ( array $row ): array {
					return array( $row['object_type'], $row['object_id'], $row['deleted'] );
				},
				$rows
			)
		);
	}

	/**
	 * Turning the pos_only_products feature ON hides every configured id at once.
	 *
	 * The id lists can be populated while the feature is off — `hidden_ids()`
	 * reports an empty set until the toggle flips, so the toggle IS the moment
	 * those records leave the POS servable set.
	 */
	public function test_enabling_pos_only_products_tombstones_the_configured_ids(): void {
		// Arrange.
		$product_id = ProductHelper::create_simple_product()->get_id();
		$this->set_online_only( array( $product_id ) );
		$this->observer->register_hooks();
		$cursor = $this->journal->head_sequence();

		// Act.
		$this->enable_pos_only_products();

		// Assert.
		$rows = $this->journal->page( array( 'product' ), $cursor, 100 )['rows'];
		$this->assertSame( array( $product_id ), array_column( $rows, 'object_id' ) );
		$this->assertSame( 1, $rows[0]['deleted'] );
	}

	/**
	 * Turning the feature back OFF re-announces the ids as ordinary updates.
	 */
	public function test_disabling_pos_only_products_re_announces_the_configured_ids(): void {
		// Arrange.
		$product_id = ProductHelper::create_simple_product()->get_id();
		$this->enable_pos_only_products();
		$this->set_online_only( array( $product_id ) );
		$this->observer->register_hooks();
		$cursor = $this->journal->head_sequence();

		// Act.
		update_option( 'woocommerce_pos_settings_general', array( 'pos_only_products' => false ), false );

		// Assert.
		$rows = $this->journal->page( array( 'product' ), $cursor, 100 )['rows'];
		$this->assertSame( array( $product_id ), array_column( $rows, 'object_id' ) );
		$this->assertSame( 0, $rows[0]['deleted'] );
	}

	/**
	 * A settings write that moves no record writes no journal row.
	 *
	 * Every save of the General or Visibility section reaches this observer, and the whole
	 * catalogue stream would churn if an unrelated settings edit re-announced the hidden set. The
	 * diff is over the RESOLVED set for exactly this reason.
	 */
	public function test_a_settings_write_that_moves_no_record_writes_no_row(): void {
		// Arrange.
		$product_id = ProductHelper::create_simple_product()->get_id();
		$this->enable_pos_only_products();
		$this->set_online_only( array( $product_id ) );
		$this->observer->register_hooks();
		$cursor = $this->journal->head_sequence();

		// Act: an unrelated General setting moves, and the visibility option changes in a way that
		// leaves the POS hidden set alone — `pos_only` is a WEB-store concern, not this stream's.
		update_option(
			'woocommerce_pos_settings_general',
			array(
				'pos_only_products' => true,
				'decimal_qty'       => true,
			),
			false
		);
		update_option(
			Pos_Visibility::OPTION,
			array(
				'products' => array(
					'default' => array(
						'online_only' => array( 'ids' => array( $product_id ) ),
						'pos_only'    => array( 'ids' => array( ProductHelper::create_simple_product()->get_id() ) ),
					),
				),
			),
			false
		);

		// Assert.
		$this->assertSame( array(), $this->journal->page( array( 'product', 'variation' ), $cursor, 100 )['rows'] );
	}

	/**
	 * The policy end to end: hidden once, announced once, then silent.
	 *
	 * The client is told to drop the record exactly once, and every later edit
	 * of that record is dropped from the stream instead of costing every till a
	 * targeted pull that comes back empty.
	 */
	public function test_hidden_product_is_announced_once_then_stays_out_of_the_stream(): void {
		// Arrange.
		$product = ProductHelper::create_simple_product();
		$this->enable_pos_only_products();
		$this->observer->register_hooks();
		$this->journal->register_hooks();
		$cursor = $this->journal->head_sequence();

		// Act: hide it, then keep editing it.
		Settings::instance()->update_visibility_settings(
			array(
				'post_type'  => 'products',
				'visibility' => 'online_only',
				'ids'        => array( $product->get_id() ),
			)
		);
		$announced = $this->sequence_log_changes( $cursor );

		$after_announcement = (int) $this->journal->head_sequence( Sync_Journal::catalogue_object_types() );
		$product->set_regular_price( '9.99' );
		$product->save();
		$later = $this->sequence_log_changes( $after_announcement );

		// Assert.
		$this->assertSame( array( $product->get_id() ), array_column( $announced, 'id' ) );
		$this->assertSame( 1, $announced[0]['deleted'] );
		$this->assertSame( array(), $later );
	}
}
