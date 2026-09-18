<?php
/**
 * Create identity protocol tests without REST dispatch.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable Squiz.Commenting, Generic.Commenting, Generic.Files.OneObjectStructurePerFile, WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Compact protocol tests use the shared fake-store vocabulary.

use WCPOS\WooCommercePOS\API\V2\Writers\Null_Writer;
use WCPOS\WooCommercePOS\Interfaces\Collection_Writer_Interface;
use WCPOS\WooCommercePOS\Sync\Create_Identity;
use WP_Error;
use WP_UnitTestCase;

final class Create_Identity_Spy_Writer extends Null_Writer implements Collection_Writer_Interface {
	public array $calls = array();
	private Fake_Mutation_Store $store;

	public function __construct( Fake_Mutation_Store $store ) {
		$this->store = $store;
	}
	public function after_create( int $id, array $payload ): void {
		$this->record( __FUNCTION__, $id, $payload );
	}
	public function after_identity( int $id, array $payload ): void {
		$this->record( __FUNCTION__, $id, $payload );
	}
	public function after_recovery( int $id, array $payload ): void {
		$this->record( __FUNCTION__, $id, $payload );
	}
	public function after_update( int $id, array $payload, array $current, array $response_data, array $context ): void {
		$this->record( __FUNCTION__, $id, $payload );
	}
	private function record( string $method, int $id, array $payload ): void {
		$this->calls[] = array( $method, $id, $payload );
		$this->store->identity_trace[] = $method;
	}
}

/** @covers \WCPOS\WooCommercePOS\Sync\Create_Identity */
final class Test_Create_Identity extends WP_UnitTestCase {
	private const META = array( 'id_type' => 'user' );
	private const MUTATION = array(
		'mutationId' => 'a1b2c3d4-1111-4222-8333-444455556666',
		'recordId' => '5b8e1a3c-2f4d-4a6b-9c8e-1d2f3a4b5c6d',
		'payload' => array( 'email' => 'a@b.c' ),
	);
	private const HIT = array(
		'operation' => 'create',
		'remote_id' => 4242,
		'record_uuid' => self::MUTATION['recordId'],
		'status' => 'poison',
	);

	public function test_stamp_happy_path_proves_identity_before_finalization(): void {
		// Arrange.
		$store = new Fake_Mutation_Store();
		$writer = new Create_Identity_Spy_Writer( $store );
		// Act.
		$result = ( new Create_Identity( $store ) )->stamp( self::META, self::MUTATION, 4242, 201, $writer );
		// Assert.
		$this->assertSame( 4242, $result );
		$this->assertSame( array( 'mark_poison', 'after_create', 'persist_uuid', 'resolve_id_by_uuid', 'after_identity', 'finalize_poison' ), $store->identity_trace );
		$this->assertSame( array( array( 'after_create', 4242, self::MUTATION['payload'] ), array( 'after_identity', 4242, self::MUTATION['payload'] ) ), $writer->calls );
		$this->assertSame(
			array(
				array(
					'id_type' => 'user',
					'id' => 4242,
					'uuid' => self::MUTATION['recordId'],
				),
			),
			$store->persisted
		);
		$this->assertSame( array( self::MUTATION['mutationId'] => 4242 ), $store->finalized );
		$this->assertSame( 201, $store->lookups[ self::MUTATION['mutationId'] ]['response_status'] );
	}

	/** @dataProvider checkpoint_failures */
	public function test_stamp_checkpoint_failure_attempts_identity_before_finalize_error( bool $persist_ok ): void {
		// Arrange.
		$store = new Fake_Mutation_Store();
		$store->poisonOk = false;
		$store->persistUuidOk = $persist_ok;
		$writer = new Create_Identity_Spy_Writer( $store );
		// Act.
		$result = ( new Create_Identity( $store ) )->stamp( self::META, self::MUTATION, 4242, 202, $writer );
		// Assert.
		$this->assertSame( 'woo_rxdb_sync_finalize_failed', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
		$this->assertSame(
			array(
				array(
					'id_type' => 'user',
					'id' => 4242,
					'uuid' => self::MUTATION['recordId'],
				),
			),
			$store->persisted
		);
		$this->assertSame( 'mark_indeterminate', end( $store->identity_trace ) );
		$this->assertSame( array( array( 'after_create', 4242, self::MUTATION['payload'] ) ), $writer->calls );
		$this->assertSame( array(), $store->finalized );
		$this->assertSame(
			array(
				'remote_id' => 4242,
				'status' => 'blocked',
				'response_status' => 202,
			),
			$store->lookups[ self::MUTATION['mutationId'] ]
		);
	}

	public static function checkpoint_failures(): array {
		return array(
			'identity succeeds' => array( true ),
			'identity also fails' => array( false ),
		);
	}

	/** @dataProvider persistence_failures */
	public function test_identity_persistence_failure_returns_one_wording_without_finalizing( bool $recover, array $hit_override, bool $persist_ok, array $resolves, array $trace ): void {
		// Arrange.
		$store = new Fake_Mutation_Store();
		$store->persistUuidOk = $persist_ok;
		$store->resolveResults = $resolves;
		$writer = new Create_Identity_Spy_Writer( $store );
		$identity = new Create_Identity( $store );
		// Act.
		$result = $recover
			? $identity->recover( self::META, self::MUTATION, array_merge( self::HIT, $hit_override ), $writer )
			: $identity->stamp( self::META, self::MUTATION, 4242, 201, $writer );
		// Assert.
		$this->assertSame( 'woo_rxdb_sync_identity_persistence_failed', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
		$this->assertSame( 'Unable to persist created record identity.', $result->get_error_message() );
		$this->assertSame( $trace, $store->identity_trace );
		$this->assertSame( array(), $store->finalized );
		$this->assertSame( $recover ? array() : array( array( 'after_create', 4242, self::MUTATION['payload'] ) ), $writer->calls );
	}

	public static function persistence_failures(): array {
		return array(
			'fresh persist fails' => array( false, array(), false, array(), array( 'mark_poison', 'after_create', 'persist_uuid' ) ),
			'fresh resolves elsewhere' => array( false, array(), true, array( 99 ), array( 'mark_poison', 'after_create', 'persist_uuid', 'resolve_id_by_uuid' ) ),
			'retry not create' => array( true, array( 'operation' => 'update' ), true, array(), array() ),
			'retry no remote id' => array( true, array( 'remote_id' => 0 ), true, array(), array() ),
			'retry owned elsewhere' => array( true, array(), true, array( 99 ), array( 'resolve_id_by_uuid' ) ),
			'retry persist fails' => array( true, array(), false, array( 0 ), array( 'resolve_id_by_uuid', 'persist_uuid' ) ),
			'retry verification differs' => array( true, array(), true, array( 0, 99 ), array( 'resolve_id_by_uuid', 'persist_uuid', 'resolve_id_by_uuid' ) ),
		);
	}

	/** @dataProvider resolver_errors */
	public function test_identity_resolver_error_returns_original_error( bool $recover, bool $precheck ): void {
		// Arrange.
		$error = new WP_Error( 'ambiguous_uuid', 'Ambiguous identity.', array( 'status' => 409 ) );
		$store = new Fake_Mutation_Store();
		$store->resolveResults = $recover && ! $precheck ? array( 0, $error ) : array( $error );
		$writer = new Create_Identity_Spy_Writer( $store );
		$identity = new Create_Identity( $store );
		// Act.
		$result = $recover ? $identity->recover( self::META, self::MUTATION, self::HIT, $writer ) : $identity->stamp( self::META, self::MUTATION, 4242, 201, $writer );
		// Assert.
		$this->assertSame( $error, $result );
		$this->assertSame( array(), $store->finalized );
		$this->assertSame( $recover ? array() : array( array( 'after_create', 4242, self::MUTATION['payload'] ) ), $writer->calls );
		if ( $precheck ) {
			$this->assertSame( array(), $store->persisted );
		}
	}

	public static function resolver_errors(): array {
		return array(
			'fresh proof' => array( false, false ),
			'retry precheck' => array( true, true ),
			'retry proof' => array( true, false ),
		);
	}

	public function test_recover_uuid_mismatch_returns_conflict_without_store_calls(): void {
		// Arrange.
		$store = new Fake_Mutation_Store();
		$writer = new Create_Identity_Spy_Writer( $store );
		$hit = array_merge( self::HIT, array( 'record_uuid' => 'another-uuid' ) );
		// Act.
		$result = ( new Create_Identity( $store ) )->recover( self::META, self::MUTATION, $hit, $writer );
		// Assert.
		$this->assertSame( 'woo_rxdb_sync_identity_conflict', $result->get_error_code() );
		$this->assertSame( 422, $result->get_error_data()['status'] );
		$this->assertSame( 'recordId disagrees with the stored mutation identity.', $result->get_error_message() );
		$this->assertSame( array(), $store->identity_trace );
		$this->assertSame( array(), $writer->calls );
	}

	/** @dataProvider recovery_statuses */
	public function test_recover_happy_path_reproves_identity_and_returns_status( array $override, int $status ): void {
		// Arrange.
		$store = new Fake_Mutation_Store();
		$writer = new Create_Identity_Spy_Writer( $store );
		// Act.
		$result = ( new Create_Identity( $store ) )->recover( self::META, self::MUTATION, array_merge( self::HIT, $override ), $writer );
		// Assert.
		$this->assertSame(
			array(
				'id' => 4242,
				'status' => $status,
			),
			$result
		);
		$this->assertSame( array( 'resolve_id_by_uuid', 'persist_uuid', 'resolve_id_by_uuid', 'after_recovery', 'finalize_poison' ), $store->identity_trace );
		$this->assertSame( array( array( 'after_recovery', 4242, self::MUTATION['payload'] ) ), $writer->calls );
		$this->assertSame(
			array(
				array(
					'id_type' => 'user',
					'id' => 4242,
					'uuid' => self::MUTATION['recordId'],
				),
			),
			$store->persisted
		);
		$this->assertSame( array( self::MUTATION['mutationId'] => 4242 ), $store->finalized );
	}

	public static function recovery_statuses(): array {
		return array(
			'recorded' => array( array( 'response_status' => '200' ), 200 ),
			'default' => array( array(), 201 ),
		);
	}

	/** @dataProvider lanes */
	public function test_identity_finalize_failure_returns_finalize_error( bool $recover ): void {
		// Arrange.
		$store = new Fake_Mutation_Store();
		$store->finalizeOk = false;
		$writer = new Create_Identity_Spy_Writer( $store );
		$identity = new Create_Identity( $store );
		// Act.
		$result = $recover ? $identity->recover( self::META, self::MUTATION, self::HIT, $writer ) : $identity->stamp( self::META, self::MUTATION, 4242, 201, $writer );
		// Assert.
		$this->assertSame( 'woo_rxdb_sync_finalize_failed', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
		$this->assertSame( 'Woo write succeeded but mutation finalization failed; retry the same mutationId.', $result->get_error_message() );
		$this->assertSame( 'finalize_poison', end( $store->identity_trace ) );
		$this->assertSame( 'poison', $store->lookups[ self::MUTATION['mutationId'] ]['status'] );
	}

	public static function lanes(): array {
		return array(
			'fresh' => array( false ),
			'retry' => array( true ),
		);
	}
}
