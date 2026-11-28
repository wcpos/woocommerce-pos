# Codex job spec — #2825: two tests the reviews found missing (tests only)

## Goal

Two independent reviews approved PR #2825 at 8abf5566. Each found a mutation that no test catches. Add exactly two test changes and touch no production code:

1. **A row landing inside a sequence gap of two or more numbers.** `Sync_Journal::settled_sequence()` (`includes/Sync/Sync_Journal.php`, about line 1248) returns `$previous`, the number before the first missing one. Mutating it to `return $sequence - 1;` (settle just below the young row) passes every test today. The existing race test lands its row above a one-number gap, so it cannot tell the two apart.
2. **The cursor must not advance on an empty page.** On a held, empty orders page, `checkpoint.since` must stay at the cursor. No test pins `since` on the second, empty poll, so a regression that echoes the uncapped head as `since` passes.

## Stakes

High tier (sync durability). This job changes tests only.

## In scope (the only files you may edit)

- `tests/includes/Sync/Test_Sync_Journal_Hold_Back.php`
- `tests/includes/Sync/Test_Order_Change_Stream.php`

## Out of scope (do not edit)

- Everything under `includes/`, and every other file (`Test_Order_Change_Stream_HPOS.php` inherits the stream class's cases; do not touch it)

## Interfaces and constraints

1. In `Test_Sync_Journal_Hold_Back.php`, add this method directly after `test_hold_back_row_landing_after_tail_read_waits()`. Reproduce it exactly, with tab indentation like its neighbours:

   ```php
   /**
    * A row that commits inside a gap of two numbers, between the tail read and the rows read, waits for the next page.
    */
   public function test_hold_back_row_landing_inside_wide_gap_waits(): void {
   	// Arrange.
   	global $wpdb;
   	$this->row( 1, 'order', self::YOUNG );
   	$this->row( 4, 'order', self::YOUNG );
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
   	$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $this->journal->table_name() . ' WHERE sequence = %d', $this->floor + 3 ) ), 'Precondition: the row landed.' );
   	$this->assertSame( $this->seq( 1 ), array_column( $page['rows'], 'sequence' ) );
   	$this->assertSame( $this->floor + 1, $page['head'] );
   }
   ```

2. In `Test_Order_Change_Stream.php`, in `test_orders_stream_young_gap_holds_page_until_row_after_it_is_old()`, add one assertion directly after the line `$this->assertSame( array(), $again->get_data()['changes'] );`:

   ```php
   $this->assertSame( $data['checkpoint']['head'], $again->get_data()['checkpoint']['since'], 'An empty held page keeps the cursor.' );
   ```

Change nothing else: no other assertions, helpers or docblocks.

## Do not

- Edit any production file or any file not listed
- Commit or push
- Create plan, notes or scratch files

## Pre-authorised actions

- Edit the in-scope files
- Run the acceptance commands

## Budget

- Max non-test lines changed: 0
- Max total lines changed: 40

If you are about to exceed either, STOP and report why instead of continuing.

## Acceptance criteria (runnable)

```sh
php -l tests/includes/Sync/Test_Sync_Journal_Hold_Back.php
php -l tests/includes/Sync/Test_Order_Change_Stream.php
git diff --stat -- includes/   # must print nothing
```

## Reviewer reruns (not for Codex)

These run under wp-env:
- Test_Sync_Journal_Hold_Back must pass 25 of 25.
- Test_Order_Change_Stream and _HPOS must each pass 25 of 25.
- Mutation `return $sequence - 1;` must fail the new hold-back test.
- Mutation "empty page echoes the uncapped head as checkpoint.since" (Changes_Controller) must fail the stream test in both classes.

## Environment

- Network: no
- Writable outside the worktree: none

## Output expected

End with the report format from ~/.codex/AGENTS.md (STATUS / CHANGED /
ACCEPTANCE / NOT DONE / QUESTIONS / NOTES / BEHAVIOUR CHANGES).
