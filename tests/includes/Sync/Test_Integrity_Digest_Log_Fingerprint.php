<?php
/**
 * Regression coverage for digest failure log fingerprints.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting -- Compact regression coverage.

use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Services\Error_Reporter;
use WCPOS\WooCommercePOS\Services\Settings;
use WCPOS\WooCommercePOS\Sync\Integrity_Digest;
use WCPOS\WooCommercePOS\Tests\Services\Stub_Transport;

class Test_Integrity_Digest_Log_Fingerprint extends Sync_Store_Test_Case {

	private Stub_Transport $transport;
	private $query_filter;
	private $previous_suppress_errors;

	public function setUp(): void {
		parent::setUp();
		Error_Reporter::reset_instance();
		$this->transport = new Stub_Transport();
		Error_Reporter::set_transport_factory_for_testing(
			function (): Stub_Transport {
				return $this->transport;
			}
		);
		Error_Reporter::set_dev_override_for_testing( false );
		Logger::reset_dedup_state();
		delete_transient( Error_Reporter::BACKOFF_TRANSIENT );
		$settings                     = (array) wcpos_get_settings( 'general' );
		$settings['tracking_consent'] = 'allowed';
		Settings::instance()->save_settings( 'general', $settings );
		$this->previous_suppress_errors = $GLOBALS['wpdb']->suppress_errors( true );
	}

	public function tearDown(): void {
		remove_filter( 'query', $this->query_filter );
		$GLOBALS['wpdb']->suppress_errors( $this->previous_suppress_errors );
		Error_Reporter::set_transport_factory_for_testing( null );
		Error_Reporter::set_dev_override_for_testing( null );
		Error_Reporter::reset_instance();
		delete_transient( Error_Reporter::BACKOFF_TRANSIENT );
		$settings                     = (array) wcpos_get_settings( 'general' );
		$settings['tracking_consent'] = 'undecided';
		Settings::instance()->save_settings( 'general', $settings );
		Integrity_Digest::reset_request_state();
		parent::tearDown();
	}

	public function test_observer_failures_for_two_object_ids_share_one_fingerprint(): void {
		// Arrange: inject a real SQL syntax error containing each customer id.
		$a     = $this->factory->user->create( array( 'role' => 'customer' ) );
		$b     = $this->factory->user->create( array( 'role' => 'customer' ) );
		$table = ( new Integrity_Digest() )->table_name();
		$this->query_filter = static function ( string $query ) use ( $table ): string {
			if ( 0 === strpos( $query, 'DELETE FROM' ) && false !== strpos( $query, $table ) ) {
				return str_replace( '`object_id` = ', '`object_id` = = ', $query );
			}
			return $query;
		};
		add_filter( 'query', $this->query_filter );

		// Act: reset the per-request reporting budget between the two failures.
		( new Integrity_Digest() )->record_customer_deleted( $a );
		Error_Reporter::reset_instance();
		Logger::reset_dedup_state();
		( new Integrity_Digest() )->record_customer_deleted( $b );

		// Assert: the ids reach the event messages but never the fingerprint.
		$events = $this->transport->events;
		$this->assertCount( 2, $events );
		$this->assertStringContainsString( (string) $a, $events[0]->getMessage() );
		$this->assertStringContainsString( (string) $b, $events[1]->getMessage() );
		$this->assertSame(
			array( 'wcpos-log', 'error', 'Sync digest observer failed (sync will self-heal via scan/rebuild)' ),
			$events[0]->getFingerprint()
		);
		$this->assertSame( $events[0]->getFingerprint(), $events[1]->getFingerprint() );
	}

	public function test_scheduled_rebuild_failure_keeps_the_db_error_out_of_the_fingerprint(): void {
		// Arrange: point digest queries at a missing table.
		$table = ( new Integrity_Digest() )->table_name();
		$this->query_filter = static function ( string $query ) use ( $table ): string {
			return str_replace( $table, $table . '_missing', $query );
		};
		add_filter( 'query', $this->query_filter );

		// Act.
		Integrity_Digest::run_scheduled_rebuild();

		// Assert: the DB error stays visible only in the event message.
		$events = $this->transport->events;
		$this->assertCount( 1, $events );
		$this->assertSame(
			array( 'wcpos-log', 'error', 'WCPOS sync: scheduled integrity digest rebuild failed' ),
			$events[0]->getFingerprint()
		);
		$this->assertStringContainsString( '_missing', $events[0]->getMessage() );
	}
}
