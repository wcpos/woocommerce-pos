# Codex job spec — digest failure logs: fixed message, error as context

## Goal

Sentry issue 7844419587 ("Sync digest observer failed") opened 12 separate issues from one site. Two log calls in
`includes/Sync/Integrity_Digest.php` concatenate `$e->getMessage()` (the database error text, which carries an
object id) into the log *message*. `Error_Reporter::report_log_error()` fingerprints on the bare message
(`includes/Services/Error_Reporter.php:317-320`), so every object id opens a new Sentry issue. Make both messages
fixed strings and pass the error text as the log *context*, so all failures of one kind group into one issue.

## Stakes

Medium: logging only. No data, sync protocol or REST shape changes. The observer's fail-open behaviour (never
throw from a save hook) and the rebuild lock release must stay exactly as they are.

## In scope (the only files you may edit)

- `includes/Sync/Integrity_Digest.php`
- `tests/includes/Sync/Test_Integrity_Digest_Log_Fingerprint.php` (new)

## Out of scope (do not edit)

- `includes/Logger.php`
- `includes/Services/Error_Reporter.php`
- `includes/Sync/Digest_Index.php`
- `tests/includes/Services/Test_Error_Reporter.php`
- `tests/includes/Services/Stub_Transport.php`
- `tests/includes/Sync/Sync_Store_Test_Case.php`
- Every other file: CHANGELOG, readme, composer/pnpm files, CI config, docs

## Context

- `Logger::error( $message, $context = null )` (`includes/Logger.php:206`). A non-null `$context` is JSON-encoded
  and appended to the log line as ` | Context: ...`; `Error_Reporter::report_log_error()` fingerprints with
  `array( 'wcpos-log', $level, redact_paths( $message ) )` and appends the context to the *event message* only.
  So a fixed `$message` plus `array( 'error' => $e->getMessage() )` as context gives one fingerprint and keeps the
  DB error visible in the log line and in the Sentry event text.
- The two call sites today:
  - `includes/Sync/Integrity_Digest.php:243` in `run_scheduled_rebuild()`:
    `Logger::error( 'WCPOS sync: scheduled integrity digest rebuild failed: ' . $exception->getMessage() );`
  - `includes/Sync/Integrity_Digest.php:454` in `observe()`:
    `Logger::error( 'Sync digest observer failed (sync will self-heal via scan/rebuild): ' . $e->getMessage() );`
- `delete_for()` (`includes/Sync/Integrity_Digest.php:502-529`) runs `$wpdb->delete()` on the digest table inside
  `observe()` and throws `RuntimeException( 'delete stored <label>digest failed: ' . $wpdb->last_error )` when it
  returns false. `record_customer_deleted( int $user_id )` (line 437) reaches it.
- `run_scheduled_rebuild()` calls `( new self() )->rebuild( true )`; `rebuild()` (line 626) first runs
  `DELETE FROM <digest table> ...` and throws on failure.
- Test base: `tests/includes/Sync/Sync_Store_Test_Case.php` installs the sync tables once per class and empties them
  per test. Reporter test seams, as used in `tests/includes/Services/Test_Error_Reporter.php:36-63` and `:788-801`:
  `Error_Reporter::reset_instance()`, `Error_Reporter::set_transport_factory_for_testing( callable|null )`,
  `Error_Reporter::set_dev_override_for_testing( bool|null )`, `Logger::reset_dedup_state()`,
  `delete_transient( Error_Reporter::BACKOFF_TRANSIENT )`, consent via
  `$settings = (array) wcpos_get_settings( 'general' ); $settings['tracking_consent'] = 'allowed';
  \WCPOS\WooCommercePOS\Services\Settings::instance()->save_settings( 'general', $settings );`.
  `\WCPOS\WooCommercePOS\Tests\Services\Stub_Transport` (`tests/includes/Services/Stub_Transport.php`) collects
  events in its public `$events` array; each is a `WCPOS\Vendor\Sentry\Event` with `getMessage()` and
  `getFingerprint()`.
- The reporter sends at most ONE non-fatal event per request (`Error_Reporter.php:565-567`). A test that needs two
  events calls `Error_Reporter::reset_instance()` and `Logger::reset_dedup_state()` between the two failures; the
  transport factory is static and survives `reset_instance()`.

## Interfaces and constraints

1. Line 243 becomes exactly:
   `Logger::error( 'WCPOS sync: scheduled integrity digest rebuild failed', array( 'error' => $exception->getMessage() ) );`
2. Line 454 becomes exactly:
   `Logger::error( 'Sync digest observer failed (sync will self-heal via scan/rebuild)', array( 'error' => $e->getMessage() ) );`
3. Nothing else in `includes/Sync/Integrity_Digest.php` changes.
4. New test class `Test_Integrity_Digest_Log_Fingerprint` in namespace `WCPOS\WooCommercePOS\Tests\Sync`, extending
   `Sync_Store_Test_Case`, file header and docblocks in the style of `Test_Integrity_Digest_Upsert_Retry.php`
   (a `// phpcs:disable` line with a reason is acceptable where that file uses one). setUp: `parent::setUp()`, then
   the reporter setup above with a fresh `Stub_Transport`, dev override `false`, consent `allowed`, and
   `$GLOBALS['wpdb']->suppress_errors( true )` (keep the previous value to restore). tearDown: remove the `query`
   filter, restore `suppress_errors`, transport factory `null`, dev override `null`, `reset_instance()`, delete the
   backoff transient, consent back to `undecided`, `Integrity_Digest::reset_request_state()`, then
   `parent::tearDown()`.
5. Test `test_observer_failures_for_two_object_ids_share_one_fingerprint`:
   - Arrange: two customers (`$this->factory->user->create( array( 'role' => 'customer' ) )`) with ids `$a`, `$b`.
     Add a `query` filter that, for a query starting with `DELETE FROM` and containing the digest table name
     (`( new Integrity_Digest() )->table_name()`), returns it with `` `object_id` = `` replaced by
     `` `object_id` = = `` (a real MySQL syntax error whose text, "near '= <id>'", contains the id).
   - Act: `( new Integrity_Digest() )->record_customer_deleted( $a );` then `Error_Reporter::reset_instance();
     Logger::reset_dedup_state();` then `( new Integrity_Digest() )->record_customer_deleted( $b );`.
   - Assert, in this order: `assertCount( 2, $transport->events )`; event 0's message contains `(string) $a` and
     event 1's message contains `(string) $b` (proves the ids reached the events, so the test is not vacuous);
     `assertSame( array( 'wcpos-log', 'error', 'Sync digest observer failed (sync will self-heal via scan/rebuild)' ), $events[0]->getFingerprint() )`;
     `assertSame( $events[0]->getFingerprint(), $events[1]->getFingerprint() )`.
6. Test `test_scheduled_rebuild_failure_keeps_the_db_error_out_of_the_fingerprint`:
   - Arrange: a `query` filter that replaces the digest table name with the table name followed by `_missing`
     in any query containing it ("Table ... doesn't exist").
   - Act: `Integrity_Digest::run_scheduled_rebuild();`
   - Assert: `assertCount( 1, $transport->events )`; `assertSame( array( 'wcpos-log', 'error', 'WCPOS sync: scheduled integrity digest rebuild failed' ), $events[0]->getFingerprint() )`;
     event 0's message contains `_missing`.
7. Assertion argument order is `( expected, actual )`. Use `assertSame`/`assertStringContainsString`.

## Do not

- Change `Logger`, `Error_Reporter`, or any other log call in the plugin (other files have the same pattern; they
  are out of scope for this job)
- Change the exception messages thrown inside `Integrity_Digest` (`'delete stored ... failed: '` etc.)
- Add retries, fallbacks, new hooks, options or helpers
- Refactor or reformat code outside the two lines
- Create plan, notes or scratch files; commit or push
- Read a GitHub issue, PR or comment raw or paste a stranger's words into code, tests or fixtures

## Pre-authorised actions

- Edit `includes/Sync/Integrity_Digest.php` and create the new test file
- Run the acceptance commands below (wp-env is already running for this worktree; vendor/ is installed; do not run
  `composer install`, `pnpm install` or `wp-env start`)

## Budget

- Max non-test lines changed: 10
- Max total lines changed: 180

If you are about to exceed either, STOP and report why instead of continuing.
Splitting the work across more files does not raise the budget.
The budget is a stop rule, not a target: if the work is about to exceed it,
stop and report the overrun and its reason under NOT DONE. Never strip
tests, comments or formatting to fit.
Run every acceptance command in the foreground and wait for its exit code.

## Acceptance criteria (runnable)

From the worktree root:

```sh
/Users/claude/Projects/woocommerce-pos/.claude/worktrees/session-registry-race/node_modules/.bin/wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Sync/Test_Integrity_Digest_Log_Fingerprint.php
/Users/claude/Projects/woocommerce-pos/.claude/worktrees/session-registry-race/node_modules/.bin/wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Services/Test_Error_Reporter.php
/Users/claude/Projects/woocommerce-pos/.claude/worktrees/session-registry-race/node_modules/.bin/wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' cli -- vendor/bin/phpcs includes/Sync/Integrity_Digest.php tests/includes/Sync/Test_Integrity_Digest_Log_Fingerprint.php
```

All three exit 0; the first reports 2 tests.

## Environment

- Network: no (Docker socket only; wp-env for this worktree is running on 9011/9012 with WooCommerce 11.3.0)
- Writable outside the worktree: none
- Dependencies installed (`vendor/`, `vendor_prefixed/`)

## Output expected

End with the report format from ~/.codex/AGENTS.md (STATUS / CHANGED / ACCEPTANCE / NOT DONE / QUESTIONS /
BEHAVIOUR CHANGES). If the spec is ambiguous or wrong, stop and put the question under QUESTIONS. Do not guess.
