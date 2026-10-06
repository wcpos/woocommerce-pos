<?php
/**
 * POS payments list tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Pos_Payments;
use WP_UnitTestCase;

/**
 * Pos_Payments_Test class.
 */
class Pos_Payments_Test extends WP_UnitTestCase {
	/** Canonical split-tender fixture. */
	private const CANONICAL_JSON = '[{"method":"pos_card","title":"Card","amount":"20.00","reference":"auth-1"},{"method":"pos_cash","title":"Cash","amount":"19.00","tendered":"20.00","change":"1.00"}]';

	/**
	 * JSON input is normalized to ordered, known keys.
	 */
	public function test_normalize_returns_canonical_json_for_a_json_string_list(): void {
		// Arrange.
		$value = '[{"foo":"bar","reference":"auth-1","amount":"20.00","title":"Card","method":"pos_card"},{"change":"1.00","tendered":"20.00","amount":"19.00","title":"Cash","method":"pos_cash"}]';

		// Act.
		$result = Pos_Payments::normalize( $value );

		// Assert.
		$this->assertSame( self::CANONICAL_JSON, $result );
	}

	/**
	 * Native lists use the same canonical encoding as JSON input.
	 */
	public function test_normalize_accepts_a_native_array_list(): void {
		// Arrange.
		$value = array(
			array(
				'method'    => 'pos_card',
				'title'     => 'Card',
				'amount'    => '20.00',
				'reference' => 'auth-1',
			),
			array(
				'method'   => 'pos_cash',
				'title'    => 'Cash',
				'amount'   => '19.00',
				'tendered' => '20.00',
				'change'   => '1.00',
			),
		);

		// Act.
		$result = Pos_Payments::normalize( $value );

		// Assert.
		$this->assertSame( self::CANONICAL_JSON, $result );
	}

	/**
	 * Invalid lists are rejected in full.
	 *
	 * @dataProvider invalid_lists_provider
	 * @param mixed $value Invalid payments input.
	 */
	public function test_normalize_rejects_invalid_lists( $value ): void {
		$this->assertNull( Pos_Payments::normalize( $value ) );
	}

	/**
	 * Invalid list shapes and single-entry faults.
	 *
	 * @return array
	 */
	public function invalid_lists_provider(): array {
		$entry = array(
			'method' => 'pos_cash',
			'title'  => 'Cash',
			'amount' => '19.00',
		);
		$missing_method = $entry;
		unset( $missing_method['method'] );

		return array(
			'not json'          => array( 'not json' ),
			'empty object'      => array( '{}' ),
			'empty array'       => array( array() ),
			'associative array' => array( array( 'a' => $entry ) ),
			'too many tenders'  => array( array_fill( 0, 21, $entry ) ),
			'missing method'    => array( array( $missing_method ) ),
			'invalid method'    => array( array( array_merge( $entry, array( 'method' => 'pos cash' ) ) ) ),
			'empty title'       => array( array( array_merge( $entry, array( 'title' => '' ) ) ) ),
			'negative amount'   => array( array( array_merge( $entry, array( 'amount' => '-1.00' ) ) ) ),
			'exponent amount'   => array( array( array_merge( $entry, array( 'amount' => '1e3' ) ) ) ),
			'comma amount'      => array( array( array_merge( $entry, array( 'amount' => '10,50' ) ) ) ),
			'invalid tendered'  => array( array( array_merge( $entry, array( 'tendered' => 'x' ) ) ) ),
			'non-array entry'   => array( array( 'pos_cash' ) ),
			'integer'           => array( 5 ),
			'null'              => array( null ),
		);
	}

	/**
	 * Order metadata yields the normalized list or an empty array.
	 */
	public function test_from_order_returns_the_list_or_empty(): void {
		// Arrange.
		$order = wc_create_order();
		$order->update_meta_data( Pos_Payments::META_KEY, self::CANONICAL_JSON );
		$order->save();
		$invalid_order = wc_create_order();
		$invalid_order->update_meta_data( Pos_Payments::META_KEY, 'garbage' );
		$invalid_order->save();

		// Act.
		$list    = Pos_Payments::from_order( $order );
		$invalid = Pos_Payments::from_order( $invalid_order );

		// Assert.
		$this->assertCount( 2, $list );
		$this->assertSame( '1.00', $list[1]['change'] );
		$this->assertSame( array(), $invalid );
	}
}
