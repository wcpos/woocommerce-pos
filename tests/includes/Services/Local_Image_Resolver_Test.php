<?php
/**
 * Tests for local image resolution.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Local_Image_Resolver;
use WP_UnitTestCase;

/**
 * Local_Image_Resolver_Test class.
 */
class Local_Image_Resolver_Test extends WP_UnitTestCase {

	/**
	 * Resolver under test.
	 *
	 * @var Local_Image_Resolver
	 */
	private $resolver;

	/**
	 * Files written during a test, removed in tearDown.
	 *
	 * @var array<int, string>
	 */
	private $written = array();

	/**
	 * Set up the resolver.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->resolver = new Local_Image_Resolver();
		$this->written  = array();
	}

	/**
	 * Remove any files written by a test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		foreach ( $this->written as $file ) {
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}
		parent::tearDown();
	}

	/**
	 * Write a file into the uploads directory and return its path and URL.
	 *
	 * @param string $name     File name.
	 * @param string $contents File contents.
	 *
	 * @return array{path:string, url:string}
	 */
	private function write_upload( string $name, string $contents ): array {
		$uploads = wp_upload_dir();
		$path    = trailingslashit( $uploads['path'] ) . $name;
		file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$this->written[] = $path;

		return array(
			'path' => $path,
			'url'  => trailingslashit( $uploads['url'] ) . $name,
		);
	}

	/**
	 * It reads bytes for a local uploads URL.
	 */
	public function test_bytes_reads_a_local_uploads_url(): void {
		$file = $this->write_upload( 'wcpos-resolver.png', 'PNGBYTES' );

		$this->assertSame( 'PNGBYTES', $this->resolver->bytes( $file['url'] ) );
	}

	/**
	 * It decodes a data URI in place.
	 */
	public function test_bytes_decodes_a_data_uri(): void {
		$this->assertSame(
			'HELLO',
			$this->resolver->bytes( 'data:image/png;base64,' . base64_encode( 'HELLO' ) )
		);
	}

	/**
	 * It never fetches a remote URL.
	 */
	public function test_bytes_refuses_remote_urls(): void {
		$this->assertSame( '', $this->resolver->bytes( 'https://example.test/logo.png' ) );
		$this->assertSame( '', $this->resolver->bytes( '//example.test/logo.png' ) );
	}

	/**
	 * It refuses to walk outside the allowed roots.
	 */
	public function test_local_path_refuses_traversal(): void {
		$uploads = wp_upload_dir();

		$this->assertNull( $this->resolver->local_path( trailingslashit( $uploads['url'] ) . '../../../../etc/passwd' ) );
	}

	/**
	 * It ignores query strings and fragments when resolving.
	 */
	public function test_local_path_ignores_query_and_fragment(): void {
		$file = $this->write_upload( 'wcpos-resolver-query.png', 'BYTES' );

		$this->assertSame( 'BYTES', $this->resolver->bytes( $file['url'] . '?ver=2#frag' ) );
	}

	/**
	 * It returns null for a file that is not there.
	 */
	public function test_local_path_returns_null_for_a_missing_file(): void {
		$uploads = wp_upload_dir();

		$this->assertNull( $this->resolver->local_path( trailingslashit( $uploads['url'] ) . 'not-here.png' ) );
	}

	/**
	 * It maps the image types receipts actually use.
	 *
	 * SVG is load-bearing for PDF receipts: Dompdf renders it, and dropping it
	 * from this map makes a vector logo silently render blank.
	 */
	public function test_mime_type_covers_the_rendered_formats(): void {
		$this->assertSame( 'image/png', $this->resolver->mime_type( '/x/logo.png' ) );
		$this->assertSame( 'image/jpeg', $this->resolver->mime_type( '/x/logo.JPG' ) );
		$this->assertSame( 'image/jpeg', $this->resolver->mime_type( '/x/logo.jpeg' ) );
		$this->assertSame( 'image/gif', $this->resolver->mime_type( '/x/logo.gif' ) );
		$this->assertSame( 'image/webp', $this->resolver->mime_type( '/x/logo.webp' ) );
		$this->assertSame( 'image/svg+xml', $this->resolver->mime_type( '/x/logo.svg' ) );
		$this->assertNull( $this->resolver->mime_type( '/x/logo.txt' ) );
	}

	/**
	 * It builds a data URI for a local file.
	 */
	public function test_data_uri_embeds_a_local_file(): void {
		$file = $this->write_upload( 'wcpos-resolver-embed.png', 'BYTES' );

		$this->assertSame(
			'data:image/png;base64,' . base64_encode( 'BYTES' ),
			$this->resolver->data_uri( $file['url'] )
		);
	}

	/**
	 * It leaves an existing data URI alone.
	 */
	public function test_data_uri_ignores_a_data_uri(): void {
		$this->assertNull( $this->resolver->data_uri( 'data:image/png;base64,AAAA' ) );
	}
}
