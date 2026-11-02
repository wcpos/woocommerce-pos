# Spec: c2-6, one write path — the old save/derive, the keeper's lock and the test-guard exemption are gone

**Date:** 2026-10-23 · **Lane:** `next` · **Parent:** `c2-ledger-write.md` (slice c2-6) · **Binding:** ADR 0040 rules 1, 2, 5, 7 and the amendment (the order of the ledger write); `.ai/rules/no-saves-inside-save-hooks.mdc` · **Stakes:** checkout-money, High; the slice is deletion, the behaviour is pinned by `Test_Ledger_Write`, `Test_Ledger`, `Test_Payments_Controller`, `Test_Gateway_Submission`, `Test_Settlement`, `Test_Rest_Dispatch_Money_Model`.

## Prerequisite

After c2-5 (#2439) nothing in `includes/` calls `Ledger::save()`, `derive()`, `replace_and_save()`; the survivors are bookkeeping, listed below. Pro `origin/next` `includes/` calls none of the deleted members; Pro's tests seed rows through `Ledger::save()` and move to `write()` in the Pro companion.

## What goes

| Member | Callers on `origin/next` (f339c1a9) | Disposition |
|---|---|---|
| `Ledger::save()`, `derive()` | none in `includes/`; the test seeders | deleted; the seeders write through `write()` (`tests/Helpers/Ledger_Seed.php`) |
| `$deriving`, `is_deriving()` | `Webview_Passthrough` (mint skip ×2), `Completion_Stamp::has_origin()`, `Ledger::complete()`/`finish()` | `is_writing()`: every payment hook that fires while the ledger writes the order is the ledger's own |
| `$refused`, `save_was_refused()` | `Order_Writer::forward()` (the 409 on a refused keeper save) | deleted with the branch: the keeper takes no lock, so it refuses nothing |
| `$closure_locks`, `release_closure_lock()` and its two hooks, `saving()` | `Order_Writer` ×2, `Mutation_Store` ×2, `Ledger::store_rows()`/`heal()` | deleted; each wrapper becomes the plain save it wrapped |
| `$server_saves` | the keeper's strip, `store_rows()` | the strip skips while `is_writing()` |
| `Save_Reentry_Guard::except( includes/Payments/Contract )` | `tests/bootstrap.php` | removed: the guard watches `includes/` whole |

## What stays

The keeper's strip on `woocommerce_before_order_object_save` (renamed `keep_server_fields`, it was more than closure stamps): a save that carries the ledger outside a write keeps every server-owned field (`seen_events`, `events`, `commands`, `refunds`, `refunded_amount`, `updated_at_gmt`, `closure_id`) as storage holds it, keeps a stamped row the save omits, and re-indexes. It takes no lock, writes nothing of its own, refuses nothing.

`stamp_closure()` already writes meta-only under the H2 carrier with a locking read and write-once closure ids; it does not use the old path and is unchanged.

## Tests

- Deleted as waste: the keeper's lock and refusal tests, the stale-rebase tests (`save()`'s `$read_rows`), the `saving()`/keeper-lock release tests, `derive()`'s direct tests where `Test_Projection` holds the table, `Test_Ledger_Sole_Writer`.
- Kept: every test pinning behaviour a caller relies on; their seeders move from `save()` to `write()`.
- The full suite runs once with the guard watching `includes/` whole.

## Pro companion

`wcpos/woocommerce-pos-pro` `tests/bootstrap.php`: the `except()` line goes; the test seeders move from `Ledger::save()` to `write()`. Merges after the Free PR.
