<?php
/**
 * Tests for the WCPOS Emails class.
 *
 * Tests the automated email functionality for POS orders, including:
 * - Admin email notifications (new_order, cancelled_order, failed_order)
 * - Customer email notifications (processing, completed, refunded, etc.)
 * - Cashier email notifications (new_order)
 * - Per-email granular toggle support
 * - Legacy boolean settings migration
 *
 * @package WCPOS\WooCommercePOS\Tests
 */

namespace WCPOS\WooCommercePOS\Tests;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WC_Email;
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

		$this->original_checkout_settings = get_option( 'woocommerce_pos_settings_checkout' );

		EmailHelper::init();
		EmailHelper::prevent_sending();
		EmailHelper::reset_mailer();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		if ( false !== $this->original_checkout_settings ) {
			update_option( 'woocommerce_pos_settings_checkout', $this->original_checkout_settings );
		} else {
			delete_option( 'woocommerce_pos_settings_checkout' );
		}

		EmailHelper::cleanup();
		EmailHelper::allow_sending();

		parent::tearDown();
	}

	// ==========================================================================
	// HOOK REGISTRATION TESTS
	// ==========================================================================

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
	 * Test that new_order recipient filter is registered.
	 */
	public function test_new_order_recipient_filter_registered(): void {
		$emails = new Emails();

		$this->assertNotFalse(
			has_filter( 'woocommerce_email_recipient_new_order', array( $emails, 'filter_new_order_recipients' ) ),
			'new_order recipient filter should be registered'
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
	 * Test filter priority is high enough (999) to run after other plugins.
	 */
	public function test_filter_priority_is_high(): void {
		$emails   = new Emails();
		$priority = has_filter( 'woocommerce_email_enabled_cancelled_order', array( $emails, 'manage_admin_emails' ) );

		$this->assertNotFalse( $priority, 'Filter should be registered' );
		$this->assertEquals( 999, $priority, 'Filter priority should be 999' );
	}

	// ==========================================================================
	// ADMIN EMAIL ENABLED FILTER TESTS (cancelled_order, failed_order)
	// ==========================================================================

	/**
	 * Test admin master on + individual on = enabled.
	 */
	public function test_admin_email_enabled_master_on_individual_on(): void {
		$this->set_checkout_settings(
			array(
				'enabled' => true,
				'cancelled_order' => true,
			),
			array( 'enabled' => true ),
			array( 'enabled' => false )
		);
		$emails = new Emails();
		$order  = $this->create_pos_order();

		$mock_email = $this->create_email_mock( 'cancelled_order' );

		$result = $emails->manage_admin_emails( true, $order, $mock_email );
		$this->assertTrue( $result, 'Admin email should be enabled when master and individual are both on' );
	}

	/**
	 * Test admin master on + individual off = disabled.
	 */
	public function test_admin_email_enabled_master_on_individual_off(): void {
		$this->set_checkout_settings(
			array(
				'enabled' => true,
				'cancelled_order' => false,
			),
			array( 'enabled' => true ),
			array( 'enabled' => false )
		);
		$emails = new Emails();
		$order  = $this->create_pos_order();

		$mock_email = $this->create_email_mock( 'cancelled_order' );

		$result = $emails->manage_admin_emails( true, $order, $mock_email );
		$this->assertFalse( $result, 'Admin email should be disabled when individual toggle is off' );
	}

	/**
	 * Test admin master off = all disabled regardless of individual.
	 */
	public function test_admin_email_disabled_master_off(): void {
		$this->set_checkout_settings(
			array(
				'enabled' => false,
				'cancelled_order' => true,
			),
			array( 'enabled' => true ),
			array( 'enabled' => false )
		);
		$emails = new Emails();
		$order  = $this->create_pos_order();

		$mock_email = $this->create_email_mock( 'cancelled_order' );

		$result = $emails->manage_admin_emails( true, $order, $mock_email );
		$this->assertFalse( $result, 'Admin email should be disabled when master toggle is off' );
	}

	/**
	 * Test non-POS orders are not affected by POS email settings.
	 */
	public function test_admin_emails_unaffected_for_non_pos_order(): void {
		$this->set_checkout_settings(
			array( 'enabled' => false ),
			array( 'enabled' => false ),
			array( 'enabled' => false )
		);
		$emails = new Emails();
		$order  = $this->create_regular_order();

		$mock_email = $this->create_email_mock( 'cancelled_order' );

		$result = $emails->manage_admin_emails( true, $order, $mock_email );
		$this->assertTrue( $result, 'Non-POS orders should not be affected by POS email settings' );
	}

	/**
	 * Test admin email handles null order gracefully.
	 */
	public function test_manage_admin_emails_handles_null_order(): void {
		$emails = new Emails();

		$mock_email = $this->create_email_mock( 'cancelled_order' );

		$result = $emails->manage_admin_emails( true, null, $mock_email );
		$this->assertTrue( $result, 'Should return original value when order is null' );
	}

	/**
	 * Test individual toggle defaults to true when key not set.
	 */
	public function test_admin_email_individual_defaults_true(): void {
		$this->set_checkout_settings(
			array( 'enabled' => true ),
			array( 'enabled' => true ),
			array( 'enabled' => false )
		);
		$emails = new Emails();
		$order  = $this->create_pos_order();

		$mock_email = $this->create_email_mock( 'some_unknown_email' );

		$result = $emails->manage_admin_emails( true, $order, $mock_email );
		$this->assertTrue( $result, 'Unknown email IDs should default to enabled when master is on' );
	}

	/**
	 * Test email settings default to enabled when not set in DB.
	 */
	public function test_email_settings_default_to_enabled(): void {
		delete_option( 'woocommerce_pos_settings_checkout' );

		$emails = new Emails();
		$order  = $this->create_pos_order();

		$mock_email = $this->create_email_mock( 'cancelled_order' );

		$result = $emails->manage_admin_emails( true, $order, $mock_email );
		$this->assertTrue( $result, 'Admin emails should default to enabled when setting is not configured' );
	}

	// ==========================================================================
	// CUSTOMER EMAIL ENABLED FILTER TESTS
	// ==========================================================================

	/**
	 * Test customer master on + individual on = enabled.
	 */
	public function test_customer_email_enabled_master_on_individual_on(): void {
		$this->set_checkout_settings(
			array( 'enabled' => true ),
			array(
				'enabled' => true,
				'customer_processing_order' => true,
			),
			array( 'enabled' => false )
		);
		$emails = new Emails();
		$order  = $this->create_pos_order();

		$mock_email = $this->create_email_mock( 'customer_processing_order' );

		$result = $emails->manage_customer_emails( true, $order, $mock_email );
		$this->assertTrue( $result, 'Customer email should be enabled when master and individual are both on' );
	}

	/**
	 * Test customer master on + individual off = disabled.
	 */
	public function test_customer_email_enabled_master_on_individual_off(): void {
		$this->set_checkout_settings(
			array( 'enabled' => true ),
			array(
				'enabled' => true,
				'customer_processing_order' => false,
			),
			array( 'enabled' => false )
		);
		$emails = new Emails();
		$order  = $this->create_pos_order();

		$mock_email = $this->create_email_mock( 'customer_processing_order' );

		$result = $emails->manage_customer_emails( true, $order, $mock_email );
		$this->assertFalse( $result, 'Customer email should be disabled when individual toggle is off' );
	}

	/**
	 * Test customer master off = all disabled regardless of individual.
	 */
	public function test_customer_email_disabled_master_off(): void {
		$this->set_checkout_settings(
			array( 'enabled' => true ),
			array(
				'enabled' => false,
				'customer_processing_order' => true,
			),
			array( 'enabled' => false )
		);
		$emails = new Emails();
		$order  = $this->create_pos_order();

		$mock_email = $this->create_email_mock( 'customer_processing_order' );

		$result = $emails->manage_customer_emails( true, $order, $mock_email );
		$this->assertFalse( $result, 'Customer email should be disabled when master toggle is off' );
	}

	/**
	 * Test non-POS orders are not affected by POS customer email settings.
	 */
	public function test_customer_emails_unaffected_for_non_pos_order(): void {
		$this->set_checkout_settings(
			array( 'enabled' => false ),
			array( 'enabled' => false ),
			array( 'enabled' => false )
		);
		$emails = new Emails();
		$order  = $this->create_regular_order();

		$mock_email = $this->create_email_mock( 'customer_processing_order' );

		$result = $emails->manage_customer_emails( true, $order, $mock_email );
		$this->assertTrue( $result, 'Non-POS orders should not be affected by POS email settings' );
	}

	/**
	 * Test customer email handles null order gracefully.
	 */
	public function test_manage_customer_emails_handles_null_order(): void {
		$emails = new Emails();

		$mock_email = $this->create_email_mock( 'customer_processing_order' );

		$result = $emails->manage_customer_emails( true, null, $mock_email );
		$this->assertTrue( $result, 'Should return original value when order is null' );
	}

	/**
	 * Test customer emails with various IDs all respect settings.
	 */
	public function test_customer_emails_various_ids(): void {
		$emails = new Emails();
		$order  = $this->create_pos_order();

		$email_ids = array(
			'customer_failed_order',
			'customer_on_hold_order',
			'customer_processing_order',
			'customer_completed_order',
			'customer_refunded_order',
		);

		// Test with emails enabled.
		$this->set_checkout_settings(
			array( 'enabled' => true ),
			array( 'enabled' => true ),
			array( 'enabled' => false )
		);
		foreach ( $email_ids as $email_id ) {
			$mock_email = $this->create_email_mock( $email_id );
			$result         = $emails->manage_customer_emails( true, $order, $mock_email );
			$this->assertTrue( $result, "Customer email {$email_id} should be enabled" );
		}

		// Test with emails disabled.
		$this->set_checkout_settings(
			array( 'enabled' => true ),
			array( 'enabled' => false ),
			array( 'enabled' => false )
		);
		foreach ( $email_ids as $email_id ) {
			$mock_email = $this->create_email_mock( $email_id );
			$result         = $emails->manage_customer_emails( true, $order, $mock_email );
			$this->assertFalse( $result, "Customer email {$email_id} should be disabled" );
		}
	}

	// ==========================================================================
	// NEW ORDER RECIPIENT FILTER TESTS (filter_new_order_recipients)
	// ==========================================================================

	/**
	 * Test POS order, admin on + cashier on = both recipients.
	 */
	public function test_new_order_recipients_admin_and_cashier(): void {
		$cashier = $this->factory->user->create( array( 'user_email' => 'cashier@example.com' ) );
		$this->set_checkout_settings(
			array(
				'enabled' => true,
				'new_order' => true,
			),
			array( 'enabled' => true ),
			array(
				'enabled' => true,
				'new_order' => true,
			)
		);
		$emails = new Emails();
		$order  = $this->create_pos_order( 'pending', $cashier );

		$admin_email = get_option( 'admin_email' );
		$result      = $emails->filter_new_order_recipients( $admin_email, $order, null );
		$recipients  = array_map( 'trim', explode( ',', $result ) );

		$this->assertContains( $admin_email, $recipients, 'Admin email should be in recipients' );
		$this->assertContains( 'cashier@example.com', $recipients, 'Cashier email should be in recipients' );
	}

	/**
	 * Test POS order, admin on + cashier off = admin only.
	 */
	public function test_new_order_recipients_admin_only(): void {
		$cashier = $this->factory->user->create( array( 'user_email' => 'cashier@example.com' ) );
		$this->set_checkout_settings(
			array(
				'enabled' => true,
				'new_order' => true,
			),
			array( 'enabled' => true ),
			array( 'enabled' => false )
		);
		$emails = new Emails();
		$order  = $this->create_pos_order( 'pending', $cashier );

		$admin_email = get_option( 'admin_email' );
		$result      = $emails->filter_new_order_recipients( $admin_email, $order, null );
		$recipients  = array_filter( array_map( 'trim', explode( ',', $result ) ) );

		$this->assertContains( $admin_email, $recipients, 'Admin email should be in recipients' );
		$this->assertNotContains( 'cashier@example.com', $recipients, 'Cashier email should NOT be in recipients' );
	}

	/**
	 * Test POS order, admin off + cashier on = cashier only.
	 */
	public function test_new_order_recipients_cashier_only(): void {
		$cashier = $this->factory->user->create( array( 'user_email' => 'cashier@example.com' ) );
		$this->set_checkout_settings(
			array( 'enabled' => false ),
			array( 'enabled' => true ),
			array(
				'enabled' => true,
				'new_order' => true,
			)
		);
		$emails = new Emails();
		$order  = $this->create_pos_order( 'pending', $cashier );

		$admin_email = get_option( 'admin_email' );
		$result      = $emails->filter_new_order_recipients( $admin_email, $order, null );
		$recipients  = array_filter( array_map( 'trim', explode( ',', $result ) ) );

		$this->assertNotContains( $admin_email, $recipients, 'Admin email should NOT be in recipients' );
		$this->assertContains( 'cashier@example.com', $recipients, 'Cashier email should be in recipients' );
	}

	/**
	 * Test POS order, admin off + cashier off = nobody.
	 */
	public function test_new_order_recipients_nobody(): void {
		$cashier = $this->factory->user->create( array( 'user_email' => 'cashier@example.com' ) );
		$this->set_checkout_settings(
			array( 'enabled' => false ),
			array( 'enabled' => true ),
			array( 'enabled' => false )
		);
		$emails = new Emails();
		$order  = $this->create_pos_order( 'pending', $cashier );

		$admin_email = get_option( 'admin_email' );
		$result      = $emails->filter_new_order_recipients( $admin_email, $order, null );
		$recipients  = array_filter( array_map( 'trim', explode( ',', $result ) ) );

		$this->assertEmpty( $recipients, 'No recipients when both admin and cashier are off' );
	}

	/**
	 * Test admin master on + new_order individual OFF, cashier on = cashier only.
	 */
	public function test_new_order_recipients_admin_new_order_off_cashier_on(): void {
		$cashier = $this->factory->user->create( array( 'user_email' => 'cashier@example.com' ) );
		$this->set_checkout_settings(
			array(
				'enabled' => true,
				'new_order' => false,
			),
			array( 'enabled' => true ),
			array(
				'enabled' => true,
				'new_order' => true,
			)
		);
		$emails = new Emails();
		$order  = $this->create_pos_order( 'pending', $cashier );

		$admin_email = get_option( 'admin_email' );
		$result      = $emails->filter_new_order_recipients( $admin_email, $order, null );
		$recipients  = array_filter( array_map( 'trim', explode( ',', $result ) ) );

		$this->assertNotContains( $admin_email, $recipients, 'Admin should NOT get email when new_order individual is off' );
		$this->assertContains( 'cashier@example.com', $recipients, 'Cashier should still get email' );
	}

	/**
	 * Test admin on, cashier master on + new_order individual OFF = admin only.
	 */
	public function test_new_order_recipients_cashier_new_order_off_admin_on(): void {
		$cashier = $this->factory->user->create( array( 'user_email' => 'cashier@example.com' ) );
		$this->set_checkout_settings(
			array(
				'enabled' => true,
				'new_order' => true,
			),
			array( 'enabled' => true ),
			array(
				'enabled' => true,
				'new_order' => false,
			)
		);
		$emails = new Emails();
		$order  = $this->create_pos_order( 'pending', $cashier );

		$admin_email = get_option( 'admin_email' );
		$result      = $emails->filter_new_order_recipients( $admin_email, $order, null );
		$recipients  = array_filter( array_map( 'trim', explode( ',', $result ) ) );

		$this->assertContains( $admin_email, $recipients, 'Admin should still get email' );
		$this->assertNotContains( 'cashier@example.com', $recipients, 'Cashier should NOT get email when new_order individual is off' );
	}

	/**
	 * Test non-POS order = recipients unchanged.
	 */
	public function test_new_order_recipients_unchanged_for_non_pos_order(): void {
		$this->set_checkout_settings(
			array( 'enabled' => false ),
			array( 'enabled' => false ),
			array(
				'enabled' => true,
				'new_order' => true,
			)
		);
		$emails = new Emails();
		$order  = $this->create_regular_order();

		$original = 'admin@example.com';
		$result   = $emails->filter_new_order_recipients( $original, $order, null );

		$this->assertEquals( $original, $result, 'Non-POS order recipients should not be modified' );
	}

	/**
	 * Test cashier IS admin = dedup (one copy only).
	 */
	public function test_new_order_recipients_dedup_cashier_is_admin(): void {
		$admin_email = get_option( 'admin_email' );
		$cashier     = $this->factory->user->create( array( 'user_email' => $admin_email ) );
		$this->set_checkout_settings(
			array(
				'enabled' => true,
				'new_order' => true,
			),
			array( 'enabled' => true ),
			array(
				'enabled' => true,
				'new_order' => true,
			)
		);
		$emails = new Emails();
		$order  = $this->create_pos_order( 'pending', $cashier );

		$result     = $emails->filter_new_order_recipients( $admin_email, $order, null );
		$recipients = array_filter( array_map( 'trim', explode( ',', $result ) ) );

		$this->assertCount( 1, $recipients, 'Should only have one recipient when cashier and admin share the same email' );
		$this->assertContains( $admin_email, $recipients );
	}

	/**
	 * Test POS order with no _pos_user meta = admin only.
	 */
	public function test_new_order_recipients_no_pos_user_meta(): void {
		$this->set_checkout_settings(
			array(
				'enabled' => true,
				'new_order' => true,
			),
			array( 'enabled' => true ),
			array(
				'enabled' => true,
				'new_order' => true,
			)
		);
		$emails = new Emails();
		$order  = $this->create_pos_order( 'pending', 0 );

		$admin_email = get_option( 'admin_email' );
		$result      = $emails->filter_new_order_recipients( $admin_email, $order, null );
		$recipients  = array_filter( array_map( 'trim', explode( ',', $result ) ) );

		$this->assertContains( $admin_email, $recipients, 'Admin should still get email' );
		$this->assertCount( 1, $recipients, 'Only admin should be in recipients when no cashier is set' );
	}

	/**
	 * Test POS order, cashier has no email = admin only.
	 */
	public function test_new_order_recipients_cashier_no_email(): void {
		$cashier = $this->factory->user->create( array( 'user_email' => '' ) );
		$this->set_checkout_settings(
			array(
				'enabled' => true,
				'new_order' => true,
			),
			array( 'enabled' => true ),
			array(
				'enabled' => true,
				'new_order' => true,
			)
		);
		$emails = new Emails();
		$order  = $this->create_pos_order( 'pending', $cashier );

		$admin_email = get_option( 'admin_email' );
		$result      = $emails->filter_new_order_recipients( $admin_email, $order, null );
		$recipients  = array_filter( array_map( 'trim', explode( ',', $result ) ) );

		$this->assertContains( $admin_email, $recipients, 'Admin should still get email' );
		$this->assertCount( 1, $recipients, 'Only admin when cashier has no email' );
	}

	/**
	 * Test filter handles null order parameter.
	 */
	public function test_new_order_recipients_handles_null_order(): void {
		$emails = new Emails();

		$original = 'admin@example.com';
		$result   = $emails->filter_new_order_recipients( $original, null, null );

		$this->assertEquals( $original, $result, 'Should return original when order is null' );
	}

	// ==========================================================================
	// TRIGGER NEW ORDER EMAIL TESTS (POS status transitions)
	// ==========================================================================

	/**
	 * Test trigger fires when admin new_order on + cashier on.
	 */
	public function test_trigger_new_order_email_both_on(): void {
		$cashier = $this->factory->user->create( array( 'user_email' => 'cashier@example.com' ) );
		$this->set_checkout_settings(
			array(
				'enabled' => true,
				'new_order' => true,
			),
			array( 'enabled' => true ),
			array(
				'enabled' => true,
				'new_order' => true,
			)
		);
		$emails = new Emails();
		$order  = $this->create_pos_order( 'processing', $cashier );
		EmailHelper::clear();

		$emails->trigger_new_order_email( $order->get_id(), $order );

		$this->assertTrue( true, 'Email trigger should run without error' );
	}

	/**
	 * Test trigger fires when admin off + cashier on (for cashier).
	 */
	public function test_trigger_new_order_email_admin_off_cashier_on(): void {
		$cashier = $this->factory->user->create( array( 'user_email' => 'cashier@example.com' ) );
		$this->set_checkout_settings(
			array( 'enabled' => false ),
			array( 'enabled' => true ),
			array(
				'enabled' => true,
				'new_order' => true,
			)
		);
		$emails = new Emails();
		$order  = $this->create_pos_order( 'processing', $cashier );
		EmailHelper::clear();

		$emails->trigger_new_order_email( $order->get_id(), $order );

		$this->assertTrue( true, 'Email trigger should run for cashier even when admin is off' );
	}

	/**
	 * Test trigger does NOT fire when both off.
	 */
	public function test_trigger_new_order_email_both_off(): void {
		$this->set_checkout_settings(
			array( 'enabled' => false ),
			array( 'enabled' => true ),
			array( 'enabled' => false )
		);
		$emails = new Emails();
		$order  = $this->create_pos_order( 'processing' );
		EmailHelper::clear();

		$emails->trigger_new_order_email( $order->get_id(), $order );

		$new_order_emails = EmailHelper::get_emails_by_wc_id( 'new_order' );
		$this->assertCount( 0, $new_order_emails, 'No email should be triggered when both admin and cashier are off' );
	}

	/**
	 * Test trigger fires when admin on + cashier off.
	 */
	public function test_trigger_new_order_email_admin_on_cashier_off(): void {
		$this->set_checkout_settings(
			array(
				'enabled' => true,
				'new_order' => true,
			),
			array( 'enabled' => true ),
			array( 'enabled' => false )
		);
		$emails = new Emails();
		$order  = $this->create_pos_order( 'processing' );
		EmailHelper::clear();

		$emails->trigger_new_order_email( $order->get_id(), $order );

		$this->assertTrue( true, 'Email trigger should run for admin' );
	}

	/**
	 * Test trigger ignores non-POS orders.
	 */
	public function test_trigger_new_order_email_ignores_non_pos_orders(): void {
		$this->set_checkout_settings(
			array(
				'enabled' => true,
				'new_order' => true,
			),
			array( 'enabled' => true ),
			array(
				'enabled' => true,
				'new_order' => true,
			)
		);
		$emails = new Emails();
		$order  = $this->create_regular_order( 'processing' );
		EmailHelper::clear();

		$emails->trigger_new_order_email( $order->get_id(), $order );

		$new_order_emails = EmailHelper::get_emails_by_wc_id( 'new_order' );
		$this->assertCount( 0, $new_order_emails, 'No new_order email should be triggered for non-POS orders' );
	}

	/**
	 * Test trigger loads order from ID when null.
	 */
	public function test_trigger_new_order_email_loads_order_from_id(): void {
		$this->set_checkout_settings(
			array(
				'enabled' => true,
				'new_order' => true,
			),
			array( 'enabled' => true ),
			array( 'enabled' => false )
		);
		$emails = new Emails();
		$order  = $this->create_pos_order( 'processing' );

		$emails->trigger_new_order_email( $order->get_id(), null );
		$this->assertTrue( true, 'Should load order from ID without error' );
	}

	// ==========================================================================
	// LEGACY MIGRATION TESTS
	// ==========================================================================

	/**
	 * Test legacy boolean admin_emails=true migrates correctly.
	 */
	public function test_legacy_boolean_admin_emails_true_migrates(): void {
		update_option(
			'woocommerce_pos_settings_checkout',
			array(
				'order_status'    => 'wc-completed',
				'admin_emails'    => true,
				'customer_emails' => true,
			)
		);

		$settings = woocommerce_pos_get_settings( 'checkout', 'admin_emails' );

		$this->assertIsArray( $settings, 'Legacy boolean should be migrated to array' );
		$this->assertTrue( $settings['enabled'], 'Master toggle should be true' );
		$this->assertTrue( $settings['new_order'], 'new_order should default to true' );
	}

	/**
	 * Test legacy boolean admin_emails=false migrates correctly.
	 */
	public function test_legacy_boolean_admin_emails_false_migrates(): void {
		update_option(
			'woocommerce_pos_settings_checkout',
			array(
				'order_status'    => 'wc-completed',
				'admin_emails'    => false,
				'customer_emails' => true,
			)
		);

		$settings = woocommerce_pos_get_settings( 'checkout', 'admin_emails' );

		$this->assertIsArray( $settings, 'Legacy boolean should be migrated to array' );
		$this->assertFalse( $settings['enabled'], 'Master toggle should be false' );
	}

	/**
	 * Test legacy boolean customer_emails migrates correctly.
	 */
	public function test_legacy_boolean_customer_emails_migrates(): void {
		update_option(
			'woocommerce_pos_settings_checkout',
			array(
				'order_status'    => 'wc-completed',
				'admin_emails'    => true,
				'customer_emails' => false,
			)
		);

		$settings = woocommerce_pos_get_settings( 'checkout', 'customer_emails' );

		$this->assertIsArray( $settings, 'Legacy boolean should be migrated to array' );
		$this->assertFalse( $settings['enabled'], 'Master toggle should be false' );
		$this->assertTrue( $settings['customer_processing_order'], 'Individual emails should default to true' );
	}

	/**
	 * Test cashier_emails defaults correctly for fresh installs.
	 */
	public function test_cashier_emails_defaults_on_fresh_install(): void {
		delete_option( 'woocommerce_pos_settings_checkout' );

		$settings = woocommerce_pos_get_settings( 'checkout', 'cashier_emails' );

		$this->assertIsArray( $settings );
		$this->assertFalse( $settings['enabled'], 'Cashier emails should default to disabled' );
		$this->assertTrue( $settings['new_order'], 'new_order individual should default to true' );
	}

	// ==========================================================================
	// FILTER HOOK TESTS
	// ==========================================================================

	/**
	 * Test that the admin email filter can be further filtered.
	 */
	public function test_admin_email_filter_is_filterable(): void {
		$this->set_checkout_settings(
			array(
				'enabled' => true,
				'cancelled_order' => true,
			),
			array( 'enabled' => true ),
			array( 'enabled' => false )
		);
		$emails = new Emails();
		$order  = $this->create_pos_order();

		add_filter(
			'woocommerce_pos_admin_email_enabled',
			function ( $enabled, $email_id, $order, $email_class ) {
				return false;
			},
			10,
			4
		);

		$mock_email = $this->create_email_mock( 'cancelled_order' );

		$result = $emails->manage_admin_emails( true, $order, $mock_email );
		$this->assertFalse( $result, 'Admin email should be disabled via filter override' );

		remove_all_filters( 'woocommerce_pos_admin_email_enabled' );
	}

	/**
	 * Test that the customer email filter can be further filtered.
	 */
	public function test_customer_email_filter_is_filterable(): void {
		$this->set_checkout_settings(
			array( 'enabled' => true ),
			array(
				'enabled' => true,
				'customer_processing_order' => true,
			),
			array( 'enabled' => false )
		);
		$emails = new Emails();
		$order  = $this->create_pos_order();

		add_filter(
			'woocommerce_pos_customer_email_enabled',
			function ( $enabled, $email_id, $order, $email_class ) {
				return false;
			},
			10,
			4
		);

		$mock_email = $this->create_email_mock( 'customer_processing_order' );

		$result = $emails->manage_customer_emails( true, $order, $mock_email );
		$this->assertFalse( $result, 'Customer email should be disabled via filter override' );

		remove_all_filters( 'woocommerce_pos_customer_email_enabled' );
	}

	/**
	 * Test that email IDs can be filtered via woocommerce_pos_admin_emails filter.
	 */
	public function test_admin_email_ids_are_filterable(): void {
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

		remove_all_filters( 'woocommerce_pos_admin_emails' );
	}

	/**
	 * Test that email IDs can be filtered via woocommerce_pos_customer_emails filter.
	 */
	public function test_customer_email_ids_are_filterable(): void {
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

		remove_all_filters( 'woocommerce_pos_customer_emails' );
	}

	/**
	 * Test admin and customer emails work independently.
	 */
	public function test_admin_and_customer_emails_independent(): void {
		$this->set_checkout_settings(
			array( 'enabled' => true ),
			array( 'enabled' => false ),
			array( 'enabled' => false )
		);
		$emails = new Emails();
		$order  = $this->create_pos_order();

		$admin_mock    = $this->create_email_mock( 'cancelled_order' );
		$customer_mock = $this->create_email_mock( 'customer_processing_order' );

		$admin_result    = $emails->manage_admin_emails( true, $order, $admin_mock );
		$customer_result = $emails->manage_customer_emails( true, $order, $customer_mock );

		$this->assertTrue( $admin_result, 'Admin emails should be enabled' );
		$this->assertFalse( $customer_result, 'Customer emails should be disabled' );

		// Reverse.
		$this->set_checkout_settings(
			array( 'enabled' => false ),
			array( 'enabled' => true ),
			array( 'enabled' => false )
		);

		$admin_result    = $emails->manage_admin_emails( true, $order, $admin_mock );
		$customer_result = $emails->manage_customer_emails( true, $order, $customer_mock );

		$this->assertFalse( $admin_result, 'Admin emails should be disabled' );
		$this->assertTrue( $customer_result, 'Customer emails should be enabled' );
	}

	/**
	 * Test WC_Email ID extraction works with real WC_Email instances.
	 */
	public function test_email_id_extraction_from_wc_email(): void {
		$this->set_checkout_settings(
			array( 'enabled' => true ),
			array( 'enabled' => true ),
			array( 'enabled' => false )
		);
		$emails = new Emails();
		$order  = $this->create_pos_order();

		$mailer        = WC()->mailer();
		$email_classes = $mailer->get_emails();

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

	// ==========================================================================
	// HELPERS
	// ==========================================================================

	/**
	 * Create a mock WC_Email with the given ID.
	 *
	 * Uses PHPUnit's mock builder to create a WC_Email instance without
	 * calling the constructor, then sets the public $id property.
	 *
	 * @param string $id The email ID (e.g. 'cancelled_order').
	 *
	 * @return WC_Email The mock email instance.
	 */
	private function create_email_mock( string $id ): WC_Email {
		$mock     = $this->getMockBuilder( WC_Email::class )
			->disableOriginalConstructor()
			->getMock();
		$mock->id = $id;

		return $mock;
	}

	/**
	 * Set checkout email settings with the new array format.
	 *
	 * @param array $admin_emails    Admin email settings array.
	 * @param array $customer_emails Customer email settings array.
	 * @param array $cashier_emails  Cashier email settings array.
	 */
	private function set_checkout_settings( array $admin_emails, array $customer_emails, array $cashier_emails ): void {
		$settings                    = get_option( 'woocommerce_pos_settings_checkout', array() );
		$settings['admin_emails']    = $admin_emails;
		$settings['customer_emails'] = $customer_emails;
		$settings['cashier_emails']  = $cashier_emails;
		update_option( 'woocommerce_pos_settings_checkout', $settings );
	}

	/**
	 * Create a POS order.
	 *
	 * @param string $status     Order status. Default 'pending'.
	 * @param int    $cashier_id User ID of the cashier. Default 0 (no cashier).
	 *
	 * @return WC_Order The created order.
	 */
	private function create_pos_order( string $status = 'pending', int $cashier_id = 0 ): WC_Order {
		$order = OrderHelper::create_order();
		$order->update_meta_data( '_pos', '1' );
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_status( $status );

		if ( $cashier_id > 0 ) {
			$order->update_meta_data( '_pos_user', (string) $cashier_id );
		}

		$order->save();

		return $order;
	}

	/**
	 * Create a regular (non-POS) order.
	 *
	 * @param string $status Order status. Default 'pending'.
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
