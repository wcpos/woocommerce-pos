<?php
/**
 * Tests for Single_Template save flow.
 *
 * Verifies that raw HTML/XML template content survives the WordPress save
 * pipeline without entity-encoding or tag stripping.
 *
 * @package WCPOS\WooCommercePOS\Tests\Admin\Templates
 */

namespace WCPOS\WooCommercePOS\Tests\Admin\Templates;

use WCPOS\WooCommercePOS\Admin\Templates\Single_Template;
use WCPOS\WooCommercePOS\Templates as TemplatesManager;
use WC_REST_Unit_Test_Case;

/**
 * Test_Single_Template_Save class.
 *
 * @internal
 *
 * @coversNothing
 */
class Test_Single_Template_Save extends WC_REST_Unit_Test_Case {

	/**
	 * Sample XML content for thermal templates.
	 *
	 * @var string
	 */
	private $sample_xml = '<receipt paper-width="32">
  <align mode="center">
    <bold><size width="2" height="2">{{store.name}}</size></bold>
  </align>
  <line />
  {{#lines}}
  <text>{{name}}</text>
  <row>
    <col width="6">x{{qty}}</col>
    <col width="16" align="right">{{line_total_incl}}</col>
  </row>
  {{/lines}}
  <cut />
</receipt>';

	/**
	 * Sample HTML content for logicless templates.
	 *
	 * @var string
	 */
	private $sample_html = '<div class="receipt">
  <h1>{{store.name}}</h1>
  <table>
    <thead><tr><th>Item</th><th>Total</th></tr></thead>
    <tbody>
      {{#lines}}
      <tr><td>{{name}}</td><td>{{line_total_incl}}</td></tr>
      {{/lines}}
    </tbody>
  </table>
  <p><strong>Total: {{totals.grand_total_incl}}</strong></p>
</div>';

	/**
	 * Stored map_meta_cap callback for tearDown removal.
	 *
	 * @var null|callable
	 */
	private $meta_cap_filter = null;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Grant the POS management capability.
		$user = wp_get_current_user();
		$user->add_cap( 'manage_woocommerce_pos' );
	}

	/**
	 * Clean up globals mutated during tests.
	 */
	public function tearDown(): void {
		// Remove any save_post hooks registered by Single_Template instances.
		remove_all_filters( 'save_post_wcpos_template' );
		remove_all_filters( 'use_block_editor_for_post_type' );
		remove_all_filters( 'user_can_richedit' );

		// Remove the map_meta_cap filter added by remove_unfiltered_html().
		if ( $this->meta_cap_filter ) {
			remove_filter( 'map_meta_cap', $this->meta_cap_filter, 10 );
			$this->meta_cap_filter = null;
		}

		// Restore default kses state.
		kses_remove_filters();

		// Clear any leftover POST data.
		$this->cleanup_post_globals();

		parent::tearDown();
	}

	/**
	 * Create a template post with the given engine and raw content.
	 *
	 * Uses $wpdb->update() to bypass wp_kses so the initial state is clean.
	 *
	 * @param string $engine      Template engine (logicless, thermal, legacy-php).
	 * @param string $raw_content Raw template content.
	 *
	 * @return int Post ID.
	 */
	private function create_template( string $engine, string $raw_content ): int {
		$post_id = $this->factory->post->create(
			array(
				'post_type'    => 'wcpos_template',
				'post_status'  => 'publish',
				'post_title'   => 'Test Template',
				'post_content' => '', // Start empty, set raw content below.
			)
		);

		// Write raw content directly to bypass wp_kses.
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			array( 'post_content' => $raw_content ),
			array( 'ID' => $post_id ),
			array( '%s' ),
			array( '%d' )
		);
		clean_post_cache( $post_id );

		// Set engine metadata.
		update_post_meta( $post_id, '_template_engine', $engine );
		$language_map = array(
			'thermal'    => 'xml',
			'logicless'  => 'html',
			'legacy-php' => 'php',
		);
		update_post_meta( $post_id, '_template_language', $language_map[ $engine ] ?? 'php' );
		update_post_meta( $post_id, '_template_output_type', 'thermal' === $engine ? 'escpos' : 'html' );

		wp_set_object_terms( $post_id, 'receipt', 'wcpos_template_type' );

		return $post_id;
	}

	/**
	 * Simulate an admin save by setting up $_POST and calling wp_update_post().
	 *
	 * This mirrors what happens when a user clicks "Update" on the edit screen:
	 * 1. WordPress processes the form via edit_post() -> wp_update_post()
	 * 2. wp_update_post() filters content through wp_kses (mangling HTML/XML)
	 * 3. save_post_wcpos_template hook fires (our save_post handler)
	 * 4. save_raw_content() should overwrite the mangled content
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $raw_content Raw content the user is saving.
	 * @param string $engine      Template engine.
	 */
	private function simulate_admin_save( int $post_id, string $raw_content, string $engine ): void {
		$nonce = wp_create_nonce( 'wcpos_template_settings' );

		// phpcs:disable WordPress.Security
		$_POST['wcpos_template_settings_nonce'] = $nonce;
		$_POST['content']                       = addslashes( $raw_content );
		$_POST['wcpos_template_engine']         = $engine;
		// phpcs:enable WordPress.Security

		// Create the handler (constructor registers hooks).
		$single_template = new Single_Template();

		// Trigger the full WordPress save pipeline — this is what mangles content.
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $raw_content,
			)
		);
	}

	/**
	 * Clean up $_POST after simulate_admin_save().
	 */
	private function cleanup_post_globals(): void {
		unset(
			$_POST['wcpos_template_settings_nonce'],
			$_POST['content'],
			$_POST['wcpos_template_engine']
		);
	}

	/**
	 * Test that saving a thermal (XML) template preserves raw XML content.
	 */
	public function test_save_thermal_template_preserves_raw_xml(): void {
		$post_id = $this->create_template( 'thermal', $this->sample_xml );

		// Verify the initial state is correct.
		$post = get_post( $post_id );
		$this->assertSame( $this->sample_xml, $post->post_content, 'Initial content should be raw XML' );

		// Simulate admin save.
		$this->simulate_admin_save( $post_id, $this->sample_xml, 'thermal' );
		$this->cleanup_post_globals();

		// Verify content survived the save.
		$saved_post = get_post( $post_id );
		$this->assertSame(
			$this->sample_xml,
			$saved_post->post_content,
			'XML content should be preserved after save — got entity-encoded or stripped content instead'
		);
	}

	/**
	 * Test that saving a logicless (HTML) template preserves raw HTML content.
	 */
	public function test_save_logicless_template_preserves_raw_html(): void {
		$post_id = $this->create_template( 'logicless', $this->sample_html );

		// Verify the initial state is correct.
		$post = get_post( $post_id );
		$this->assertSame( $this->sample_html, $post->post_content, 'Initial content should be raw HTML' );

		// Simulate admin save.
		$this->simulate_admin_save( $post_id, $this->sample_html, 'logicless' );
		$this->cleanup_post_globals();

		// Verify content survived the save.
		$saved_post = get_post( $post_id );
		$this->assertSame(
			$this->sample_html,
			$saved_post->post_content,
			'HTML content should be preserved after save — got entity-encoded or stripped content instead'
		);
	}

	/**
	 * Test that content survives multiple consecutive saves.
	 *
	 * This catches the case where the first save works but subsequent saves
	 * degrade the content (double-encoding, progressive mangling).
	 */
	public function test_content_survives_multiple_saves(): void {
		$post_id = $this->create_template( 'thermal', $this->sample_xml );

		for ( $i = 1; $i <= 3; $i++ ) {
			$this->simulate_admin_save( $post_id, $this->sample_xml, 'thermal' );
			$this->cleanup_post_globals();

			$saved_post = get_post( $post_id );
			$this->assertSame(
				$this->sample_xml,
				$saved_post->post_content,
				"Content should survive save #{$i} without degradation"
			);
		}
	}

	/**
	 * Test that installing a gallery template preserves raw content.
	 */
	public function test_install_gallery_template_preserves_content(): void {
		$gallery_templates = TemplatesManager::get_gallery_templates( 'receipt' );

		// Find a logicless gallery template.
		$logicless_gallery = null;
		foreach ( $gallery_templates as $template ) {
			if ( 'logicless' === ( $template['engine'] ?? '' ) ) {
				$logicless_gallery = $template;
				break;
			}
		}

		if ( ! $logicless_gallery ) {
			$this->markTestSkipped( 'No logicless gallery template found' );
		}

		$raw_content = $logicless_gallery['content'];
		$result      = TemplatesManager::install_gallery_template( $logicless_gallery['key'] );

		$this->assertIsInt( $result, 'install_gallery_template should return post ID' );

		$installed_post = get_post( $result );
		$this->assertSame(
			$raw_content,
			$installed_post->post_content,
			'Gallery template content should be preserved after installation'
		);
	}

	/**
	 * Test that installing a thermal gallery template preserves XML content.
	 */
	public function test_install_thermal_gallery_template_preserves_content(): void {
		$gallery_templates = TemplatesManager::get_gallery_templates( 'receipt' );

		// Find a thermal gallery template.
		$thermal_gallery = null;
		foreach ( $gallery_templates as $template ) {
			if ( 'thermal' === ( $template['engine'] ?? '' ) ) {
				$thermal_gallery = $template;
				break;
			}
		}

		if ( ! $thermal_gallery ) {
			$this->markTestSkipped( 'No thermal gallery template found' );
		}

		$raw_content = $thermal_gallery['content'];
		$result      = TemplatesManager::install_gallery_template( $thermal_gallery['key'] );

		$this->assertIsInt( $result, 'install_gallery_template should return post ID' );

		$installed_post = get_post( $result );
		$this->assertSame(
			$raw_content,
			$installed_post->post_content,
			'Thermal gallery template XML content should be preserved after installation'
		);
	}

	/**
	 * Simulate a user without unfiltered_html (multisite admin scenario).
	 *
	 * On multisite, even administrators don't have unfiltered_html.
	 * WordPress removes kses filters during init for users with the cap,
	 * so we must re-add them to simulate the restricted environment.
	 */
	private function remove_unfiltered_html(): void {
		$user = wp_get_current_user();
		$user->remove_cap( 'unfiltered_html' );

		// Block the capability check so current_user_can('unfiltered_html') returns false.
		// Store the callback so tearDown() can remove it.
		$this->meta_cap_filter = function ( $caps, $cap ) {
			if ( 'unfiltered_html' === $cap ) {
				$caps[] = 'do_not_allow';
			}
			return $caps;
		};
		add_filter( 'map_meta_cap', $this->meta_cap_filter, 10, 2 );

		// Re-add the kses content filters that WordPress removed during init.
		// This is what actually causes content mangling on multisite.
		kses_init_filters();
	}

	/**
	 * Test saving thermal template WITHOUT unfiltered_html (multisite scenario).
	 *
	 * This is the actual bug: on multisite, wp_kses mangles the XML content
	 * during wp_update_post(). save_raw_content() should still fix it.
	 */
	public function test_save_thermal_template_without_unfiltered_html(): void {
		$this->remove_unfiltered_html();

		$post_id = $this->create_template( 'thermal', $this->sample_xml );

		$this->simulate_admin_save( $post_id, $this->sample_xml, 'thermal' );
		$this->cleanup_post_globals();

		$saved_post = get_post( $post_id );
		$this->assertSame(
			$this->sample_xml,
			$saved_post->post_content,
			'XML content should survive save even without unfiltered_html capability'
		);
	}

	/**
	 * Test saving logicless template WITHOUT unfiltered_html (multisite scenario).
	 */
	public function test_save_logicless_template_without_unfiltered_html(): void {
		$this->remove_unfiltered_html();

		$post_id = $this->create_template( 'logicless', $this->sample_html );

		$this->simulate_admin_save( $post_id, $this->sample_html, 'logicless' );
		$this->cleanup_post_globals();

		$saved_post = get_post( $post_id );
		$this->assertSame(
			$this->sample_html,
			$saved_post->post_content,
			'HTML content should survive save even without unfiltered_html capability'
		);
	}

	/**
	 * Test installing a gallery template WITHOUT unfiltered_html.
	 */
	public function test_install_gallery_template_without_unfiltered_html(): void {
		$this->remove_unfiltered_html();

		$gallery_templates = TemplatesManager::get_gallery_templates( 'receipt' );

		$logicless_gallery = null;
		foreach ( $gallery_templates as $template ) {
			if ( 'logicless' === ( $template['engine'] ?? '' ) ) {
				$logicless_gallery = $template;
				break;
			}
		}

		if ( ! $logicless_gallery ) {
			$this->markTestSkipped( 'No logicless gallery template found' );
		}

		$raw_content = $logicless_gallery['content'];
		$result      = TemplatesManager::install_gallery_template( $logicless_gallery['key'] );

		$this->assertIsInt( $result, 'install_gallery_template should return post ID' );

		$installed_post = get_post( $result );
		$this->assertSame(
			$raw_content,
			$installed_post->post_content,
			'Gallery template content should be preserved even without unfiltered_html'
		);
	}

	/**
	 * Test the REAL admin save flow using edit_post().
	 *
	 * Edit_post() is what actually runs when you click "Update" in wp-admin.
	 * It does more processing than wp_update_post() alone — it reads from
	 * $_POST, translates post data, and handles nonces/capabilities.
	 */
	public function test_edit_post_flow_preserves_xml_content(): void {
		$post_id = $this->create_template( 'thermal', $this->sample_xml );

		// Create the handler so save_post hook is registered.
		new Single_Template();

		// Simulate the FULL admin form submission — exactly what the browser sends.
		$nonce = wp_create_nonce( 'update-post_' . $post_id );
		$settings_nonce = wp_create_nonce( 'wcpos_template_settings' );

		// phpcs:disable WordPress.Security
		$_POST = array(
			'post_ID'                          => $post_id,
			'post_type'                        => 'wcpos_template',
			'post_title'                       => 'Test Template',
			'content'                          => addslashes( $this->sample_xml ),
			'post_status'                      => 'publish',
			'_wpnonce'                         => $nonce,
			'originalaction'                   => 'editpost',
			'action'                           => 'editpost',
			'original_post_status'             => 'publish',
			'wcpos_template_settings_nonce'    => $settings_nonce,
			'wcpos_template_engine'            => 'thermal',
			'wcpos_template_paper_width'       => '80mm',
			'user_ID'                          => get_current_user_id(),
			'post_author'                      => get_current_user_id(),
		);

		// This is what WordPress admin calls when you click Update.
		edit_post();

		// phpcs:enable WordPress.Security

		$saved_post = get_post( $post_id );
		$this->assertSame(
			$this->sample_xml,
			$saved_post->post_content,
			'XML content should survive edit_post() flow'
		);
	}

	/**
	 * Test edit_post() flow WITHOUT unfiltered_html.
	 */
	public function test_edit_post_flow_without_unfiltered_html_preserves_xml(): void {
		$this->remove_unfiltered_html();

		$post_id = $this->create_template( 'thermal', $this->sample_xml );

		new Single_Template();

		$nonce          = wp_create_nonce( 'update-post_' . $post_id );
		$settings_nonce = wp_create_nonce( 'wcpos_template_settings' );

		// phpcs:disable WordPress.Security
		$_POST = array(
			'post_ID'                          => $post_id,
			'post_type'                        => 'wcpos_template',
			'post_title'                       => 'Test Template',
			'content'                          => addslashes( $this->sample_xml ),
			'post_status'                      => 'publish',
			'_wpnonce'                         => $nonce,
			'originalaction'                   => 'editpost',
			'action'                           => 'editpost',
			'original_post_status'             => 'publish',
			'wcpos_template_settings_nonce'    => $settings_nonce,
			'wcpos_template_engine'            => 'thermal',
			'wcpos_template_paper_width'       => '80mm',
			'user_ID'                          => get_current_user_id(),
			'post_author'                      => get_current_user_id(),
		);

		edit_post();

		// phpcs:enable WordPress.Security

		$saved_post = get_post( $post_id );
		$this->assertSame(
			$this->sample_xml,
			$saved_post->post_content,
			'XML content should survive edit_post() even without unfiltered_html'
		);
	}

	/**
	 * Diagnostic: what does wp_filter_post_kses actually do to XML template content?
	 *
	 * This test documents the exact mangling behavior so we know what to defend against.
	 */
	public function test_kses_behavior_on_xml_content(): void {
		$xml = '<receipt paper-width="32"><bold>{{store.name}}</bold><line /><cut /></receipt>';

		$filtered = wp_filter_post_kses( $xml );

		// Document what kses actually produces — is it stripped or entity-encoded?
		$has_entities = strpos( $filtered, '&lt;' ) !== false;
		$has_raw_tags = strpos( $filtered, '<receipt' ) !== false;

		// Log the actual output for debugging.
		fwrite( STDERR, "\n[KSES OUTPUT]: " . var_export( $filtered, true ) . "\n" );

		// At minimum, verify the content was modified (kses did something).
		$this->assertNotSame( $xml, $filtered, 'wp_filter_post_kses should modify unknown XML tags' );
	}

	/**
	 * Document what happens when $_POST['content'] arrives entity-encoded.
	 *
	 * This simulates a JS bug where the hidden textarea receives
	 * entity-encoded content. save_raw_content() writes whatever it
	 * receives, so the encoded markup ends up in the DB.
	 *
	 * Marked incomplete rather than green because this behavior should
	 * eventually be caught by client- or server-side validation.
	 */
	public function test_entity_encoded_post_content_gets_saved_as_is(): void {
		$post_id = $this->create_template( 'thermal', $this->sample_xml );

		$entity_encoded = htmlspecialchars( $this->sample_xml, ENT_QUOTES, 'UTF-8' );

		new Single_Template();

		$nonce          = wp_create_nonce( 'update-post_' . $post_id );
		$settings_nonce = wp_create_nonce( 'wcpos_template_settings' );

		// phpcs:disable WordPress.Security
		$_POST = array(
			'post_ID'                          => $post_id,
			'post_type'                        => 'wcpos_template',
			'post_title'                       => 'Test Template',
			'content'                          => addslashes( $entity_encoded ),
			'post_status'                      => 'publish',
			'_wpnonce'                         => $nonce,
			'originalaction'                   => 'editpost',
			'action'                           => 'editpost',
			'original_post_status'             => 'publish',
			'wcpos_template_settings_nonce'    => $settings_nonce,
			'wcpos_template_engine'            => 'thermal',
			'user_ID'                          => get_current_user_id(),
			'post_author'                      => get_current_user_id(),
		);

		edit_post();
		// phpcs:enable WordPress.Security

		$this->markTestIncomplete(
			'Documentary: entity-encoded payload is saved as-is. Should be validated/decoded client- or server-side.'
		);
	}

	/**
	 * Test that copying a template via the REST API preserves content.
	 */
	public function test_copy_template_preserves_content(): void {
		$post_id = $this->create_template( 'thermal', $this->sample_xml );

		// Copy via the controller.
		$request = new \WP_REST_Request( 'POST', '/wcpos/v1/templates/' . $post_id . '/copy' );
		$request->set_url_params( array( 'id' => $post_id ) );

		$controller = new \WCPOS\WooCommercePOS\API\Templates_Controller();

		// Register routes so the controller works.
		$controller->register_routes();

		$response = $controller->copy_item( $request );

		$this->assertNotWPError( $response );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'id', $data );

		$copied_post = get_post( $data['id'] );
		$this->assertSame(
			$this->sample_xml,
			$copied_post->post_content,
			'Copied template content should preserve raw XML'
		);
	}
}
