<?php
/**
 * Sessions on a register Free does not hold (ruling 3: Pro deactivated with sessions open on its registers).
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use WCPOS\WooCommercePOS\Services\Receipt_Data_Builder;
use WCPOS\WooCommercePOS\Services\Register_Session_Store;
use WCPOS\WooCommercePOS\Services\Register_Store;
use WCPOS\WooCommercePOS\Services\Report_Scope_Resolver;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;
use WCPOS\WooCommercePOS\Tests\Services\Closure_Test_Fixture;

/** Free can close a session on an unknown register, never open one. */
class Test_Unknown_Register_Sessions extends WCPOS_REST_Unit_Test_Case {
	use Closure_Test_Fixture;

	/** Grant the cash and closure capabilities. */
	public function setUp(): void {
		parent::setUp();
		wp_get_current_user()->add_cap( 'manage_woocommerce_pos_cash' );
		wp_get_current_user()->add_cap( 'manage_woocommerce_pos_closures' );
	}

	/**
	 * Dispatch a v2 write.
	 *
	 * @param string $route Resource path.
	 * @param array  $body Request fields.
	 */
	private function post( string $route, array $body ) {
		$request = $this->wp_rest_post_request( '/wcpos/v2/' . $route );
		$request->set_body_params( $body );
		return $this->server->dispatch( $request );
	}

	/**
	 * A REST opening on a register.
	 *
	 * @param string $register_id Register UUID.
	 */
	private function opening( string $register_id ): array {
		return array(
			'id' => wp_generate_uuid4(),
			'register_id' => $register_id,
			'opened_at' => '2026-09-11T08:00:00Z',
			'expected_float' => '100',
			'counted_float' => '100',
		);
	}

	/** A session opened on a Pro register that then disappears is counted, moved, approved and closed; history names it. */
	public function test_session_on_unknown_register_closes_and_is_named_from_retired_map(): void {
		// Arrange: Pro holds the register while the session opens, then Pro is gone.
		$repository = $this->use_multi_register_repository();
		$register = $this->create_register(
			array(
				'name' => 'Pro till',
				'store_id' => 456,
			)
		);
		$this->closure_registers[] = $register['id'];
		$opening = $this->opening( $register['id'] );
		$this->assertSame( 201, $this->post( 'sessions', $opening )->get_status() );
		$repository->forget( $register['id'], 'Pro till' );
		$this->assertNull( ( new Register_Store() )->get( $register['id'] ) );
		$manager = self::factory()->user->create_and_get(
			array(
				'role' => 'subscriber',
				'user_pass' => 'approval-fixture',
			)
		);
		$manager->add_cap( 'manage_woocommerce_pos_closures' );

		// Act / Assert: never open another session on it.
		$refused = $this->post( 'sessions', $this->opening( $register['id'] ) );
		$this->assertSame( 400, $refused->get_status() );
		$this->assertSame( 'rest_invalid_param', $refused->get_data()['code'] );
		$this->assertArrayHasKey( 'register_id', $refused->get_data()['data']['params'] );

		// Act / Assert: movement, count, approval.
		$movement = $this->post(
			'movements',
			array(
				'id' => wp_generate_uuid4(),
				'session_id' => $opening['id'],
				'type' => 'paid_in',
				'amount' => '20',
				'reason' => 'Float top-up',
				'created_at' => '2026-09-11T09:00:00Z',
			)
		);
		$this->assertSame( 201, $movement->get_status() );
		$counting = $this->post(
			'sessions/' . $opening['id'] . '/status',
			array(
				'status' => 'counting',
				'at' => '2026-09-11T11:00:00Z',
			)
		);
		$this->assertSame( 200, $counting->get_status() );
		$approved = $this->post(
			'sessions/' . $opening['id'] . '/approve',
			array(
				'username' => $manager->user_login,
				'password' => 'approval-fixture',
				'counted' => array( 'cash' => '101' ),
			)
		);
		$this->assertSame( 200, $approved->get_status() );

		// Act / Assert: the closure closes it, and its label resolves from the retired name.
		$body = $this->closure_fields( ( new Register_Session_Store() )->get( $opening['id'] ) );
		foreach ( array( 'opened_at', 'closed_at', 'printed_at' ) as $key ) {
			$body[ $key ] = null === $body[ $key . '_gmt' ] ? null : str_replace( ' ', 'T', $body[ $key . '_gmt' ] ) . 'Z';
			unset( $body[ $key . '_gmt' ] );
		}
		$closure = $this->post( 'closures', $body );
		$this->assertSame( 201, $closure->get_status() );
		$row = $closure->get_data();
		$this->assertSame( 'Pro till', $row['breakdowns']['labels']['register_name'] );
		$this->assertSame( 'closed', ( new Register_Session_Store() )->get( $opening['id'] )['status'] );
		unset( $row['breakdowns']['labels'] );
		$this->assertSame( 'Pro till', ( new Receipt_Data_Builder() )->build_closure_document( $row )['register']['name'] );
		$this->assertSame( 'Pro till', ( new Receipt_Data_Builder() )->build_closure_document( $row, true )['register']['name'] );

		// Act / Assert: its session report resolves rather than answering 404.
		$context = Report_Scope_Resolver::context(
			array(
				'mode' => 'session',
				'session_id' => $opening['id'],
			)
		);
		$this->assertIsArray( $context );
		$this->assertSame( 'Pro till', $context['register_name'] );
		$range = Report_Scope_Resolver::context(
			array(
				'mode' => 'range',
				'register_id' => $register['id'],
			)
		);
		$this->assertWPError( $range );
		$this->assertSame( 'wcpos_report_register_not_found', $range->get_error_code() );
	}

	/** A sale on a register nobody holds or retired prints the literal "Register" with its stamped id kept empty. */
	public function test_order_receipt_on_unknown_register_names_it_register(): void {
		// Arrange.
		$order = OrderHelper::create_order();
		$order->update_meta_data( '_wcpos_register', wp_generate_uuid4() );
		$order->save();

		// Act.
		$data = ( new Receipt_Data_Builder() )->build( $order, 'live' );

		// Assert.
		$this->assertSame(
			array(
				'id' => '',
				'name' => 'Register',
			),
			$data['register']
		);
	}
}
