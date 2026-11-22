<?php
/**
 * The completed-sale design is chosen in wp-admin and used by the real mailer.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\HPOSToggleTrait;
use WCPOS\WooCommercePOS\Services\Print_Job_Service;

/**
 * Customer HTML email design API and delivery.
 *
 * @coversNothing
 */
class Test_Customer_Email_Design extends WCPOS_REST_Unit_Test_Case {
	use HPOSToggleTrait;

	private const ROUTE = '/wcpos/v1/templates/customer-email-design';

	/**
	 * Create a merchant template.
	 *
	 * @param string $content Template content.
	 * @param string $status  Publication state.
	 * @param string $type    Template type term.
	 * @param string $engine  Rendering engine.
	 */
	private function design( string $content, string $status = 'publish', string $type = 'email', string $engine = 'logicless' ): int {
		$id = $this->factory->post->create(
			array(
				'post_type'    => 'wcpos_template',
				'post_status'  => $status,
				'post_content' => $content,
			)
		);
		wp_set_object_terms( $id, $type, 'wcpos_template_type' );
		update_post_meta( $id, '_template_engine', $engine );
		return $id;
	}

	/**
	 * Assign the design via the same REST route as the gallery.
	 *
	 * @param int $id Design ID.
	 */
	private function select( int $id ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', self::ROUTE );
		$request->set_param( 'template_id', $id );
		return $this->server->dispatch( $request );
	}

	/** Verify the message WordPress accepted, including live order interpolation. */
	public function test_completed_pos_mail_contains_published_design(): void {
		$this->assert_completed_mail_uses_design();
	}

	/** Assert the WordPress mail capture for both POS and online orders. */
	private function assert_completed_mail_uses_design(): void {
		$id = $this->design( '<p>Juniper counter {{order.number}}</p>' );
		$this->assertSame( 200, $this->select( $id )->get_status() );
		update_option( 'woocommerce_pos_settings_checkout', array( 'customer_emails' => true ) );
		$order = OrderHelper::create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->set_billing_email( 'buyer@example.test' );
		$order->save();
		$messages = array();
		$capture  = static function ( $result, $args ) use ( &$messages ) {
			$messages[] = $args;
			return true;
		};
		add_filter( 'pre_wp_mail', $capture, 10, 2 );
		try {
			$email = WC()->mailer()->get_emails()['WC_Email_Customer_Completed_Order'];
			$email->trigger( $order->get_id() );
			$this->assertCount( 1, $messages );
			$this->assertSame( 'buyer@example.test', $messages[0]['to'] );
			$this->assertStringContainsString( 'Juniper counter ' . $order->get_order_number(), $messages[0]['message'] );
			$order->set_created_via( 'checkout' );
			$order->save();
			$email->trigger( $order->get_id() );
			$this->assertCount( 2, $messages );
			$this->assertStringNotContainsString( 'Juniper counter', $messages[1]['message'] );
		} finally {
			remove_filter( 'pre_wp_mail', $capture );
		}
	}

	/**
	 * The same notification is sent with HPOS authoritative.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_email_design_with_order_tables(): void {
		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		$this->setup_cot();
		try {
			$this->assert_completed_mail_uses_design();
		} finally {
			$this->clean_up_cot_setup();
			remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		}
	}

	/** An email design is private to the admin editor, never a printable document. */
	public function test_email_design_is_not_a_print_template(): void {
		$id = $this->design( 'Merchant draft', 'draft' );
		$this->assertNull( Print_Job_Service::load_template( (string) $id ) );
		$user = $this->factory->user->create_and_get();
		$user->add_cap( 'access_woocommerce_pos' );
		wp_set_current_user( $user->ID );
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v1/templates/' . $id ) );
		$this->assertSame( 404, $response->get_status() );
	}

	/** Password-protected designs must never be sent to a customer. */
	public function test_password_protection_deactivates_design(): void {
		$id = $this->design( 'Internal design' );
		$this->assertSame( 200, $this->select( $id )->get_status() );
		wp_update_post(
			array(
				'ID'            => $id,
				'post_password' => 'fixture',
			)
		);
		$data = $this->server->dispatch( new \WP_REST_Request( 'GET', self::ROUTE ) )->get_data();
		$this->assertSame( 0, $data['template_id'] );
		$this->assertSame( array(), $data['designs'] );
		$this->assertSame( 400, $this->select( $id )->get_status() );
	}

	/** Rendering errors must keep the stock order details and fire each document hook once. */
	public function test_invalid_content_uses_woocommerce_details_once(): void {
		$id = $this->design( '{{#unclosed}}' );
		$this->assertSame( 200, $this->select( $id )->get_status() );
		$order = OrderHelper::create_order();
		$order->set_created_via( 'woocommerce-pos' );
		$order->save();
		$email = WC()->mailer()->get_emails()['WC_Email_Customer_Completed_Order'];
		$email->object = $order;
		$count = 0;
		$observe = static function ( $name ) use ( &$count ) {
			if ( 'emails/email-order-details.php' === $name ) {
				++$count;
			}
		};
		add_action( 'woocommerce_before_template_part', $observe );
		try {
			$html = $email->get_content_html();
			$this->assertStringContainsString( $order->get_items()[ array_key_first( $order->get_items() ) ]->get_name(), $html );
			$this->assertStringNotContainsString( '{{#unclosed}}', $html );
			$this->assertSame( 1, $count );
		} finally {
			remove_action( 'woocommerce_before_template_part', $observe );
		}
	}

	/** Manual invoices, other events and plain text keep their usual order details. */
	public function test_design_is_limited_to_completed_pos_html(): void {
		$id = $this->design( '<p>Juniper counter {{order.number}}</p>' );
		$this->assertSame( 200, $this->select( $id )->get_status() );
		$order = OrderHelper::create_order();
		$order->set_created_via( 'checkout' );
		$order->update_meta_data( '_pos', '1' );
		$order->save();
		$emails = WC()->mailer()->get_emails();
		$completed = $emails['WC_Email_Customer_Completed_Order'];
		$completed->object = $order;
		$this->assertStringContainsString( 'Juniper counter', $completed->get_content_html() );
		$this->assertStringNotContainsString( 'Juniper counter', $completed->get_content_plain() );
		foreach ( array( 'WC_Email_Customer_Processing_Order', 'WC_Email_Customer_Invoice' ) as $key ) {
			$emails[ $key ]->object = $order;
			$this->assertStringNotContainsString( 'Juniper counter', $emails[ $key ]->get_content_html() );
		}
	}
	/** Only a published, logic-less email design can be activated. */
	public function test_invalid_designs_cannot_replace_an_active_design(): void {
		$id = $this->design( '<p>Amber</p>' );
		$this->assertSame( $id, $this->select( $id )->get_data()['template_id'] );
		foreach ( array( $this->design( 'Draft', 'draft' ), $this->design( 'Receipt', 'publish', 'receipt' ), $this->design( 'Code', 'publish', 'email', 'legacy-php' ) ) as $invalid ) {
			$this->assertSame( 400, $this->select( $invalid )->get_status() );
		}
		$this->assertSame( $id, $this->server->dispatch( new \WP_REST_Request( 'GET', self::ROUTE ) )->get_data()['template_id'] );
		wp_trash_post( $id );
		$this->assertSame( 0, $this->server->dispatch( new \WP_REST_Request( 'GET', self::ROUTE ) )->get_data()['template_id'] );
		$this->assertSame( 0, $this->select( 0 )->get_data()['template_id'] );
	}

	/** A signed-in shopper cannot read drafts or bind a notification. */
	public function test_customer_cannot_manage_designs(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'customer' ) ) );
		$this->assertSame( 403, $this->server->dispatch( new \WP_REST_Request( 'GET', self::ROUTE ) )->get_status() );
		$this->assertSame( 403, $this->select( 0 )->get_status() );
	}
}
