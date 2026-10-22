<?php
/**
 * Self-test for the re-entrant save tripwire installed by the bootstrap.
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use LogicException;
use WC_Product_Simple;
use WCPOS\WooCommercePOS\Tests\Helpers\Save_Reentry_Guard;
use WP_UnitTestCase;

use function WCPOS\WooCommercePOS\Tests\Helpers\Fixtures\reentry_offender_delete_post;
use function WCPOS\WooCommercePOS\Tests\Helpers\Fixtures\reentry_offender_save_product;
use function WCPOS\WooCommercePOS\Tests\Helpers\Fixtures\reentry_offender_update_post;
use function WCPOS\WooCommercePOS\Tests\Helpers\Fixtures\First_Plugin\first_plugin_update_post;
use function WCPOS\WooCommercePOS\Tests\Helpers\Fixtures\Second_Plugin\second_plugin_update_post;

/**
 * The guard must fire for plugin code saving from inside a save or transition hook, and
 * stay silent for everything else, or a green suite proves nothing about #2371.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Save_Reentry_Guard extends WP_UnitTestCase {
	/**
	 * Fixture function that re-saves a product with wp_update_post() on a stock change.
	 */
	const TOUCH_HANDLER = 'WCPOS\WooCommercePOS\Tests\Helpers\Fixtures\reentry_offender_touch_product';

	/**
	 * Directory the guard watched before this test re-pointed it.
	 *
	 * @var string
	 */
	private $previous_dir = '';

	/**
	 * Original woocommerce_manage_stock option.
	 *
	 * @var mixed
	 */
	private $original_manage_stock;

	/**
	 * Point the guard at the fixture directory so its functions count as plugin code.
	 */
	public function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/../Helpers/fixtures/save-reentry-offender.php';
		require_once __DIR__ . '/../Helpers/fixtures/first-plugin/includes/offenders.php';
		require_once __DIR__ . '/../Helpers/fixtures/second-plugin/includes/offenders.php';
		$this->previous_dir          = Save_Reentry_Guard::watch_directory( __DIR__ . '/../Helpers/fixtures' );
		$this->original_manage_stock = get_option( 'woocommerce_manage_stock' );
		update_option( 'woocommerce_manage_stock', 'yes' );
	}

	/**
	 * Restore `includes/` as the policed directory.
	 */
	public function tearDown(): void {
		Save_Reentry_Guard::watch_directory( $this->previous_dir );
		update_option( 'woocommerce_manage_stock', $this->original_manage_stock );
		parent::tearDown();
	}

	/**
	 * The #2371 shape: wp_update_post() on the product from a stock hook fails the test and names everything.
	 */
	public function test_guard_update_post_inside_set_stock_hook_throws_naming_caller_object_and_hook(): void {
		// Arrange.
		$this->setExpectedIncorrectUsage( Save_Reentry_Guard::NOTICE );
		$product_id = $this->create_managed_stock_product();
		add_action( 'woocommerce_product_set_stock', self::TOUCH_HANDLER );

		// Act.
		$message = '';
		try {
			wc_update_product_stock( wc_get_product( $product_id ), 4, 'set' );
		} catch ( LogicException $e ) {
			$message = $e->getMessage();
		}

		// Assert.
		$this->assertStringContainsString( 'fixtures/save-reentry-offender.php:', $message );
		$this->assertStringContainsString( 'saved product #' . $product_id . ' (wp_update_post())', $message );
		$this->assertStringContainsString( "inside 'woocommerce_product_set_stock'", $message );
		$this->assertStringContainsString( '.ai/rules/no-saves-inside-save-hooks.mdc', $message );
	}

	/**
	 * Deleting a related post from a pre_delete_post handler re-enters the delete stack and trips.
	 */
	public function test_guard_delete_post_inside_pre_delete_post_hook_throws_naming_caller_and_hook(): void {
		// Arrange.
		$this->setExpectedIncorrectUsage( Save_Reentry_Guard::NOTICE );
		$post_id    = self::factory()->post->create();
		$related_id = self::factory()->post->create();
		add_filter(
			'pre_delete_post',
			function ( $delete, $post ) use ( $post_id, $related_id ) {
				if ( $post_id === $post->ID ) {
					reentry_offender_delete_post( $related_id );
				}
				return $delete;
			},
			10,
			2
		);

		// Act.
		$message = '';
		try {
			wp_delete_post( $post_id, true );
		} catch ( LogicException $e ) {
			$message = $e->getMessage();
		}

		// Assert.
		$this->assertStringContainsString( 'fixtures/save-reentry-offender.php:', $message );
		$this->assertStringContainsString( 'deleted post #' . $related_id . ' (wp_delete_post())', $message );
		$this->assertStringContainsString( "inside 'pre_delete_post'", $message );
	}

	/**
	 * Deleting an attachment from inside a stock hook trips: wp_delete_post() hands it to
	 * wp_delete_attachment(), which fires pre_delete_attachment, not pre_delete_post.
	 */
	public function test_guard_attachment_delete_inside_set_stock_hook_throws_naming_caller(): void {
		// Arrange.
		$this->setExpectedIncorrectUsage( Save_Reentry_Guard::NOTICE );
		$product_id    = $this->create_managed_stock_product();
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'save-reentry-guard.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);
		add_action(
			'woocommerce_product_set_stock',
			function () use ( $attachment_id ) {
				reentry_offender_delete_post( $attachment_id );
			}
		);

		// Act.
		$message = '';
		try {
			wc_update_product_stock( wc_get_product( $product_id ), 4, 'set' );
		} catch ( LogicException $e ) {
			$message = $e->getMessage();
		}

		// Assert.
		$this->assertStringContainsString( 'fixtures/save-reentry-offender.php:', $message );
		$this->assertStringContainsString( 'deleted attachment #' . $attachment_id . ' (wp_delete_post())', $message );
		$this->assertStringContainsString( "inside 'woocommerce_product_set_stock'", $message );
	}

	/**
	 * After a trip and a test boundary, the same offender on the same hook stack trips again.
	 *
	 * The boundary is simulated the way WP_UnitTestCase does it: the hook stack goes back to
	 * what it was, then the per-test reset runs. Without the reset, the stack saved at the
	 * first trip equals the live one and the second trip is dropped.
	 */
	public function test_guard_reset_after_trip_still_catches_identical_trip(): void {
		// Arrange.
		$this->setExpectedIncorrectUsage( Save_Reentry_Guard::NOTICE );
		$product_id = $this->create_managed_stock_product();
		add_action( 'woocommerce_product_set_stock', self::TOUCH_HANDLER );
		$product      = wc_get_product( $product_id );
		$stack_before = $GLOBALS['wp_current_filter'];
		try {
			do_action( 'woocommerce_product_set_stock', $product ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- fires WooCommerce's stock hook directly, so no other save runs in between.
		} catch ( LogicException $e ) {
			$first = $e->getMessage();
		}
		$this->assertNotEmpty( $first ?? '', 'Precondition: the first stock hook trips.' );
		$GLOBALS['wp_current_filter'] = $stack_before; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulating WP_UnitTestCase's hook restore.
		Save_Reentry_Guard::reset();

		// Act.
		$message = '';
		try {
			do_action( 'woocommerce_product_set_stock', $product ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- same hook stack as the first trip.
		} catch ( LogicException $e ) {
			$message = $e->getMessage();
		}

		// Assert.
		$this->assertStringContainsString( "inside 'woocommerce_product_set_stock'", $message );
	}

	/**
	 * A second plugin directory added with also_watch() is policed too, and its paths carry the plugin directory name.
	 */
	public function test_guard_also_watch_second_directory_offender_throws_naming_plugin_directory(): void {
		// Arrange.
		$this->setExpectedIncorrectUsage( Save_Reentry_Guard::NOTICE );
		Save_Reentry_Guard::also_watch( __DIR__ . '/../Helpers/fixtures/second-plugin/includes' );
		$product_id = $this->create_managed_stock_product();
		add_action(
			'woocommerce_product_set_stock',
			function ( $product ) {
				second_plugin_update_post( $product->get_id() );
			}
		);

		// Act.
		$message = '';
		try {
			wc_update_product_stock( wc_get_product( $product_id ), 4, 'set' );
		} catch ( LogicException $e ) {
			$message = $e->getMessage();
		}

		// Assert.
		$this->assertStringStartsWith( 'second-plugin/includes/offenders.php:', $message );
		$this->assertStringContainsString( "inside 'woocommerce_product_set_stock'", $message );
	}

	/**
	 * With a primary directory that does not contain the extra one (the Pro shape: Free's
	 * `includes/` primary, Pro's beside it), offenders in both trip and each is named by
	 * its own plugin directory.
	 */
	public function test_guard_also_watch_disjoint_from_primary_offenders_throw_naming_their_plugin_directory(): void {
		// Arrange.
		$this->setExpectedIncorrectUsage( Save_Reentry_Guard::NOTICE );
		Save_Reentry_Guard::watch_directory( __DIR__ . '/../Helpers/fixtures/first-plugin/includes' );
		Save_Reentry_Guard::also_watch( __DIR__ . '/../Helpers/fixtures/second-plugin/includes' );
		$product_id = $this->create_managed_stock_product();
		$second_handler = function ( $product ) {
			second_plugin_update_post( $product->get_id() );
		};
		$first_handler  = function ( $product ) {
			first_plugin_update_post( $product->get_id() );
		};

		// Act.
		add_action( 'woocommerce_product_set_stock', $second_handler );
		$second = '';
		try {
			wc_update_product_stock( wc_get_product( $product_id ), 4, 'set' );
		} catch ( LogicException $e ) {
			$second = $e->getMessage();
		}
		remove_action( 'woocommerce_product_set_stock', $second_handler );
		add_action( 'woocommerce_product_set_stock', $first_handler );
		$first = '';
		try {
			wc_update_product_stock( wc_get_product( $product_id ), 3, 'set' );
		} catch ( LogicException $e ) {
			$first = $e->getMessage();
		}

		// Assert.
		$this->assertStringStartsWith( 'second-plugin/includes/offenders.php:', $second );
		$this->assertStringStartsWith( 'first-plugin/includes/offenders.php:', $first );
	}

	/**
	 * Re-pointing the guard with watch_directory() forgets every directory added with also_watch().
	 */
	public function test_guard_watch_directory_forgets_also_watched_directory(): void {
		// Arrange.
		Save_Reentry_Guard::also_watch( __DIR__ . '/../Helpers/fixtures/second-plugin/includes' );
		Save_Reentry_Guard::watch_directory( $this->previous_dir );
		$product_id = $this->create_managed_stock_product();
		$result     = null;
		add_action(
			'woocommerce_product_set_stock',
			function ( $product ) use ( &$result ) {
				$result = second_plugin_update_post( $product->get_id() );
			}
		);

		// Act.
		wc_update_product_stock( wc_get_product( $product_id ), 4, 'set' );

		// Assert.
		$this->assertSame( $product_id, $result );
	}

	/**
	 * The same wp_update_post() from a request context (no save hook running) passes.
	 */
	public function test_guard_update_post_from_request_context_passes(): void {
		// Arrange.
		$product_id = $this->create_managed_stock_product();

		// Act.
		$result = reentry_offender_update_post( $product_id );

		// Assert.
		$this->assertSame( $product_id, $result );
	}

	/**
	 * A WC_Data save from inside an order status hook trips even though WooCommerce swallows the exception.
	 *
	 * `WC_Order::status_transition()` catches every exception and turns it into an order
	 * note, so the guard's `_doing_it_wrong()` notice is what fails the test.
	 */
	public function test_guard_product_save_inside_order_status_hook_reports_through_swallowing_transition(): void {
		// Arrange.
		$this->setExpectedIncorrectUsage( Save_Reentry_Guard::NOTICE );
		$product_id = $this->create_managed_stock_product();
		$order      = wc_create_order();
		$order->add_product( wc_get_product( $product_id ), 1 );
		$order->set_payment_method( 'bacs' );
		$order->save();
		add_action(
			'woocommerce_order_status_on-hold',
			function () use ( $product_id ) {
				reentry_offender_save_product( $product_id );
			}
		);

		// Act.
		$order->update_status( 'on-hold' );

		// Assert.
		$this->assertArrayHasKey( Save_Reentry_Guard::NOTICE, $this->caught_doing_it_wrong );
		$notice = $this->caught_doing_it_wrong[ Save_Reentry_Guard::NOTICE ];
		$this->assertStringContainsString( 'fixtures/save-reentry-offender.php:', $notice );
		$this->assertStringContainsString( 'saved product #' . $product_id . ' (WC_Product::save())', $notice );
		$this->assertStringContainsString( "inside 'woocommerce_order_status_on-hold'", $notice );
	}

	/**
	 * After a trip WooCommerce swallowed, a plugin save from request context in the same test passes.
	 *
	 * The swallowed exception leaves the unwound hooks on `$wp_current_filter`; the guard
	 * must not blame the next, legitimate save on them.
	 */
	public function test_guard_request_save_after_swallowed_trip_passes(): void {
		// Arrange.
		$this->setExpectedIncorrectUsage( Save_Reentry_Guard::NOTICE );
		$product_id = $this->create_managed_stock_product();
		$order      = wc_create_order();
		$order->add_product( wc_get_product( $product_id ), 1 );
		$order->save();
		add_action(
			'woocommerce_order_status_on-hold',
			function () use ( $product_id ) {
				reentry_offender_save_product( $product_id );
			}
		);
		$order->update_status( 'on-hold' );

		// Act.
		$result = reentry_offender_update_post( $product_id );

		// Assert.
		$this->assertSame( $product_id, $result );
	}

	/**
	 * A listener that threw out of an order save WooCommerce swallowed leaves no hook running: a later request-context save passes.
	 *
	 * `do_action()` pops `$wp_current_filter` only when its callbacks return, so the throw leaves
	 * `woocommerce_before_order_object_save` on the stack although nothing is saving any more.
	 */
	public function test_guard_request_save_after_swallowed_listener_throw_passes(): void {
		// Arrange.
		$product_id = $this->create_managed_stock_product();
		$order      = wc_create_order();
		$throw      = static function (): void {
			throw new \RuntimeException( 'A listener failed.' );
		};
		add_action( 'woocommerce_before_order_object_save', $throw );
		$order->set_customer_note( 'Saved' );
		$order->save();
		remove_action( 'woocommerce_before_order_object_save', $throw );
		$this->assertContains( 'woocommerce_before_order_object_save', $GLOBALS['wp_current_filter'], 'Precondition: the swallowed throw left the hook on the stack.' );

		// Act.
		$result = reentry_offender_update_post( $product_id );

		// Assert.
		$this->assertSame( $product_id, $result );
	}

	/**
	 * A revision WordPress core saves inside a plugin's own request-context wp_update_post() is core's save and passes.
	 */
	public function test_guard_core_revision_inside_plugin_update_post_passes(): void {
		// Arrange.
		$post_id = self::factory()->post->create( array( 'post_content' => 'Before' ) );

		// Act.
		$result = reentry_offender_update_post( $post_id, array( 'post_content' => 'After' ) );

		// Assert.
		$this->assertSame( $post_id, $result );
		$this->assertCount( 1, wp_get_post_revisions( $post_id ) );
	}

	/**
	 * The same WC_Data save from a request context passes.
	 */
	public function test_guard_product_save_from_request_context_passes(): void {
		// Arrange.
		$product_id = $this->create_managed_stock_product();

		// Act.
		$result = reentry_offender_save_product( $product_id );

		// Assert.
		$this->assertSame( $product_id, $result );
	}

	/**
	 * A settings save is a request entry point, not an object save: a post save from a
	 * handler on woocommerce_update_options_{tab} passes.
	 */
	public function test_guard_update_post_inside_update_options_hook_passes(): void {
		// Arrange.
		$product_id = $this->create_managed_stock_product();
		$result     = null;
		add_action(
			'woocommerce_update_options_general',
			function () use ( $product_id, &$result ) {
				$result = reentry_offender_update_post( $product_id );
			}
		);

		// Act.
		do_action( 'woocommerce_update_options_general' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- fires WooCommerce's settings-save hook.

		// Assert.
		$this->assertSame( $product_id, $result );
	}

	/**
	 * Test code saving inside a save hook is not plugin code and passes.
	 */
	public function test_guard_test_code_saving_inside_set_stock_hook_passes(): void {
		// Arrange.
		$product_id = $this->create_managed_stock_product();
		$saves      = 0;
		add_action(
			'woocommerce_product_set_stock',
			function ( $product ) use ( &$saves ) {
				wp_update_post( array( 'ID' => $product->get_id() ) );
				++$saves;
			}
		);

		// Act.
		wc_update_product_stock( wc_get_product( $product_id ), 4, 'set' );

		// Assert.
		$this->assertSame( 1, $saves );
		$this->assertSame( 4, wc_get_product( $product_id )->get_stock_quantity() );
	}

	/**
	 * Create a simple product with managed stock of 5.
	 *
	 * @return int Product ID.
	 */
	private function create_managed_stock_product(): int {
		$product = new WC_Product_Simple();
		$product->set_name( 'Save Reentry Guard Product' );
		$product->set_regular_price( '10' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 5 );
		$product->save();

		return $product->get_id();
	}
}
