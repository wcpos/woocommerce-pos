# Collapse the two deferred per-request write queues into one

Branch `codex/request-write-queue`, cut from `origin/main` (e7dc4f2b). PR targets `main`.
Candidate 13 of the 2026-09-17 architecture review (tracking issue #2007, closed; Paul picked
this card on 2026-09-17). This is a **refactor with no wire-shape change**: no row, digest or
hook timing changes except the one listed under Behavioural changes.

## The problem, in the code as it is

Two modules each defer their writes to the request boundary so that WooCommerce's many saves
per request produce one row / one upsert:

| | `Sync\Sync_Journal` (`includes/Sync/Sync_Journal.php`) | `Sync\Integrity_Digest` (`includes/Sync/Integrity_Digest.php`) |
|---|---|---|
| pending state | `$pending_order_update`, ONE slot `{blog, id, order}` | `$pending_digests`, map `"blog:type:id" => [blog, type, id]`, capped by `PENDING_DIGEST_FLUSH_THRESHOLD` (50) |
| latch | `$shutdown_flushed` | `$shutdown_flushed` |
| shutdown flush | `flush_pending_order_updates_at_shutdown()` at `PHP_INT_MAX`, 0 args | `flush_pending_digests_at_shutdown()` at `PHP_INT_MAX`, 0 args |
| flush | `flush_pending_order_updates()` | `flush_pending_digests()` (static; writes through `$flusher`, the instance that first queued, else `new self()`) |
| blog switch on flush | `in_blog()` | inline `switch_to_blog` / `restore_current_blog` in `try/finally` |
| after the latch | `record_order_updated()` writes immediately | `defer()` writes immediately |
| cancel a pending write | `record_order_change()` drops the slot when a `hook:create` row for the same order arrives | `delete_for()` unsets the pending key |
| flush on a different key | a save for a DIFFERENT order flushes the pending one first | the 50th distinct record flushes the whole queue |
| test reset | `reset_request_state()` | `reset_request_state()` (both called from `tests/includes/Sync/Sync_Store_Test_Case.php`) |

Four encodings of "do not write this record twice in one request", written twice, with two
sets of `shutdown` wiring and two blog-switch helpers. The rules are pure state over injected
writes, so they can be tested with no bootstrap.

## Goal

One module, `Sync\Request_Write_Queue`, owns the queue rules. `Sync_Journal` and
`Integrity_Digest` each hold one static queue instance and keep only their write callables and
their public method names. Every existing public method and constant on the two owners stays,
with the same signature, delegating to the queue:
`record_order_updated()`, `flush_pending_order_updates()`,
`flush_pending_order_updates_at_shutdown()`, `Sync_Journal::reset_request_state()`,
`Integrity_Digest::flush_pending_digests()`, `flush_pending_digests_at_shutdown()`,
`Integrity_Digest::reset_request_state()`, `PENDING_DIGEST_FLUSH_THRESHOLD`. The two
`shutdown` registrations stay where they are (the owners register their own hooks; the
existing tests pin priority and arity). `Digest_Index::read_digests()` keeps calling
`Integrity_Digest::flush_pending_digests()`.

## The module

`includes/Sync/Request_Write_Queue.php`, `final class Request_Write_Queue` in
`WCPOS\WooCommercePOS\Sync`. Match the PHP level and style of the two owners (they use typed
properties and `?array`; WordPress coding standards). No statics inside the queue: the owners
hold the instance.

- `__construct( int $capacity, callable $write )` — `$write( string $type, int $id, $payload )`
  performs one write; the queue calls it under the blog the entry was recorded on.
- `owe( string $type, int $id, $payload = null ): void` — key the entry by
  `get_current_blog_id()`, `$type`, `$id`. After `flush_at_shutdown()` has run, write now
  instead (under the current blog). If the key is already pending, keep the pending entry and
  only replace its payload when the new payload is not null (the journal keeps the order object
  from an earlier hook call when a later one has none). If the key is new and the queue already
  holds `$capacity` entries, `flush()` first, then add.
- `owes( string $type, int $id ): bool` — whether that key is pending under the current blog.
- `drop( string $type, int $id ): void` — forget a pending entry without writing it.
- `flush(): void` — take every entry, clear the queue, then write each under its blog
  (`switch_to_blog` / `restore_current_blog` in `try/finally`, only when multisite and the blog
  differs). Clear BEFORE writing so a write that throws cannot be replayed by a later flush,
  and so a write that itself calls `owe()` (the journal's flush records a row that could trigger
  another save) starts a fresh queue. Safe to call repeatedly.
- `flush_at_shutdown(): void` — set the latch, then `flush()`.
- `reset(): void` — clear entries and the latch. Tests only.

The class docblock carries the queue rules (why one row per record per request, why a
different record beyond capacity flushes first, why the latch exists: WooCommerce saves the
customer at shutdown priority 10 and the session at 20, and a save those trigger must still
land). Keep the measurements and hook-name facts that live in the two owners' docblocks
today: move the queue-mechanics sentences here, leave the domain facts (why a journal row is a
pointer, why the digest is a pure function of the settled record) where they are.

## Owners

**`Sync_Journal`.** Delete `$pending_order_update`, `$shutdown_flushed` and `in_blog()`. Add
`private static ?Request_Write_Queue $pending_updates = null;` and a private `queue()` that
creates it on first use with capacity 1 and the write
`function ( $type, $id, $order ) { $this->record_order_change( $id, 'hook:update', false, $order ); }`.
The first instance to need the queue binds the write; document that in the property docblock
(today the flushing instance writes; both are `Sync_Journal` instances over the same table and
the `$recorded_this_request` dedup only concerns customer rows, so nothing observable changes —
`test_the_ordering_guarantee_holds_across_journal_instances` must stay green).
`record_order_updated()` → `queue()->owe( 'order', $order_id, $order )` (the
`instanceof \WC_Abstract_Order` normalisation stays in the owner).
`flush_pending_order_updates()` → flush the queue if it exists. `…_at_shutdown()` →
`queue()->flush_at_shutdown()` (create it if needed: the latch must be set even when nothing was
queued). In `record_order_change()`, the non-update branch becomes: a `hook:create` for an
order the queue `owes()` drops it; any other origin flushes. `reset_request_state()` sets the
static queue back to null.

**`Integrity_Digest`.** Delete `$pending_digests`, `$flusher`, `$shutdown_flushed` and
`pending_key()`. Add `private static ?Request_Write_Queue $pending = null;` and a private
static `queue()` that creates it on first use with capacity `PENDING_DIGEST_FLUSH_THRESHOLD`
and a write bound to the creating instance:
`function ( $type, $id ) { $this->upsert_pending( $type, $id ); }` — the queue is created from
`defer()` (the first instance to queue, today's `$flusher`) or, when the shutdown flush or a
read-lane flush runs before anything was queued, from `new self()` (today's fallback). `defer()`
→ `queue()->owe( $type, $id )`. `delete_for()` → `queue()->drop( self::pending_type( $collection ), $id )`.
`flush_pending_digests()` → flush the queue if it exists. `…_at_shutdown()` →
`queue()->flush_at_shutdown()`. `reset_request_state()` sets the static back to null. Update
the `PENDING_DIGEST_FLUSH_THRESHOLD` docblock to the new meaning: the most distinct records the
queue holds; the next distinct record flushes them first.

Do not touch `Digest_Index`, `Init.php`, the hook registrations, `$request_write_ms` in either
owner (that is write timing, not queue state), or any table SQL.

## Behavioural changes (all of them — list any other you find in the final report)

1. The digest queue flushes when the record AFTER the threshold arrives, not on the threshold
   record itself: 50 distinct records stay pending, the 51st flushes them and is then pending
   alone. Bounded exactly as before; the bulk-import concern the threshold exists for is
   unchanged. `test_the_queue_flushes_itself_at_the_threshold` in
   `tests/includes/Sync/Test_Integrity_Digest_Write_Coalescing.php` pins the old rule: change
   its two assertions and its docblock to the new one (nothing written at `$threshold`
   records; `$threshold` inserts after the next record). Do not change the constant's value.
2. After the shutdown latch, a queued write goes through the queue's bound writer (the first
   instance) rather than the calling instance. Both owners' writers act on the same table; say
   so in the property docblocks.

No new options, env vars, filters, constants outside the new class, or parameters on existing
public methods. No `error_log()`.

## Tests

New file `tests/includes/Sync/Test_Request_Write_Queue.php` extending `WP_UnitTestCase`
(look at how other `tests/includes/Sync/Test_*.php` files that need no store are declared
and follow that). Inject a write that records `[type, id, payload, get_current_blog_id()]`.
Cases, Arrange / Act / Assert, `assertSame`, `( expected, actual )`:

1. Owing the same key twice writes once, on `flush()`, with the last non-null payload; a null
   payload after a non-null one keeps the earlier payload.
2. Capacity 1: a second distinct key flushes the first before it is added; capacity 3: the
   fourth distinct key flushes the three. Assert the write order.
3. `drop()` forgets a pending key; `owes()` reflects pending state before and after.
4. `flush()` twice writes once; `flush()` on an empty queue writes nothing.
5. After `flush_at_shutdown()`, `owe()` writes immediately; after `reset()` it queues again.
6. A write that throws on the first entry: the exception propagates, and a later `flush()`
   does not replay it (the queue was cleared first).
7. A write that calls `owe()` for a new key during `flush()` leaves that key pending
   afterwards, not lost and not written twice.

The existing coalescing suites are the behavioural net and must stay green as they are, apart
from the one threshold test above:
`tests/includes/Sync/Test_Sync_Journal_Order_Write_Coalescing.php` (9 cases),
`tests/includes/Sync/Test_Integrity_Digest_Write_Coalescing.php` (9 cases),
`tests/includes/Sync/Test_Online_Checkout_Journal_Shape.php`,
`tests/includes/Sync/Test_Integrity_Digest_Upsert_Retry.php`,
`tests/includes/Sync/Test_Sync_Journal_Observation.php`, `tests/includes/Sync/Test_Sync_Observation.php`.

Run nothing through local PHPUnit (the sandbox has no Docker and no wp-env). Paul's agent runs
the suite on the wp-env runner after you finish.

## Budget and rules

- NET production change (additions minus deletions, non-test PHP): at most +40 lines — the new
  class is roughly 130 with docblocks against roughly 100 deleted across the two owners. Tests:
  at most +170 lines net. Do not shrink completed work to satisfy a count; report the real
  numbers.
- WordPress coding standards: run the host linter on every PHP file you change —
  `/Users/kilbot/Projects/woocommerce-pos/vendor/bin/phpcs --standard=.phpcs.xml.dist <files>`
  (readable from the sandbox; the worktree has no vendor). Zero errors before you finish.
- Git is READABLE from the sandbox (`git diff`, `git show`, `git log -S`); writes are not. Do
  not commit; Paul's agent commits with explicit paths.
- Proceed; do not stop to ask. If a stated assumption is wrong, make the smallest reasonable
  choice, record it in your final report, and continue.

## Final report

List: the production diff summary with net line counts per file; phpcs result per file; every
behavioural change you can identify beyond the two listed; anything you chose differently from
this spec and why.
