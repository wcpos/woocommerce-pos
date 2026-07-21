<?php
/**
 * Test Extensions Service.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Extensions;
use WP_UnitTestCase;

/**
 * Extensions Service test case.
 */
class Test_Extensions_Service extends WP_UnitTestCase {

	/**
	 * Extensions service instance.
	 *
	 * @var Extensions
	 */
	private $service;

	/**
	 * Dynamic catalog data for parameterized status tests.
	 *
	 * @var array
	 */
	private $mock_catalog_data = array();

	/**
	 * Result returned by a nested refresh attempted while another refresh owns the lock.
	 *
	 * @var array|\WP_Error|null
	 */
	private $concurrent_refresh_result;

	/**
	 * Number of remote catalog requests observed by the concurrency mock.
	 *
	 * @var int
	 */
	private $catalog_http_requests = 0;

	/**
	 * Replacement lock installed while a compare-and-swap delete is pending.
	 *
	 * @var array
	 */
	private $replacement_refresh_lock = array();

	/**
	 * Result returned by a nested refresh inserted before the outer acquisition query.
	 *
	 * @var array|\WP_Error|null
	 */
	private $initial_acquisition_result;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->service = Extensions::instance();
		delete_transient( 'wcpos_extensions_catalog' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		$this->concurrent_refresh_result = null;
		$this->catalog_http_requests     = 0;
		$this->replacement_refresh_lock  = array();
		$this->initial_acquisition_result = null;
		delete_option( Extensions::REFRESH_LOCK_KEY );
		delete_transient( 'wcpos_extensions_catalog' );
		delete_site_option( 'auto_update_plugins' );
		parent::tearDown();
	}

	/**
	 * Build a complete valid catalog entry with optional overrides.
	 *
	 * @param array $overrides Entry fields to replace.
	 */
	private function valid_catalog_entry( array $overrides = array() ): array {
		return array_merge(
			array(
				'slug'           => 'wcpos-example',
				'name'           => 'Example',
				'description'    => 'Example extension.',
				'version'        => '1.0.0',
				'author'         => 'WCPOS',
				'category'       => 'payments',
				'tags'           => array( 'example' ),
				'requires_wp'    => '6.0',
				'requires_wc'    => '8.0',
				'requires_wcpos' => '1.9',
				'requires_pro'   => true,
				'icon'           => '',
				'homepage'       => '',
				'download_url'   => '',
				'latest_version' => '1.0.0',
				'released_at'    => '2026-01-01T00:00:00Z',
			),
			$overrides
		);
	}

	/**
	 * Test a failed refresh leaves the cached catalog unchanged.
	 */
	public function test_refresh_catalog_failure_preserves_cached_catalog(): void {
		$cached = array( $this->valid_catalog_entry() );
		set_transient( Extensions::TRANSIENT_KEY, $cached, HOUR_IN_SECONDS );
		add_filter( 'pre_http_request', array( $this, 'mock_catalog_failure' ), 10, 3 );

		$result = $this->service->refresh_catalog();

		$this->assertWPError( $result );
		$this->assertEquals( 'woocommerce_pos_extensions_refresh_failed', $result->get_error_code() );
		$this->assertEquals( $cached, get_transient( Extensions::TRANSIENT_KEY ) );
		remove_filter( 'pre_http_request', array( $this, 'mock_catalog_failure' ) );
	}

	/**
	 * Test wrong field types are rejected without replacing the cached catalog.
	 */
	public function test_refresh_catalog_wrong_typed_consumed_fields_preserve_cached_catalog(): void {
		$cached = array( $this->valid_catalog_entry() );
		$invalid_overrides = array(
			array( 'tags' => 'payments' ),
			array( 'category' => array( 'payments' ) ),
			array( 'description' => 42 ),
			array( 'download_url' => array() ),
			array( 'homepage' => array() ),
			array( 'icon' => array() ),
			array( 'requires_pro' => 'yes' ),
			array( 'latest_version' => array( '2.0.0' ) ),
			array( 'settings_url' => array() ),
			array( 'log_source' => false ),
		);

		foreach ( $invalid_overrides as $overrides ) {
			set_transient( Extensions::TRANSIENT_KEY, $cached, HOUR_IN_SECONDS );
			$this->mock_catalog_data = array( $this->valid_catalog_entry( $overrides ) );
			add_filter( 'pre_http_request', array( $this, 'mock_dynamic_response' ), 10, 3 );

			$result = $this->service->refresh_catalog();

			$this->assertWPError( $result );
			$this->assertEquals( 'woocommerce_pos_extensions_refresh_failed', $result->get_error_code() );
			$this->assertEquals( $cached, get_transient( Extensions::TRANSIENT_KEY ) );
			remove_filter( 'pre_http_request', array( $this, 'mock_dynamic_response' ) );
		}
	}

	/**
	 * Test tags must be a JSON-list-compatible array.
	 */
	public function test_refresh_catalog_associative_tags_preserve_cached_catalog(): void {
		$cached = array( $this->valid_catalog_entry() );
		set_transient( Extensions::TRANSIENT_KEY, $cached, HOUR_IN_SECONDS );
		$this->mock_catalog_data = array(
			$this->valid_catalog_entry(
				array( 'tags' => array( 'type' => 'payments' ) )
			),
		);
		add_filter( 'pre_http_request', array( $this, 'mock_dynamic_response' ), 10, 3 );

		$result = $this->service->refresh_catalog();

		$this->assertWPError( $result );
		$this->assertEquals( 'woocommerce_pos_extensions_refresh_failed', $result->get_error_code() );
		$this->assertEquals( $cached, get_transient( Extensions::TRANSIENT_KEY ) );
		remove_filter( 'pre_http_request', array( $this, 'mock_dynamic_response' ) );
	}

	/**
	 * Test stale lock reclamation cannot delete a replacement owner.
	 */
	public function test_refresh_catalog_stale_takeover_preserves_new_owner_without_fetching(): void {
		$stale = array(
			'token'      => 'stale-owner',
			'expires_at' => time() - 1,
		);
		$this->replacement_refresh_lock = array(
			'token'      => 'replacement-owner',
			'expires_at' => time() + Extensions::REFRESH_LOCK_TTL,
		);
		add_option( Extensions::REFRESH_LOCK_KEY, $stale, '', false );
		add_filter( 'query', array( $this, 'mock_replace_refresh_lock_before_write' ) );
		add_filter( 'pre_http_request', array( $this, 'mock_counting_catalog_response' ), 10, 3 );

		$result = $this->service->refresh_catalog();

		remove_filter( 'query', array( $this, 'mock_replace_refresh_lock_before_write' ) );
		remove_filter( 'pre_http_request', array( $this, 'mock_counting_catalog_response' ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'woocommerce_pos_extensions_refresh_in_progress', $result->get_error_code() );
		$this->assertEquals( 0, $this->catalog_http_requests );
		$this->assertEquals( $this->replacement_refresh_lock, get_option( Extensions::REFRESH_LOCK_KEY ) );
	}

	/**
	 * Test lock release cannot delete a replacement owner.
	 */
	public function test_refresh_catalog_release_preserves_replacement_owner(): void {
		$this->replacement_refresh_lock = array(
			'token'      => 'replacement-owner',
			'expires_at' => time() + Extensions::REFRESH_LOCK_TTL,
		);
		add_filter( 'pre_http_request', array( $this, 'mock_catalog_response_with_release_takeover' ), 10, 3 );

		$result = $this->service->refresh_catalog();

		remove_filter( 'query', array( $this, 'mock_replace_refresh_lock_before_write' ) );
		remove_filter( 'pre_http_request', array( $this, 'mock_catalog_response_with_release_takeover' ) );
		$this->assertIsArray( $result );
		$this->assertEquals( $this->replacement_refresh_lock, get_option( Extensions::REFRESH_LOCK_KEY ) );
	}

	/**
	 * Test overlapping initial acquisition queries produce only one owner.
	 */
	public function test_refresh_catalog_initial_acquisition_allows_one_owner_and_one_fetch(): void {
		$this->initial_acquisition_result = null;
		$this->catalog_http_requests       = 0;
		add_filter( 'query', array( $this, 'mock_initial_acquisition_interleaving' ) );
		add_filter( 'pre_http_request', array( $this, 'mock_counting_catalog_response' ), 10, 3 );

		$outer_result = $this->service->refresh_catalog();

		remove_filter( 'query', array( $this, 'mock_initial_acquisition_interleaving' ) );
		remove_filter( 'query', array( $this, 'mock_hold_refresh_lock_on_release' ) );
		remove_filter( 'pre_http_request', array( $this, 'mock_counting_catalog_response' ) );
		$this->assertIsArray( $this->initial_acquisition_result );
		$this->assertWPError( $outer_result );
		$this->assertEquals( 'woocommerce_pos_extensions_refresh_in_progress', $outer_result->get_error_code() );
		$this->assertEquals( 1, $this->catalog_http_requests );
	}

	/**
	 * Test a live lock rejects a concurrent refresh without another fetch.
	 */
	public function test_refresh_catalog_concurrent_request_is_rejected_without_fetching(): void {
		$this->concurrent_refresh_result = null;
		$this->catalog_http_requests     = 0;
		add_filter( 'pre_http_request', array( $this, 'mock_refresh_with_concurrent_attempt' ), 10, 3 );

		$owner_result = $this->service->refresh_catalog();
		$cached       = get_transient( Extensions::TRANSIENT_KEY );

		$this->assertIsArray( $owner_result );
		$this->assertWPError( $this->concurrent_refresh_result );
		$this->assertEquals( 'woocommerce_pos_extensions_refresh_in_progress', $this->concurrent_refresh_result->get_error_code() );
		$this->assertEquals( 1, $this->catalog_http_requests );
		$this->assertEquals( '1.0.0', $cached[0]['latest_version'] );
		$this->assertFalse( get_option( Extensions::REFRESH_LOCK_KEY, false ) );
		remove_filter( 'pre_http_request', array( $this, 'mock_refresh_with_concurrent_attempt' ) );
	}

	/**
	 * Attempt a nested refresh while the owner is inside its only HTTP request.
	 *
	 * @param false|array $response    Response.
	 * @param array       $parsed_args Args.
	 * @param string      $url         URL.
	 */
	public function mock_refresh_with_concurrent_attempt( $response, $parsed_args, $url ) {
		if ( false === strpos( $url, 'catalog.json' ) ) {
			return $response;
		}

		++$this->catalog_http_requests;
		$this->concurrent_refresh_result = $this->service->refresh_catalog();

		return array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( array( $this->valid_catalog_entry() ) ),
		);
	}

	/**
	 * Replace the observed lock immediately before its pending database write executes.
	 *
	 * @param string $query Database query.
	 */
	public function mock_replace_refresh_lock_before_write( $query ) {
		global $wpdb;

		$is_conditional_write = false !== strpos( $query, 'DELETE FROM' ) || false !== strpos( $query, 'UPDATE' );
		if ( ! $is_conditional_write || false === strpos( $query, $wpdb->options ) || false === strpos( $query, Extensions::REFRESH_LOCK_KEY ) ) {
			return $query;
		}

		remove_filter( 'query', array( $this, 'mock_replace_refresh_lock_before_write' ) );
		delete_option( Extensions::REFRESH_LOCK_KEY );
		add_option( Extensions::REFRESH_LOCK_KEY, $this->replacement_refresh_lock, '', false );

		return $query;
	}

	/**
	 * Count any unexpected HTTP fetch and return a valid response.
	 *
	 * @param false|array $response    Response.
	 * @param array       $parsed_args Args.
	 * @param string      $url         URL.
	 */
	public function mock_counting_catalog_response( $response, $parsed_args, $url ) {
		if ( false === strpos( $url, 'catalog.json' ) ) {
			return $response;
		}

		++$this->catalog_http_requests;

		return array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( array( $this->valid_catalog_entry() ) ),
		);
	}

	/**
	 * Arrange for another owner to replace the lock while the first owner releases it.
	 *
	 * @param false|array $response    Response.
	 * @param array       $parsed_args Args.
	 * @param string      $url         URL.
	 */
	public function mock_catalog_response_with_release_takeover( $response, $parsed_args, $url ) {
		if ( false === strpos( $url, 'catalog.json' ) ) {
			return $response;
		}

		add_filter( 'query', array( $this, 'mock_replace_refresh_lock_before_write' ) );

		return array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( array( $this->valid_catalog_entry() ) ),
		);
	}

	/**
	 * Run a nested refresh immediately before the outer acquisition query executes.
	 *
	 * The nested owner's release is held so the paused outer acquisition observes its row.
	 *
	 * @param string $query Database query.
	 */
	public function mock_initial_acquisition_interleaving( $query ) {
		global $wpdb;

		if ( false === strpos( $query, 'INSERT' ) || false === strpos( $query, $wpdb->options ) || false === strpos( $query, Extensions::REFRESH_LOCK_KEY ) ) {
			return $query;
		}

		remove_filter( 'query', array( $this, 'mock_initial_acquisition_interleaving' ) );
		add_filter( 'query', array( $this, 'mock_hold_refresh_lock_on_release' ) );
		$this->initial_acquisition_result = $this->service->refresh_catalog();
		remove_filter( 'query', array( $this, 'mock_hold_refresh_lock_on_release' ) );

		return $query;
	}

	/**
	 * Hold the nested owner's lock row after its successful refresh.
	 *
	 * @param string $query Database query.
	 */
	public function mock_hold_refresh_lock_on_release( $query ) {
		global $wpdb;

		if ( false === strpos( $query, 'DELETE FROM' ) || false === strpos( $query, $wpdb->options ) || false === strpos( $query, Extensions::REFRESH_LOCK_KEY ) ) {
			return $query;
		}

		return 'SELECT 0';
	}

	/**
	 * Test that the service returns an array.
	 */
	public function test_get_catalog_returns_array(): void {
		// Mock the remote fetch to return known data.
		add_filter( 'pre_http_request', array( $this, 'mock_catalog_response' ), 10, 3 );

		$catalog = $this->service->get_catalog();

		$this->assertIsArray( $catalog );
		$this->assertCount( 2, $catalog );
		$this->assertEquals( 'wcpos-stripe-terminal', $catalog[0]['slug'] );

		remove_filter( 'pre_http_request', array( $this, 'mock_catalog_response' ) );
	}

	/**
	 * Test that the catalog is cached in a transient.
	 */
	public function test_get_catalog_uses_transient_cache(): void {
		add_filter( 'pre_http_request', array( $this, 'mock_catalog_response' ), 10, 3 );

		// First call — fetches and caches.
		$this->service->get_catalog();

		remove_filter( 'pre_http_request', array( $this, 'mock_catalog_response' ) );

		// Second call — should use cache, not HTTP.
		add_filter( 'pre_http_request', array( $this, 'mock_catalog_failure' ), 10, 3 );

		$catalog = $this->service->get_catalog();
		$this->assertIsArray( $catalog );
		$this->assertCount( 2, $catalog );

		remove_filter( 'pre_http_request', array( $this, 'mock_catalog_failure' ) );
	}

	/**
	 * Test that catalog cache TTL is one hour.
	 */
	public function test_catalog_cache_ttl_is_one_hour(): void {
		$this->assertSame( HOUR_IN_SECONDS, Extensions::CACHE_TTL );
	}

	/**
	 * Test that HTTP failure returns empty array.
	 */
	public function test_get_catalog_returns_empty_on_failure(): void {
		add_filter( 'pre_http_request', array( $this, 'mock_catalog_failure' ), 10, 3 );

		$catalog = $this->service->get_catalog();
		$this->assertIsArray( $catalog );
		$this->assertEmpty( $catalog );

		remove_filter( 'pre_http_request', array( $this, 'mock_catalog_failure' ) );
	}

	/**
	 * Test get_extensions enriches catalog with local status.
	 */
	public function test_get_extensions_includes_status(): void {
		add_filter( 'pre_http_request', array( $this, 'mock_catalog_response' ), 10, 3 );

		$extensions = $this->service->get_extensions();

		$this->assertIsArray( $extensions );
		foreach ( $extensions as $ext ) {
			$this->assertArrayHasKey( 'status', $ext );
			$this->assertContains( $ext['status'], array( 'not_installed', 'inactive', 'active', 'update_available' ) );
		}

		remove_filter( 'pre_http_request', array( $this, 'mock_catalog_response' ) );
	}

	/**
	 * Test not_installed status for plugins not present locally.
	 */
	public function test_status_not_installed_for_absent_plugin(): void {
		$this->mock_catalog_data = array(
			array(
				'slug'           => 'nonexistent-plugin-xyz',
				'name'           => 'Nonexistent',
				'latest_version' => '1.0.0',
			),
		);
		add_filter( 'pre_http_request', array( $this, 'mock_dynamic_response' ), 10, 3 );

		$extensions = $this->service->get_extensions();

		$this->assertCount( 1, $extensions );
		$this->assertEquals( 'not_installed', $extensions[0]['status'] );
		$this->assertArrayNotHasKey( 'installed_version', $extensions[0] );

		remove_filter( 'pre_http_request', array( $this, 'mock_dynamic_response' ) );
	}

	/**
	 * Test active status for installed, active, up-to-date plugin.
	 */
	public function test_status_active_for_current_plugin(): void {
		// PHPUnit bootstrap loads plugins directly; active_plugins option may be empty.
		update_option( 'active_plugins', array( 'woocommerce/woocommerce.php' ) );

		$this->mock_catalog_data = array(
			array(
				'slug'           => 'woocommerce',
				'name'           => 'WooCommerce',
				'latest_version' => '0.0.1',
			),
		);
		add_filter( 'pre_http_request', array( $this, 'mock_dynamic_response' ), 10, 3 );

		$extensions = $this->service->get_extensions();

		$this->assertCount( 1, $extensions );
		$this->assertEquals( 'active', $extensions[0]['status'] );
		$this->assertArrayHasKey( 'installed_version', $extensions[0] );
		$this->assertArrayHasKey( 'plugin_file', $extensions[0] );
		$this->assertArrayNotHasKey( 'has_update', $extensions[0] );

		remove_filter( 'pre_http_request', array( $this, 'mock_dynamic_response' ) );
	}

	/**
	 * Test update_available status for active plugin with newer remote version.
	 */
	public function test_status_update_available_for_active_outdated_plugin(): void {
		update_option( 'active_plugins', array( 'woocommerce/woocommerce.php' ) );

		$this->mock_catalog_data = array(
			array(
				'slug'           => 'woocommerce',
				'name'           => 'WooCommerce',
				'latest_version' => '999.0.0',
			),
		);
		add_filter( 'pre_http_request', array( $this, 'mock_dynamic_response' ), 10, 3 );

		$extensions = $this->service->get_extensions();

		$this->assertCount( 1, $extensions );
		$this->assertEquals( 'update_available', $extensions[0]['status'] );
		$this->assertArrayHasKey( 'has_update', $extensions[0] );
		$this->assertTrue( $extensions[0]['has_update'] );

		remove_filter( 'pre_http_request', array( $this, 'mock_dynamic_response' ) );
	}

	/**
	 * Test inactive status for inactive plugin even with newer remote version.
	 * Only active plugins should show update_available, but has_update should be set.
	 */
	public function test_status_inactive_for_inactive_outdated_plugin(): void {
		update_option( 'active_plugins', array() );

		$this->mock_catalog_data = array(
			array(
				'slug'           => 'woocommerce',
				'name'           => 'WooCommerce',
				'latest_version' => '999.0.0',
			),
		);
		add_filter( 'pre_http_request', array( $this, 'mock_dynamic_response' ), 10, 3 );

		$extensions = $this->service->get_extensions();

		$this->assertCount( 1, $extensions );
		$this->assertEquals( 'inactive', $extensions[0]['status'] );
		$this->assertArrayHasKey( 'has_update', $extensions[0] );
		$this->assertTrue( $extensions[0]['has_update'] );

		remove_filter( 'pre_http_request', array( $this, 'mock_dynamic_response' ) );
	}

	/**
	 * Test inactive status for installed but deactivated plugin.
	 */
	public function test_status_inactive_for_deactivated_plugin(): void {
		update_option( 'active_plugins', array() );

		$this->mock_catalog_data = array(
			array(
				'slug'           => 'woocommerce',
				'name'           => 'WooCommerce',
				'latest_version' => '0.0.1',
			),
		);
		add_filter( 'pre_http_request', array( $this, 'mock_dynamic_response' ), 10, 3 );

		$extensions = $this->service->get_extensions();

		$this->assertCount( 1, $extensions );
		$this->assertEquals( 'inactive', $extensions[0]['status'] );

		remove_filter( 'pre_http_request', array( $this, 'mock_dynamic_response' ) );
	}

	/**
	 * Test that activating a plugin clears the extensions catalog cache.
	 */
	public function test_clear_cache_on_plugin_activation(): void {
		set_transient( 'wcpos_extensions_catalog', array( 'dummy' ), 3600 );
		$this->assertNotFalse( get_transient( 'wcpos_extensions_catalog' ) );

		do_action( 'activated_plugin', 'some-plugin/some-plugin.php', false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- testing WP core hook.

		$this->assertFalse( get_transient( 'wcpos_extensions_catalog' ) );
	}

	/**
	 * Test that deactivating a plugin clears the extensions catalog cache.
	 */
	public function test_clear_cache_on_plugin_deactivation(): void {
		set_transient( 'wcpos_extensions_catalog', array( 'dummy' ), 3600 );
		$this->assertNotFalse( get_transient( 'wcpos_extensions_catalog' ) );

		do_action( 'deactivated_plugin', 'some-plugin/some-plugin.php', false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- testing WP core hook.

		$this->assertFalse( get_transient( 'wcpos_extensions_catalog' ) );
	}

	/**
	 * Test auto_update is true when plugin is in auto_update_plugins option.
	 */
	public function test_auto_update_true_when_in_option(): void {
		update_option( 'active_plugins', array( 'woocommerce/woocommerce.php' ) );
		update_site_option( 'auto_update_plugins', array( 'woocommerce/woocommerce.php' ) );

		$this->mock_catalog_data = array(
			array(
				'slug'           => 'woocommerce',
				'name'           => 'WooCommerce',
				'latest_version' => '0.0.1',
			),
		);
		add_filter( 'pre_http_request', array( $this, 'mock_dynamic_response' ), 10, 3 );

		$extensions = $this->service->get_extensions();

		$this->assertCount( 1, $extensions );
		$this->assertArrayHasKey( 'auto_update', $extensions[0] );
		$this->assertTrue( $extensions[0]['auto_update'] );

		remove_filter( 'pre_http_request', array( $this, 'mock_dynamic_response' ) );
	}

	/**
	 * Test auto_update is false when plugin is not in auto_update_plugins option.
	 */
	public function test_auto_update_false_when_not_in_option(): void {
		update_option( 'active_plugins', array( 'woocommerce/woocommerce.php' ) );
		update_site_option( 'auto_update_plugins', array() );

		$this->mock_catalog_data = array(
			array(
				'slug'           => 'woocommerce',
				'name'           => 'WooCommerce',
				'latest_version' => '0.0.1',
			),
		);
		add_filter( 'pre_http_request', array( $this, 'mock_dynamic_response' ), 10, 3 );

		$extensions = $this->service->get_extensions();

		$this->assertCount( 1, $extensions );
		$this->assertArrayHasKey( 'auto_update', $extensions[0] );
		$this->assertFalse( $extensions[0]['auto_update'] );

		remove_filter( 'pre_http_request', array( $this, 'mock_dynamic_response' ) );
	}

	/**
	 * Test auto_update is not set for uninstalled extensions.
	 */
	public function test_auto_update_not_set_when_not_installed(): void {
		$this->mock_catalog_data = array(
			array(
				'slug'           => 'nonexistent-plugin-xyz',
				'name'           => 'Nonexistent',
				'latest_version' => '1.0.0',
			),
		);
		add_filter( 'pre_http_request', array( $this, 'mock_dynamic_response' ), 10, 3 );

		$extensions = $this->service->get_extensions();

		$this->assertCount( 1, $extensions );
		$this->assertArrayNotHasKey( 'auto_update', $extensions[0] );

		remove_filter( 'pre_http_request', array( $this, 'mock_dynamic_response' ) );
	}

	/**
	 * Mock catalog response using dynamic data from $this->mock_catalog_data.
	 *
	 * @param false|array $response    Response.
	 * @param array       $parsed_args Args.
	 * @param string      $url         URL.
	 *
	 * @return array|false
	 */
	public function mock_dynamic_response( $response, $parsed_args, $url ) {
		if ( false === strpos( $url, 'catalog.json' ) ) {
			return $response;
		}

		return array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( $this->mock_catalog_data ),
		);
	}

	/**
	 * Mock a successful catalog HTTP response.
	 *
	 * @param false|array $response    Response.
	 * @param array       $parsed_args Args.
	 * @param string      $url         URL.
	 *
	 * @return array
	 */
	public function mock_catalog_response( $response, $parsed_args, $url ) {
		if ( false === strpos( $url, 'catalog.json' ) ) {
			return $response;
		}

		return array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					array(
						'slug'           => 'wcpos-stripe-terminal',
						'name'           => 'Stripe Terminal',
						'description'    => 'Accept in-person card payments.',
						'version'        => '1.2.0',
						'author'         => 'wcpos',
						'category'       => 'payments',
						'tags'           => array( 'stripe', 'terminal' ),
						'requires_wp'    => '6.0',
						'requires_wc'    => '8.0',
						'requires_wcpos' => '1.7',
						'requires_pro'   => true,
						'icon'           => 'https://raw.githubusercontent.com/wcpos/wcpos-stripe-terminal/main/assets/icon-128x128.png',
						'homepage'       => 'https://wcpos.com/extensions/stripe-terminal',
						'download_url'   => 'https://github.com/wcpos/wcpos-stripe-terminal/releases/download/v1.2.0/wcpos-stripe-terminal.zip',
						'latest_version' => '1.2.0',
						'released_at'    => '2026-01-15T10:00:00Z',
					),
					array(
						'slug'           => 'wcpos-bookings',
						'name'           => 'Bookings',
						'description'    => 'WooCommerce Bookings integration.',
						'version'        => '1.0.0',
						'author'         => 'wcpos',
						'category'       => 'integrations',
						'tags'           => array( 'bookings' ),
						'requires_wp'    => '6.0',
						'requires_wc'    => '8.0',
						'requires_wcpos' => '1.7',
						'requires_pro'   => true,
						'icon'           => '',
						'homepage'       => '',
						'download_url'   => 'https://github.com/wcpos/wcpos-bookings/releases/download/v1.0.0/wcpos-bookings.zip',
						'latest_version' => '1.0.0',
						'released_at'    => '2026-01-10T10:00:00Z',
					),
				)
			),
		);
	}

	/**
	 * Mock a failed catalog HTTP response.
	 *
	 * @param false|array $response    Response.
	 * @param array       $parsed_args Args.
	 * @param string      $url         URL.
	 *
	 * @return \WP_Error
	 */
	public function mock_catalog_failure( $response, $parsed_args, $url ) {
		if ( false === strpos( $url, 'catalog.json' ) ) {
			return $response;
		}

		return new \WP_Error( 'http_request_failed', 'Connection timed out' );
	}
}
