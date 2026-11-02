# Spec: `?authorization=` is a REST-only credential, and the `use_jwt_as_param` setting goes (verdict-auth D5)

**Lane: released line.** Branch from `origin/main`, PR base `main`.
Tree read: Free `main` @ `408915f5`, which equals `origin/main` after a fetch on 2026-10-13. Pro `origin/main` @ `38e5c7c` and `origin/next` were grepped only. Client: `monorepo-v2` `origin/main` @ `387b9ed39` (2026-10-12), grepped only. The local monorepo `main` has diverged, so it was not used.
`get_auth_header()` is byte-identical on `origin/next`. The main→next sync is mechanical except for one next-only test, covered under **Sequencing** below.

**Ruling (Paul, 2026-10-13).** A `?authorization=` token is accepted on REST requests only. It is the till's fallback for hosts that strip the `Authorization` header. The client probes the host itself and never reads a server flag. The token is never accepted on a front-end or admin page load. The `use_jwt_as_param` setting is deleted from the settings payload, because nothing enforces it.

## Goal
`Auth::get_auth_header()` (`includes/Services/Auth.php:167-194`) reads its third source, `$_GET['authorization']`, only when the request is a REST request. Today any page load accepts it, because `determine_current_user` runs on every request (`Init.php:247-252`, `:352-364`). The server stops publishing `use_jwt_as_param` in three places: the wp-json index, `/wcpos/v2/site`, and the `tools` settings section.

## Stakes
**High tier**: this is authentication. There are two ways to fail.
- **Fail closed (the costly one).** A REST request from a till on a header-stripping host stops authenticating, and that till goes dark. The rule therefore has to recognise REST requests at `determine_current_user` time, which is before core defines `REST_REQUEST`.
- **Fail open.** A page URL is still classed as REST, so the token still signs a page load in. Today's behaviour is already the fail-open state, so a miss here is no regression.

The rule below is strict: a page load is never classed as REST. A REST request it misses is still authenticated on `wcpos/*` routes by the existing fallback in `API::rest_authentication_errors()` (`API.php:191-215`), which runs after `REST_REQUEST` is defined.

Accepted risks, not addressed here:
- **Old clients.** Before monorepo `eb859bfd0` (2025-07-31), the client took `use_jwt_as_param` from server data. The admin toggle existed from Free `6638f6b1` (2023-07-31) until `ef55a329` (2026-04-16). Consider a store where an admin turned the toggle on, the host strips headers, and a pre-2025-08 app build reconnects: that build stores `false` and sends the header instead. Current clients probe the host every boot and are unaffected.
- **wc/v3 without the marker.** A `wc/v3` REST request that carries neither `X-WCPOS` nor `?wcpos=1`, through a front controller this rule cannot recognise, loses query-token authentication: the `wcpos/*` fallback does not run for it. The client sends `wcpos=1` on every query-transport request (`packages/utils/src/request-preamble.ts`, `markerParam`).
- **Core order audit guard.** `Core_Order_Audit_Guard` still has its own ungated copy of the reader on `main` (`Core_Order_Audit_Guard.php:297-319`). This is safe, and it stays. The guard runs only inside `rest_pre_dispatch` (`:47`). It reports "JWT-authenticated" only when the token's user is already the current user and no earlier filter authenticated anyone (`:269-295`). That current user can only have come from the two readers this PR gates. #2193 (merged into `next` 2026-10-13, **not on `main`**) replaces the copy with `Auth::instance()->get_auth_header()`, so after the sync `next` inherits this rule with no further edit.

## Out of scope: do NOT add
- A setting, option, filter or env var for the rule.
- A memo or cache of the classification.
- Changes to `Request_Lane`, `wcpos_request()`, `API.php` auth flow, `Init.php` hook rows, or `Core_Order_Audit_Guard.php`.
- Changes to `Form_Handler.php` or `V1/Auth.php` readers (see the reader table).
- Deleting the `tools` section or its `/settings/tools` route.
- A DB write to clean stored options.
- Readme, changelog or docs edits.
- Plan files.

## What counts as a "REST request", and why not the existing helpers
Identity is normally resolved inside `WP::init()`, which `wp-settings.php` calls just before `do_action( 'init' )`. That is before `parse_request`, the point where `rest_api_loaded()` defines `REST_REQUEST`. So none of the existing signals works on its own:

| Candidate | Verdict | Evidence |
|---|---|---|
| `REST_REQUEST` constant | Necessary but not sufficient. It is undefined at `determine_current_user` time on the dominant path. | `Init.php:335-338` docblock: "runs BEFORE rest_api_init" |
| `wp_is_rest_endpoint()` | Cannot be used. It needs WP 6.5, and the floor is 5.6 (`woocommerce-pos.php:13`). It reads the same constant. It also counts internal `rest_do_request()` dispatches made during a page load, which is exactly what must not count. | — |
| `Request_Lane::current()` | Cannot be used as a gate. Its own docblock calls it "an OPTIMISATION, not a correctness gate" (`Request_Lane.php:22`), and the lane is filterable (`:61`). `?wcpos=1` returns POS before REST is checked (`:103-105`), yet the client sends `wcpos=1` on REST requests. The REST test is a substring match anywhere in the URI, query string included (`:108`), so `/?s=/wp-json/` counts as REST. | — |
| `wcpos_request()` | Means "POS request", not "REST request". A `?wcpos=1` page load returns true. | `wcpos-functions.php:147-150` |

**Definition.** A request is a REST request when either of these holds:
1. `REST_REQUEST` is defined and true. This covers everything after `parse_request`.
2. The request came through the theme front controller (`wp_using_themes()`, which is true only under `index.php`; never for wp-admin, `admin-ajax.php`, `wp-login.php`, `xmlrpc.php` or `wp-cron.php`) **and** its URL is one core's `rest_api_loaded()` will route. That means either:
   - a non-empty string `$_GET['rest_route']`; or
   - with pretty permalinks on, a request path that, after trimming slashes and stripping the home path case-insensitively (as `WP::parse_request()` does), matches `#^(?:index\.php/)?<rest_get_url_prefix()>(?:/|$)#`. These are core's REST rewrite rules.

## Exact changes

### 1. `includes/Services/Auth.php`: `get_auth_header()` (167-194) and a new private method
Docblock item 3 (`:173`) becomes:
`3. authorization query parameter, on REST requests only (for hosts that strip auth headers; never a page load)`.
Replace the third source (`:188-191`):

```php
		// Query param: REST requests only. A token in a page URL must never sign a browser in.
		if ( ! empty( $_GET['authorization'] ) && $this->is_rest_request() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a bearer credential, not a form.
			return sanitize_text_field( wp_unslash( $_GET['authorization'] ) );
		}
```

The header sources at `:178-186` are not touched: same `! empty()` checks, same order. Add directly after `get_auth_header()`:

```php
	/**
	 * Whether the current request is served by the REST API.
	 *
	 * `?authorization=` is the till's fallback for hosts that strip the Authorization
	 * header, so it is a REST credential only. Identity is usually resolved from
	 * WP::init(), before parse_request defines REST_REQUEST, so the URL is matched the
	 * way core's rest_api_loaded() will route it. Every test here is strict. A REST
	 * request this misses is still authenticated by API::rest_authentication_errors()
	 * once REST_REQUEST is defined; a page load it wrongly accepted would be signed in.
	 *
	 * @return bool
	 */
	private function is_rest_request(): bool {
		if ( \defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		// Only the theme front controller (index.php) routes to REST: never wp-admin,
		// admin-ajax.php, wp-login.php, xmlrpc.php or wp-cron.php.
		if ( ! wp_using_themes() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing marker only.
		if ( isset( $_GET['rest_route'] ) && \is_string( $_GET['rest_route'] ) && '' !== $_GET['rest_route'] ) {
			return true;
		}

		// The /wp-json/ path form exists only as a rewrite rule.
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return false;
		}

		$uri       = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only matched against a prefix.
		$path      = trim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );
		$home_path = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		if ( '' !== $home_path ) {
			$path = trim( (string) preg_replace( '|^' . preg_quote( $home_path, '|' ) . '|i', '', $path ), '/' );
		}

		return 1 === preg_match( '#^(?:index\.php/)?' . preg_quote( rest_get_url_prefix(), '#' ) . '(?:/|$)#', $path );
	}
```

If phpcs rejects an inline-ignore wording, change only the comment text.

### 2. `includes/Init.php`: docblock only (340-341)
Make it: `This is especially important for authorization via query parameter (?authorization=Bearer...), which some servers require; Auth::get_auth_header() accepts it on REST requests only.` No code change.

### 3. `includes/API.php:306`: delete the line
Delete `$response->data['use_jwt_as_param'] = SettingsService::instance()->use_jwt_as_param_enabled();`. If `SettingsService` is then unused in `API.php`, remove its `use` line. Check with `grep -n SettingsService includes/API.php`.

### 4. `includes/API/V2/Site.php:61`: delete the array row
Delete `'use_jwt_as_param' => …,`. If the `SettingsService` import becomes unused, remove it. Re-align the remaining `=>` if phpcs asks.

### 5. `includes/Services/Settings/Tools_Section.php`
`defaults()` returns `array()`. Add a `migrate()` override that drops the key stored by sites that used the old toggle. This is in memory only, the same pattern as `General_Section::migrate()` (`General_Section.php:92-101`):

```php
	/**
	 * Drop the retired `use_jwt_as_param` toggle from stored options, in memory only.
	 *
	 * The admin toggle (2023-07 to 2026-04) was never enforced: the app probes the
	 * host and keeps its own flag, and `?authorization=` is accepted on every REST
	 * request. Sites that saved it still hold the key, so it is removed on read.
	 *
	 * @param array $raw Raw option value.
	 */
	protected function migrate( array $raw ): array {
		unset( $raw['use_jwt_as_param'] );

		return $raw;
	}
```

Keep the class, its id and its registration (`Settings.php:66`). `/settings/tools` stays registered and is pinned at `Test_Settings_API.php:48`.

### 6. `includes/Services/Settings.php:627-632`: keep the public method as a deprecated constant
Pro does not call it (grepped Pro `origin/main` and `origin/next`, no hits). The repo rule still says public methods keep backward compatibility.

```php
	/**
	 * Whether the JWT may be passed as a query parameter.
	 *
	 * @deprecated The `use_jwt_as_param` setting was removed. `?authorization=` is accepted
	 *             on REST requests only (Auth::get_auth_header()); this always returns false.
	 */
	public function use_jwt_as_param_enabled(): bool {
		return false;
	}
```

No `_deprecated_function()` call. It would fail any test that touches the method, for no gain.

## Every other token reader: keep, remove, or D6

| Reader | What it reads | Kind | Decision |
|---|---|---|---|
| `Auth.php:188-191` | `$_GET['authorization']` | identity at `determine_current_user` | **Gate to REST** (this PR) |
| `Core_Order_Audit_Guard.php:314-316` (main only) | `$_GET['authorization']` | REST: `rest_pre_dispatch` only (`:47`) | **Keep**, untouched. Cannot fail open (see Stakes). `next` already reads through `Auth` (#2193). |
| `API/V1/Auth.php:193-201` `test_authorization` | `$request->get_param('authorization')` | REST callback (legacy header/param probe) | **Keep** |
| `API/V1/Auth.php:559-590` `get_current_jti_from_request` | header, then `$request->get_param('authorization')` | REST callback | **Keep** |
| `API/V2/Echo_Probe.php:52,71` | header presence; `params['authorization']` presence, never the value | REST diagnostic | **Keep** |
| `Form_Handler.php:71-100` `pay_action` | `$_GET['wcpos_jwt']` / `$_GET['token']` | order-pay page POST: a pay gate, not an identity source; then switches to the order's customer | **Out of this PR (D6).** It is a different credential with a different meaning. The client deliberately puts `token` in the order-pay page URL (`monorepo-v2 packages/core/src/screens/main/pos/checkout/components/payment-webview.tsx:248`), and Pro `next`'s order-pay panel reads `wcpos_jwt`/`token` from the page URL (`Order_Pay_Panel.php:109`, `assets/js/order-pay-panel.js:277`). Removing it is a cross-repo checkout change, not "the same rule". |
| Templates (`includes/Templates/*`, `templates/*`) | none read `authorization`, `wcpos_jwt` or `token` (grepped) | — | nothing to change. `Templates/Payment.php:171-194` authorises by order key; `Frontend.php:52` uses the cookie login. |

The client never sends `?authorization=` to a page URL. On `monorepo-v2` `origin/main`, the query credential is attached only by the HTTP client's request preamble (`packages/utils/src/request-preamble.ts:205-228`). The only page-URL token is the order-pay `token` above.

## The client never reads the server's `use_jwt_as_param`
Run `git grep use_jwt_as_param origin/main` in `/Users/kilbot/Projects/monorepo-v2` (`387b9ed39`). Every hit reads the **local** site document. That document's flag is written only from the client's own probe:
- `packages/core/src/screens/auth/hooks/use-site-connect.ts:136-140`: `...siteData` (the wp-json index, server key included) is spread first, then overwritten by `use_jwt_as_param: authResult.useJwtAsParam`.
- `packages/core/src/contexts/app-state/hydration-steps.ts:1091-1101` re-probes every boot and patches the flag both ways ("The embedded payload never carries these local flags").
- The probe decision itself is at `hydration-steps.ts:615-624` (`echo.params.authorization`).

The readers at `apps/main/app/(app)/_layout.tsx:107`, `lib/create-app-engine.ts:198`, `use-user-validation.ts:53` and `request-preamble.ts:212` all read that local document. The Free web app stopped receiving the key in `initialProps` in Free `aa0541a4` (2026-01-04). Before monorepo `eb859bfd0` (2025-07-31) the client did take it from the server (see Stakes).

## Rulings to preserve, and what pins each
- **Headers before the query, header order `HTTP_AUTHORIZATION` then `REDIRECT_HTTP_AUTHORIZATION`, and `! empty()` on both.** Apache and htaccess `SetEnvIf` can set an empty `HTTP_AUTHORIZATION`. Today these are only partly pinned (`Test_Auth_Service::test_authenticate_request_accepts_valid_http_authorization_header`, `…_accepts_redirect_http_authorization_header`). New tests 4-6 pin them fully.
- **Query-token authentication on REST, through the current lane.** New tests 1-2, plus the two updated `Test_Auth_Service` cases.
- **`determine_current_user` order: audit guard before JWT at priority 20.** `Test_Init_Hook_Wiring.php:185-210, 401` stays green untouched.
- **The audit guard's JWT detection** stays green untouched: `Test_Core_Order_Audit_Guard`, header path (`:49-55`).
- **The `/settings/tools` route exists.** `Test_Settings_API.php:48` stays green untouched.

## Tests

### 0. Base-class helper: `tests/includes/API/WCPOS_REST_Unit_Test_Case.php`
Add the helper, and add `unset( $_GET['rest_route'] );` to `tearDown()` before `parent::tearDown()`:

```php
	/**
	 * Make this request look like a REST request entering the front controller
	 * (`index.php?rest_route=…`), the shape on which Auth accepts `?authorization=`
	 * before REST_REQUEST is defined.
	 *
	 * @param string $route REST route, e.g. '/wcpos/v2/status'.
	 */
	protected function arrange_rest_front_controller_request( string $route ): void {
		$_GET['rest_route'] = $route;
		add_filter( 'wp_using_themes', '__return_true' );
	}
```

WP_UnitTestCase restores hooks after each test, so the filter needs no teardown.

### New file: `tests/includes/API/Test_Query_Token_Rest_Only.php`
Namespace `WCPOS\WooCommercePOS\Tests\API`; `class Test_Query_Token_Rest_Only extends WCPOS_REST_Unit_Test_Case`; `@covers \WCPOS\WooCommercePOS\Services\Auth::get_auth_header`.

**`setUp()`:**
- call `parent::setUp()`;
- save `$_SERVER['REQUEST_URI']`;
- unset `$_SERVER['HTTP_AUTHORIZATION']`, `$_SERVER['REDIRECT_HTTP_AUTHORIZATION']`, `$_GET['authorization']`, `$_GET['rest_route']` and `$_GET['wcpos']`;
- set `$this->token = Auth::instance()->generate_access_token( get_user_by( 'id', $this->user ) )`. `$this->user` is the setUp administrator, who holds `access_woocommerce_pos`.

**`tearDown()`:** unset the same keys, restore `REQUEST_URI`, call `$this->set_permalink_structure( '' )`, then `parent::tearDown()`.

**Private helper `resolve_current_user(): int`:** call `wp_set_current_user( 0 )`, then `global $current_user; $current_user = null;` (with the phpcs ignore used at `Test_Core_Order_Audit_Guard.php:54`), then return `get_current_user_id()`. This fires `determine_current_user`, which is where every page and every REST request gets its identity.

**No `wcpos/v1` literal anywhere in the file** (the lane gate rule).

**Write test 3 first and run it: every row must FAIL on today's code.** If a row passes before your change, STOP and report: the harness is not reproducing the page-load path.

1. `test_query_token_rest_route_request_authenticates_current_lane_route`
   - Arrange: `arrange_rest_front_controller_request( '/wcpos/v2/status' )`; `$_GET['wcpos'] = '1'`; `$_GET['authorization'] = 'Bearer ' . $this->token`.
   - Act: `$user_id = $this->resolve_current_user()`, then `$response = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/status' ) )`.
   - Assert: `assertSame( $this->user, $user_id )`; `assertSame( 200, $response->get_status() )`.
2. `test_query_token_pretty_rest_path_sets_current_user`
   - Arrange: `set_permalink_structure( '/%postname%/' )`; `add_filter( 'wp_using_themes', '__return_true' )`; `REQUEST_URI = '/wp-json/wcpos/v2/status?authorization=x'`; `$_GET['authorization'] = 'Bearer ' . $this->token`.
   - Act: `resolve_current_user()`.
   - Assert: `assertSame( $this->user, … )`.
3. `test_query_token_on_page_load_does_not_set_current_user`, with `@dataProvider page_load_requests`. Rows are `( string $request_uri, string $permalink_structure, bool $front_controller )`:
   - `'storefront page'` → `'/sample-page/'`, `'/%postname%/'`, true
   - `'POS template with marker'` → `'/pos/?wcpos=1'`, `'/%postname%/'`, true
   - `'REST prefix only in the query string'` → `'/?s=/wp-json/wcpos/v2/status'`, `'/%postname%/'`, true
   - `'REST path without pretty permalinks'` → `'/wp-json/wcpos/v2/status'`, `''`, true
   - `'lookalike prefix'` → `'/wp-jsonp/status'`, `'/%postname%/'`, true
   - `'rest_route outside the front controller'` → `'/wp-login.php?rest_route=/wcpos/v2/status'`, `'/%postname%/'`, false

   Arrange:
   - `set_permalink_structure( $permalink_structure )`;
   - `add_filter( 'wp_using_themes', $front_controller ? '__return_true' : '__return_false' )`;
   - set `REQUEST_URI`;
   - `parse_str( (string) wp_parse_url( $request_uri, PHP_URL_QUERY ), $query )`, then merge `$query` into `$_GET`, and unset each of those keys in `tearDown` (`wcpos`, `s`, `rest_route` are enough);
   - `$_GET['authorization'] = 'Bearer ' . $this->token`.

   Act: `resolve_current_user()`.
   Assert: `assertSame( 0, … )`.
4. `test_get_auth_header_prefers_header_over_query_on_rest_request`
   - Arrange: `arrange_rest_front_controller_request( '/wcpos/v2/status' )`; `$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer header-token'`; `$_GET['authorization'] = 'Bearer query-token'`.
   - Act: `Auth::instance()->get_auth_header()`.
   - Assert: `assertSame( 'Bearer header-token', … )`.
5. `test_get_auth_header_empty_http_authorization_falls_through_to_redirect_header`
   - Arrange: `HTTP_AUTHORIZATION = ''`; `REDIRECT_HTTP_AUTHORIZATION = 'Bearer redirect-token'`.
   - Assert: `assertSame( 'Bearer redirect-token', … )`.
6. `test_get_auth_header_empty_headers_fall_through_to_query_on_rest_request`
   - Arrange: REST arrangement as in test 4, both headers `''`, query `'Bearer query-token'`.
   - Assert: `assertSame( 'Bearer query-token', … )`.
7. `test_rest_index_omits_use_jwt_as_param`
   - Arrange: `update_option( 'woocommerce_pos_settings_tools', array( 'use_jwt_as_param' => true ) )`.
   - Act: `$this->server->dispatch( $this->wp_rest_get_request( '/' ) )`.
   - Assert: status `200`; `assertArrayHasKey( 'wcpos_version', $data )`, which proves the WCPOS `rest_index` filter ran; `assertArrayNotHasKey( 'use_jwt_as_param', $data )`.

### Updated existing tests
- `tests/includes/Services/Test_Auth_Service.php`:
  - `test_authenticate_request_accepts_bearer_authorization_query_parameter` (`:111`) becomes `test_authenticate_request_accepts_bearer_authorization_query_parameter_on_rest_request`.
  - `test_authenticate_request_accepts_bare_jwt_authorization_query_parameter` (`:121`) becomes `…_on_rest_request`.
  - Each case gains, in Arrange: `$_GET['rest_route'] = '/wcpos/v2/status'; add_filter( 'wp_using_themes', '__return_true' );`. The class extends `WP_UnitTestCase`, so it cannot use the base helper.
  - Switch both to `assertSame`.
  - Add `$_GET['rest_route']` to the `unset` in `tearDown()` (`:62`).
  - `test_authenticate_request_returns_false_for_bare_non_jwt_value` (`:141`) stays as it is.
- `tests/includes/Services/Test_Settings_Service.php`:
  - Replace `test_direct_get_tools_settings` (`:435-440`, `assertArrayHasKey( 'use_jwt_as_param' )`) with `test_get_tools_settings_omits_retired_use_jwt_as_param`.
  - Arrange: `update_option( 'woocommerce_pos_settings_tools', array( 'use_jwt_as_param' => true ) )`.
  - Assert: `assertIsArray`, then `assertArrayNotHasKey( 'use_jwt_as_param', $result )`.
  - Keep its `@covers`.
  - Leave `test_tracking_consent_migrates_from_legacy_tools_option` (`:229-243`) unchanged. Its fixture is a legacy stored shape, not a payload pin.
- `tests/includes/Services/Settings/Test_Typed_Accessors.php:70`: delete `$this->assertFalse( $settings->use_jwt_as_param_enabled() );`. The accessor no longer reads a setting.
- `tests/includes/API/V2/Test_Site.php`: add `test_site_payload_omits_use_jwt_as_param`.
  - Arrange: the stored option set to `true`; `wp_set_current_user( 0 )`.
  - Act: `dispatch( new WP_REST_Request( 'GET', '/wcpos/v2/site' ) )`.
  - Assert: `200`; `assertArrayNotHasKey( 'use_jwt_as_param', $data )`.

Delete nothing else.
Conventions:
- Arrange / Act / Assert comments.
- `assertSame` with `( expected, actual )`.
- Never set `REST_REQUEST`. It cannot be undefined afterwards and would poison the suite.

## Budget (from `wc -l` on `main` @ `408915f5`)
Regions:
- `Auth.php:167-194` (28 lines, file 1076)
- `API.php:306`
- `V2/Site.php:61` (file 90)
- `Settings.php:627-632` (file 689)
- `Tools_Section.php` (29)
- `Init.php:340-341`
- tests: `Test_Auth_Service.php` (1256), `Test_Settings_Service.php` (769), `Test_Typed_Accessors.php` (91), `Test_Site.php` (66), `WCPOS_REST_Unit_Test_Case.php` (268)

Expected:
- Non-test: about +45 in `Auth.php`, -2 to -4 in `API.php`/`Site.php`, about ±6 in `Settings.php`, about +14 in `Tools_Section.php`, ±2 in `Init.php`. That is about 75 changed lines.
- Tests: new file about 230; base helper about 15; updates about 40.

Ceilings, lint budget included:
- **Non-test: at most 130 changed lines.**
- **Total diff: at most 520 lines.**

If you are about to exceed either, STOP and report why instead of continuing. Splitting the work into more files or commits does not raise this budget.

## Pro impact
None. The following were grepped in Pro (`/Users/kilbot/Projects/woocommerce-pos-pro`, `origin/main` and `origin/next`, excluding `vendor`):
- `use_jwt_as_param`, `tools_settings`, `get_auth_header`, `authenticate_request`, `'authorization'`, `REST_REQUEST`, `wp_is_rest_endpoint`: no hits.
- `wcpos_jwt`: hits on `next` only, in the order-pay panel (D6, untouched).

No Free public signature changes. `use_jwt_as_param_enabled()` stays, as a deprecated constant.

## Sequencing
- This PR is independent, and it fits in one PR. Its `Init.php` edit (`:340-341`, docblock) is disjoint from `spec-retire-wcpos-login.md`'s `Init.php:503-519`.
- **The main→next sync will turn one next-only test red.** `origin/next:tests/includes/API/Test_Core_Order_Audit_Guard.php:164` is `test_audit_guard_jwt_query_param_order_update_with_audit_key_returns_forbidden`, from #2193. It sets `$_GET['authorization']` with no REST arrangement. After the sync, `determine_current_user` no longer authenticates it, and WooCommerce returns 401 instead of 403.
  - The fix is one Arrange line in that test, inside the sync PR: `$this->arrange_rest_front_controller_request( '/wc/v3/orders/' . $order->get_id() );`. The helper arrives with this PR; the class extends `WCPOS_REST_Unit_Test_Case`.
  - It cannot be fixed on `main`, because the test does not exist there.
- This unblocks verdict-auth candidate 2, the request-identity resolver. The set of channels that count is now settled.

## Verification you can run (no Docker)
- `php -l` on every changed file.
- `/Users/kilbot/Projects/woocommerce-pos/vendor/bin/phpcs --standard=.phpcs.xml.dist` on the following files:
  - `includes/Services/Auth.php`
  - `includes/Init.php`
  - `includes/API.php`
  - `includes/API/V2/Site.php`
  - `includes/Services/Settings.php`
  - `includes/Services/Settings/Tools_Section.php`
  - `tests/includes/API/Test_Query_Token_Rest_Only.php`
  - `tests/includes/API/WCPOS_REST_Unit_Test_Case.php`
- `php scripts/lane-coverage.php --json`. Every new or renamed case must show `wcpos/v2` (or `[]`), never v1-only.

The orchestrator then runs these in wp-env, **one file per call**:
- `tests/includes/API/Test_Query_Token_Rest_Only.php`
- `tests/includes/Services/Test_Auth_Service.php`
- `tests/includes/API/V2/Test_Site.php`
- `tests/includes/Services/Test_Settings_Service.php`
- `tests/includes/Services/Settings/Test_Typed_Accessors.php`
- `tests/includes/API/Test_Core_Order_Audit_Guard.php`
- `tests/includes/API/Test_JWT_Plugin_Compatibility.php`
- `tests/includes/Test_Init_Hook_Wiring.php`
- `tests/includes/API/Test_Settings_API.php`
- `tests/includes/API/Test_Cashier_Core_User_Routes.php`
- `tests/includes/API/Test_Auth_Refusal_Reason.php`

Git is readable from the sandbox (`git log -S`, `git show HEAD:path`); only writes fail. Commit nothing. The orchestrator commits with `git add -u` (and `git add` for the new test file).

Stop and report only if: this brief contradicts itself, something it names is missing, or a test was already failing before you started. A test you wrote failing first is expected, not a surprise. Anything else you fix and list at the end.

Do not push; do not open or edit a PR; do not reply on any PR.

Proceed; do not stop to ask again — make the smallest reasonable choice and record it in your final report.
