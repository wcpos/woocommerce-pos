# Spec — Pro refund idempotency lock is released by the path that took it (verdict D1)

**Repo / lane:** `woocommerce-pos-pro`, branch from **`origin/main`**. This is a released defect: Pro `Idempotency_Repository.php` is identical on `main` and `next`, and Free has had `claim()`/`release()` but never `reserve()` since v1.9.0. The fix reaches `next` through the normal main→next sync.

## Goal
`Idempotency_Repository::release()` must undo what `reserve()` did. Today `reserve()` takes Pro's own option lock, because Free has no `reserve()`. But `release()` delegates to Free's `release()`, which deletes a different key. One lock option is orphaned per refund request.

## Stakes
High tier (payments). If this fails, every refund leaves a non-autoloaded `wcpos_pro_idempotency_lock_<md5>` row behind. A refund request that throws, and is then retried with the same idempotency key, gets a 409 for 5 minutes. Accepted risk, not handled: concurrent reserve/release races on the same key beyond what `add_option()` already gives. Cleaning up rows orphaned on existing sites is also out of scope (a later decision).

## Out of scope
Do not add locking, retries, new options, cleanup crons, an upgrade routine, env vars, feature flags or new classes. Do not touch `WordPress_Idempotency_Store`, `Contracts\Idempotency_Store`, Free's repository or `Refund_Processor`. Do not change `reserve()`.

## Exact change
File: `includes/Payments/Idempotency_Repository.php`, method `release()` (lines 144-157).

Replace line 151:
```php
		if ( $this->delegate && method_exists( $this->delegate, 'release' ) ) {
```
with:
```php
		// Release whatever reserve() took. reserve() delegates only when the delegate has its own
		// reserve(); Free's repository has claim() instead, so the lock is this class's option.
		if ( $this->delegate && method_exists( $this->delegate, 'reserve' ) ) {
```
Change nothing else in the file.

## Rulings to preserve
- `reserve()` returns `true`, a `wcpos_idempotency_conflict` 409 or a `wcpos_idempotency_in_progress` 409. This is unchanged and pinned by `tests/includes/Payments/Test_Refund_Processor.php::test_refund_processor_idempotency_key_reused_for_different_request_returns_conflict` (through the in-memory store) and by `tests/includes/API/Test_Order_Refunds_Controller.php`.
- `find()`, `store()` and `assert_not_conflicting()` still delegate to Free. Unchanged.

## Tests
Add `tests/includes/Payments/Test_Idempotency_Repository.php`: namespace following the neighbouring Pro tests, class `Test_Idempotency_Repository extends \WP_UnitTestCase`, using Arrange / Act / Assert, `assertSame` / `assertTrue` / `assertFalse`, argument order `( expected, actual )`.

1. `test_idempotency_repository_release_after_reserve_removes_the_lock_option`
   - Arrange: `$repository = new Idempotency_Repository();` `$key = wp_generate_uuid4();`
   - Act: `$reserved = $repository->reserve( 'refund', $key, 'hash-a' ); $repository->release( 'refund', $key );`
   - Assert: `assertTrue( $reserved )`; `assertFalse( get_option( 'wcpos_pro_idempotency_lock_' . md5( 'refund|' . $key ) ) )`.
2. `test_idempotency_repository_reserve_after_release_accepts_a_different_request`
   - Arrange: reserve with `'hash-a'`, then release.
   - Act: `$again = $repository->reserve( 'refund', $key, 'hash-b' );`
   - Assert: `assertTrue( $again )`. Today this returns a `WP_Error` 409 conflict.
3. `test_refund_processor_with_the_wordpress_store_leaves_no_lock_option`. Put it in the existing `tests/includes/Payments/Test_Refund_Processor.php`.
   - Arrange: build the processor the way `create_processor()` does, but pass `new \WCPOS\WooCommercePOSPro\Payments\Adapters\WordPress_Idempotency_Store()` as the first argument. Copy the arrange block of `test_refund_processor_cash_refund_uses_manual_mode_without_gateway_lookup` for the order and params. If `create_processor()` is typed to `In_Memory_Idempotency_Store`, construct `new Refund_Processor( new WordPress_Idempotency_Store(), new Fake_Gateway_Registry() )` directly.
   - Act: `process( $order, $params, $key )`.
   - Assert: `assertFalse( get_option( 'wcpos_pro_idempotency_lock_' . md5( 'refund|' . $key ) ) )`.

Tests 1 and 2 must fail before the one-line change and pass after it. Write them first.

## Budget
Non-test: at most **5 changed lines** (the comment plus the condition). Total diff, including tests and lint docblocks: at most **160 lines**.
If you are about to exceed either, STOP and report why instead of continuing. Splitting the work into more files or commits does not raise this budget.

## Verification (sandbox, no Docker)
- `php -l` on every changed file.
- `/Users/kilbot/Projects/woocommerce-pos/vendor/bin/phpcs --standard=/Users/kilbot/Projects/woocommerce-pos-pro-worktrees/arch-review-next/.phpcs.xml.dist <changed files>`.
- The orchestrator runs afterwards in wp-env: `tests/includes/Payments/Test_Idempotency_Repository.php`, `tests/includes/Payments/Test_Refund_Processor.php`, `tests/includes/API/Test_Order_Refunds_Controller.php` (one file per invocation).

Stop and report only if: this brief contradicts itself, something it names is missing, or a test was already failing before you started. A test you wrote failing first is expected, not a surprise. Anything else you fix and list at the end.
Do not push; do not open or edit a PR; do not reply on any PR.
Proceed; do not stop to ask again — make the smallest reasonable choice and record it in your final report.
Git is readable from the sandbox (`git log -S`, `git show HEAD:path`); only writes fail. Commit nothing; the orchestrator commits with `git add -u` (it will `git add` the new test file itself).
