<?php
/**
 * Tests for the POS store's money format on the POS pay page.
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates
 */

namespace WCPOS\WooCommercePOS\Tests\Templates;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use ReflectionMethod;
use WC_Unit_Test_Case;
use WCPOS\WooCommercePOS\Templates\Payment;

/**
 * Payment store format tests.
 *
 * @internal
 * @coversNothing
 */
class Test_Payment_Store_Format extends WC_Unit_Test_Case {
	/**
	 * The site format for these tests: USD, symbol left, `.` decimal, `,` thousand, 2 decimals.
	 *
	 * @var array<string, string>
	 */
	private const SITE_FORMAT = array(
		'woocommerce_currency'           => 'USD',
		'woocommerce_currency_pos'       => 'left',
		'woocommerce_price_decimal_sep'  => '.',
		'woocommerce_price_thousand_sep' => ',',
		'woocommerce_price_num_decimals' => '2',
	);

	/**
	 * Option values before the test, restored in tearDown().
	 *
	 * @var array<string, mixed>
	 */
	private $previous_options = array();

	/**
	 * The wcpos_get_store filters this test added.
	 *
	 * @var callable[]
	 */
	private $store_filters = array();

	/**
	 * Set the site format.
	 */
	public function setUp(): void {
		parent::setUp();
		foreach ( self::SITE_FORMAT as $option => $value ) {
			$this->previous_options[ $option ] = get_option( $option );
			update_option( $option, $value );
		}
	}

	/**
	 * Restore the options and remove the store filters.
	 */
	public function tearDown(): void {
		foreach ( $this->store_filters as $filter ) {
			remove_filter( 'wcpos_get_store', $filter, 10 );
		}
		// A separate-process test serializes itself on failure, and closures cannot be serialized.
		$this->store_filters = array();
		foreach ( $this->previous_options as $option => $value ) {
			update_option( $option, $value );
		}
		parent::tearDown();
	}

	/**
	 * Symbol positions and the price each one prints for a `,` decimal, `.` thousand, 3-decimal store.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function position_provider(): array {
		return array(
			'left'        => array( 'left', '&euro;1.234,500' ),
			'left_space'  => array( 'left_space', '&euro;&nbsp;1.234,500' ),
			'right'       => array( 'right', '1.234,500&euro;' ),
			'right_space' => array( 'right_space', '1.234,500&nbsp;&euro;' ),
		);
	}

	/**
	 * The store's symbol position and separators replace the site's.
	 *
	 * @dataProvider position_provider
	 *
	 * @param string $position Store currency position.
	 * @param string $expected Price as printed.
	 */
	public function test_store_format_applies_position_and_separators( string $position, string $expected ): void {
		// Arrange.
		$this->add_store( $position, ',', '.', 3 );
		$payment = $this->payment_for( $this->unpaid_order( null )->get_id() );

		// Act.
		$price = $this->price_with( $payment );

		// Assert.
		$this->assertSame( $expected, $price );
	}

	/**
	 * A store with 0 decimals prints none.
	 */
	public function test_store_format_zero_decimals_applies(): void {
		// Arrange.
		$this->add_store( 'right', ',', '.', 0 );
		$payment = $this->payment_for( $this->unpaid_order( null )->get_id() );

		// Act.
		$price = $this->price_with( $payment );

		// Assert: number_format( 1234.5, 0, ',', '.' ) is '1.235'.
		$this->assertSame( '1.235&euro;', $price );
	}

	/**
	 * A store's decimals arrive as a string and leave as an int.
	 */
	public function test_store_format_decimals_leave_as_an_int(): void {
		// Arrange.
		$this->add_store( 'right', ',', '.', '3' );
		$payment = $this->payment_for( $this->unpaid_order( null )->get_id() );

		// Act.
		$args = $payment->apply_store_price_format( array() );

		// Assert.
		$this->assertSame( 3, $args['decimals'] );
	}

	/**
	 * A store with empty values leaves the site format alone.
	 */
	public function test_store_format_empty_values_keep_the_site_format(): void {
		// Arrange.
		$this->add_store( '', '', '', '' );
		$payment = $this->payment_for( $this->unpaid_order( null )->get_id() );

		// Act.
		$price = $this->price_with( $payment );

		// Assert.
		$this->assertSame( '&euro;1,234.50', $price );
	}

	/**
	 * An order whose store is gone keeps the site format, with no error.
	 */
	public function test_store_format_missing_store_keeps_the_site_format(): void {
		// Arrange: the order's store lookup finds nothing, so resolve_pos_store() gives a stdClass.
		$missing_store = static function ( $store, $the_store ) {
			return 456 === (int) $the_store ? false : $store;
		};
		$this->store_filters[] = $missing_store;
		add_filter( 'wcpos_get_store', $missing_store, 10, 2 );
		$payment = $this->payment_for( $this->unpaid_order( 456 )->get_id() );

		// Act.
		$price = $this->price_with( $payment );

		// Assert.
		$this->assertSame( '&euro;1,234.50', $price );
	}

	/**
	 * The order's own store wins over the current store.
	 */
	public function test_store_format_uses_the_orders_store_not_the_current_store(): void {
		// Arrange.
		$this->add_store( 'left', '.', ',', 2 );
		$this->add_store( 'right', ',', '.', 2, 123 );
		$payment = $this->payment_for( $this->unpaid_order( 123 )->get_id() );

		// Act.
		$price = $this->price_with( $payment );

		// Assert.
		$this->assertSame( '1.234,50&euro;', $price );
	}

	/**
	 * The real render prints prices in the store's format and removes the filter afterwards.
	 *
	 * Runs in its own process: get_template() defines WOOCOMMERCE_CHECKOUT.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_template_renders_prices_in_store_format_and_removes_the_filter(): void {
		// Arrange.
		$this->add_store( 'right_space', ',', '.', 3 );
		$order   = $this->unpaid_order( null );
		$payment = new Payment( $order->get_id() );

		// Act.
		$output = $this->render( $payment, $order, 'payment-price.php' );

		// Assert.
		$this->assertSame( '1.234,500&nbsp;&euro;', trim( wp_strip_all_tags( $output ) ) );
		$this->assertFalse( has_filter( 'wc_price_args', array( $payment, 'apply_store_price_format' ) ) );
		$this->assertSame( '&euro;1,234.50', wp_strip_all_tags( wc_price( 1234.5, array( 'currency' => 'EUR' ) ) ) );
	}

	/**
	 * A template that throws still has the filter removed, and the notice is printed.
	 *
	 * Runs in its own process: get_template() defines WOOCOMMERCE_CHECKOUT.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_template_removes_the_filter_when_the_template_throws(): void {
		// Arrange.
		$this->add_store( 'right_space', ',', '.', 3 );
		$order   = $this->unpaid_order( null );
		$payment = new Payment( $order->get_id() );

		// Act.
		$output = $this->render( $payment, $order, 'payment-throws.php' );

		// Assert.
		$this->assertStringContainsString( 'fixture failure', $output );
		$this->assertFalse( has_filter( 'wc_price_args', array( $payment, 'apply_store_price_format' ) ) );
	}

	/**
	 * Add a wcpos_get_store filter that returns a store stub with the given format.
	 *
	 * @param string     $position Currency position.
	 * @param string     $decimal  Decimal separator.
	 * @param string     $thousand Thousand separator.
	 * @param int|string $decimals Number of decimals.
	 * @param int|null   $for_id   Only answer lookups for this store id; null answers every lookup.
	 */
	private function add_store( string $position, string $decimal, string $thousand, $decimals, ?int $for_id = null ): void {
		$stub = new class( $position, $decimal, $thousand, $decimals ) {
			/**
			 * Format values.
			 *
			 * @var array
			 */
			private $values;

			/**
			 * Constructor.
			 *
			 * @param mixed ...$values Position, decimal, thousand, decimals.
			 */
			public function __construct( ...$values ) {
				$this->values = $values;
			}

			/**
			 * Currency position.
			 */
			public function get_currency_position() {
				return $this->values[0];
			}

			/**
			 * Decimal separator.
			 */
			public function get_price_decimal_separator() {
				return $this->values[1];
			}

			/**
			 * Thousand separator.
			 */
			public function get_price_thousand_separator() {
				return $this->values[2];
			}

			/**
			 * Number of decimals.
			 */
			public function get_price_number_of_decimals() {
				return $this->values[3];
			}
		};

		$filter = static function ( $store, $the_store ) use ( $stub, $for_id ) {
			return null === $for_id || $for_id === (int) $the_store ? $stub : $store;
		};
		$this->store_filters[] = $filter;
		add_filter( 'wcpos_get_store', $filter, 10, 2 );
	}

	/**
	 * Print 1234.5 EUR with the template's price filter in place, as plain text.
	 *
	 * @param Payment $payment The template.
	 */
	private function price_with( Payment $payment ): string {
		add_filter( 'wc_price_args', array( $payment, 'apply_store_price_format' ) );
		try {
			return wp_strip_all_tags( wc_price( 1234.5, array( 'currency' => 'EUR' ) ) );
		} finally {
			remove_filter( 'wc_price_args', array( $payment, 'apply_store_price_format' ) );
		}
	}

	/**
	 * Run the real get_template() with a fixture in place of payment.php.
	 *
	 * @param Payment   $payment  The template.
	 * @param \WC_Order $order    The order being paid.
	 * @param string    $fixture  Fixture file name.
	 *
	 * @return string The output.
	 */
	private function render( Payment $payment, \WC_Order $order, string $fixture ): string {
		$template_filter = static function ( $path, $template ) use ( $fixture ) {
			return 'payment.php' === $template ? __DIR__ . '/fixtures/' . $fixture : $path;
		};
		$original_get = $_GET;
		$buffer_level = ob_get_level();
		add_filter( 'wcpos_locate_template', $template_filter, 10, 2 );
		$_GET['key'] = $order->get_order_key();
		ob_start();

		try {
			$payment->get_template();
			return (string) ob_get_clean();
		} finally {
			if ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
			$_GET = $original_get;
			remove_filter( 'wcpos_locate_template', $template_filter, 10 );
		}
	}

	/**
	 * Build the template the way get_template() does: the constructor stores the id,
	 * initialize_order_and_nonces() loads the order.
	 *
	 * @param int $order_id Order to render.
	 */
	private function payment_for( int $order_id ): Payment {
		$payment = new Payment( $order_id );
		$init    = new ReflectionMethod( $payment, 'initialize_order_and_nonces' );
		$init->setAccessible( true );
		$init->invoke( $payment );

		return $payment;
	}

	/**
	 * An unpaid order with the given `_pos_store` meta (none when null).
	 *
	 * @param int|null $pos_store Store id, or null for no meta.
	 */
	private function unpaid_order( ?int $pos_store ): \WC_Order {
		$order = OrderHelper::create_order();
		$order->set_status( 'pending' );
		$order->set_date_paid( null );
		if ( null !== $pos_store ) {
			$order->update_meta_data( '_pos_store', $pos_store );
		}
		$order->save();

		return $order;
	}
}
