<?php
/**
 * The completed-sale design is chosen in wp-admin and used by the real mailer.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;

/** @coversNothing */
class Test_Customer_Email_Design extends WCPOS_REST_Unit_Test_Case {
	private const ROUTE = '/wcpos/v1/templates/customer-email-design';

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

	private function select( int $id ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', self::ROUTE );
		$request->set_param( 'template_id', $id );
		return $this->server->dispatch( $request );
	}

	/** Verify the message WordPress accepted, including live order interpolation. */
	public function test_completed_pos_mail_contains_published_design(): void {
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
