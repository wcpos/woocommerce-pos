<?php
/**
 * Default Templates Handler.
 *
 * Handles creation and migration of default templates.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS\Templates;

class Defaults {
	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_notices', array( $this, 'maybe_show_migration_notice' ) );
		add_action( 'admin_post_wcpos_create_default_templates', array( $this, 'create_default_templates' ) );
	}

	/**
	 * Check if default templates exist.
	 *
	 * @return bool True if default templates exist, false otherwise.
	 */
	public static function default_templates_exist(): bool {
		$args = array(
			'post_type'      => 'wcpos_template',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'meta_query'     => array(
				array(
					'key'   => '_template_default',
					'value' => '1',
				),
			),
		);

		$query = new \WP_Query( $args );

		return $query->have_posts();
	}

	/**
	 * Maybe show migration notice.
	 *
	 * @return void
	 */
	public function maybe_show_migration_notice(): void {
		// Only show on relevant admin pages
		$screen = get_current_screen();
		if ( ! $screen || ! \in_array( $screen->id, array( 'edit-wcpos_template', 'wcpos_template' ), true ) ) {
			return;
		}

		// Check if user has capability
		if ( ! current_user_can( 'manage_woocommerce_pos' ) ) {
			return;
		}

		// Check if default templates already exist
		if ( self::default_templates_exist() ) {
			return;
		}

		// Check if notice was dismissed
		if ( get_option( 'wcpos_default_templates_notice_dismissed', false ) ) {
			return;
		}

		$create_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=wcpos_create_default_templates' ),
			'wcpos_create_default_templates'
		);

		?>
		<div class="notice notice-info">
			<p>
				<strong><?php esc_html_e( 'WooCommerce POS Templates', 'woocommerce-pos' ); ?></strong><br>
				<?php esc_html_e( 'Default receipt templates are not yet created. Would you like to create them now?', 'woocommerce-pos' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( $create_url ); ?>" class="button button-primary">
					<?php esc_html_e( 'Create Default Templates', 'woocommerce-pos' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'wcpos_dismiss_templates_notice', '1' ) ); ?>" class="button">
					<?php esc_html_e( 'Dismiss', 'woocommerce-pos' ); ?>
				</a>
			</p>
		</div>
		<?php

		// Handle dismiss
		if ( isset( $_GET['wcpos_dismiss_templates_notice'] ) ) {
			update_option( 'wcpos_default_templates_notice_dismissed', true );
		}
	}

	/**
	 * Create default templates.
	 *
	 * @return void
	 */
	public function create_default_templates(): void {
		// Verify nonce
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'wcpos_create_default_templates' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'woocommerce-pos' ) );
		}

		// Check capability
		if ( ! current_user_can( 'manage_woocommerce_pos' ) ) {
			wp_die( esc_html__( 'You do not have permission to create templates.', 'woocommerce-pos' ) );
		}

		// Create default receipt templates
		$created = $this->create_default_receipt_templates();

		// Redirect back with success message
		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'               => 'wcpos_template',
					'wcpos_templates_created' => $created,
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Get default template content.
	 *
	 * @param string $type Template type (receipt, report).
	 *
	 * @return false|string Template content or false if not found.
	 */
	public static function get_default_template_content( string $type ) {
		if ( 'receipt' === $type ) {
			// Try Pro first
			if ( \defined( 'WCPOS\WooCommercePOSPro\PLUGIN_PATH' ) ) {
				$pro_path = \WCPOS\WooCommercePOSPro\PLUGIN_PATH . 'templates/receipt.php';
				if ( file_exists( $pro_path ) ) {
					return file_get_contents( $pro_path );
				}
			}

			// Fall back to core
			$core_path = \WCPOS\WooCommercePOS\PLUGIN_PATH . 'templates/receipt.php';
			if ( file_exists( $core_path ) ) {
				return file_get_contents( $core_path );
			}
		}

		return false;
	}

	/**
	 * Create a sample report template.
	 *
	 * @return string Sample report template content.
	 */
	public static function get_sample_report_template(): string {
		return <<<'JAVASCRIPT'
// Sample Report Template
// This template will be executed in the React application

export default function ReportTemplate({ data, options }) {
  return (
    <div className="wcpos-report">
      <h2>{options.title || 'Sales Report'}</h2>
      
      <div className="report-summary">
        <div className="summary-item">
          <span className="label">Total Sales:</span>
          <span className="value">{data.totalSales}</span>
        </div>
        
        <div className="summary-item">
          <span className="label">Total Orders:</span>
          <span className="value">{data.totalOrders}</span>
        </div>
        
        <div className="summary-item">
          <span className="label">Average Order Value:</span>
          <span className="value">{data.averageOrderValue}</span>
        </div>
      </div>
      
      <div className="report-details">
        <table>
          <thead>
            <tr>
              <th>Date</th>
              <th>Orders</th>
              <th>Sales</th>
            </tr>
          </thead>
          <tbody>
            {data.items.map((item, index) => (
              <tr key={index}>
                <td>{item.date}</td>
                <td>{item.orders}</td>
                <td>{item.sales}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
JAVASCRIPT;
	}

	/**
	 * Create default receipt templates.
	 *
	 * @return int Number of templates created.
	 */
	private function create_default_receipt_templates(): int {
		$created = 0;

		// Create default receipt template from core
		$core_receipt_path = \WCPOS\WooCommercePOS\PLUGIN_PATH . 'templates/receipt.php';
		if ( file_exists( $core_receipt_path ) ) {
			$content = file_get_contents( $core_receipt_path );
			$post_id = $this->create_default_template(
				__( 'Default Receipt Template', 'woocommerce-pos' ),
				$content,
				'receipt',
				'php',
				$core_receipt_path
			);

			if ( $post_id ) {
				$created++;
				// Set as active template
				\WCPOS\WooCommercePOS\Templates::set_active_template( $post_id );
			}
		}

		// Create Pro receipt template if Pro is active
		if ( \defined( 'WCPOS\WooCommercePOSPro\PLUGIN_PATH' ) ) {
			$pro_receipt_path = \WCPOS\WooCommercePOSPro\PLUGIN_PATH . 'templates/receipt.php';
			if ( file_exists( $pro_receipt_path ) ) {
				$content = file_get_contents( $pro_receipt_path );
				$post_id = $this->create_default_template(
					__( 'Default Receipt Template (Pro)', 'woocommerce-pos' ),
					$content,
					'receipt',
					'php',
					$pro_receipt_path
				);

				if ( $post_id ) {
					$created++;
				}
			}
		}

		return $created;
	}

	/**
	 * Create a default template.
	 *
	 * @param string $title     Template title.
	 * @param string $content   Template content.
	 * @param string $type      Template type (receipt, report).
	 * @param string $language  Template language (php, javascript).
	 * @param string $file_path Optional file path.
	 *
	 * @return false|int Post ID on success, false on failure.
	 */
	private function create_default_template( string $title, string $content, string $type, string $language, string $file_path = '' ) {
		// Check if template already exists
		$existing = get_posts(
			array(
				'post_type'      => 'wcpos_template',
				'post_status'    => 'any',
				'title'          => $title,
				'posts_per_page' => 1,
			)
		);

		if ( ! empty( $existing ) ) {
			return false; // Template already exists
		}

		// Create the post
		$post_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_content' => $content,
				'post_type'    => 'wcpos_template',
				'post_status'  => 'publish',
				'post_author'  => get_current_user_id(),
			)
		);

		if ( is_wp_error( $post_id ) ) {
			return false;
		}

		// Set taxonomy
		wp_set_object_terms( $post_id, $type, 'wcpos_template_type' );

		// Set meta data
		update_post_meta( $post_id, '_template_language', $language );
		update_post_meta( $post_id, '_template_default', '1' );

		if ( ! empty( $file_path ) ) {
			update_post_meta( $post_id, '_template_file_path', $file_path );
		}

		return $post_id;
	}
}
