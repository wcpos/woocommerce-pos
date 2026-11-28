<?php
/**
 * Coupon types controller tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\API\V2
 */

namespace WCPOS\WooCommercePOS\Tests\API\V2;

use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Tests\API\WCPOS_REST_Unit_Test_Case;

/** Exercises the public coupon types contract. */
class Test_Coupon_Types_Controller extends WCPOS_REST_Unit_Test_Case {
	/** A declaration with all four public fields. */
	private const CREDIT = array(
		'type'     => 'credit',
		'calc'     => 'store',
		'offline'  => 'none',
		'provider' => 'shop-credit',
	);

	/** @var array Captured log messages. */
	private array $logged_messages = array();

	/** @var \Closure Log capture callback. */
	private $log_filter;

	/** Capture log messages without writing them. */
	public function setUp(): void {
		parent::setUp();
		Logger::reset_dedup_state();
		$this->log_filter = function ( $should_log, $message ) {
			$this->logged_messages[] = $message;
			return false;
		};
		add_filter( 'wcpos_logging', $this->log_filter, 10, 2 );
	}

	/** Remove the capture callback after each test. */
	public function tearDown(): void {
		remove_filter( 'wcpos_logging', $this->log_filter );
		Logger::reset_dedup_state();
		parent::tearDown();
	}

	/** No plugin needs to register a callback. */
	public function test_coupon_types_no_callback_returns_empty_envelope(): void {
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/coupon-types' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'schema'       => 1,
				'coupon_types' => array(),
			),
			$response->get_data()
		);
	}

	/** Extra plugin data is not public. */
	public function test_coupon_types_valid_declaration_returns_only_public_fields(): void {
		$entry = self::CREDIT + array(
			'label'   => 'Store credit',
			'balance' => 25,
		);
		add_filter( 'wcpos_coupon_type_descriptors', static fn() => array( $entry ) );

		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/coupon-types' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( self::CREDIT ), $response->get_data()['coupon_types'] );
	}

	/** Both a missing provider and a non-string provider become empty strings. */
	public function test_coupon_types_missing_or_integer_provider_returns_empty_strings(): void {
		$missing = self::CREDIT;
		unset( $missing['provider'] );
		$integer             = $missing;
		$integer['type']     = 'voucher';
		$integer['provider'] = 12;
		add_filter( 'wcpos_coupon_type_descriptors', static fn() => array( $missing, $integer ) );

		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/coupon-types' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array( $missing + array( 'provider' => '' ), array_replace( $integer, array( 'provider' => '' ) ) ),
			$response->get_data()['coupon_types']
		);
	}

	/** Invalid entries, their diagnostic, and expected log context. */
	public static function invalid_declarations(): array {
		$missing = self::CREDIT;
		unset( $missing['type'] );
		$no_type     = 'WCPOS coupon types: dropped an entry with no type.';
		$core_type   = 'WCPOS coupon types: dropped a core coupon type, which cannot be redeclared.';
		$unsupported = 'WCPOS coupon types: dropped a type whose calc or offline value is not supported.';
		$voucher     = array( 'type' => 'voucher' ) + $missing;

		return array(
			'not an array'        => array( 'invalid', 'WCPOS coupon types: dropped a declared entry that is not an object.', null, null ),
			'missing type'        => array( $missing, $no_type, null, 'shop-credit' ),
			'integer type'        => array( array( 'type' => 12 ) + $missing, $no_type, null, 'shop-credit' ),
			'empty type'          => array( array( 'type' => '' ) + $missing, $no_type, '', 'shop-credit' ),
			'percent'             => array( array( 'type' => 'percent' ) + $missing, $core_type, 'percent', 'shop-credit' ),
			'fixed_cart'          => array( array( 'type' => 'fixed_cart' ) + $missing, $core_type, 'fixed_cart', 'shop-credit' ),
			'fixed_product'       => array( array( 'type' => 'fixed_product' ) + $missing, $core_type, 'fixed_product', 'shop-credit' ),
			'unsupported calc'    => array( array( 'calc' => 'till' ) + $voucher, $unsupported, 'voucher', 'shop-credit' ),
			'unsupported offline' => array( array( 'offline' => 'queue' ) + $voucher, $unsupported, 'voucher', 'shop-credit' ),
		);
	}

	/**
	 * Each invalid declaration is logged without hiding a valid sibling.
	 *
	 * @dataProvider invalid_declarations
	 * @param mixed       $invalid  Invalid declaration.
	 * @param string      $message  Expected log message.
	 * @param string|null $type     Expected logged type.
	 * @param string|null $provider Expected logged provider.
	 */
	public function test_coupon_types_invalid_declaration_logs_and_keeps_valid_sibling( $invalid, $message, $type, $provider ): void {
		add_filter( 'wcpos_coupon_type_descriptors', static fn() => array( $invalid, self::CREDIT ) );
		$context = wp_json_encode(
			array(
				'type'     => $type,
				'provider' => $provider,
			)
		);

		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/coupon-types' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( self::CREDIT ), $response->get_data()['coupon_types'] );
		$this->assertContains( $message . ' | Context: ' . $context, $this->logged_messages );
	}

	/** The first declaration wins even if another provider declares the same type. */
	public function test_coupon_types_duplicate_declaration_logs_and_keeps_first_provider(): void {
		$duplicate = array( 'provider' => 'other' ) + self::CREDIT;
		$sibling   = array( 'type' => 'voucher' ) + self::CREDIT;
		add_filter( 'wcpos_coupon_type_descriptors', static fn() => array( self::CREDIT, $duplicate, $sibling ) );

		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/coupon-types' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( self::CREDIT, $sibling ), $response->get_data()['coupon_types'] );
		$this->assertContains(
			'WCPOS coupon types: dropped a repeated type; the first declaration wins. | Context: {"type":"credit","provider":"other"}',
			$this->logged_messages
		);
	}

	/** An invalid filter result is logged and treated as an empty list. */
	public function test_coupon_types_string_filter_result_logs_and_returns_empty_list(): void {
		add_filter( 'wcpos_coupon_type_descriptors', static fn() => 'invalid' );

		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/coupon-types' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data()['coupon_types'] );
		$this->assertContains(
			'WCPOS coupon types: the wcpos_coupon_type_descriptors filter did not return a list; no types declared.',
			$this->logged_messages
		);
	}

	/** Anonymous clients must authenticate. */
	public function test_permissions_anonymous_user_returns_401(): void {
		wp_set_current_user( 0 );

		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/coupon-types' ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'woocommerce_pos_rest_unauthorized', $response->get_data()['code'] );
	}

	/** Authentication alone does not grant POS access. */
	public function test_permissions_user_without_pos_access_returns_403(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );

		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/coupon-types' ) );

		$this->assertSame( 403, $response->get_status() );
	}

	/** POS access suffices without an administrator role. */
	public function test_permissions_user_with_pos_access_returns_200(): void {
		$user = $this->factory->user->create_and_get( array( 'role' => 'subscriber' ) );
		$user->add_cap( 'access_woocommerce_pos' );
		wp_set_current_user( $user->ID );

		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/coupon-types' ) );

		$this->assertSame( 200, $response->get_status() );
	}

	/** OPTIONS describes the public descriptor fields. */
	public function test_schema_options_returns_coupon_type_properties(): void {
		$request = $this->wp_rest_get_request( '/wcpos/v2/coupon-types' );
		$request->set_method( 'OPTIONS' );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$schema = $response->get_data()['schema'];
		$this->assertSame( 'wcpos_coupon_types', $schema['title'] );
		$this->assertSame( array( 'type', 'calc', 'offline', 'provider' ), array_keys( $schema['properties']['coupon_types']['items']['properties'] ) );
	}
}
