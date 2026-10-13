<?php
/**
 * Query tokens are REST credentials, never page-load credentials.
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use WCPOS\WooCommercePOS\Services\Auth;

/**
 * Tests query-token routing and credential precedence.
 *
 * @covers \WCPOS\WooCommercePOS\Services\Auth::get_auth_header
 */
class Test_Query_Token_Rest_Only extends WCPOS_REST_Unit_Test_Case {

	/**
	 * Original request URI.
	 *
	 * @var string|null
	 */
	private $request_uri;

	/**
	 * Administrator access token.
	 *
	 * @var string
	 */
	private $token;

	/**
	 * Start with a fresh request and a real administrator token.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->request_uri = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- preserve the raw test fixture for restoration.
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $_GET['authorization'], $_GET['rest_route'], $_GET['wcpos'] );
		$this->token = Auth::instance()->generate_access_token( get_user_by( 'id', $this->user ) );
	}

	/**
	 * Restore request globals and permalink routing.
	 */
	public function tearDown(): void {
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $_GET['authorization'], $_GET['rest_route'], $_GET['wcpos'], $_GET['s'] );
		if ( null === $this->request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->request_uri;
		}
		$this->set_permalink_structure( '' );
		parent::tearDown();
	}

	/**
	 * Resolve identity through determine_current_user, as a real request does.
	 *
	 * @return int
	 */
	private function resolve_current_user(): int {
		wp_set_current_user( 0 );
		global $current_user;
		$current_user = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- force authentication.

		return get_current_user_id();
	}

	/**
	 * Page URLs must never authenticate using query tokens.
	 *
	 * @dataProvider page_load_requests
	 * @param string $request_uri Request URI.
	 * @param string $permalink_structure Permalink structure.
	 * @param bool   $front_controller Whether the theme front controller is running.
	 */
	public function test_query_token_on_page_load_does_not_set_current_user( string $request_uri, string $permalink_structure, bool $front_controller ): void {
		// Arrange.
		$this->set_permalink_structure( $permalink_structure );
		add_filter( 'wp_using_themes', $front_controller ? '__return_true' : '__return_false' );
		$_SERVER['REQUEST_URI'] = $request_uri;
		parse_str( (string) wp_parse_url( $request_uri, PHP_URL_QUERY ), $query );
		$_GET                  = array_merge( $_GET, $query );
		$_GET['authorization'] = 'Bearer ' . $this->token;

		// Act.
		$user_id = $this->resolve_current_user();

		// Assert.
		$this->assertSame( 0, $user_id );
	}

	/**
	 * Page-load shapes, including misleading REST markers.
	 *
	 * @return array
	 */
	public function page_load_requests(): array {
		return array(
			'storefront page'                        => array( '/sample-page/', '/%postname%/', true ),
			'POS template with marker'               => array( '/pos/?wcpos=1', '/%postname%/', true ),
			'REST prefix only in the query string'    => array( '/?s=/wp-json/wcpos/v2/status', '/%postname%/', true ),
			'REST path without pretty permalinks'     => array( '/wp-json/wcpos/v2/status', '', true ),
			'lookalike prefix'                       => array( '/wp-jsonp/status', '/%postname%/', true ),
			'rest_route outside the front controller' => array( '/wp-login.php?rest_route=/wcpos/v2/status', '/%postname%/', false ),
		);
	}

	/**
	 * Query tokens authenticate the current REST lane before dispatch.
	 */
	public function test_query_token_rest_route_request_authenticates_current_lane_route(): void {
		// Arrange.
		$this->arrange_rest_front_controller_request( '/wcpos/v2/status' );
		$_GET['wcpos']         = '1';
		$_GET['authorization'] = 'Bearer ' . $this->token;

		// Act.
		$user_id  = $this->resolve_current_user();
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/status' ) );

		// Assert.
		$this->assertSame( $this->user, $user_id );
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Pretty REST paths authenticate before REST_REQUEST is defined.
	 */
	public function test_query_token_pretty_rest_path_sets_current_user(): void {
		// Arrange.
		$this->set_permalink_structure( '/%postname%/' );
		add_filter( 'wp_using_themes', '__return_true' );
		$_SERVER['REQUEST_URI'] = '/wp-json/wcpos/v2/status?authorization=x';
		$_GET['authorization'] = 'Bearer ' . $this->token;

		// Act.
		$user_id = $this->resolve_current_user();

		// Assert.
		$this->assertSame( $this->user, $user_id );
	}

	/**
	 * Standard headers take precedence over query credentials.
	 */
	public function test_get_auth_header_prefers_header_over_query_on_rest_request(): void {
		// Arrange.
		$this->arrange_rest_front_controller_request( '/wcpos/v2/status' );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer header-token';
		$_GET['authorization']         = 'Bearer query-token';

		// Act.
		$header = Auth::instance()->get_auth_header();

		// Assert.
		$this->assertSame( 'Bearer header-token', $header );
	}

	/**
	 * Empty standard headers do not hide Apache's redirected header.
	 */
	public function test_get_auth_header_empty_http_authorization_falls_through_to_redirect_header(): void {
		// Arrange.
		$_SERVER['HTTP_AUTHORIZATION']          = '';
		$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer redirect-token';

		// Act.
		$header = Auth::instance()->get_auth_header();

		// Assert.
		$this->assertSame( 'Bearer redirect-token', $header );
	}

	/**
	 * Empty headers permit the REST query-credential fallback.
	 */
	public function test_get_auth_header_empty_headers_fall_through_to_query_on_rest_request(): void {
		// Arrange.
		$this->arrange_rest_front_controller_request( '/wcpos/v2/status' );
		$_SERVER['HTTP_AUTHORIZATION']          = '';
		$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = '';
		$_GET['authorization']                = 'Bearer query-token';

		// Act.
		$header = Auth::instance()->get_auth_header();

		// Assert.
		$this->assertSame( 'Bearer query-token', $header );
	}

	/**
	 * The REST index does not publish the retired server toggle.
	 */
	public function test_rest_index_omits_use_jwt_as_param(): void {
		// Arrange.
		update_option(
			'woocommerce_pos_settings_tools',
			array(
				'use_jwt_as_param' => true,
			)
		);

		// Act.
		$response = $this->server->dispatch( $this->wp_rest_get_request( '/' ) );
		$data     = $response->get_data();

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'wcpos_version', $data );
		$this->assertArrayNotHasKey( 'use_jwt_as_param', $data );
	}
}
