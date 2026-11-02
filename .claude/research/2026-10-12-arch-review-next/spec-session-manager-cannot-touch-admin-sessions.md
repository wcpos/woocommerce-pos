# Spec — a shop manager cannot list or end an administrator's POS sessions

**Lane:** branch from `origin/main` and target `main`. The bug is on the released line at the same code (`git show origin/main:includes/Services/Auth.php`, `can_manage_user_sessions` at :805-823), and it reaches `next` through the normal main→next sync. The line numbers below are from `next` @ `837e985f`; on `main` they differ by at most 1–2 lines, so locate code by method name.

## Goal
A user without `manage_options` must not read, list or revoke an administrator's POS sessions. Shop managers keep managing everyone else's sessions, cashiers included.

## Stakes
**High tier** (capability check on authentication data). A failure either locks administrators out of the POS and exposes their IPs and user agents (today's bug), or stops managers from signing out cashiers (the regression to avoid). Bug fix in a High-tier area, so the failing test comes first. Accepted risk, not addressed here: one shop manager can still sign out another shop manager (today's behaviour, deliberately unchanged).

## Out of scope — do NOT add
No new capability, filter, option, env var, route, error code or validation layer. Do not reuse `Permission_Rules::can_modify()`. It protects every `edit_users` holder, the Cashier role holds `edit_users` (`includes/Activator.php:581`), and reusing it would stop managers revoking cashier sessions. Do not change `check_admin_permissions()`. No logging, no refactor of the revoke methods, no plan files.

## Exact changes

### 1. `includes/Services/Auth.php` — `can_manage_user_sessions()` (:798-824)
Keep the signature `public function can_manage_user_sessions( int $target_user_id ): bool`. Replace the shop-manager branch (:818-821):

```php
		// Shop managers manage everyone's sessions except an administrator's:
		// the staff rule (#1918) — WooCommerce likewise refuses a manager acting on an administrator.
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return ! user_can( $target_user_id, 'manage_options' );
		}
```

Leave the self branch (:808-811) and the administrator branch (:813-816) untouched and in the same order. Self comes first, so an administrator who is also a target of their own request stays allowed.

### 2. `includes/API/V1/Auth.php` — `get_all_users_sessions()` (:450-509)
Inside the `foreach ( $user_ids as $user_id )` loop, directly after the `if ( ! $user ) { continue; }` block (:462-465), add:

```php
			// Only rows the caller may manage: a shop manager does not see administrators' sessions.
			if ( ! $auth_service->can_manage_user_sessions( (int) $user_id ) ) {
				continue;
			}
```

Nothing else in the file changes. `check_session_permissions()` (:518-534) already routes GET `/auth/sessions`, DELETE `/auth/sessions` and DELETE `/auth/sessions/{jti}` through `can_manage_user_sessions()`, so change 1 covers all three.

## Rulings to preserve (and the tests that pin them)
- An administrator manages anyone's sessions: `Test_Auth_Service::test_admin_can_manage_all_sessions`, `Test_Auth_API::test_check_session_permissions_for_admin`.
- A shop manager manages a non-staff user's sessions: `Test_Auth_API::test_check_session_permissions_for_shop_manager`.
- A user manages their own sessions: `test_can_manage_own_sessions`, `test_check_session_permissions_for_own_sessions`.
- A subscriber cannot manage others' sessions: `test_non_admin_cannot_manage_others_sessions`, `test_check_session_permissions_for_regular_user`.
- A logged-out user is refused: `test_check_session_permissions_when_not_logged_in`.
- A shop manager may call `/auth/users/sessions`: `test_check_admin_permissions_for_shop_manager`.
- An administrator's listing includes every user and marks the current session: `test_get_all_users_sessions`.

## Tests
Write the new tests first and run them before any production edit. The administrator-target rows must FAIL on the current code; the cashier row passes before and after.

**`tests/includes/Services/Test_Auth_Service.php`** (extends `WP_UnitTestCase`; `$this->test_user` is an administrator). Add:
1. `test_can_manage_user_sessions_shop_manager_targeting_administrator_returns_false`
   - Arrange: create a `shop_manager` user and `wp_set_current_user()` to them.
   - Act: `can_manage_user_sessions( $this->test_user->ID )`.
   - Assert: `assertFalse`.
2. `test_can_manage_user_sessions_shop_manager_targeting_cashier_returns_true`
   - Arrange: a `shop_manager` as current user, and a user with role `cashier` (the role exists in the test env; see `tests/includes/API/Test_Cashier_Core_User_Routes.php`). Precondition: `assertTrue( user_can( $cashier, 'edit_users' ) )`, which guards against a future `can_modify()` reuse.
   - Act: `can_manage_user_sessions( $cashier_id )`.
   - Assert: `assertTrue`.
3. `test_can_manage_user_sessions_administrator_targeting_other_administrator_returns_true`
   - Arrange: current user `$this->test_user`, plus a second administrator.
   - Act / Assert: `assertTrue`.

**`tests/includes/API/Test_Auth_API.php`** (extends `WP_UnitTestCase`, calls controller methods directly and dispatches no route; keep it that way so the REST lane-coverage gate is not involved). Add:
4. `test_check_session_permissions_shop_manager_targeting_administrator_returns_false`
   - Arrange: `wp_set_current_user( $this->shop_manager_user->ID )`; `$request = $this->create_request( array( 'user_id' => $this->admin_user->ID ) )`.
   - Act: `$this->api->check_session_permissions( $request )`.
   - Assert: `assertFalse`.
5. `test_get_all_users_sessions_shop_manager_omits_administrator_rows`
   - Arrange: `generate_refresh_token()` for `admin_user`, `regular_user` and `shop_manager_user`; current user `shop_manager_user`.
   - Act: `$this->api->get_all_users_sessions( $this->create_request() )`, then collect `array_column( $data['users'], 'user_id' )`.
   - Assert:
     - `assertNotContains( $this->admin_user->ID, $ids )`
     - `assertContains( $this->regular_user->ID, $ids )`
     - `assertContains( $this->shop_manager_user->ID, $ids )`
     - `assertSame( count( $data['users'] ), $data['total'] )`
6. `test_delete_all_sessions_shop_manager_cannot_revoke_administrator_sessions_via_permission_check`
   - Arrange: `generate_refresh_token( $this->admin_user )`; current user shop manager; request `array( 'user_id' => $this->admin_user->ID )`.
   - Act: `$this->api->check_session_permissions( $request )`.
   - Assert: `assertFalse`. Also assert the admin still has 1 session through `$this->auth_service->get_user_sessions( $this->admin_user->ID )`, which proves nothing was revoked by Arrange.

Conventions: Arrange / Act / Assert comments, `assertSame` over `assertEquals`, `( expected, actual )`. Delete no tests.

## Budget
- Non-test: at most **15 changed lines** in the two production files (expected about 8).
- Total diff including tests and WordPress-standards docblocks: at most **170 lines**.

If you are about to exceed either, STOP and report why instead of continuing. Splitting the work into more files or commits does not raise this budget.

## Verification you can run (no Docker)
- `php -l includes/Services/Auth.php includes/API/V1/Auth.php tests/includes/Services/Test_Auth_Service.php tests/includes/API/Test_Auth_API.php`
- `/Users/kilbot/Projects/woocommerce-pos/vendor/bin/phpcs --standard=.phpcs.xml.dist includes/Services/Auth.php includes/API/V1/Auth.php`

The orchestrator then runs these in wp-env, one call per file:
- `tests/includes/Services/Test_Auth_Service.php`
- `tests/includes/API/Test_Auth_API.php`
- `tests/includes/API/Test_POS_Endpoint_Permissions_Matrix.php`

Git is readable from the sandbox (`git log -S`, `git show HEAD:path`); only writes fail. Commit nothing. The orchestrator commits with `git add -u` (the new tests live in existing files).

Stop and report only if: this brief contradicts itself, something it names is missing, or a test was already failing before you started. A test you wrote failing first is expected, not a surprise. Anything else you fix and list at the end.

Do not push; do not open or edit a PR; do not reply on any PR.

Proceed; do not stop to ask again — make the smallest reasonable choice and record it in your final report.
