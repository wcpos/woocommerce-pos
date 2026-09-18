# Create Identity: the v2 create-identity protocol written once

Candidate 12 of the 2026-09-17 architecture review (tracking issue #2007, closed). Paul ruled on the three contested points on 2026-09-18 (§1). Proceed without asking questions; if a detail here does not match the tree, take the reading that matches the tree, note it in your report, and keep going. Revision 2: the budget in §Budget was measured wrong in revision 1 (the fake-store move alone is ~310 diff lines, the controller extraction removes ~60) and is corrected; **do not stop again before editing**. The tree is already at the right base; do not pull or fetch anything. Stakes tier: **High** (the `wcpos/v2` push lane creates orders and customers; a wrong identity stamp keys a till on the wrong record). This is a **pure move with no wire change**: every `(error code, HTTP status)` pair reachable today stays reachable from the same situation, every store call keeps its order, and every existing test in `tests/includes/Sync/Test_Write_Controller.php` passes unchanged except where §5 says otherwise.

## Why

`includes/API/V2/Write_Controller.php` writes the create-identity sequence twice. `apply_create()` (~line 372) runs it for a fresh create after the wc/v3 forward: poison checkpoint → writer persist → `persist_uuid` → `resolve_id_by_uuid` verification → writer persist → `finalize_poison`. `retry_identity_stamp()` (~line 645) runs it again for a retry that hits a `poison` checkpoint, with its own pre-checks and three differently worded 500s. The writers (`includes/API/V2/Writers/*`) receive the phase as a string through `persist( string $phase, … )` and branch on it. The seam is: **one Sync module owns the proof that a created record carries its client UUID, both lanes call it, and writers get named lifecycle methods.**

## 1. Rulings (Paul, 2026-09-18)

1. **Home.** A new module `includes/Sync/Create_Identity.php` (`final class Create_Identity`, namespace `WCPOS\WooCommercePOS\Sync`) owns the sequence. `Mutation_Store` stays a table gateway: do not add orchestration to it, and do **not** introduce a `Mutation_Store_Interface`. The module takes the duck-typed store exactly as the controller does (`@var mixed`, tests inject `Fake_Mutation_Store`).
2. **Writer API.** Replace `Collection_Writer_Interface::persist( string $phase, … )` with four named methods and delete `persist()` outright (no shim). The interface is `@internal`, five weeks old, implemented only by the four in-repo writers; Pro does not implement it (checked 2026-09-18).
3. **Wire errors.** Same codes and statuses; one wording per code. The retry lane's 422 `woo_rxdb_sync_identity_conflict` stays a pre-check before re-entry. The three retry-lane 500 messages collapse to the fresh lane's: `Unable to persist created record identity.` No test pins a message (checked); tests pin codes and statuses.

## 2. `Sync\Create_Identity`

Constructor: `__construct( $store )`. Two public entry points; the identity proof between them is written once (a private method), which is the point of the module.

- `public function stamp( array $meta, array $m, int $new_id, int $response_status, Collection_Writer_Interface $writer )` — the fresh lane, called by `apply_create()` after the forward has produced `$new_id > 0`. Returns `int $new_id` on success or `WP_Error`. Exact order, matching today's `apply_create()` lines 410–435:
  1. `$checkpointed = $store->mark_poison( mutationId, $new_id, $response_status )`.
  2. `$writer->after_create( $new_id, $m['payload'] )` (today's `'create_before_identity'`).
  3. Prove identity: `persist_uuid( id_type, $new_id, recordId )`; on `false` the identity error is `woo_rxdb_sync_identity_persistence_failed` (500). Otherwise `resolve_id_by_uuid( id_type, recordId, $meta )`; a `WP_Error` is the identity error as-is; a resolved id `!== $new_id` is the same persistence-failed error.
  4. **Only now** check `$checkpointed`: on `false`, `mark_indeterminate( mutationId, $new_id, $response_status )` and return `woo_rxdb_sync_finalize_failed` (500, today's `finalize_error()` wording). The identity is proven before this return on purpose: `test_create_checkpoint_failure_stamps_known_identity_before_returning` pins it.
  5. If there was an identity error, return it (the poison row stays, so a retry re-enters through `recover()`).
  6. `$writer->after_identity( $new_id, $m['payload'] )` (today's `'create_after_identity'`).
  7. `finalize_poison( mutationId, $new_id )`; `false` → `woo_rxdb_sync_finalize_failed` (500).
- `public function recover( array $meta, array $m, array $hit, Collection_Writer_Interface $writer )` — the retry lane, called by `replay_or_conflict()` when the stored row's status is `poison`. Returns `array{ id: int, status: int }` (the `status` is `$hit['response_status']` cast to int, or 201 when absent) or `WP_Error`. Exact order, matching today's `retry_identity_stamp()`:
  1. `$hit['record_uuid'] !== $m['recordId']` → `woo_rxdb_sync_identity_conflict` (422, message unchanged: `recordId disagrees with the stored mutation identity.`).
  2. `'create' !== $hit['operation']` or `remote_id <= 0` → `woo_rxdb_sync_identity_persistence_failed` (500, unified wording).
  3. `resolve_id_by_uuid( id_type, record_uuid, $meta )`: a `WP_Error` returns as-is; `> 0` and `!== remote_id` → persistence-failed (500, unified wording).
  4. Prove identity (the same private method as the fresh lane: `persist_uuid` then `resolve_id_by_uuid` must equal `remote_id`; either failure → persistence-failed 500).
  5. `$writer->after_recovery( remote_id, $m['payload'] )` (today's `'create_recovery'`).
  6. `finalize_poison( mutationId, remote_id )`; `false` → `woo_rxdb_sync_finalize_failed`.

The error constructors live in this module (one private method per code so the wording exists once). `Write_Controller::finalize_error()` stays for the update/delete paths; the create paths use the module's. Either move the `finalize_failed` wording into a public constant on the module and have the controller read it, or leave both literals identical — pick one and say which.

Class docblock: what the proof is and why it exists (a created record must own its client UUID before the mutation is finalized, or the till keys on the wrong record), that the retry lane is the same proof re-entered against a `poison` checkpoint, and that ADR 0038 governs *when* identity is re-proved (this module does not change that; it moves *where* the sequence lives). One line each for the four writer hooks and which lane fires them.

## 3. `Write_Controller` changes

- Construct `new Create_Identity( $this->store )` in the constructor and hold it in a private property.
- `apply_create()`: everything after `$new_id` is computed and checked (`create_no_id` handling stays in the controller unchanged) becomes `$stamped = $this->identity->stamp( $meta, $m, $new_id, $response->get_status(), $writer ); if ( is_wp_error( $stamped ) ) { return $stamped; }` followed by today's `envelope_document(...)` call.
- `replay_or_conflict()`: the `poison` branch becomes `$recovered = $this->identity->recover( $meta, $m, $hit, $writer ); if ( is_wp_error( $recovered ) ) { return $recovered; } return $this->envelope_document( $this->document_for( $meta, $recovered['id'] ), $m['recordId'], $meta, $recovered['id'], $recovered['status'], $writer );`. Delete `retry_identity_stamp()`.
- `apply_update()` line ~523: `$writer->persist( 'update', … )` becomes `$writer->after_update( $id, $m['payload'], $current_bare, $data_array, $prepared['context'] )` with the same arguments.
- Remove imports that become unused. Nothing else in the controller changes.

## 4. Writers

`Collection_Writer_Interface`: delete `persist()`; add, with one-line docblocks in the file's existing terse style:

```php
/** After the wc/v3 create returned an id, before the client UUID is proven on it. */
public function after_create( int $id, array $payload ): void;
/** After the client UUID is proven on the created record, before the mutation is finalized. */
public function after_identity( int $id, array $payload ): void;
/** On a retry that re-proves a poisoned create's identity, before it is finalized. */
public function after_recovery( int $id, array $payload ): void;
/** After a wc/v3 update succeeded, before the mutation is finalized. */
public function after_update( int $id, array $payload, array $current, array $response_data, array $context ): void;
```

- `Null_Writer`: four empty bodies replace `persist()`.
- `Order_Writer`: split today's four `persist()` branches into the four methods, bodies unchanged (`after_create` → `persist_tax_ids( …, true )`; `after_identity` → audit stamp `true` + creation note; `after_recovery` → audit stamp `false` + `persist_tax_ids( …, true )`; `after_update` → till meta, `persist_tax_ids( …, false )`, cashier/store reassignment, `clear_email`).
- `Customer_Writer`: `after_create`, `after_recovery`, `after_update` each write tax ids when `$id > 0` and `tax_ids` is an array; `after_identity` is inherited empty. Keep the one-line guard shape.
- `Variation_Writer` inherits.
- Update the interface's `@internal` paragraph only where it mentions `persist`; leave the callable follow-up sentence.

## 5. Tests

- **New `tests/includes/Sync/Test_Create_Identity.php`** (unit, no REST dispatch, no route literal). To share `Fake_Mutation_Store`, move it out of `Test_Write_Controller.php` into `tests/Helpers/FakeMutationStore.php` (keep the class name `Fake_Mutation_Store` and its namespace so the existing test needs only its `use`/require adjusted; add the `require_once` to `tests/bootstrap.php` beside the other Helpers). Use a tiny in-file spy writer implementing the interface that records the method names it received, in order. Cases (Arrange / Act / Assert, `assertSame`, expected first, `test_[feature]_[scenario]_[expected_result]`):
  1. fresh stamp, happy path: store calls in order `mark_poison`, `persist_uuid`, `resolve_id_by_uuid`, `finalize_poison`; writer receives `after_create` before `persist_uuid` and `after_identity` after the resolve; returns the id.
  2. fresh stamp, `mark_poison` returns false: uuid still persisted, `mark_indeterminate` called, result code `woo_rxdb_sync_finalize_failed` status 500, `after_identity` never called.
  3. fresh stamp, `persist_uuid` returns false: code `woo_rxdb_sync_identity_persistence_failed` status 500, `finalize_poison` never called, `after_identity` never called.
  4. fresh stamp, resolve returns a different id: same code and status as 3.
  5. fresh stamp, resolve returns a `WP_Error`: that error is returned unchanged.
  6. recover, record uuid differs: `woo_rxdb_sync_identity_conflict` 422, no store write.
  7. recover, hit is not a create (or remote_id 0): persistence-failed 500, no store write.
  8. recover, resolve points at another record: persistence-failed 500, `persist_uuid` never called.
  9. recover, happy path: `persist_uuid` then resolve then `after_recovery` then `finalize_poison`; returns `['id' => 4242, 'status' => 200]` when the hit carries `response_status` 200 and 201 when it does not.
  10. every 500 from both lanes carries the message `Unable to persist created record identity.` for the persistence-failed code (one wording, ruling 3).
- **`Test_Write_Controller.php`**: passes unchanged apart from the fake-store relocation. Do not edit assertions. If one fails, the move is wrong; fix the move.
- **Writer tests** under `tests/includes/Sync/Writers/`: none call `persist()` directly (checked); adjust only if a spy or stub implements the interface.
- **You cannot run PHPUnit here**: the sandbox has no Docker and the suites run only through wp-env. The orchestrator runs `Test_Create_Identity`, `Test_Write_Controller`, `Test_Order_Write_Parity`, `Test_Order_Write_Contract_HPOS`, the four writer tests, `Test_Mutation_Store`, `Test_Mutation_Replay_After_Prune` and `Test_Mutation_Retention` after you finish, so write the tests to pass first time: mirror the fixtures and helpers `Test_Write_Controller.php` already uses. Run `php -l` on every changed PHP file. Run `php scripts/lane-coverage.php --write` and confirm the new file has no `wcpos/v1`-only case (it dispatches no route at all); leave the inventory files untracked.
- phpcs on every changed PHP file, using the host binary: `/Users/kilbot/Projects/woocommerce-pos/vendor/bin/phpcs --standard=.phpcs.xml.dist <files>`. phpstan is run by the orchestrator.

## 6. `CONTEXT.md`

Add under `### Sync`, after **Write Payload shape**:

```
**Create Identity**:
The proof that a record born on the `wcpos/v2` push lane owns its client UUID before the mutation is finalized: poison checkpoint, UUID persisted, resolved back to the same id, checkpoint finalized. Written once in the Create Identity module; a retry against a `poison` checkpoint re-enters the same proof rather than running a second copy. ADR 0038 decides when identity is re-proved; this module decides where the proof lives.
_Avoid_: poison retry, identity stamp, recovery path
```

## Out of scope

No new options, filters, constants for tunables, or env vars. No `Mutation_Store_Interface`. No change to `Mutation_Store`. No change to update or delete paths beyond the `after_update` rename. No change to replay, reservation, locking or fingerprint logic. No change to any error code or HTTP status. No changes to `wcpos/v1`. No lane-coverage `--compare` run.

## Budget

Production code net **+110 lines at most** (the controller loses about 60, the module adds about 140, the interface and writers add about 30). Total diff including the new test file and the fake-store move **≤ 1000 lines** (the move alone is ~310). If you are about to exceed either, STOP and report why instead of continuing; splitting the work across files or commits does not raise the budget.

## Report

Under 25 lines: the files changed with net lines each, the `php -l` and phpcs results, the lane-coverage confirmation, and any reading you took that differs from this spec.
