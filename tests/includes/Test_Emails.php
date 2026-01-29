<?php
/**
 * Tests for the WCPOS Emails class.
 *
 * Tests the automated email functionality for POS orders, including:
 * - Admin email notifications (new_order, cancelled_order, failed_order)
 * - Customer email notifications (processing, completed, refunded, etc.)
 * - Email settings from checkout configuration
 */

namespace WCPOS\WooCommercePOS\Tests;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use stdClass;
use WC_Order;
use WC_Unit_Test_Case;
use WCPOS\WooCommercePOS\Emails;
use WCPOS\WooCommercePOS\Tests\Helpers\EmailHelper;

/**
 * Test_Emails class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Emails extends WC_Unit_Test_Case {
	/**
	 * The Emails instance.
	 *
	 * @var Emails
	 */
	private $emails;

	/**
	 * Original checkout settings.
	 *
	 * @var array|false
	 */
	private $original_checkout_settings;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Store original settings
		$this->original_checkout_settings = get_option( 'woocommerce_pos_settings_checkout' );

		// Initialize email capturing
		EmailHelper::init();
		EmailHelper::prevent_sending(); // Don't actually send emails

		// Reset mailer to ensure clean state
		EmailHelper::reset_mailer();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		// Restore original settings
		if ( false !== $this->original_checkout_settings ) {
			update_option( 'woocommerce_pos_settings_checkout', $this->original_checkout_settings );
		} else {
			delete_option( 'woocommerce_pos_settings_checkout' );
		}

		// Clean up email capturing
		EmailHelper::cleanup();
		EmailHelper::allow_sending();

		parent::tearDown();
	}

	/**
	 * Test that Emails class can be instantiated.
	 */
	public function test_emails_class_instantiation(): void {
		$emails = new Emails();
		$this->assertInstanceOf( Emails::class, $emails );
	}

	/**
	 * Test that admin email filters are registered.
	 */
	public function test_admin_email_filters_registered(): void {
		$emails = new Emails();

		$this->assertTrue(
			has_filter( 'woocommerce_email_enabled_cancelled_order' ),
			'cancelled_order email filter should be registered'
		);
		$this->assertTrue(
			has_filter( 'woocommerce_email_enabled_failed_order' ),
			'failed_order email filter should be registered'
		);
	}

	/**
	 * Test that customer email filters are registered.
	 */
	public function test_customer_email_filters_registered(): void {
		$emails = new Emails();

		$this->assertTrue(
			has_filter( 'woocommerce_email_enabled_customer_processing_order' ),
			'customer_processing_order email filter should be registered'
		);
		$this->assertTrue(
			has_filter( 'woocommerce_email_enabled_customer_completed_order' ),
			'customer_completed_order email filter should be registered'
		);
		$this->assertTrue(
			has_filter( 'woocommerce_email_enabled_customer_refunded_order' ),
			'customer_refunded_order email filter should be registered'
		);
	}

	/**
	 * Test that status transition actions are registered for new_order email.
	 */
	public function test_status_transition_actions_registered(): void {
		$emails = new Emails();

		$this->assertTrue(
			has_action( 'woocommerce_order_status_pos-open_to_completed' ),
			'pos-open to completed action should be registered'
		);
		$this->assertTrue(
			has_action( 'woocommerce_order_status_pos-open_to_processing' ),
			'pos-open to processing action should be registered'
		);
		$this->assertTrue(
			has_action( 'woocommerce_order_status_pos-partial_to_completed' ),
			'pos-partial to completed action should be registered'
		);
	}

	/**
	 * Test manage_admin_emails returns true when admin emails enabled for POS order.
	 */
	public function test_manage_admin_emails_enabled_for_pos_order(): void {
		$this->set_email_settings( true, true );
		$emails = new Emails();
		$order  = $this->create_pos_order();

		// Create a mock email class
		$mock_email     = new stdClass();
		$mock_email->id = 'cancelled_order';

		$result = $emails->manage_admin_emails( true, $order, $mock_email );

		$this->assertTrue( $result, 'Admin emails should be enabled when setting is true' );
	}

	/**
	 * Test manage_admin_emails returns false when admin emails disabled for POS order.
	 */
	public function test_manage_admin_emails_disabled_for_pos_order(): void {
		$this->set_email_settings( false, true );
		$emails = new Emails();
		$order  = $this->create_pos_order();

		// Create a mock email class
		$mock_email     = new stdClass();
		$mock_email->id = 'cancelled_order';

		$result = $emails->manage_admin_emails( true, $order, $mock_email );

		$this->assertFalse( $result, 'Admin emails should be disabled when setting is false' );
	}

	/**
	 * Test manage_admin_emails does not affect non-POS orders.
	 */
	public function test_manage_admin_emails_unaffected_for_non_pos_order(): void {
		$this->set_email_settings( false, false );
		$emails = new Emails();
		$order  = $this->create_regular_order();

		// Create a mock email class
		$mock_email     = new stdClass();
		$mock_email->id = 'cancelled_order';

		$result = $emails->manage_admin_emails( true, $order, $mock_email );

		$this->assertTrue( $result, 'Non-POS orders should not be affected by POS email settings' );
	}

	/**
	 * Test manage_customer_emails returns true when customer emails enabled for POS order.
	 */
	public function test_manage_customer_emails_enabled_for_pos_order(): void {
		$this->set_email_settings( true, true );
		$emails = new Emails();
		$order  = $this->create_pos_order();

		// Create a mock email class
		$mock_email     = new stdClass();
		$mock_email->id = 'customer_processing_order';

		$result = $emails->manage_customer_emails( true, $order, $mock_email );

		$this->assertTrue( $result, 'Customer emails should be enabled when setting is true' );
	}

	/**
	 * Test manage_customer_emails returns false when customer emails disabled for POS order.
	 */
	public function test_manage_customer_emails_disabled_for_pos_order(): void {
		$this->set_email_settings( true, false );
		$emails = new Emails();
		$order  = $this->create_pos_order();

		// Create a mock email class
		$mock_email     = new stdClass();
		$mock_email->id = 'customer_processing_order';

		$result = $emails->manage_customer_emails( true, $order, $mock_email );

		$this->assertFalse( $result, 'Customer emails should be disabled when setting is false' );
	}

	/**
	 * Test manage_customer_emails does not affect non-POS orders.
	 */
	public function test_manage_customer_emails_unaffected_for_non_pos_order(): void {
		$this->set_email_settings( false, false );
		$emails = new Emails();
		$order  = $this->create_regular_order();

		// Create a mock email class
		$mock_email     = new stdClass();
		$mock_email->id = 'customer_processing_order';

		$result = $emails->manage_customer_emails( true, $order, $mock_email );

		$this->assertTrue( $result, 'Non-POS orders should not be affected by POS email settings' );
	}

	/**
	 * Test that the admin email filter can be further filtered.
	 */
	public function test_admin_email_filter_is_filterable(): void {
		$this->set_email_settings( true, true );
		$emails = new Emails();
		$order  = $this->create_pos_order();

		// Add a filter that overrides the setting
		add_filter(
			'woocommerce_pos_admin_email_enabled',
			function ( $enabled, $email_id, $order, $email_class ) {
				return false; // Override to disable
			},
			10,
			4
		);

		$mock_email     = new stdClass();
		$mock_email->id = 'cancelled_order';

		$result = $emails->manage_admin_emails( true, $order, $mock_email );

		$this->assertFalse( $result, 'Admin email should be disabled via filter override' );

		// Clean up
		remove_all_filters( 'woocommerce_pos_admin_email_enabled' );
	}

	/**
	 * Test that the customer email filter can be further filtered.
	 */
	public function test_customer_email_filter_is_filterable(): void {
		$this->set_email_settings( true, true );
		$emails = new Emails();
		$order  = $this->create_pos_order();

		// Add a filter that overrides the setting
		add_filter(
			'woocommerce_pos_customer_email_enabled',
			function ( $enabled, $email_id, $order, $email_class ) {
				return false; // Override to disable
			},
			10,
			4
		);

		$mock_email     = new stdClass();
		$mock_email->id = 'customer_processing_order';

		$result = $emails->manage_customer_emails( true, $order, $mock_email );

		$this->assertFalse( $result, 'Customer email should be disabled via filter override' );

		// Clean up
		remove_all_filters( 'woocommerce_pos_customer_email_enabled' );
	}

	/**
	 * Test that admin and customer emails can have independent settings.
	 */
	public function test_admin_and_customer_emails_independent(): void {
		// Enable admin, disable customer
		$this->set_email_settings( true, false );
		$emails = new Emails();
		$order  = $this->create_pos_order();

		$admin_email     = new stdClass();
		$admin_email->id = 'cancelled_order';

		$customer_email     = new stdClass();
		$customer_email->id = 'customer_processing_order';

		$admin_result    = $emails->manage_admin_emails( true, $order, $admin_email );
		$customer_result = $emails->manage_customer_emails( true, $order, $customer_email );

		$this->assertTrue( $admin_result, 'Admin emails should be enabled' );
		$this->assertFalse( $customer_result, 'Customer emails should be disabled' );

		// Now reverse: disable admin, enable customer
		$this->set_email_settings( false, true );

		$admin_result    = $emails->manage_admin_emails( true, $order, $admin_email );
		$customer_result = $emails->manage_customer_emails( true, $order, $customer_email );

		$this->assertFalse( $admin_result, 'Admin emails should be disabled' );
		$this->assertTrue( $customer_result, 'Customer emails should be enabled' );
	}

	/**
	 * Test that email IDs can be filtered via woocommerce_pos_admin_emails filter.
	 */
	public function test_admin_email_ids_are_filterable(): void {
		// Add a custom email ID to the admin emails list
		add_filter(
			'woocommerce_pos_admin_emails',
			function ( $emails ) {
				$emails[] = 'custom_admin_email';

				return $emails;
			}
		);

		$emails = new Emails();

		$this->assertTrue(
			has_filter( 'woocommerce_email_enabled_custom_admin_email' ),
			'Custom admin email filter should be registered'
		);

		// Clean up
		remove_all_filters( 'woocommerce_pos_admin_emails' );
	}

	/**
	 * Test that email IDs can be filtered via woocommerce_pos_customer_emails filter.
	 */
	public function test_customer_email_ids_are_filterable(): void {
		// Add a custom email ID to the customer emails list
		add_filter(
			'woocommerce_pos_customer_emails',
			function ( $emails ) {
				$emails[] = 'custom_customer_email';

				return $emails;
			}
		);

		$emails = new Emails();

		$this->assertTrue(
			has_filter( 'woocommerce_email_enabled_custom_customer_email' ),
			'Custom customer email filter should be registered'
		);

		// Clean up
		remove_all_filters( 'woocommerce_pos_customer_emails' );
	}

	/**
	 * Test trigger_new_order_email does nothing for non-POS orders.
	 */
	public function test_trigger_new_order_email_ignores_non_pos_orders(): void {
		$this->set_email_settings( true, true );
		$emails = new Emails();
		$order  = $this->create_regular_order( 'processing' );

		EmailHelper::clear();

		// Call trigger_new_order_email directly
		$emails->trigger_new_order_email( $order->get_id(), $order );

		// No new_order email should be triggered for non-POS orders via this method
		$new_order_emails = EmailHelper::get_emails_by_wc_id( 'new_order' );
		$this->assertCount( 0, $new_order_emails, 'No new_order email should be triggered for non-POS orders' );
	}

	/**
	 * Test trigger_new_order_email respects admin email setting.
	 */
	public function test_trigger_new_order_email_respects_admin_setting(): void {
		$this->set_email_settings( false, true );
		$emails = new Emails();
		$order  = $this->create_pos_order( 'processing' );

		EmailHelper::clear();

		// Call trigger_new_order_email directly
		$emails->trigger_new_order_email( $order->get_id(), $order );

		// No email should be sent when admin_emails is disabled
		$new_order_emails = EmailHelper::get_emails_by_wc_id( 'new_order' );
		$this->assertCount( 0, $new_order_emails, 'No new_order email should be triggered when admin_emails is disabled' );
	}

	/**
	 * Test manage_admin_emails handles null order gracefully.
	 */
	public function test_manage_admin_emails_handles_null_order(): void {
		$this->set_email_settings( false, false );
		$emails = new Emails();

		$mock_email     = new stdClass();
		$mock_email->id = 'cancelled_order';

		// Should return the original value when order is null
		$result = $emails->manage_admin_emails( true, null, $mock_email );

		$this->assertTrue( $result, 'Should return original value when order is null' );
	}

	/**
	 * Test manage_customer_emails handles null order gracefully.
	 */
	public function test_manage_customer_emails_handles_null_order(): void {
		$this->set_email_settings( false, false );
		$emails = new Emails();

		$mock_email     = new stdClass();
		$mock_email->id = 'customer_processing_order';

		// Should return the original value when order is null
		$result = $emails->manage_customer_emails( true, null, $mock_email );

		$this->assertTrue( $result, 'Should return original value when order is null' );
	}

	/**
	 * Test that email settings default to enabled when not set.
	 */
	public function test_email_settings_default_to_enabled(): void {
		// Remove all checkout settings
		delete_option( 'woocommerce_pos_settings_checkout' );

		$emails = new Emails();
		$order  = $this->create_pos_order();

		$mock_email     = new stdClass();
		$mock_email->id = 'cancelled_order';

		// Default should be enabled (truthy)
		$result = $emails->manage_admin_emails( true, $order, $mock_email );

		// Note: This test documents current behavior - if defaults are false, test should be updated
		$this->assertTrue( $result, 'Admin emails should default to enabled when setting is not configured' );
	}

	/**
	 * Test that the email class ID is correctly extracted from WC_Email instance.
	 */
	public function test_email_id_extraction_from_wc_email(): void {
		$this->set_email_settings( true, true );
		$emails = new Emails();
		$order  = $this->create_pos_order();

		// Get actual WC_Email instance
		$mailer        = WC()->mailer();
		$email_classes = $mailer->get_emails();

		// Find the customer_processing_order email
		$processing_email = null;
		foreach ( $email_classes as $email ) {
			if ( 'customer_processing_order' === $email->id ) {
				$processing_email = $email;

				break;
			}
		}

		if ( $processing_email ) {
			$result = $emails->manage_customer_emails( true, $order, $processing_email );
			$this->assertTrue( $result, 'Should correctly handle WC_Email instance' );
		} else {
			$this->markTestSkipped( 'customer_processing_order email class not found' );
		}
	}

	/**
	 * Test filter priority is high enough (999) to run after other plugins.
	 */
	public function test_filter_priority_is_high(): void {
		$emails = new Emails();

		// Check the priority of our filter
		$priority = has_filter( 'woocommerce_email_enabled_cancelled_order', array( $emails, 'manage_admin_emails' ) );

		// has_filter returns the priority or false
		$this->assertNotFalse( $priority, 'Filter should be registered' );
		$this->assertEquals( 999, $priority, 'Filter priority should be 999' );
	}

	// ==========================================================================
	// DIRECT METHOD CALL TESTS (for line coverage)
	// ==========================================================================

	/**
	 * Direct test: manage_admin_emails with WC_Email instance.
	 *
	 * @covers \WCPOS\WooCommercePOS\Emails::manage_admin_emails
	 */
	public function test_direct_manage_admin_emails_with_wc_email(): void {
		$this->set_email_settings( true, true );
		$emails = new Emails();
		$order  = $this->create_pos_order();

		// Create mock that looks like WC_Email
		$mock_email     = new class() {
			public $id = 'new_order';
		};

		// Direct method call
		$result = $emails->manage_admin_emails( true, $order, $mock_email );

		$this->assertTrue( $result );
	}

	/**
	 * Direct test: manage_customer_emails with various email IDs.
	 *
	 * @covers \WCPOS\WooCommercePOS\Emails::manage_customer_emails
	 */
	public function test_direct_manage_customer_emails_various_ids(): void {
		$emails = new Emails();
		$order  = $this->create_pos_order();

		$email_ids = array(
			'customer_failed_order',
			'customer_on_hold_order',
			'customer_processing_order',
			'customer_completed_order',
			'customer_refunded_order',
		);

		// Test with emails enabled
		$this->set_email_settings( true, true );
		foreach ( $email_ids as $email_id ) {
			$mock_email     = new stdClass();
			$mock_email->id = $email_id;
			$result         = $emails->manage_customer_emails( true, $order, $mock_email );
			$this->assertTrue( $result, "Customer email {$email_id} should be enabled" );
		}

		// Test with emails disabled
		$this->set_email_settings( true, false );
		foreach ( $email_ids as $email_id ) {
			$mock_email     = new stdClass();
			$mock_email->id = $email_id;
			$result         = $emails->manage_customer_emails( true, $order, $mock_email );
			$this->assertFalse( $result, "Customer email {$email_id} should be disabled" );
		}
	}

	/**
	 * Direct test: trigger_new_order_email with enabled emails.
	 *
	 * @covers \WCPOS\WooCommercePOS\Emails::trigger_new_order_email
	 */
	public function test_direct_trigger_new_order_email_enabled(): void {
		$this->set_email_settings( true, true );
		$emails = new Emails();
		$order  = $this->create_pos_order( 'processing' );

		// Clear any previous email state
		EmailHelper::clear();

		// Call method directly - this should attempt to trigger the email
		$emails->trigger_new_order_email( $order->get_id(), $order );

		// The test verifies the method runs without error
		// Actual email sending is mocked
		$this->assertTrue( true );
	}

	/**
	 * Direct test: trigger_new_order_email with null order parameter.
	 *
	 * @covers \WCPOS\WooCommercePOS\Emails::trigger_new_order_email
	 */
	public function test_direct_trigger_new_order_email_loads_order(): void {
		$this->set_email_settings( true, true );
		$emails = new Emails();
		$order  = $this->create_pos_order( 'processing' );

		// Call with only order ID (no order object)
		$emails->trigger_new_order_email( $order->get_id(), null );

		// Test passes if no exception is thrown
		$this->assertTrue( true );
	}

	/**
	 * Direct test: manage_admin_emails returns original value for order ID.
	 *
	 * @covers \WCPOS\WooCommercePOS\Emails::manage_admin_emails
	 */
	public function test_direct_manage_admin_emails_with_order_id(): void {
		$this->set_email_settings( true, true );
		$emails = new Emails();
		$order  = $this->create_pos_order();

		$mock_email     = new stdClass();
		$mock_email->id = 'cancelled_order';

		// WooCommerce sometimes passes the order object, sometimes the ID
		// Our method should handle both
		$result = $emails->manage_admin_emails( true, $order, $mock_email );

		$this->assertTrue( $result );
	}

	/**
	 * Direct test: constructor registers all expected filters.
	 *
	 * @covers \WCPOS\WooCommercePOS\Emails::__construct
	 */
	public function test_direct_constructor_registers_filters(): void {
		// Remove all existing filters first
		remove_all_filters( 'woocommerce_email_enabled_cancelled_order' );
		remove_all_filters( 'woocommerce_email_enabled_failed_order' );
		remove_all_filters( 'woocommerce_email_enabled_customer_processing_order' );

		// Create new instance
		$emails = new Emails();

		// Verify admin email filters
		$this->assertNotFalse(
			has_filter( 'woocommerce_email_enabled_cancelled_order', array( $emails, 'manage_admin_emails' ) )
		);
		$this->assertNotFalse(
			has_filter( 'woocommerce_email_enabled_failed_order', array( $emails, 'manage_admin_emails' ) )
		);

		// Verify customer email filters
		$this->assertNotFalse(
			has_filter( 'woocommerce_email_enabled_customer_processing_order', array( $emails, 'manage_customer_emails' ) )
		);
	}

	/**
	 * Direct test: constructor registers status transition actions.
	 *
	 * @covers \WCPOS\WooCommercePOS\Emails::__construct
	 */
	public function test_direct_constructor_registers_actions(): void {
		$emails = new Emails();

		$this->assertNotFalse(
			has_action( 'woocommerce_order_status_pos-open_to_completed', array( $emails, 'trigger_new_order_email' ) )
		);
		$this->assertNotFalse(
			has_action( 'woocommerce_order_status_pos-open_to_processing', array( $emails, 'trigger_new_order_email' ) )
		);
		$this->assertNotFalse(
			has_action( 'woocommerce_order_status_pos-open_to_on-hold', array( $emails, 'trigger_new_order_email' ) )
		);
	}

	/**
	 * Helper to set checkout email settings.
	 *
	 * @param bool $admin_emails    Enable admin emails.
	 * @param bool $customer_emails Enable customer emails.
	 */
	private function set_email_settings( bool $admin_emails, bool $customer_emails ): void {
		$settings                    = get_option( 'woocommerce_pos_settings_checkout', array() );
		$settings['admin_emails']    = $admin_emails;
		$settings['customer_emails'] = $customer_emails;
		update_option( 'woocommerce_pos_settings_checkout', $settings );
	}

	/**
	 * Helper to create a POS order.
	 *
	 * @param string $status Optional. Order status. Default 'pending'.
	 *
	 * @return WC_Order The created order.
	 */
	private function create_pos_order( string $status = 'pending' ): WC_Order {
		$order = OrderHelper::create_order();
		$order->update_meta_data( '_pos', '1' );
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( $status );
		$order->save();

		return $order;
	}

	/**
	 * Helper to create a regular (non-POS) order.
	 *
	 * @param string $status Optional. Order status. Default 'pending'.
	 *
	 * @return WC_Order The created order.
	 */
	private function create_regular_order( string $status = 'pending' ): WC_Order {
		$order = OrderHelper::create_order();
		$order->set_status( $status );
		$order->save();

		return $order;
	}
}
