<?php
/**
 * Register resource storage.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Payments\Contract\Order_Lock;

/** Registers through the bound repository; callers validate fields. */
final class Register_Store {
	/** The pre-schema-12 table; sync-schema step 12 copies it into the option and drops it. */
	public const LEGACY_TABLE = 'wcpos_registers';

	/** Named lock every writer of the register option shares: Free's repository, schema 12 and Pro's retired-map writes. */
	public const DEFAULT_LOCK = 'wcpos_register_default';

	/** A close stamps every session leg, so allow longer than Order_Lock's wait. */
	private const LOCK_WAIT_SECONDS = 10;

	/** Serialize a close and movements without requiring a movement transaction.
	 *
	 * @param string $register_id Register UUID.
	 */
	public function lock( string $register_id ): bool {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', 'wcpos_register_' . strtolower( $register_id ), self::LOCK_WAIT_SECONDS ) );
	}

	/** Release the register after the write or closure transaction ends.
	 *
	 * @param string $register_id Register UUID.
	 */
	public function unlock( string $register_id ): void {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', 'wcpos_register_' . strtolower( $register_id ) ) );
	}

	/** Run an operation under the register lock.
	 *
	 * @param string   $register_id Register UUID.
	 * @param callable $callback Locked operation.
	 * @param string   $failure_message Lock acquisition failure message.
	 * @param array    $context Additional failure log context.
	 * @return mixed
	 * @throws \RuntimeException When the lock cannot be acquired.
	 */
	public function with_lock( string $register_id, callable $callback, string $failure_message, array $context = array() ) {
		if ( ! $this->lock( $register_id ) ) {
			Logger::warning(
				$failure_message,
				array(
					'register_id' => $register_id,
					'user_id' => get_current_user_id(),
				) + $context
			);
			throw new \RuntimeException( $failure_message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal failure message, not HTML output.
		}
		try {
			return $callback();
		} finally {
			$this->unlock( $register_id );
		}
	}

	/** Warn once per request, only where a second named lock is needed. */
	public static function warn_if_locks_cannot_nest(): void {
		static $warned = false;
		if ( ! $warned && ! Order_Lock::supports_multiple_locks() ) {
			$warned = true;
			Logger::warning( 'Register lock nesting is unsupported on this database server; a concurrent close and movement may interleave' );
		}
	}

	/**
	 * The bound repository, resolved once per instance.
	 *
	 * @var Register_Repository|null
	 */
	private $repository;

	/**
	 * Create a server-owned register.
	 *
	 * @param array                 $fields Validated fields.
	 * @param \WP_REST_Request|null $request REST create context for the Pro fields filter.
	 * @throws \RuntimeException When storage fails, refuses another register, or is migrating.
	 */
	public function create( array $fields, ?\WP_REST_Request $request = null ): array {
		$id           = wp_generate_uuid4();
		self::refuse_while_migrating( $id );
		$fields['id'] = $id;
		if ( null !== $request ) {
			/** Filter initial registration fields; Pro may set store_id. */
			$fields = apply_filters( 'woocommerce_pos_register_upsert_fields', $fields, $request );
		}
		$fields['id'] = $id;
		$this->insert_row( $fields );
		$row = $this->get( $id );
		Logger::log(
			'Register created',
			array(
				'register_id' => $row['id'],
				'name' => $row['name'],
				'default_float' => $row['default_float'],
				'user_id' => get_current_user_id(),
			)
		);
		return $row;
	}

	/**
	 * Insert a register row, without reading it back.
	 *
	 * @param array $fields Validated fields with an id.
	 * @throws \RuntimeException When storage fails or refuses the row.
	 */
	private function insert_row( array $fields ): void {
		$this->repository()->insert(
			array(
				'id' => $fields['id'],
				'name' => $fields['name'],
				'store_id' => $fields['store_id'] ?? null,
				'default_float' => $fields['default_float'] ?? null,
				'created_at_gmt' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Mint the default register when the repository holds none. Free's option writes run
	 * under one named lock and re-read inside it (add_option() is not atomic), so a second
	 * request minting at once is refused there; its register is then the default.
	 *
	 * @throws \RuntimeException When the write fails and no register was minted meanwhile.
	 */
	public function ensure_default(): void {
		// Never while schema 12 is pending: a register minted now could win the fold over the table's.
		if ( self::legacy_tables() || $this->repository()->list( array() ) ) {
			return;
		}
		try {
			$this->insert_row(
				array(
					'id' => wp_generate_uuid4(),
					'name' => __( 'Register', 'woocommerce-pos' ),
				)
			);
		} catch ( \RuntimeException $error ) {
			if ( ! $this->repository()->list( array() ) ) {
				throw $error;
			}
		}
	}

	/**
	 * Read one register; null for an id the repository does not hold.
	 *
	 * @param string $id Register UUID.
	 */
	public function get( string $id ): ?array {
		$this->ensure_free_default();
		$row = $this->row( $id );
		return $row ? $this->normalize_row( $row ) : null;
	}

	/**
	 * List registers by name, optionally scoped by store.
	 *
	 * @param array $args Filters.
	 * @throws \RuntimeException When latest closure counters cannot be read.
	 */
	public function list( array $args ): array {
		$this->ensure_free_default();
		$rows = $this->rows( $args );
		usort(
			$rows,
			static function ( $a, $b ) {
				return strcasecmp( (string) $a['name'], (string) $b['name'] );
			}
		);
		$latest = ( new Closure_Store() )->latest_for_registers( array_column( $rows, 'id' ) );
		$sessions = ( new Register_Session_Store() )->open_for_registers( array_column( $rows, 'id' ) );
		if ( $sessions ) {
			cache_users( array_values( array_unique( array_map( 'intval', array_column( $sessions, 'opened_by' ) ) ) ) );
		}
		return array_map(
			function ( $row ) use ( $latest, $sessions ) {
				return $this->normalize_row( $row, $latest[ $row['id'] ] ?? array(), $sessions[ $row['id'] ] ?? array() );
			},
			$rows
		);
	}

	/** Retire under the register lock, preserving the repository's history.
	 *
	 * @param string $id Register UUID.
	 * @throws \RuntimeException When retirement is unsupported or storage fails.
	 */
	public function retire( string $id ): void {
		$this->with_lock(
			$id,
			function () use ( $id ) {
				$repository = $this->repository();
				if ( ! $repository instanceof Register_Retirement ) {
					throw new \RuntimeException( 'Register retirement is not supported.' );
				}
				$repository->retire( $id );
				Logger::log(
					'Register retired',
					array(
						'register_id' => $id,
						'user_id' => get_current_user_id(),
					)
				);
			},
			'Register retirement lock failed.'
		);
	}

	/**
	 * Edit admin-owned fields; Pro may explicitly pass store_id.
	 *
	 * @param string $id Register UUID.
	 * @param array  $fields Validated admin fields.
	 * @throws \RuntimeException When storage fails or is migrating.
	 */
	public function update( string $id, array $fields ): ?array {
		self::refuse_while_migrating( $id );
		$before = $this->get( $id );
		if ( ! $before ) {
			Logger::warning(
				'Register update refused: register_id not found',
				array(
					'register_id' => $id,
					'user_id' => get_current_user_id(),
				)
			);
			return null;
		}
		$data = array_intersect_key( $fields, array_flip( array( 'name', 'default_float', 'store_id' ) ) );
		if ( $data ) {
			$this->repository()->update( $id, $data );
		}
		$row = $this->get( $id );
		$changed = array();
		foreach ( array_keys( $data ) as $key ) {
			if ( $before[ $key ] !== $row[ $key ] ) {
				$changed[] = $key;
			}
		}
		if ( $changed ) {
			Logger::log(
				'Register changed',
				array(
					'register_id' => $id,
					'user_id' => get_current_user_id(),
					'fields' => $changed,
					'before' => array_intersect_key( $before, array_flip( $changed ) ),
					'after' => array_intersect_key( $row, array_flip( $changed ) ),
				)
			);
		}
		return $row;
	}

	/**
	 * Whether a register is known.
	 *
	 * @param string $id Register UUID.
	 */
	public function exists( string $id ): bool {
		$this->ensure_free_default();
		return null !== $this->row( $id );
	}

	/** Every register id provenance may name: the held registers and the retired ones. */
	public function known_ids(): array {
		$this->ensure_free_default();
		return array_values( array_unique( array_merge( array_column( $this->rows( array() ), 'id' ), $this->repository()->retired_ids() ) ) );
	}

	/**
	 * The name history prints for a register: its row, else its retired name, else "Register".
	 *
	 * @param string $id Register UUID.
	 */
	public function display_name( string $id ): string {
		$row = $this->row( $id );
		if ( $row ) {
			return (string) $row['name'];
		}
		return $this->repository()->retired_name( $id ) ?? __( 'Register', 'woocommerce-pos' );
	}

	/** Resolve the repository once: Free's option, or the one Pro binds. */
	private function repository(): Register_Repository {
		if ( null === $this->repository ) {
			$repository = apply_filters( 'woocommerce_pos_register_repository', new Option_Register_Repository() );
			if ( ! $repository instanceof Register_Repository ) {
				Logger::warning( 'woocommerce_pos_register_repository did not return a Register_Repository; using the option' );
				$repository = new Option_Register_Repository();
			}
			$this->repository = $repository;
		}
		return $this->repository;
	}

	/**
	 * One register: the repository's, else (while schema 12 is pending) the legacy table's.
	 *
	 * @param string $id Register UUID.
	 * @throws \RuntimeException When a legacy probe or read fails.
	 */
	private function row( string $id ): ?array {
		global $wpdb;
		return $this->repository()->get( $id ) ?? ( self::legacy_rows( $wpdb->prepare( 'id = %s', $id ) )[ $id ] ?? null );
	}

	/**
	 * The repository's registers, plus (while schema 12 is pending) the legacy table's it lacks.
	 *
	 * @param array $args Filters.
	 * @throws \RuntimeException When a legacy probe or read fails.
	 */
	private function rows( array $args ): array {
		$legacy = array_filter(
			self::legacy_rows(),
			static function ( $row ) use ( $args ) {
				return Option_Register_Repository::in_scope( $row, $args );
			}
		);
		return array_values( array_column( $this->repository()->list( $args ), null, 'id' ) + $legacy );
	}

	/**
	 * Rows of the legacy registers tables, keyed by id; none once the schema-12 latch is set.
	 *
	 * @param string $where Prepared predicate.
	 * @throws \RuntimeException When a probe or read fails.
	 */
	private static function legacy_rows( string $where = '1=1' ): array {
		global $wpdb;
		$rows = array();
		foreach ( self::legacy_tables() as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Known legacy table; the predicate is prepared.
			$found = $wpdb->get_results( "SELECT id, name, store_id, default_float, created_at_gmt FROM {$table} WHERE {$where}", ARRAY_A );
			if ( '' !== $wpdb->last_error ) {
				throw new \RuntimeException( 'Register read failed.' );
			}
			$rows += array_column( $found, null, 'id' );
		}
		return $rows;
	}

	/** Legacy registers tables still to be folded by schema 12, or none once its latch is set.
	 *
	 * @throws \RuntimeException When a probe fails; a caller must not assume absence.
	 */
	private static function legacy_tables(): array {
		return Legacy_Tables::pending( self::LEGACY_TABLE, '12', 'Register read refused: register storage probe failed', 'Register storage probe failed.' );
	}

	/**
	 * Refuse a register write while schema 12 has not folded the legacy table.
	 *
	 * @param string $id Register UUID.
	 * @throws \RuntimeException While the fold is pending.
	 */
	private static function refuse_while_migrating( string $id ): void {
		if ( self::legacy_tables() ) {
			Logger::warning(
				'Register write refused: register storage is migrating',
				array(
					'register_id' => $id,
					'user_id' => get_current_user_id(),
				)
			);
			throw new \RuntimeException( 'Register storage is migrating.' );
		}
	}

	/** A fresh Free site has its register the first time anything asks. */
	private function ensure_free_default(): void {
		if ( $this->repository() instanceof Option_Register_Repository && ! $this->repository()->list( array() ) ) {
			$this->ensure_default();
		}
	}

	/**
	 * Convert stored types to the REST resource types.
	 *
	 * @param array      $row Repository row.
	 * @param null|array $closure Preloaded latest closure; null loads it here.
	 * @param null|array $session Preloaded current session; null loads it here.
	 */
	private function normalize_row( array $row, ?array $closure = null, ?array $session = null ): array {
		$closure = null === $closure ? ( ( new Closure_Store() )->latest_for_registers( array( $row['id'] ) )[ $row['id'] ] ?? array() ) : $closure;
		$session = null === $session ? ( ( new Register_Session_Store() )->open_for_registers( array( $row['id'] ) )[ $row['id'] ] ?? array() ) : $session;
		$row['store_id'] = null === ( $row['store_id'] ?? null ) ? null : (int) $row['store_id'];
		$row['default_float'] = null === ( $row['default_float'] ?? null ) ? null : (string) $row['default_float'];
		// Derived: the opening of the register's first numbered session, null before its first closure.
		$row['counters_started_at_gmt'] = $closure['counters_started_at_gmt'] ?? null;
		foreach ( array( 'counters_started_at_gmt', 'created_at_gmt' ) as $key ) {
			$row[ $key ] = null === $row[ $key ] ? null : str_replace( ' ', 'T', $row[ $key ] ) . 'Z';
		}
		$row['counters'] = array(
			'last_closure_number' => isset( $closure['number'] ) ? (int) $closure['number'] : 0,
			'perpetual_sales_total' => $closure['perpetual_sales_total'] ?? '0',
			'perpetual_refunds_total' => $closure['perpetual_refunds_total'] ?? '0',
		);
		$row['session'] = $session ? array(
			'id' => $session['id'],
			'status' => $session['status'],
			'opened_at_gmt' => str_replace( ' ', 'T', $session['opened_at_gmt'] ) . 'Z',
			'opened_by' => array(
				'id' => (int) $session['opened_by'],
				'name' => get_userdata( $session['opened_by'] )->display_name ?? '',
			),
		) : null;
		$row['last_closure'] = $closure ? array(
			'number' => (int) $closure['number'],
			'closed_at_gmt' => null === $closure['closed_at_gmt'] ? null : str_replace( ' ', 'T', $closure['closed_at_gmt'] ) . 'Z',
		) : null;
		return $row;
	}
}
