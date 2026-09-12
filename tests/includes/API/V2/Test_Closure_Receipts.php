<?php
/**
 * Closure receipt documents.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use WCPOS\WooCommercePOS\Services\Closure_Store;
use WCPOS\WooCommercePOS\Services\Receipt_Preview_Fixture_Loader;
use WCPOS\WooCommercePOS\Templates;
use WCPOS\WooCommercePOS\Templates\Renderers\Legacy_Php_Renderer;
use WCPOS\WooCommercePOS\Tests\Services\Closure_Test_Fixture;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/** Stored figures, live sessions and independent print bookkeeping. */
class Test_Closure_Receipts extends WCPOS_REST_Unit_Test_Case {
	use Closure_Test_Fixture;

	/** Read a document without requiring an unrelated order.
	 *
	 * @param string      $document Selector.
	 * @param string|null $intent Print intent.
	 */
	private function document( string $document, ?string $intent = null ) {
		$request = $this->wp_rest_get_request( '/wcpos/v2/receipts/0' );
		$request->set_param( 'document', $document );
		if ( $intent ) {
			$request->set_param( 'intent', $intent );
		}
		return $this->server->dispatch( $request );
	}

	/** Render with the actual filesystem default.
	 *
	 * @param array $data Payload.
	 */
	private function html( array $data ): string {
		ob_start();
		( new Legacy_Php_Renderer() )->render( Templates::get_virtual_template( 'plugin-core', 'closure' ), null, $data );
		return ob_get_clean();
	}

	/** Frozen rows, not later ledger edits, supply every Z figure. */
	public function test_closure_frozen_render_and_once_only_copy_count(): void {
		$session = $this->closure_session();
		$fields = $this->closure_fields( $session, 7 );
		$fixture = ( new Receipt_Preview_Fixture_Loader() )->build( 'closure' );
		$fields['breakdowns'] = $fixture['closure']['breakdowns'];
		$fields['unsynced_count'] = 2;
		$fields['unsynced_total'] = '12.0000';
		$store = new Closure_Store();
		$row = $store->create( $fields );
		$this->closure_ledger( $session );
		foreach ( array( null, 'print', null, 'print' ) as $index => $intent ) {
			$response = $this->document( 'closure:' . $row['id'], $intent );
			$this->assertSame( 200, $response->get_status() );
			$data = $response->get_data()['data'];
			$this->assertSame( $row['expected'], $data['closure']['expected'] );
			$this->assertSame( $row['period_sales_total'], $data['closure']['period_sales_total'] );
			$html = $this->html( $data );
			foreach ( array( 'Closure 7 · Closure fixture', '101.0000', '100.0000', '1.0000', '2 sales not yet on the server · 12.0000', 'Petty cash', 'Voided' ) as $text ) {
				$this->assertStringContainsString( $text, $html );
			}
			$this->assertSame( array( 0, 1, 1, 2 )[ $index ], $store->get( $row['id'] )['print_count'] );
			$this->assertSame( 3 === $index, $data['fiscal']['is_reprint'] );
		}
		$this->assertStringContainsString( 'COPY 1', $html );
	}

	/** A thin X-report needs no closure, number or count. */
	public function test_xreport_live_session_and_missing_or_scoped_documents(): void {
		$session = $this->closure_session();
		$order = $this->closure_ledger( $session );
		$response = $this->document( 'xreport:' . $session['id'], 'print' );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data()['data'];
		$this->assertSame( 'xreport', $data['fiscal']['document_type'] );
		$this->assertTrue( $data['fiscal']['is_closure_document'] );
		$this->assertTrue( $data['fiscal']['is_x_report'] );
		$this->assertSame( '', $data['fiscal']['receipt_number'] );
		$this->assertSame( '140.0000', $data['closure']['expected']['cash'] );
		$this->assertSame( 1, $data['closure']['breakdowns']['transaction_count'] );
		$this->assertSame( 1, $data['closure']['breakdowns']['refund_count'] );
		$this->assertStringContainsString( 'X-report · Closure fixture', $this->html( $data ) );
		$this->assertStringNotContainsString( 'sales not yet on the server', $this->html( $data ) );
		$this->assertNull( ( new Closure_Store() )->for_session( $session['id'] ) );
		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( '_wcpos_receipt_print_count' ) );
		$row = ( new Closure_Store() )->create( $this->closure_fields( $session ) );
		$this->assertSame( 404, $this->document( 'xreport:' . $session['id'] )->get_status() );
		$this->assertStringNotContainsString( 'sales not yet on the server', $this->html( $this->document( 'closure:' . $row['id'] )->get_data()['data'] ) );
		foreach ( array( 'closure:', 'xreport:' ) as $prefix ) {
			$this->assertSame( 404, $this->document( $prefix . wp_generate_uuid4() )->get_status() );
		}
		$scope = static function ( $args ) {
			$args['store_id'] = array( 999 );
			return $args;
		};
		add_filter( 'woocommerce_pos_closures_list_args', $scope );
		try {
			$this->assertSame( 404, $this->document( 'closure:' . $row['id'], 'print' )->get_status() );
			$open = $this->closure_session();
			$this->assertSame( 404, $this->document( 'xreport:' . $open['id'] )->get_status() );
			$this->assertSame( 0, ( new Closure_Store() )->get( $row['id'] )['print_count'] );
		} finally {
			remove_filter( 'woocommerce_pos_closures_list_args', $scope );
		}
	}
	/** Refund counts use captured session payment rows, not orders or pending rows. */
	public function test_xreport_refund_count_uses_live_session_ledger(): void {
		$session = $this->closure_session();
		$this->assertSame( 0, $this->document( 'xreport:' . $session['id'] )->get_data()['data']['closure']['breakdowns']['refund_count'] );
		$order = $this->closure_ledger( $session );
		$ledger = \WCPOS\WooCommercePOS\Payments\Contract\Ledger::instance();
		$rows = $ledger->read( $order );
		$rows[1]['amount'] = '-5';
		$rows[2]['refunded_amount'] = '10'; // Pending: excluded.
		$rows[3]['refunded_amount'] = '10'; // Another session: excluded.
		$refund = $rows[0];
		$refund['id'] = wp_generate_uuid4();
		$refund['kind'] = 'refund';
		$refund['refunded_amount'] = '0';
		$rows[] = $refund;
		$ledger->save( $order, $rows, false );
		$data = $this->document( 'xreport:' . $session['id'] )->get_data()['data'];
		$this->assertSame( 3, $data['closure']['breakdowns']['refund_count'] );
		$this->assertSame( 1, $data['closure']['breakdowns']['transaction_count'] );
	}

	/** PDFs use the same document count, including on the virtual default. */
	public function test_closure_pdf_count_and_failed_render_rollback(): void {
		$row = ( new Closure_Store() )->create( $this->closure_fields( $this->closure_session() ) );
		$request = $this->wp_rest_get_request( '/wcpos/v2/receipts/0/pdf' );
		$request->set_query_params(
			array(
				'document' => 'closure:' . $row['id'],
				'template_id' => 'plugin-core',
				'intent' => 'print',
			)
		);
		foreach ( array( 1, 2 ) as $count ) {
			$response = $this->server->dispatch( $request );
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( $count, ( new Closure_Store() )->get( $row['id'] )['print_count'] );
		}
		$request->set_param( 'intent', null );
		$this->assertSame( 200, $this->server->dispatch( $request )->get_status() );
		$data = $this->document( 'closure:' . $row['id'] )->get_data()['data'];
		$counter = new \WCPOS\WooCommercePOS\Services\Closure_Print_Counter();
		$this->assertSame(
			'',
			$counter->count_after(
				$data,
				static function () {
					return '';
				}
			)
		);
		try {
			$counter->count_after(
				$data,
				static function () {
					throw new \RuntimeException( 'test render failure' );
				}
			);
			$this->fail( 'Expected render failure.' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'test render failure', $error->getMessage() );
		}
		$this->assertSame( 2, ( new Closure_Store() )->get( $row['id'] )['print_count'] );
	}

	/** Closure PDFs reject database templates of every unrelated type before counting. */
	public function test_closure_pdf_non_closure_template_returns_type_mismatch(): void {
		$row = ( new Closure_Store() )->create( $this->closure_fields( $this->closure_session() ) );
		foreach ( array( 'receipt', 'report', 'display' ) as $type ) {
			$id = self::factory()->post->create(
				array(
					'post_type' => 'wcpos_template',
					'post_status' => 'publish',
					'post_content' => '<p>Wrong layout</p>',
				)
			);
			wp_set_object_terms( $id, $type, 'wcpos_template_type' );
			update_post_meta( $id, '_template_engine', 'logicless' );
			$request = $this->wp_rest_get_request( '/wcpos/v2/receipts/0/pdf' );
			$request->set_query_params(
				array(
					'document' => 'closure:' . $row['id'],
					'template_id' => $id,
					'intent' => 'print',
				)
			);
			$response = $this->server->dispatch( $request );
			$this->assertSame( 400, $response->get_status() );
			$this->assertSame( 'wcpos_template_type_mismatch', $response->get_data()['code'] );
			$this->assertSame( 0, ( new Closure_Store() )->get( $row['id'] )['print_count'] );
		}
	}

	/** Both template preview and merchant-created templates accept the new type. */
	public function test_closure_template_preview_listing_and_custom_save(): void {
		$request = $this->wp_rest_get_request( '/wcpos/v2/templates/plugin-core/preview' );
		$request->set_query_params(
			array(
				'type' => 'closure',
				'order_id' => 'latest',
			)
		);
		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertStringContainsString( 'Closure 42 · Main register', $data['preview_html'] );
		$this->assertArrayNotHasKey( 'requires_order', $data );
		$this->assertSame( 'closure', $data['receipt_data']['fiscal']['document_type'] );
		$request = $this->wp_rest_get_request( '/wcpos/v2/templates' );
		$request->set_param( 'type', 'closure' );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( 'plugin-core', array_column( $response->get_data(), 'id' ) );
		$this->assertSame( 404, $this->server->dispatch( $this->wp_rest_patch_request( '/wcpos/v2/templates/plugin-core' ) )->get_status() );
		$id = self::factory()->post->create(
			array(
				'post_type' => 'wcpos_template',
				'post_status' => 'draft',
				'post_content' => '<p>{{closure.number}}</p>',
			)
		);
		wp_set_object_terms( $id, 'closure', 'wcpos_template_type' );
		update_post_meta( $id, '_template_engine', 'logicless' );
		$request = $this->wp_rest_patch_request( '/wcpos/v2/templates/' . $id );
		$request->set_body_params( array( 'status' => 'publish' ) );
		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'closure', $response->get_data()['type'] );
		$this->assertSame( 'publish', get_post_status( $id ) );
	}

	/** Live cashiers come from captured ledger rows, not the session opener. */
	public function test_xreport_ledger_cashiers_and_live_movements(): void {
		$session = $this->closure_session( null, 'open' );
		$order = $this->closure_ledger( $session );
		$cashier = self::factory()->user->create( array( 'display_name' => 'Ledger cashier' ) );
		$ledger = \WCPOS\WooCommercePOS\Payments\Contract\Ledger::instance();
		$rows = $ledger->read( $order );
		$rows[0]['cashier_id'] = $cashier;
		$ledger->save( $order, $rows, false );
		( new \WCPOS\WooCommercePOS\Services\Cash_Movement_Store() )->create(
			array(
				'id' => wp_generate_uuid4(),
				'session_id' => $session['id'],
				'type' => 'paid_in',
				'amount' => '7.0000',
				'reason' => 'Live movement',
				'actor' => get_current_user_id(),
				'created_at_gmt' => '2026-09-11 09:00:00',
			)
		);
		$reads = array(
			'orders' => 0,
			'movements' => 0,
		);
		$observe = static function ( $sql ) use ( &$reads ) {
			if ( false !== strpos( $sql, 'SELECT DISTINCT' ) && false !== strpos( $sql, \WCPOS\WooCommercePOS\Payments\Contract\Ledger::META_KEY ) ) {
				++$reads['orders'];
			}
			if ( 0 === strpos( $sql, 'SELECT * FROM' ) && false !== strpos( $sql, 'wcpos_cash_movements' ) ) {
				++$reads['movements'];
			}
			return $sql;
		};
		add_filter( 'query', $observe );
		try {
			$data = $this->document( 'xreport:' . $session['id'], 'print' )->get_data()['data'];
		} finally {
			remove_filter( 'query', $observe );
		}
		$this->assertSame(
			array(
				'orders' => 1,
				'movements' => 1,
			),
			$reads
		);
		$this->assertSame( '147.0000', $data['closure']['expected']['cash'] );
		$this->assertSame( 'Ledger cashier', $data['closure']['breakdowns']['cashiers'][0]['name'] );
		$this->assertStringContainsString( 'Live movement', $this->html( $data ) );
		// The fixture's register is named "Closure fixture"; only the heading must not say Closure.
		$this->assertStringNotContainsString( '<h1>Closure', $this->html( $data ) );
	}
	/**
	 * Exercise checkout routing and get_template(), stopping at its completion hook before exit.
	 *
	 * @param int   $order_id Requested order.
	 * @param array $params Query parameters.
	 * @throws \Error When rendering fails unexpectedly.
	 */
	private function render_receipt_page( int $order_id, array $params ): array {
		global $wp;
		$original_vars = $wp->query_vars;
		$wp->query_vars['wcpos-receipt'] = $order_id;
		$original_get = $_GET;
		$buffer_level = ob_get_level();
		$page = array(
			'output' => '',
			'error' => null,
			'title' => null,
			'status' => null,
		);
		$stop = static function () {
			throw new \Error( 'Receipt page stopped for test.' );
		};
		$die = static function () use ( &$page, $stop ) {
			return static function ( $message, $title = '', $args = array() ) use ( &$page, $stop ) {
				$page['error'] = $message;
				$page['title'] = $title;
				$page['status'] = $args['response'] ?? null;
				$stop();
			};
		};
		add_action( 'woocommerce_pos_after_template_render', $stop );
		add_filter( 'wp_die_handler', $die );
		$_GET = $params;
		ob_start();
		try {
			$router = new \ReflectionClass( \WCPOS\WooCommercePOS\Template_Router::class );
			$dispatch = $router->getMethod( 'load_checkout_template' );
			$dispatch->setAccessible( true );
			$dispatch->invoke( $router->newInstanceWithoutConstructor(), array( 'wcpos-receipt' => \WCPOS\WooCommercePOS\Templates\Receipt::class ) );
		} catch ( \Error $e ) {
			if ( 'Receipt page stopped for test.' !== $e->getMessage() ) {
				throw $e;
			}
			$page['output'] = (string) ob_get_contents();
		} finally {
			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
			$_GET = $original_get;
			$wp->query_vars = $original_vars;
			remove_action( 'woocommerce_pos_after_template_render', $stop );
			remove_filter( 'wp_die_handler', $die );
		}
		return $page;
	}

	/** A storefront PDF preview must not enter closure print bookkeeping. */
	public function test_storefront_closure_pdf_preview_does_not_start_print_counting(): void {
		$row = ( new Closure_Store() )->create( $this->closure_fields( $this->closure_session() ) );
		$user = wp_set_current_user( self::factory()->user->create() );
		$user->add_cap( 'access_woocommerce_pos' );
		$user->add_cap( 'manage_woocommerce_pos' );
		$user->add_cap( 'manage_woocommerce_pos_cash' );
		$queries = array();
		$observe = static function ( $query ) use ( &$queries ) {
			$queries[] = $query;
			return $query;
		};
		$native_template = static function () {
			return array( 'id' => 'wp-overnight-invoice' );
		};
		add_filter( 'query', $observe );
		add_filter( 'woocommerce_pos_storefront_receipt_template', $native_template );
		try {
			$page = $this->render_receipt_page(
				0,
				array(
					'document' => 'closure:' . $row['id'],
					'intent' => 'print',
					'format' => 'pdf',
					'wcpos_preview_template' => 1,
				)
			);
		} finally {
			remove_filter( 'query', $observe );
			remove_filter( 'woocommerce_pos_storefront_receipt_template', $native_template );
		}
		$this->assertSame( 500, $page['status'] );
		$this->assertNotContains( 'START TRANSACTION', $queries );
		$this->assertSame( 0, ( new Closure_Store() )->get( $row['id'] )['print_count'] );
	}

	/** All orderless storefront selectors resolve before the order-key gate. */
	public function test_storefront_orderless_documents_and_permissions(): void {
		$session = $this->closure_session();
		$row = ( new Closure_Store() )->create( $this->closure_fields( $this->closure_session() ) );
		$viewer = wp_set_current_user( self::factory()->user->create() );
		$viewer->add_cap( 'access_woocommerce_pos' );
		foreach ( array(
			'closure:' . $row['id'] => 'Closure 1',
			'xreport:' . $session['id'] => 'X-report',
		) as $document => $heading ) {
			$page = $this->render_receipt_page( 0, array( 'document' => $document ) );
			$this->assertNull( $page['error'] );
			$this->assertStringContainsString( '<h1>' . $heading, $page['output'] );
		}
		foreach ( array( 'closure:', 'xreport:' ) as $prefix ) {
			$page = $this->render_receipt_page( 0, array( 'document' => $prefix . wp_generate_uuid4() ) );
			$this->assertSame( 404, $page['status'] );
		}
		foreach ( array( '', 'pdf' ) as $format ) {
			$page = $this->render_receipt_page(
				0,
				array(
					'document' => 'closure:' . $row['id'],
					'intent' => 'print',
					'format' => $format,
				)
			);
			$this->assertSame( 403, $page['status'] );
			$this->assertSame( '', $page['title'] );
			$this->assertSame( 0, ( new Closure_Store() )->get( $row['id'] )['print_count'] );
		}
		$viewer->add_cap( 'manage_woocommerce_pos_cash' );
		foreach ( array( 1, 2 ) as $count ) {
			$page = $this->render_receipt_page(
				0,
				array(
					'document' => 'closure:' . $row['id'],
					'intent' => 'print',
				)
			);
			$this->assertNull( $page['error'] );
			$this->assertSame( $count, ( new Closure_Store() )->get( $row['id'] )['print_count'] );
		}
		$this->assertStringContainsString( 'COPY 1', $page['output'] );
		$viewer->remove_cap( 'access_woocommerce_pos' );
		$this->assertSame( 403, $this->render_receipt_page( 0, array( 'document' => 'closure:' . $row['id'] ) )['status'] );
	}

	/** Read access alone cannot mutate the closure's print audit on either REST GET. */
	public function test_closure_get_print_requires_cash_capability(): void {
		$row = ( new Closure_Store() )->create( $this->closure_fields( $this->closure_session() ) );
		$viewer = wp_set_current_user( self::factory()->user->create() );
		$viewer->add_cap( 'access_woocommerce_pos' );
		foreach ( array( '', '/pdf' ) as $suffix ) {
			$request = $this->wp_rest_get_request( '/wcpos/v2/receipts/0' . $suffix );
			$request->set_query_params(
				array(
					'document' => 'closure:' . $row['id'],
					'template_id' => 'plugin-core',
				)
			);
			$this->assertSame( 200, $this->server->dispatch( $request )->get_status() );
			$request->set_param( 'intent', 'print' );
			$response = $this->server->dispatch( $request );
			$this->assertSame( 403, $response->get_status() );
			$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );
			$this->assertSame( 0, ( new Closure_Store() )->get( $row['id'] )['print_count'] );
		}
		$viewer->add_cap( 'manage_woocommerce_pos_cash' );
		$this->assertSame( 200, $this->server->dispatch( $request )->get_status() );
		$this->assertSame( 200, $this->document( 'closure:' . $row['id'], 'print' )->get_status() );
		$this->assertSame( 2, ( new Closure_Store() )->get( $row['id'] )['print_count'] );
	}

	/** Copies keep server-snapshotted register and operator labels after renames. */
	public function test_closure_copy_preserves_labels_and_uncounted_tenders(): void {
		$session = $this->closure_session();
		$session = ( new \WCPOS\WooCommercePOS\Services\Register_Session_Store() )->transition(
			$session,
			array(
				'status' => 'counting',
				'approved_by' => get_current_user_id(),
			)
		);
		$this->closure_ledger( $session );
		$fields = $this->closure_fields( $session );
		$fields['breakdowns']['labels'] = array( 'register_name' => 'Untrusted client label' );
		$row = ( new Closure_Store() )->create( $fields );
		$this->assertSame( 'Closure fixture', $row['breakdowns']['labels']['register_name'] );
		$original_name = wp_get_current_user()->display_name;
		foreach ( array( 'opened_by_name', 'closed_by_name', 'approved_by_name' ) as $key ) {
			$this->assertSame( $original_name, $row['breakdowns']['labels'][ $key ] );
		}
		$this->document( 'closure:' . $row['id'], 'print' );
		( new \WCPOS\WooCommercePOS\Services\Register_Store() )->update( $session['register_id'], array( 'name' => 'Renamed register' ) );
		wp_update_user(
			array(
				'ID' => get_current_user_id(),
				'display_name' => 'Renamed operator',
			)
		);
		$data = $this->document( 'closure:' . $row['id'], 'print' )->get_data()['data'];
		$html = $this->html( $data );
		$this->assertTrue( $data['fiscal']['is_reprint'] );
		$this->assertStringContainsString( 'Closure 1 · Closure fixture', $html );
		$this->assertStringContainsString( $original_name, $html );
		$this->assertStringNotContainsString( 'Renamed', $html );
		$this->assertArrayNotHasKey( 'card', $data['closure']['counted'] );
		$this->assertStringContainsString( '<td>card</td><td></td><td>' . $row['expected']['card'] . '</td>', $html );
		unset( $row['breakdowns']['labels'] ); // Rows predating label snapshots still resolve live names.
		$legacy = $this->html( ( new \WCPOS\WooCommercePOS\Services\Receipt_Data_Builder() )->build_closure_document( $row ) );
		$this->assertStringContainsString( 'Renamed register', $legacy );
		$this->assertStringContainsString( 'Renamed operator', $legacy );
	}
}
