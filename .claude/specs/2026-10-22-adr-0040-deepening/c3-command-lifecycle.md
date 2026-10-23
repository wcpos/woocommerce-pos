# Spec: one command lifecycle for ADR 0040 rule 5 (c3)

**Date:** 2026-10-22 · **Lane:** `next` · **Binding:** ADR 0040 rules 5, 6, 7; c2 spec (`write()`, `Ledger_Draft`, S1 read-back) · **Stakes:** checkout-money, High. A lost entry runs a charge twice; a kept entry replays an answer for a command that never ran. · **Needs:** c2-3 and c7 landed.

## Outcome

One module answers a money command by id: replay, refuse the conflict, or check the revision and run. Every write made for a command carries its entry in the same S1 save as its rows; the entry follows what the write answered and c2's read-back verifies it. `admit()` shrinks to transport (parse, lock, answer). No wire change. The three idempotency stores (`Mutation_Store`, `Command_Log`, `Idempotency_Repository`) stay separate.

## Decision

- **A. Deepen `Command_Log` with `run()`** (picked): the rule-5 docblock already lives there; its seven helpers become implementation.
- **B. `Ledger::command()`**: rejected; the Ledger is 2,600 lines, `Gateway_Submission` is not the Ledger, and the revision check is a lane concern.
- **C. A strategy `Command_Steps` (hold, call, settle, refuse)**: rejected; five of eight kinds have no external call, and it rewrites c2-4/c2-5's bodies past 600 lines.

For the entry: an ambient "open command" read by `write()` is hidden state (c4 removes that pattern for provenance); `$d->remember()/forget()` is hand-application again. **Picked: `write()` takes the command; the entry follows the answer of the write's change.**

## Interface

```php
// includes/Payments/Contract/Money_Command.php — immutable value; private props, getters (PHP 7.4)
public static function parse( string $kind, WP_REST_Request $r ); // self|null|WP_Error: 400 rest_invalid_param, 428 wcpos_revision_required
id(), fp(), kind(), target() /* row uuid | method | '' */, ref() /* refund_id | attempt_id | null */, revision()

// Command_Log
public static function run( WC_Order $order, Money_Command $c, callable $work ); // $work( Money_Command ): answer
// Ledger (c2 signature, plus one optional parameter)
public function write( $order, ?callable $change = null, string $mode = self::MODE_AUTO, ?Money_Command $c = null ): Ledger_Written;
```

**`run()`, in this order** (rule 6 requires replay before revision):
1. `find()` the id order-wide. A different `fp` is 422 `wcpos_command_conflict` with `command_id` and `kind`; a `record` conflict also carries `payment`.
2. If an entry is found, replay it (table below) and stop.
3. Refuse a stale revision: 409 `wcpos_revision_stale`. This is the controller's `stale()` (:488) moved here, on c7's `Revision::stale()`.
4. Otherwise `$answer = $work( $c )`.
5. If `$answer` is not a `WP_Error` and a fresh read holds no entry, call `write( $order, fn() => $answer, MODE_HOLD, $c )`. (a 2xx that changed nothing keeps its entry).

**What `write()` does with `$c`, at S1** (`Command_Log::stage()`, called only by `write()`):
- **Placement:** `record` on the row whose id is the command id; `intent`/`capture`/`void`/`refund` on the target row (missing row: `LogicException`); `submit`/`cancel`/`close` on `_wcpos_commands`.
- **Effect, from `$change`'s answer:** a `WP_Error` removes the entry (forget on definite failure); `null` leaves it; anything else puts `{fp, kind, ref, outcome?, at_gmt}`, `ref` from `$c` (for `close`, S3 fills it with the completion it appends, in that save), `outcome` = `$answer['outcome']` (submit only), a stored `at_gmt` kept.
- **Read-back:** c2's S1 read-back compares `fp`, `kind`, `ref`, `outcome` (or absence); a miss retries once, then throws `rows` → 409. The handler decides "definite" by what its change answers:

| write | answers | entry |
|---|---|---|
| record, including a refused row | the row | put |
| refund hold, refund strand | the held row | put / kept |
| refund withdraw | the refusal | removed |
| intent pending; intent indeterminate | the row | put |
| intent refused, or a determinate failure on resume (:823, :916) | the error | removed |
| capture/void apply; amount mismatch | the row; the refusal | put; removed |
| submit hold / settled / minted | `outcome: sent / settled / recorded` | put |
| submit definite failure; throw after dispatch | the error; `null` | removed; kept |
| cancel, close | the answer | put |

**Replay answers** (`Command_Log`, moved from controller `replay()` :448–480). From storage, after `write( $order )` (c2's heal; saves nothing on a healthy order). Transport adds `revision`.

| kind | answer |
|---|---|
| record | the row, or its stored refusal (`refusal_error`). The heal drains the row's parked settlement, as :631 does today |
| intent | if the row is `pending`, run `$work` again (it resumes at the provider for the lost handoff). Otherwise `{payment, order, handoff: {}}` |
| capture, void | `{payment, order}` |
| refund | `{payment}` |
| cancel | `{order}` |
| close | `{order, completion}` by `ref`. A null `ref` names the completion that stands, if any |
| submit | attempt fate `cancelled` or `superseded`: 409 `wcpos_payment_conflict`. Entry `outcome: recorded` (D6): `recorded` with the row. Fate `paid`: `paid`. Fate `sent` or `settled`: that fate. No fate (`ATTEMPTS_CAP` evicted it): the entry's outcome. `payment` is null except for `recorded` |

## Transport

The route closure: lock → `get_order` (404) → `Money_Command::parse` (400, 428) → `Command_Log::run( $order, $c, fn( $c ) => provenance-wrapped handler )` → `answer()`. `admit()` is deleted. A `record` with no string `payment.id` parses to `null` and runs as today (its handler answers 400). In-flight 409 stays the lock's; the `Ledger_Persistence_Exception` catch is unchanged. `array $command` becomes `?Money_Command` on the handlers, the Ledger verbs' trailing optional parameter, `with_command` and `remember_command`.

## Dispositions

- **`Ledger::replay` 422 (:1616–1655) stays.** It is reachable: an `intent` under a new command id naming an existing row with another body. For `record` it guards the public method. `record()`'s replay branch shrinks to `return $this->replay(…)`; its heal moves to the wrapper. Pins `Test_Ledger` :575, :923, :1959 stay.
- **Deleted in c3:** `remember_close`, `Ledger_Draft::command()` and c2-3's two callers; the three entry-only writes (intent :797, `apply_result` :1003, refund :1103) and `Gateway_Submission::answered()` (step 5 covers them).
- **Deleted by c2-4** instead of ported: `with_command` (879, 1068, 1146, 1186), `without_on_row` (823), the entry half of `withdraw`/`reserved` (1326–1345, 1373). **By c2-5:** `remember_command`, `command_kept`, `verify_command`, `forget` (233, 432, 528, 546); until then they work unchanged, typed on `Money_Command`.
- **Attempt history keeps fates only** (`sent`, `settled`, `paid`, `cancelled`, `superseded`): it refuses a second dispatch and feeds close's in-motion check, the sweeper and cancel. What a command first answered (including `recorded`, not a fate) lives only in its entry. The same-id fallback at :89–97 is deleted; :66–88 stay (a new command id naming a known attempt).
- **Defect, fixed here:** `Ledger::to_wire()` keeps `commands`. Pro's `Payments_Answer_Controller.php:93` therefore answers the row's command ids and fingerprints, against README "`payment.commands` is never answered". `to_wire()` drops the key, and `answer()`'s two unsets go (found by reading; not reproduced).

## Error codes (client contract: none renamed)

Unchanged, same triggers and data: 422 `wcpos_command_conflict` (other body); 409 `wcpos_payment_locked` (same id concurrent); 409 `wcpos_revision_stale` (fresh, stale); 428 `wcpos_revision_required`; 400 `rest_invalid_param` (bad id); 409 `woo_rxdb_sync_record_locked` (entry did not land); 409 `wcpos_payment_conflict` (submit replay of a cancelled/superseded attempt). No new code.

## Tests

- **Survive unchanged (the behavioural spec):** `Test_Payments_Controller`'s replay/command/revision tests, among them 224, 294, 1272, 1298, 1322, 1352, 1430, 1503, 1598, 1643, 1748, 1772, 1831, 1856, 1883, 1910, 2011, 2045, 2082, 2114, 2135, 2165, 2203, 2256, 2280; `Test_Gateway_Submission` 219, 328, 614, 987, 1331, 1363, 1387, 1418, 1447, 1486, 1529, 1565, 1598, 1634 and PR 1's D6 pin.
- **Edited:** `Test_Ledger::refund_command()` and `Test_Fiscal_Record_Writers` :1369/:1374 build a `Money_Command`.
- **Waste:** none in c3; c2-4 removes `Test_Ledger` :3884 (the entry compare inside `reserved()`).
- **New `Test_Command_Log`** (HPOS and postmeta): `test_run_with_a_fresh_id_and_current_revision_runs_once_and_keeps_the_entry`; `test_run_with_the_same_id_and_body_replays_without_running_whatever_the_revision` (provider: 8 kinds); `test_run_with_another_body_is_422_and_runs_nothing`; `test_run_with_a_fresh_id_and_stale_revision_is_409_and_runs_nothing`; `test_run_whose_work_answers_without_writing_keeps_the_entry_in_one_write`; `test_write_places_row_kind_entries_on_the_row_and_order_kinds_on_the_order`; `test_write_whose_change_answers_an_error_removes_the_entry_and_null_leaves_it`; `test_write_whose_entry_does_not_read_back_throws_rows_and_the_retry_replays`; `test_close_entry_lands_with_its_completion_ref_in_the_completion_save`; `test_submit_replay_follows_the_fate_table` (provider); `test_replay_heal_write_saves_nothing_on_a_healthy_order`; `test_to_wire_omits_commands`.

## Pro impact

Pro `origin/next` calls `apply_result` (`Payments_Answer_Controller:88`), `refund`, `void`, `intent`, `capture` without a command: default `null`, unchanged. No Pro hits for `Command_Log`, `_wcpos_commands`, `wcpos_command_conflict`. The `to_wire` fix ends Pro's leak with no Pro change. Run Pro's `Test_Payments_Answer_Controller` and conformance suite by hand (Pro `next` runs no PHP tests on push).

## Sequence and budget

Needs c2-1 (`write`, `Ledger_Draft`), **c2-3** (`record`, `close` on `write()`, S3 carrying the close ref) and c7 (`Revision::stale`); not c2-4/c2-5. **Proposed order c2-3 → c3 → c2-4 → c2-5 → c2-6**, so c2-4/c2-5 delete hand-applied entries rather than port ~13 `$d->command()` calls for c3 to delete. This changes c2's slice table: **needs the orchestrator's yes.**

Estimate ~390 added / ~200 deleted production lines: `Money_Command` ~110, `Command_Log` (`run`, replay table, `stage`, moved `stale`) ~190, `Ledger` ~45, controller ~30, `Gateway_Submission` ~15. Cap 600: past 3× any line, or near 600, stop and report.

## Rulings (orchestrating session, 2026-10-22)

- Slice order approved: c2-1, c2-2, c2-3, **c3**, c2-4, c2-5, c2-6. c3 also needs c7 merged.
- D12 (found here): a row answered to the till must never carry its `commands` map. `to_wire()` also shapes the stored rows, so the fix is one answer-shaping function in Free that strips the map, used by `Payments_Controller::answer()` and offered to Pro's `Payments_Answer_Controller` (Pro companion PR on `next`, since Pro calls `to_wire()` directly at :93). Lands in c3; the Pro companion merges after the Free function exists.
- `Ledger::replay`'s 422 branch stays (reachable by an intent under a new command id naming an existing row with a different body).
