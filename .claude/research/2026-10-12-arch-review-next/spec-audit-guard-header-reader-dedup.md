# Spec — the core-order audit guard reads the credential through the Auth service

**Lane:** branch from `origin/next` and target `next` (a refactor, not a released-line fix). Line numbers are from `next` @ `837e985f`.

## Goal
`Core_Order_Audit_Guard` carries a private, byte-for-byte copy of `Auth::get_auth_header()`: `includes/Services/Core_Order_Audit_Guard.php:297-319` versus `includes/Services/Auth.php:167-194`. Its docblock cites `Init::get_auth_header_early()`, which no longer exists. Delete the copy and call the Auth service, so the guard and the authenticator can never disagree about which credential a request carries.

## Stakes
**High tier** (the guard is a security boundary on core order routes). The failure to avoid is the guard no longer recognising a WCPOS-token request, which would fail open and accept forged `_pos_*` audit meta through `wc/v3/orders`. No behaviour change is intended: same three sources, same order, same `! empty()` checks (Apache can set an empty `HTTP_AUTHORIZATION`), same sanitisation. Accepted risk: none beyond that.

## Out of scope — do NOT add
- No memoisation, request-identity object or provenance changes.
- Nothing in `record_prior_authentication()`.
- No change to `Auth::get_auth_header()`, `Init.php` hook rows or `Test_Init_Hook_Wiring.php`.
- No new filter, option or env var.
- Do not touch `API.php` or `V1/Auth.php`.
- No plan files.

## Exact changes — `includes/Services/Core_Order_Audit_Guard.php`
1. In `is_wcpos_jwt_authenticated()` (:269-295), replace :278 `$auth_header = $this->get_auth_header();` with `$auth_header = Auth::instance()->get_auth_header();`. `Auth` is in the same namespace (`WCPOS\WooCommercePOS\Services`), so no `use` line is needed. Leave the rest of the method unchanged; it may reuse a local `$auth_service` if you hoist `$auth_service = Auth::instance();` above :278 and use it on both lines.
2. Delete the private `get_auth_header()` and its docblock (:297-319).
3. Keep the file header comment (:1-22) as is.

## Rulings to preserve (and the tests that pin them)
These must stay green unchanged:
- A header-borne WCPOS token on core order writes with audit meta → 403 `woocommerce_pos_rest_audit_meta_forbidden`: `tests/includes/API/Test_Core_Order_Audit_Guard.php`, every `test_audit_guard_jwt_*` (helper `authenticate_via_wcpos_jwt()` :49-55).
- Cookie auth is not guarded: `test_audit_guard_cookie_order_create_with_audit_key_succeeds`, `test_audit_guard_cookie_order_create_with_same_user_bearer_token_succeeds`.
- Hook order on `determine_current_user` priority 20: `tests/includes/Test_Init_Hook_Wiring.php` :185-210 and :388-423.

## Tests — `tests/includes/API/Test_Core_Order_Audit_Guard.php` (extends `WCPOS_REST_Unit_Test_Case`)
Add `unset( $_GET['authorization'] );` to `tearDown()` next to the existing `unset( $_SERVER['HTTP_AUTHORIZATION'] );`. Then add:

1. `test_audit_guard_jwt_query_param_order_update_with_audit_key_returns_forbidden`. This is a characterization test for the one source no test covers today: it must PASS before and after the refactor.
   - Arrange: `$order = OrderHelper::create_order()`; `$order->update_meta_data( '_pos_store', 'original' ); $order->save();`; `$token = Auth::instance()->generate_access_token( get_user_by( 'id', $this->user ) )`; `unset( $_SERVER['HTTP_AUTHORIZATION'] )`; `$_GET['authorization'] = 'Bearer ' . $token;`; `global $current_user; $current_user = null;`; then `$request = new WP_REST_Request( 'PUT', '/wc/v3/orders/' . $order->get_id() )` with body `meta_data` `[{ key: '_pos_store', value: 'forged' }]`.
   - Act: `$response = $this->server->dispatch( $request )`.
   - Assert:
     - `assertSame( 403, $response->get_status() )`
     - `assertSame( 'woocommerce_pos_rest_audit_meta_forbidden', $response->get_data()['code'] )`
     - `assertSame( 'original', wc_get_order( $order->get_id() )->get_meta( '_pos_store' ) )`

Run the new test against the unmodified file first. If it fails before your change, STOP and report: it means the query-param path differs from what this spec assumes.

Delete no tests.

## Budget
- Non-test: at most **30 changed lines**; the net should be negative (about -20).
- Total diff including the test and docblocks: at most **90 lines**.

If you are about to exceed either, STOP and report why instead of continuing. Splitting the work into more files or commits does not raise this budget.

## Verification you can run (no Docker)
- `php -l includes/Services/Core_Order_Audit_Guard.php tests/includes/API/Test_Core_Order_Audit_Guard.php`
- `/Users/kilbot/Projects/woocommerce-pos/vendor/bin/phpcs --standard=.phpcs.xml.dist includes/Services/Core_Order_Audit_Guard.php`
- `grep -rn "get_auth_header_early" includes/` must return nothing.

The orchestrator then runs these in wp-env, one call each:
- `tests/includes/API/Test_Core_Order_Audit_Guard.php`
- `tests/includes/Test_Init_Hook_Wiring.php`

Git is readable from the sandbox (`git log -S`, `git show HEAD:path`); only writes fail. Commit nothing. The orchestrator commits with `git add -u`.

Stop and report only if: this brief contradicts itself, something it names is missing, or a test was already failing before you started. A test you wrote failing first is expected, not a surprise. Anything else you fix and list at the end.

Do not push; do not open or edit a PR; do not reply on any PR.

Proceed; do not stop to ask again — make the smallest reasonable choice and record it in your final report.
