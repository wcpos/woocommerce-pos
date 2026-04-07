<?php
/**
 * Tests for the shared store logo resolver.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Store_Logo_Resolver;
use WC_REST_Unit_Test_Case;

/**
 * Test_Store_Logo_Resolver class.
 *
 * @internal
 *
 * @coversDefaultClass \WCPOS\WooCommercePOS\Services\Store_Logo_Resolver
 */
class Test_Store_Logo_Resolver extends WC_REST_Unit_Test_Case {
	/**
	 * Test that site logo is returned when store has not opted out.
	 */
	public function test_resolve_returns_site_logo_when_not_opted_out(): void {
		$store_id = $this->factory->post->create();
		$logo_url = 'https://example.com/site-logo.png';
		$logo_id  = 987654;

		$image_downsize_filter = static function ( $out, $id ) use ( $logo_id, $logo_url ) {
			if ( $logo_id !== (int) $id ) {
				return $out;
			}

			return array( $logo_url, 320, 120, true );
		};

		$pos_store = new class( $store_id ) {
			/**
			 * Store ID.
			 *
			 * @var int
			 */
			private int $id;

			/**
			 * Constructor.
			 *
			 * @param int $id Store ID.
			 */
			public function __construct( int $id ) {
				$this->id = $id;
			}

			/**
			 * Get store ID.
			 *
			 * @return int
			 */
			public function get_id(): int {
				return $this->id;
			}
		};

		try {
			set_theme_mod( 'custom_logo', $logo_id );
			add_filter( 'image_downsize', $image_downsize_filter, 10, 3 );

			$this->assertSame( $logo_url, Store_Logo_Resolver::resolve( $pos_store ) );
		} finally {
			remove_filter( 'image_downsize', $image_downsize_filter, 10 );
			remove_theme_mod( 'custom_logo' );
		}
	}

	/**
	 * Test that site logo is hidden when store opts out.
	 */
	public function test_resolve_returns_null_when_store_opts_out(): void {
		$store_id = $this->factory->post->create();
		$logo_id  = 987655;

		update_post_meta( $store_id, '_use_site_logo', 'no' );

		$image_downsize_filter = static function ( $out, $id ) use ( $logo_id ) {
			if ( $logo_id !== (int) $id ) {
				return $out;
			}

			return array( 'https://example.com/hidden.png', 320, 120, true );
		};

		$pos_store = new class( $store_id ) {
			/**
			 * Store ID.
			 *
			 * @var int
			 */
			private int $id;

			/**
			 * Constructor.
			 *
			 * @param int $id Store ID.
			 */
			public function __construct( int $id ) {
				$this->id = $id;
			}

			/**
			 * Get store ID.
			 *
			 * @return int
			 */
			public function get_id(): int {
				return $this->id;
			}
		};

		try {
			set_theme_mod( 'custom_logo', $logo_id );
			add_filter( 'image_downsize', $image_downsize_filter, 10, 3 );

			$this->assertNull( Store_Logo_Resolver::resolve( $pos_store ) );
		} finally {
			remove_filter( 'image_downsize', $image_downsize_filter, 10 );
			remove_theme_mod( 'custom_logo' );
		}
	}

	/**
	 * Test that explicit store logo takes precedence over site logo.
	 */
	public function test_resolve_prefers_explicit_store_logo(): void {
		$store_id       = $this->factory->post->create();
		$store_logo_url = 'https://example.com/store-logo.png';
		$site_logo_url  = 'https://example.com/site-logo.png';
		$store_thumb_id = $this->factory->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.jpg', $store_id );
		$site_logo_id   = 987656;

		set_post_thumbnail( $store_id, $store_thumb_id );

		$image_downsize_filter = static function ( $out, $id ) use ( $store_thumb_id, $store_logo_url, $site_logo_id, $site_logo_url ) {
			if ( $store_thumb_id === (int) $id ) {
				return array( $store_logo_url, 320, 120, true );
			}
			if ( $site_logo_id === (int) $id ) {
				return array( $site_logo_url, 320, 120, true );
			}

			return $out;
		};

		$pos_store = new class( $store_id ) {
			/**
			 * Store ID.
			 *
			 * @var int
			 */
			private int $id;

			/**
			 * Constructor.
			 *
			 * @param int $id Store ID.
			 */
			public function __construct( int $id ) {
				$this->id = $id;
			}

			/**
			 * Get store ID.
			 *
			 * @return int
			 */
			public function get_id(): int {
				return $this->id;
			}
		};

		try {
			set_theme_mod( 'custom_logo', $site_logo_id );
			add_filter( 'image_downsize', $image_downsize_filter, 10, 3 );

			$this->assertSame( $store_logo_url, Store_Logo_Resolver::resolve( $pos_store ) );
		} finally {
			remove_filter( 'image_downsize', $image_downsize_filter, 10 );
			remove_theme_mod( 'custom_logo' );
		}
	}

	/**
	 * Test that resolve returns null when no logos are configured.
	 */
	public function test_resolve_returns_null_when_no_logos(): void {
		$store_id = $this->factory->post->create();

		$pos_store = new class( $store_id ) {
			/**
			 * Store ID.
			 *
			 * @var int
			 */
			private int $id;

			/**
			 * Constructor.
			 *
			 * @param int $id Store ID.
			 */
			public function __construct( int $id ) {
				$this->id = $id;
			}

			/**
			 * Get store ID.
			 *
			 * @return int
			 */
			public function get_id(): int {
				return $this->id;
			}
		};

		remove_theme_mod( 'custom_logo' );
		$this->assertNull( Store_Logo_Resolver::resolve( $pos_store ) );
	}
}
