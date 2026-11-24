<?php
/**
 * Tests for the cashier token check on the POS pay page GET.
 *
 * @package WCPOS\WooCommercePOS\Tests\Templates
 */

namespace WCPOS\WooCommercePOS\Tests\Templates;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WC_Unit_Test_Case;
use WCPOS\WooCommercePOS\Services\Auth;
use WCPOS\WooCommercePOS\Services\Cashier;
use WCPOS\WooCommercePOS\Templates\Payment;

/**
 * Payment template token tests. Each runs in its own process: get_template() defines WOOCOMMERCE_CHECKOUT.
 *
 * @internal
 * @coversNothing
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class Test_Payment_Token extends WC_Unit_Test_Case {
	/**
	 * A role holding only what a till needs to open and edit its own orders.
	 */
	private const MINIMAL_POS_ROLE = 'wcpos_test_minimal_pos';

	/**
	 * Remove the minimal role.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		remove_role( self::MINIMAL_POS_ROLE );
		parent::tearDown();
	}

	/** No token posts the session-expired signal. */
	public function test_get_template_without_token_posts_session_expired(): void {
		// Arrange.
		wp_set_current_user( 0 );
		$order = $this->create_order_for( null );

		// Act.
		$result = $this->run_get_template( $order, null );

		// Assert.
		$this->assertSame( 403, $result['refusal'][1] );
		$this->assertStringContainsString( '"action":"wcpos-session-expired"', $result['refusal'][0] );
		$this->assertStringContainsString( '"orderId":' . $order->get_id(), $result['refusal'][0] );
		$this->assertStringContainsString( 'ReactNativeWebView', $result['refusal'][0] );
		$this->assertStringContainsString( 'window.parent.postMessage', $result['refusal'][0] );
		$this->assertStringContainsString( 'Your session has expired.', $result['refusal'][0] );
		$this->assertStringNotContainsString( $order->get_billing_email(), $result['refusal'][0] );
		$this->assertStringNotContainsString( $order->get_billing_first_name(), $result['refusal'][0] );
		$this->assertStringNotContainsString( 'payment_method', $result['refusal'][0] );
		$this->assertStringNotContainsString( 'woocommerce-pay', $result['refusal'][0] );
		$this->assertSame( 0, $result['user'] );
	}

	/** An empty token posts the session-expired signal. */
	public function test_get_template_empty_token_posts_session_expired(): void {
		// Arrange.
		wp_set_current_user( 0 );
		$order = $this->create_order_for( null );

		// Act.
		$result = $this->run_get_template( $order, '' );

		// Assert.
		$this->assertSame( 403, $result['refusal'][1] );
		$this->assertStringContainsString( '"action":"wcpos-session-expired"', $result['refusal'][0] );
		$this->assertStringContainsString( '"orderId":' . $order->get_id(), $result['refusal'][0] );
		$this->assertStringContainsString( 'ReactNativeWebView', $result['refusal'][0] );
		$this->assertStringContainsString( 'window.parent.postMessage', $result['refusal'][0] );
		$this->assertStringContainsString( 'Your session has expired.', $result['refusal'][0] );
		$this->assertStringNotContainsString( $order->get_billing_email(), $result['refusal'][0] );
		$this->assertStringNotContainsString( $order->get_billing_first_name(), $result['refusal'][0] );
		$this->assertStringNotContainsString( 'payment_method', $result['refusal'][0] );
		$this->assertStringNotContainsString( 'woocommerce-pay', $result['refusal'][0] );
		$this->assertSame( 0, $result['user'] );
	}

	/** The app's literal 'undefined' token posts the session-expired signal. */
	public function test_get_template_undefined_token_posts_session_expired(): void {
		// Arrange.
		wp_set_current_user( 0 );
		$order = $this->create_order_for( null );

		// Act.
		$result = $this->run_get_template( $order, 'undefined' );

		// Assert.
		$this->assertSame( 403, $result['refusal'][1] );
		$this->assertStringContainsString( '"action":"wcpos-session-expired"', $result['refusal'][0] );
		$this->assertStringContainsString( '"orderId":' . $order->get_id(), $result['refusal'][0] );
		$this->assertStringContainsString( 'ReactNativeWebView', $result['refusal'][0] );
		$this->assertStringContainsString( 'window.parent.postMessage', $result['refusal'][0] );
		$this->assertStringContainsString( 'Your session has expired.', $result['refusal'][0] );
		$this->assertStringNotContainsString( $order->get_billing_email(), $result['refusal'][0] );
		$this->assertStringNotContainsString( $order->get_billing_first_name(), $result['refusal'][0] );
		$this->assertStringNotContainsString( 'payment_method', $result['refusal'][0] );
		$this->assertStringNotContainsString( 'woocommerce-pay', $result['refusal'][0] );
		$this->assertSame( 0, $result['user'] );
	}

	/** A token that is not a JWT posts the session-expired signal. */
	public function test_get_template_garbage_token_posts_session_expired(): void {
		// Arrange.
		wp_set_current_user( 0 );
		$order = $this->create_order_for( null );

		// Act.
		$result = $this->run_get_template( $order, 'not-a-jwt' );

		// Assert.
		$this->assertSame( 403, $result['refusal'][1] );
		$this->assertStringContainsString( '"action":"wcpos-session-expired"', $result['refusal'][0] );
		$this->assertStringContainsString( '"orderId":' . $order->get_id(), $result['refusal'][0] );
		$this->assertStringContainsString( 'ReactNativeWebView', $result['refusal'][0] );
		$this->assertStringContainsString( 'window.parent.postMessage', $result['refusal'][0] );
		$this->assertStringContainsString( 'Your session has expired.', $result['refusal'][0] );
		$this->assertStringNotContainsString( $order->get_billing_email(), $result['refusal'][0] );
		$this->assertStringNotContainsString( $order->get_billing_first_name(), $result['refusal'][0] );
		$this->assertStringNotContainsString( 'payment_method', $result['refusal'][0] );
		$this->assertStringNotContainsString( 'woocommerce-pay', $result['refusal'][0] );
		$this->assertSame( 0, $result['user'] );
	}

	/** An expired token of a user who may edit the order posts the session-expired signal. */
	public function test_get_template_expired_token_posts_session_expired(): void {
		// Arrange.
		wp_set_current_user( 0 );
		$order  = $this->create_order_for( null );
		$expire = static function () {
			return time() - HOUR_IN_SECONDS;
		};
		add_filter( 'woocommerce_pos_jwt_access_token_expire', $expire );
		$token = Auth::instance()->generate_access_token( get_user_by( 'id', $this->factory->user->create( array( 'role' => 'administrator' ) ) ) );
		remove_filter( 'woocommerce_pos_jwt_access_token_expire', $expire );

		// Act.
		$result = $this->run_get_template( $order, $token );

		// Assert.
		$this->assertSame( 403, $result['refusal'][1] );
		$this->assertStringContainsString( '"action":"wcpos-session-expired"', $result['refusal'][0] );
		$this->assertStringContainsString( '"orderId":' . $order->get_id(), $result['refusal'][0] );
		$this->assertStringContainsString( 'ReactNativeWebView', $result['refusal'][0] );
		$this->assertStringContainsString( 'window.parent.postMessage', $result['refusal'][0] );
		$this->assertStringContainsString( 'Your session has expired.', $result['refusal'][0] );
		$this->assertStringNotContainsString( $order->get_billing_email(), $result['refusal'][0] );
		$this->assertStringNotContainsString( $order->get_billing_first_name(), $result['refusal'][0] );
		$this->assertStringNotContainsString( 'payment_method', $result['refusal'][0] );
		$this->assertStringNotContainsString( 'woocommerce-pay', $result['refusal'][0] );
		$this->assertSame( 0, $result['user'] );
	}

	/** A token minted before its user lost POS access is refused with no signal. */
	public function test_get_template_demoted_cashier_is_refused_without_signal(): void {
		// Arrange.
		wp_set_current_user( 0 );
		$cashier = $this->create_pos_user( false );
		$order   = $this->create_order_for( null );
		$token   = Auth::instance()->generate_access_token( get_user_by( 'id', $cashier ) );
		get_user_by( 'id', $cashier )->add_cap( 'access_woocommerce_pos', false );
		$this->assertFalse( Cashier::instance()->can_open_pos( get_user_by( 'id', $cashier ) ), 'precondition: demoted' );

		// Act.
		$result = $this->run_get_template( $order, $token );

		// Assert.
		$this->assertSame( array( 'Cashier token mismatch.', 403 ), $result['refusal'] );
		$this->assertSame( 0, $result['user'] );
	}

	/** A role that may edit only its own orders is refused another cashier's order with no signal. */
	public function test_get_template_minimal_role_on_other_cashiers_order_is_refused_without_signal(): void {
		// Arrange.
		wp_set_current_user( 0 );
		$order = $this->create_order_for( $this->create_pos_user( true ) );
		$token = Auth::instance()->generate_access_token( get_user_by( 'id', $this->create_pos_user( true ) ) );

		// Act.
		$result = $this->run_get_template( $order, $token );

		// Assert.
		$this->assertSame( array( 'Cashier token mismatch.', 403 ), $result['refusal'] );
		$this->assertSame( 0, $result['user'] );
	}

	/** A valid token of a user who may edit the order renders the page. */
	public function test_get_template_valid_token_renders(): void {
		// Arrange.
		wp_set_current_user( 0 );
		$order = $this->create_order_for( null );
		$token = Auth::instance()->generate_access_token( get_user_by( 'id', $this->factory->user->create( array( 'role' => 'administrator' ) ) ) );

		// Act.
		$result = $this->run_get_template( $order, $token );

		// Assert.
		$this->assertNull( $result['refusal'] );
		$this->assertNotSame( '', trim( $result['output'] ) );
	}

	/**
	 * Run the real get_template() with the price fixture in place of payment.php, capturing any wp_die().
	 *
	 * @param \WC_Order   $order The order being paid.
	 * @param string|null $token The cashier token, or null to send none.
	 *
	 * @return array{refusal: array|null, output: string, user: int}
	 */
	private function run_get_template( \WC_Order $order, ?string $token ): array {
		$template_filter = static function ( $path, $template ) {
			return 'payment.php' === $template ? __DIR__ . '/fixtures/payment-price.php' : $path;
		};
		$refusal = null;
		$die     = static function () use ( &$refusal ) {
			return static function ( $message, $title, $args ) use ( &$refusal ) {
				$refusal = array( $message, $args['response'] ?? null );
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Capture wp_die output, not rendering.
				throw new \WPDieException( $message );
			};
		};
		$original_get = $_GET;
		$buffer_level = ob_get_level();
		add_filter( 'woocommerce_pos_locate_template', $template_filter, 10, 2 );
		add_filter( 'wp_die_handler', $die );
		$_GET['key'] = $order->get_order_key();
		if ( null !== $token ) {
			$_GET['token'] = $token;
		}
		ob_start();

		try {
			( new Payment( $order->get_id() ) )->get_template();
			$output = (string) ob_get_clean();
		} finally {
			if ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
			$_GET = $original_get;
			remove_filter( 'woocommerce_pos_locate_template', $template_filter, 10 );
			remove_filter( 'wp_die_handler', $die );
		}

		return array(
			'refusal' => $refusal,
			'output'  => $output,
			'user'    => get_current_user_id(),
		);
	}

	/**
	 * Create a user with the plugin's default cashier role, or with a role that
	 * may open the POS and edit only its own orders.
	 *
	 * @param bool $minimal Whether to use the minimal role.
	 *
	 * @return int The user ID.
	 */
	private function create_pos_user( bool $minimal ): int {
		if ( $minimal && ! get_role( self::MINIMAL_POS_ROLE ) ) {
			$caps = array( 'read', 'access_woocommerce_pos', 'publish_shop_orders', 'edit_shop_orders' );
			add_role( self::MINIMAL_POS_ROLE, 'Minimal POS', array_fill_keys( $caps, true ) );
		}
		$user_id = $this->factory->user->create( array( 'role' => $minimal ? self::MINIMAL_POS_ROLE : 'cashier' ) );
		$this->assertTrue( Cashier::instance()->can_open_pos( get_user_by( 'id', $user_id ) ), 'precondition: the user can open the POS' );
		$this->assertSame( ! $minimal, user_can( $user_id, 'edit_others_shop_orders' ), 'precondition: edit_others_shop_orders' );

		return $user_id;
	}

	/**
	 * Create a web order (created at checkout, no cashier) or a POS order assigned to a cashier,
	 * with a billing email and first name the refusal must not carry.
	 *
	 * @param int|null $cashier_id The cashier the POS order belongs to, or null for a web order.
	 *
	 * @return \WC_Order The saved order.
	 */
	private function create_order_for( ?int $cashier_id ): \WC_Order {
		$order = OrderHelper::create_order( array( 'customer_id' => $this->factory->user->create( array( 'role' => 'customer' ) ) ) );
		$order->set_created_via( null === $cashier_id ? 'checkout' : 'woocommerce-pos' );
		if ( null !== $cashier_id ) {
			$order->update_meta_data( '_pos_user', (string) $cashier_id );
		}
		$order->set_billing_email( 'pay-page-customer@example.org' );
		$order->set_billing_first_name( 'Paypagecustomer' );
		$order->save();

		return $order;
	}
}
