<?php
/**
 * A held page of the change stream stops before a missing sequence number that an open
 * transaction may still commit, and skips one too old to be anything but a permanent gap.
 *
 * @package WCPOS\WooCommercePOS\Tests\Sync
 */

namespace WCPOS\WooCommercePOS\Tests\Sync;

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Known internal table; values are prepared.

use WCPOS\WooCommercePOS\Sync\Sync_Journal;

/**
 * Sync_Journal::page() with and without its hold-back.
 *
 * Rows get explicit sequence numbers above the floor B, an hour-old row that took the table's
 * next AUTO_INCREMENT number, so B+n is never a number already handed out.
 *
 * @covers \WCPOS\WooCommercePOS\Sync\Sync_Journal::page
 */
class Test_Sync_Journal_Hold_Back extends Sync_Store_Test_Case {
	private const YOUNG = 2;
	private const OLD   = 60;

	/**
	 * The journal under test.
	 *
	 * @var Sync_Journal
	 */
	private $journal;

	/**
	 * Sequence of the floor row, B.
	 *
	 * @var int
	 */
	private $floor;

	/**
	 * Insert the hour-old floor row through the table's own AUTO_INCREMENT.
	 */
	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->journal = new Sync_Journal();
		$stamp         = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		$wpdb->insert(
			$this->journal->table_name(),
			array(
				'object_type'  => 'tax_rate',
				'object_id'    => 1,
				'modified_gmt' => $stamp,
				'created_gmt'  => $stamp,
			)
		);
		$this->floor = (int) $wpdb->insert_id;
	}

	/**
	 * Journal rows `[offset, type, age]`, cursor offset (null: since 0), limit, served row
	 * offsets and head offset, all above the floor B.
	 *
	 * @return array<string, array{0: array, 1: ?int, 2: int, 3: int[], 4: int}>
	 */
	public function held_pages(): array {
		$y = self::YOUNG;
		$o = self::OLD;
		return array(
			'b. old row after a gap is served'                 => array( array( array( 1, 'order', $o ), array( 3, 'order', $o ) ), 0, 100, array( 1, 3 ), 3 ),
			'c. 5 s old after a gap is held'                   => array( array( array( 1, 'order', 5 ), array( 3, 'order', 5 ) ), 0, 100, array( 1 ), 1 ),
			'c. 12 s old after a gap is served'                => array( array( array( 1, 'order', 12 ), array( 3, 'order', 12 ) ), 0, 100, array( 1, 3 ), 3 ),
			'd. row stamped 60 s in the future is served'      => array( array( array( 1, 'order', $y ), array( 3, 'order', -60 ) ), 0, 100, array( 1, 3 ), 3 ),
			'e. gap between product rows holds the orders'     => array( array( array( 1, 'order', $y ), array( 2, 'product', $y ), array( 4, 'product', $y ), array( 5, 'order', $y ) ), 0, 100, array( 1 ), 1 ),
			'f. other types between orders are not gaps'       => array( array( array( 1, 'order', $y ), array( 2, 'product', $y ), array( 3, 'order', $y ) ), 0, 100, array( 1, 3 ), 3 ),
			'g. gap directly after the cursor holds it'        => array( array( array( 1, 'order', $y ), array( 3, 'order', $y ) ), 1, 100, array(), 1 ),
			'h. cursor row gone, next number served'           => array( array( array( 2, 'order', $y ) ), 1, 100, array( 2 ), 2 ),
			'i. held cursor row gone, head stays at cursor'    => array( array( array( 1, 'order', $o ), array( 5, 'order', $y ) ), 3, 100, array(), 3 ),
			'j. cursor beyond the head gets the real head'     => array( array( array( 1, 'order', $y ) ), 100, 100, array(), 1 ),
			'k. fresh client takes a head below the gap'       => array( array( array( 1, 'order', $o ), array( 2, 'order', $y ), array( 4, 'order', $y ) ), null, 1, array( 1 ), 2 ),
			'l. old gap skipped, young gap holds'              => array( array( array( 1, 'order', $o ), array( 3, 'order', $o ), array( 5, 'order', $y ) ), 0, 100, array( 1, 3 ), 3 ),
			'l. of two young gaps the first holds'             => array( array( array( 1, 'order', $o ), array( 3, 'order', $y ), array( 5, 'order', $y ) ), 0, 100, array( 1 ), 1 ),
		);
	}

	/**
	 * A held page serves the rows up to the first young gap and a head that never passes it.
	 *
	 * @dataProvider held_pages
	 *
	 * @param array    $rows  Journal rows `[offset, type, age]`.
	 * @param int|null $since Cursor offset, or null for since 0.
	 * @param int      $limit Page size.
	 * @param int[]    $served Expected row offsets.
	 * @param int      $head  Expected head offset.
	 */
	public function test_hold_back_page_stops_before_first_young_gap( array $rows, ?int $since, int $limit, array $served, int $head ): void {
		// Arrange.
		foreach ( $rows as $row ) {
			$this->row( ...$row );
		}

		// Act.
		$page = $this->page( null === $since ? 0 : $this->floor + $since, $limit );

		// Assert.
		$this->assertSame( $this->seq( ...$served ), array_column( $page['rows'], 'sequence' ) );
		$this->assertSame( $this->floor + $head, $page['head'] );
	}

	/**
	 * A young row after a gap is held until the missing number commits.
	 */
	public function test_hold_back_young_row_after_gap_is_held_until_gap_fills(): void {
		// Arrange.
		$this->row( 1, 'order', self::YOUNG );
		$this->row( 3, 'order', self::YOUNG );

		// Act.
		$held = $this->page( $this->floor );
		$this->row( 2, 'order', self::YOUNG );
		$filled = $this->page( $this->floor );

		// Assert.
		$this->assertSame( $this->seq( 1 ), array_column( $held['rows'], 'sequence' ) );
		$this->assertSame( $this->floor + 1, $held['head'] );
		$this->assertSame( $this->seq( 1, 2, 3 ), array_column( $filled['rows'], 'sequence' ) );
		$this->assertSame( $this->floor + 3, $filled['head'] );
	}

	/**
	 * A row that commits between the tail read and the rows read waits for the next page.
	 */
	public function test_hold_back_row_landing_after_tail_read_waits(): void {
		// Arrange.
		global $wpdb;
		$this->row( 1, 'order', self::YOUNG );
		$landed = false;
		$land   = function ( $query ) use ( &$landed ) {
			if ( ! $landed && 0 === strpos( (string) $query, 'SELECT sequence, object_id,' ) ) {
				$landed = true;
				$this->row( 3, 'order', self::YOUNG );
			}
			return $query;
		};
		add_filter( 'query', $land );

		// Act.
		try {
			$page = $this->page( $this->floor );
		} finally {
			remove_filter( 'query', $land );
		}

		// Assert.
		$this->assertSame( (string) ( $this->floor + 3 ), $wpdb->get_var( 'SELECT MAX(sequence) FROM ' . $this->journal->table_name() ), 'Precondition: the row landed.' );
		$this->assertSame( $this->seq( 1 ), array_column( $page['rows'], 'sequence' ) );
		$this->assertSame( $this->floor + 1, $page['head'] );
	}

	/**
	 * A number that commits before the tail read is served, never jumped.
	 */
	public function test_hold_back_row_landing_before_tail_read_is_served(): void {
		// Arrange.
		global $wpdb;
		$this->row( 1, 'order', self::YOUNG );
		$this->row( 3, 'order', self::YOUNG );
		$landed = false;
		$land   = function ( $query ) use ( &$landed ) {
			if ( ! $landed && 0 === strpos( (string) $query, 'SELECT sequence, created_gmt' ) ) {
				$landed = true;
				$this->row( 2, 'order', self::YOUNG );
			}
			return $query;
		};
		add_filter( 'query', $land );

		// Act.
		try {
			$page = $this->page( $this->floor );
		} finally {
			remove_filter( 'query', $land );
		}

		// Assert.
		$this->assertSame( '3', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->journal->table_name() . ' WHERE sequence > ' . $this->floor ), 'Precondition: the row landed.' );
		$this->assertSame( $this->seq( 1, 2, 3 ), array_column( $page['rows'], 'sequence' ) );
		$this->assertSame( $this->floor + 3, $page['head'] );
	}

	/**
	 * Without the hold-back the page reads exactly what it always has.
	 */
	public function test_hold_back_off_reads_rows_and_queries_unchanged(): void {
		// Arrange.
		$this->row( 1, 'order', self::YOUNG );
		$this->row( 3, 'order', self::YOUNG );
		$queries = array();
		$spy     = static function ( $query ) use ( &$queries ) {
			$queries[] = (string) $query;
			return $query;
		};
		add_filter( 'query', $spy );

		// Act.
		try {
			$page = $this->journal->page( array( 'order' ), $this->floor, 100 );
		} finally {
			remove_filter( 'query', $spy );
		}

		// Assert.
		$this->assertSame( $this->seq( 1, 3 ), array_column( $page['rows'], 'sequence' ) );
		$this->assertSame( $this->floor + 3, $page['head'] );
		$this->assertCount( 3, $queries, 'The rows read and the two head reads, as on the base.' );
		$this->assertSame(
			'SELECT sequence, object_id, object_type, deleted, revision, modified_gmt FROM ' . $this->journal->table_name()
				. " WHERE object_type IN ('order') AND sequence > " . $this->floor . ' ORDER BY sequence ASC LIMIT 100',
			$queries[0]
		);
	}

	/**
	 * Consecutive young orders after a young gap, either side of the tail's size.
	 *
	 * @return array<string, array{0: int, 1: bool}>
	 */
	public function tail_sizes(): array {
		return array(
			'1001 rows: the gap is outside the tail' => array( 1001, false ),
			'999 rows: the gap is inside the tail'   => array( 999, true ),
		);
	}

	/**
	 * A full tail starts its walk inside itself; a short one starts at the cursor.
	 *
	 * @dataProvider tail_sizes
	 *
	 * @param int  $count Consecutive young orders after the gap.
	 * @param bool $held  Whether the page stops before the gap.
	 */
	public function test_hold_back_tail_size_decides_where_walk_starts( int $count, bool $held ): void {
		// Arrange.
		$this->rows_in_one_statement( 2, $count );

		// Act.
		$page = $this->page( $this->floor, 1 );

		// Assert.
		$this->assertSame( $held ? array() : $this->seq( 2 ), array_column( $page['rows'], 'sequence' ) );
		$this->assertSame( $this->floor + ( $held ? 0 : $count + 1 ), $page['head'] );
	}

	/**
	 * Journal sizes the query count is measured at.
	 *
	 * @return array<string, array{0: int}>
	 */
	public function journal_sizes(): array {
		return array(
			'3 rows'   => array( 3 ),
			'300 rows' => array( 300 ),
		);
	}

	/**
	 * The hold-back costs at most two queries more, whatever the journal size.
	 *
	 * @dataProvider journal_sizes
	 *
	 * @param int $count Consecutive young orders before a young gap.
	 */
	public function test_hold_back_costs_at_most_two_queries_more( int $count ): void {
		// Arrange.
		global $wpdb;
		$this->rows_in_one_statement( 1, $count );
		$this->row( $count + 2, 'order', self::YOUNG );

		// Act.
		$before = $wpdb->num_queries;
		$this->journal->page( array( 'order' ), $this->floor, 100 );
		$plain  = $wpdb->num_queries - $before;
		$before = $wpdb->num_queries;
		$held   = $this->page( $this->floor );
		$extra  = $wpdb->num_queries - $before;

		// Assert.
		$this->assertSame( $this->floor + $count, $held['head'], 'Precondition: the capped head was read.' );
		$this->assertLessThanOrEqual( $plain + 2, $extra );
	}

	/**
	 * A held page of the order stream.
	 *
	 * @param int $since Cursor.
	 * @param int $limit Page size.
	 */
	private function page( int $since, int $limit = 100 ): array {
		return $this->journal->page( array( 'order' ), $since, $limit, true );
	}

	/**
	 * Insert one journal row at B+offset, `$age` seconds old (negative: in the future).
	 *
	 * @param int    $offset Sequence above the floor.
	 * @param string $type   Object type.
	 * @param int    $age    Seconds old.
	 */
	private function row( int $offset, string $type, int $age ): void {
		global $wpdb;
		$stamp = gmdate( 'Y-m-d H:i:s', time() - $age );
		$wpdb->insert(
			$this->journal->table_name(),
			array(
				'sequence'     => $this->floor + $offset,
				'object_type'  => $type,
				'object_id'    => $this->floor + $offset,
				'modified_gmt' => $stamp,
				'created_gmt'  => $stamp,
			)
		);
	}

	/**
	 * Insert `$count` consecutive young orders from B+offset in one statement.
	 *
	 * @param int $offset First sequence above the floor.
	 * @param int $count  Number of rows.
	 */
	private function rows_in_one_statement( int $offset, int $count ): void {
		global $wpdb;
		$stamp  = gmdate( 'Y-m-d H:i:s', time() - self::YOUNG );
		$values = array();
		foreach ( range( $this->floor + $offset, $this->floor + $offset + $count - 1 ) as $sequence ) {
			$values[] = $wpdb->prepare( "(%d, 'order', %d, %s, %s)", $sequence, $sequence, $stamp, $stamp );
		}
		$wpdb->query( 'INSERT INTO ' . $this->journal->table_name() . ' (sequence, object_type, object_id, modified_gmt, created_gmt) VALUES ' . implode( ',', $values ) );
	}

	/**
	 * Sequences B+offset for each offset.
	 *
	 * @param int ...$offsets Offsets above the floor.
	 * @return int[]
	 */
	private function seq( int ...$offsets ): array {
		return array_map( fn( int $offset ): int => $this->floor + $offset, $offsets );
	}
}
