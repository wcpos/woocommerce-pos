# Explorer findings — auth, POS sessions, permissions, REST gate, settings

Read on Free `837e985f` and Pro `05f06c4`. Verbatim explorer output.

## A) Deepening candidates

### 1. One module for the POS session lifecycle (issue, refresh, revoke, expire, web cookie). Strong.
- Files: `includes/Services/Auth.php` (1047), `Session_Registry.php` (770), `includes/API/V1/Auth.php` (591), `Templates/Frontend.php:84-87`, `Templates/Login.php:72-74`, `Templates/Auth.php:339-340`, event hooks in `Init.php` (row added by ec90a213).
- Auth has five revoke entry points with three blacklist policies: `revoke_refresh_token` (690), `revoke_session` (alias, 745), `revoke_session_with_blacklist` (858), `revoke_all_refresh_tokens` (708), `revoke_all_sessions_except` (765). Registry adds `revoke` (344), `revoke_all` (703), `keep_only` (719).
- `get_user_data(..., true)` (562-576) hides a revoke and a `setcookie` behind a flag; cookie name literal twice (1000, 1028). Part B `rotate_web_session` does not exist in Free or Pro.
- Blacklist transients are dead weight: `is_live()` runs on every access token (299); `blacklist_token` only ever called with refresh JTIs (498, 719, 779, 868), so the access-JTI check at 274 never matches.
- Sept 30 bugs landed in four modules: ec90a213 (Init + Auth), 03a6e47b (`validate_token` 292-308 vs `refresh_access_token` 649), e9f96c48 (six raw `get_user_meta` readers folded into `read_row()` 372; `revoke()` 345 and `keep_only()` 720 still read raw), 5e186631 (V1 controller 251-288).
- Two login templates, different policies: `Templates/Auth.php` skips `wp_authenticate`, own rate limit; `Login.php:42` uses `wp_signon`, no WCPOS limit.
- Deletion test: Auth's revoke/activity/list methods are pass-throughs (690-692, 733-735, 745-747, 794-796). Session_Registry earns its keep.
- Tests: `Test_Auth_Service`, `Test_Session_Registry`, `Test_Session_Revocation_On_Account_Events`, `Test_Auth_Revocation_Survives_Cache_Eviction`, `Test_Auth_Refresh_Status`, `Test_Auth_API`. Web-frontend path still permanently skipped (`Test_Auth_Service.php:978`). Platform override now covered (`Test_Session_Registry.php:205-298`). `Templates\Auth` form flow and `Login.php` have no tests (only `validate_redirect_uri` via reflection).
- Pro: none.

### 2. One module that answers "who is this request, and how did they authenticate". Strong.
- Resolved in five places: `Init.php:391-403` (`determine_current_user_early` @20, registered first); `API.php:152-282, 595-606` (three hooks, three flags `is_auth_checked`, `auth_error`, `authenticated_via_wcpos`); `Core_Order_Audit_Guard.php:57-61, 269-319` (own provenance check + copy of header reader); `API/V1/Auth.php:555-590` (`get_header` then param, different order from `$_SERVER`); `Form_Handler.php:79-88` (`$_GET` `wcpos_jwt`/`token`).
- Duplicated header reader: `Auth.php:179-191` and `Core_Order_Audit_Guard.php:306-316` (`HTTP_AUTHORIZATION`, `REDIRECT_HTTP_AUTHORIZATION`, `$_GET['authorization']`).
- Double validation (inferred from hook order, not traced live): Init's filter runs first, API's filter sees a user id and trusts it (158); API's own filter probably never fires (WC #26847, admitted at 144-146); re-validates in `rest_authentication_errors` (210). Token decoded 2-3× per request, each decode calls `touch()`.
- A WCPOS token logs the user in on every REST route (`Core_Order_Audit_Guard.php:5-10`), so guards get patched per surface (audit guard; `Permission_Rules::map_user_meta_caps` 2766ff59).
- Tests: `Test_Auth_Refusal_Reason`, `Test_Permissions`, `Test_Init_Hook_Wiring`. Memory: `determine_current_user` order is a security boundary.

### 3. Settings policy that lives outside the Section. Strong for the gateway rule, Worth exploring otherwise.
- `includes/API/V1/Settings.php:36-75` (per-section permission callbacks, legacy slugs, flat-error shape keyed by section id in the controller); `:116, 413-425` (free-tier cash/card-only rule); Pro `Init.php:44, 72-77` (`pre_option_` + `remove_all_filters`).
- The gateway rule only applies once `V1\Settings` has been constructed; non-REST readers (`Gateways.php:91`, `Orders.php:776`) read the option unfiltered.
- Pro's license row has three writers: `License_Section::write`, `LicenseSettings::save_to_database` → raw `write_license_to_db` (`LicenseSettings.php:195-203`, no sanitize/hooks), Pro `Settings::save_settings`/`ProSettings::save_settings` (tests only).
- Tests: `Test_Settings_API`, `Test_Settings_Service`, `tests/includes/Services/Settings/`; Pro `Test_License_Section`, `Test_LicenseSettings`. Conflicts with the CONTEXT glossary (Section owns its group end to end), not an ADR.

### 4. "May the actor act on this other user" written four times. Worth exploring.
- `Auth::can_manage_user_sessions` (`Auth.php:805-824`): self, or `manage_options`, or `manage_woocommerce`; `Cashier::validate_cashier_access` (`Cashier.php:273-281`): comment says "Administrators", code checks `manage_woocommerce`; `V1/Auth::check_admin_permissions` (543-546); `Permission_Rules::can_modify` (345-366): the staff rule.
- A shop manager can list an administrator's session IPs/user agents and sign them out, which the staff rule forbids. No test where a manager targets an admin's sessions.

### 5. Pro `Pro_Plugin_Updater.php` (1141) is four modules. Speculative.
- Update transient/check (111-280), plugin-information HTML/markdown/CSS (332-785), license-status notices/styles (822-1035), logging (1068-1141). Active/inactive/expired computation twice (826-844, 996-1014). `Test_Pro_Plugin_Updater` (33).

## B) Code smells
- `Auth.php:58-62` docblock "Constructor is private" (public); `:694-707`, `749-764` two stacked docblocks; `:240` loose `!=` on `iss`; `:243, 252, 262, 322` error-code prefix `woocommmerce_` (three m's); `:548` deprecated `generate_token()` no callers.
- `V1/Auth.php:273-279` dead branches (`$oauth_error` always `invalid_grant`).
- `Core_Order_Audit_Guard.php:300-301` refers to `Init::get_auth_header_early()` which does not exist.
- `Session_Context.php:123-154` vs `Templates/Auth.php:540-564` duplicated IP resolution that diverges ('' vs 'unknown').
- `Session_Registry.php:345, 720` raw reads that write malformed entries back, contradicting `read_row`'s docblock (365-366).
- `Templates/Auth.php:281`, `Login.php:32` `$_POST['_wpnonce']` without `wp_unslash`; `Login.php:46` `$error[0]` echoed unescaped; `Login.php:82` `$credentials` unused.
- `Frontend.php:73-78` "@TODO remove in 1.5.0" deprecated action. TODOs: `Cashier.php:114`, `V1/Settings.php:369`, `API.php:556`.
- `Settings.php:497-503` `delete_settings()` no callers, bypasses `option_name()`.
- Free `License_Section.php:13-22` stale "a follow-up Pro PR replaces…" (Pro already registers at Pro `License_Section.php:45`).
- `V1/Settings.php:413-425` indexes `['gateways']`/`['default_gateway']` with no array guard.
- Pro `Pro_Plugin_Updater.php:162` reads `$_ENV['DEVELOPMENT']`; 826, 996 dead `is_object` branches; 1000 `$active` unused. Pro `Settings.php:150-158`, `ProSettings.php:86-88` no non-test callers.
- No `error_log`.

## C) Possible live defects (security first; not verified live)
1. Login tokens go to any framing page: `Templates/Login.php:84-104` posts access+refresh tokens to `window.top` with targetOrigin `'*'`; `Init.php:553-558` removes X-Frame-Options for `wcpos-login`; `Template_Router.php:270-296` strips CSP.
2. Open redirect with tokens in the URL, still unfixed on next: `Templates/Auth.php:211-234` accepts any https host and appends tokens (364-368). Memory rates it LOW.
3. Rate limit bypassable / lockout abusable: `Templates/Auth.php:540-564` takes IP from client-supplied `CF-Connecting-IP`/`X-Forwarded-For`; lockout at 472-516 keyed only on the typed identifier (anyone can lock out any cashier for 15 min); username and email forms counted separately.
4. Shop managers can see and end admin sessions (`Auth.php:805-824`).
5. Query-string tokens always accepted (`Auth.php:189-191`); `use_jwt_as_param` only advertised (`API.php:308`), never enforced.
6. Order-pay page trusts any valid token: `Form_Handler.php:79-100` any valid access token for any user (caps not rechecked) + order key sets current user to the order's customer; token in URL.
7. Access tokens minted without `refresh_jti` cannot be revoked for 30 min: public `generate_access_token($user)` at 339; the `woocommerce_pos_jwt_access_token_before_sign` filter (406) can drop the claim, skipping the liveness check at 295. 03a6e47b accepted this for the deprecated generator.
- Business defect: free-tier gateway restriction applies only once `V1\Settings` is constructed (`V1/Settings.php:116`); `Gateways.php:91` reads unfiltered, so a free site with stored BACS enabled may show it at checkout.
