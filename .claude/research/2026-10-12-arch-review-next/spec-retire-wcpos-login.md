# Spec — retire the `/wcpos-login/` route and its template (verdict-auth D1)

**Lane: released line.** Branch from `origin/main`, PR base `main`. `origin/next` has the same references at different line numbers (`Init.php:554`, `Template_Router.php:53/75/135/146/207/210`, `woocommerce-pos.php:196`), so the next main→next sync carries this change mechanically.
Tree read: Free `main` @ `408915f5`. Pro `main` @ `38e5c7c` (grep only).

## Goal
Delete the legacy `/wcpos-login/` modal-login page. It posts the access and refresh tokens to `window.top` with target origin `'*'`, and the plugin strips X-Frame-Options and CSP for it, so any site that frames it gets the tokens (verdict-auth D1). The POS has not used it since 1.8.0: the site payload's `wcpos_login_url` has pointed at `/wcpos-auth/` (`includes/Templates/Frontend.php:240` → `Template_Router::get_auth_url()`) since 2eeab8cf (2025-12-16). Afterwards the path is an ordinary unknown slug, and WordPress answers it with a 404.

**Ruling (Paul, 2026-10-13):** retire the route and template altogether. Do not patch the `postMessage` origin. `/wcpos-auth/` (`includes/Templates/Auth.php`, `templates/auth.php`) stays byte-for-byte untouched.

## Stakes
Tier: **Medium**, because it is an auth surface, but the change only removes code. A failure has two possible costs:
- The stale rule survives in a site's cached `rewrite_rules` option. `/wcpos-login/` would then serve the front page with a 200 instead of a 404. Nothing leaks, because the template class is gone, but the route does not "answer 404".
- A slip in `Template_Router` breaks `/pos/`, `/wcpos-auth/` or `/wcpos-checkout/…`. The router tests already in place cover this.

Accepted risk: a client older than about September 2025 that still opens `/wcpos-login/` gets a 404 and must update. The current client (monorepo-v2 `050920fa5`) has no `wcpos-wp-credentials` listener and never requests `/wcpos-login/`. Its only mentions are test fixture strings (`packages/core/src/hooks/use-wcpos-auth/*.test.ts*`, `packages/core/src/screens/auth/components/wp-user.test.tsx:70`). A merchant theme override at `yourtheme/woocommerce-pos/login.php` becomes an inert file. That is acceptable.

## Out of scope (do NOT add)
- No redirect from `/wcpos-login/` to `/wcpos-auth/`, no tombstone handler, no 410, no deprecation notice. A plain 404 is the ruling.
- No `flush_rewrite_rules()` call, upgrade file (`includes/updates/update-*.php`), version latch, option or transient. The existing `option_rewrite_rules` filter does the regeneration (see change 1).
- No changes to `/wcpos-auth/`, `Templates/Auth.php`, `templates/auth.php`, `Frontend.php`, `Cashier`, `Services/Auth`, or the `wcpos_login_url` payload key. The key keeps its name.
- No edits to `languages/` (see i18n below), `readme.txt`, `CHANGELOG.md`, `package.json` or the plugin version header. The release commit writes notes and versions.
- The bootstrap cache-kill regex at `woocommerce-pos.php:194` loses `wcpos-login` and GAINS `wcpos-auth`: `'#^/(wcpos-auth|wcpos-checkout)(/|$)#i'`. `/wcpos-auth/` is the login form that replaced the retired page; it renders a per-request CSRF `state` and sends no no-cache headers of its own (`Templates/Auth.php` and `Template_Router.php` have none), so a page cache that stored it would serve a stale state and every sign-in through the cache would fail validation. Same line, same purpose, one word swapped and one added; add a one-line comment saying why the auth page is listed. (Decided by the orchestrator on 2026-10-13 from that reading; not a question.)
- No env vars, feature flags, new tables or options, or plan files.

## Exact changes

Reference inventory from `git grep -n -i -E "wcpos-login|Templates.{1,2}Login|pos_login_regex|login\.php|woocommerce_pos_login_(init|form)"` on `main`, excluding `.claude/`. Every hit is listed here, so after the change this grep must show only `templates/auth.php:44` (`wcpos-loginform`, a form id on the auth page that must stay):

| # | File | Line(s) today | Action |
|---|------|---------------|--------|
| 1 | `includes/Template_Router.php` | 42-47 (property `$pos_login_regex`), 69 (assignment), 129 (`rewrite_rules()` isset), 140 (template map row), 201 (`add_rewrite_tag( '%wcpos-login%' … )`), 204 (`add_rewrite_rule( $this->pos_login_regex … )`) | remove; add the retired-rule check |
| 2 | `includes/Init.php` | 503-519 (`remove_x_frame_options()` docblock and `\|\| isset( $wp->query_vars['wcpos-login'] )`) | narrow the predicate |
| 3 | `includes/Templates/Login.php` | whole file (106 lines) | delete (`git rm`) |
| 4 | `templates/login.php` | whole file (76 lines) | delete (`git rm`) |
| 5 | `woocommerce-pos.php` | 194 (`'#^/(wcpos-login\|wcpos-checkout)(/\|$)#i'`) | drop `wcpos-login` from the alternation |

Checked and found nothing to change:
- `includes/API/class-aliases.php`: no `Login` entry.
- `composer.json` autoload is PSR-4 plus `class-aliases.php`, with no classmap to edit.
- `phpstan.neon`, `.phpcs.xml.dist`, `.distignore`, `php-scoper/`, `scripts/`, `.github/`: no `login.php` reference.
- `packages/*`: no login-URL settings UI.
- `readme.txt` and `README.md`: no mention of `/wcpos-login/` or `login.php`.
- `docs/` is gitignored, so ignore it.

### 1. `includes/Template_Router.php`
- Delete the `$pos_login_regex` property and its docblock (lines 42-47) and the constructor line `$this->pos_login_regex = '^wcpos-login/?';` (69). Re-align the remaining three assignments with `=` padding, which phpcs will ask for.
- Add a private class constant, next to `CHECKOUT_PATH`:
  ```php
  /**
   * Rewrite regex of the retired /wcpos-login/ page.
   *
   * Earlier versions registered this rule; sites still carry it in their cached
   * rewrite_rules option until WordPress regenerates them, and while it is cached
   * the path serves the front page instead of a 404. rewrite_rules() treats a
   * cache that still holds it as stale.
   */
  private const RETIRED_LOGIN_REGEX = '^wcpos-login/?';
  ```
- `rewrite_rules( $rules )` (128-130): first require the three live rules (`pos_regex`, `pos_auth_regex`, `pos_checkout_regex`). Then return `false` if `$rules[ self::RETIRED_LOGIN_REGEX ]` is set, and `$rules` otherwise. Keep the signature and `@return array|bool`. Add one docblock sentence: "A cache that still routes the retired /wcpos-login/ page is also stale."
- `template_redirect()`: delete the `$this->pos_login_regex => __NAMESPACE__ . '\\Templates\\Login',` row (140). Re-align the `=>` of the remaining rows.
- `add_rewrite_rules()`: delete `add_rewrite_tag( '%wcpos-login%', '([^&]+)' );` (201) and `add_rewrite_rule( $this->pos_login_regex, 'index.php?wcpos-login=1', 'top' );` (204).

**How rules are flushed today, and why this is enough on upgrade.** The plugin never calls `flush_rewrite_rules()` on activation or upgrade. The only call is `includes/Deactivator.php:71`, on deactivation. Instead, `Template_Router::__construct()` hooks `option_rewrite_rules` (`Template_Router.php:75`), and `rewrite_rules()` returns `false` whenever the cached option lacks a POS rule. `WP_Rewrite::wp_rewrite_rules()` then sees an empty value and calls `refresh_rewrite_rules()` (WP 7.1.2 `wp-includes/class-wp-rewrite.php:1493-1515`). That function regenerates the rules from the currently registered ones and saves them, on `wp_loaded` if that has not fired yet.

Extending the same filter to reject a cache holding the retired rule makes the first front-end request on a POS lane after upgrade regenerate rules without `^wcpos-login/?`. Every later read passes the filter. Without this, the three live rules would still be present, the old `isset` would pass, and the stale `index.php?wcpos-login=1` rule would keep matching. Because `wcpos-login` is no longer a registered query var, WordPress would then serve the front page with a 200.

Note that `Template_Router` is constructed only on non-storefront lanes (`Init.php:579-583`), so the self-heal happens on the first `/pos/`, `/wcpos-auth/` or checkout request, exactly as it does for a missing POS rule today. The cost is one regeneration per site, and nothing persists besides the regenerated `rewrite_rules` option.

### 2. `includes/Init.php` `remove_x_frame_options()` (503-519)
- Change line 514 to `if ( woocommerce_pos_request() ) {`. The X-Frame-Options strip for POS requests stays (`/pos/`, checkout pages in the desktop app).
- Docblock (504-507): replace "pages like the login" with "POS pages such as checkout". Keep the method name, signature and the hook-manifest row at `Init.php:220-227` (`send_headers`, priority 9999, args 1, reason string) **unchanged**.

### 3–4. Delete `includes/Templates/Login.php` and `templates/login.php`
This retires two public action hooks that only `Login::get_template()` fired: `woocommerce_pos_login_init` (`Login.php:57`) and `woocommerce_pos_login_form_login` (`:59`). Pro does not use them (grep below).

The helpers the template called keep other callers, so delete nothing else:
- `Cashier::can_open_pos()` / `missing_pos_capabilities_message()`: `Templates/Auth.php:321-323`, `Templates/Frontend.php:59-60`
- `Auth::get_user_data()`: `Frontend.php:86`
- `woocommerce_pos_locate_template()`: `Auth.php:200` and others

### 5. `woocommerce-pos.php:194`
Change `'#^/(wcpos-login|wcpos-checkout)(/|$)#i'` to `'#^/(wcpos-auth|wcpos-checkout)(/|$)#i'` and add a one-line comment above it saying the auth page is listed because it renders a per-request CSRF state and must never be served from a page cache (see the Out-of-scope section for the reasoning). Change nothing else in that closure.

## Pro impact
Grepped Pro `origin/main` (`git -C /Users/kilbot/Projects/woocommerce-pos-pro grep … origin/main -- . ':!vendor*' ':!.claude'`) for `wcpos-login|Templates.Login|woocommerce_pos_login_(init|form)|pos_login_regex|Template_Router`:
- `includes/Templates.php:14,33-35` and `includes/Templates/Login.php`: this is Pro's **own** `WCPOS\WooCommercePOSPro\Templates\Login`. It hooks core `login_form` / `login_enqueue_scripts` / `login_redirect` on `wp-login.php?wcpos=1`, not Free's route or class, so it is unaffected.
- `woocommerce-pos-pro.php:363`: a copy of the Free cache-kill regex, `(wcpos-login|wcpos-checkout)`. After this PR its `wcpos-login` branch only disables caching on a 404 path, which is harmless. A Pro follow-up can drop it for parity, but it is not required and not part of this PR.
- Nothing subclasses or calls Free `Templates\Login` or `Template_Router` internals. **No Pro break.**

## i18n
Do not touch `languages/`. `languages/woocommerce-pos.pot` (4063 lines) has no entry from `Templates/Login.php` or `templates/login.php`: their only strings use WordPress core's text domain (`_e( 'Password' )`, `__( …, 'login' )`). In any case, `.github/workflows/update-pot.yml` regenerates the POT on every push to `main` that touches `**.php` and opens its own `chore/update-pot-file` PR.

## Rulings to preserve (and what pins each)
- `/wcpos-checkout/order-pay/123` resolves with and without a trailing slash: `Test_Template_Router::test_checkout_rewrite_resolves_with_and_without_trailing_slash`.
- `get_auth_url()` scheme and trailing slash: `Test_Template_Router::test_get_auth_url_*` (three tests).
- `/wcpos-auth/` flow and redirect-URI rules: `tests/includes/Templates/Test_Auth_Redirect_Uri.php`.
- Hook manifest, including `send_headers => array( 99, 9999 )`: `tests/includes/Test_Init_Hook_Wiring.php:243`. **This golden does not change**, because `remove_x_frame_options` keeps its row, priority and reason. Only its predicate narrows. Do not edit this file. If it goes red, you changed the row; revert that.

## Tests
Existing `Test_Login*` files, `/wcpos-login/` router tests and X-Frame tests: **none** (`git grep` for `wcpos-login`, `Templates\\Login`, `remove_x_frame_options`, `pos_login` in `tests/` is empty), so **nothing to delete**.

Add both methods to `tests/includes/Test_Template_Router.php` (193 lines today; `WC_Unit_Test_Case`, Arrange/Act/Assert, `assertSame` with `( expected, actual )`):

1. `test_retired_login_path_resolves_to_404_without_frame_exemption()`. Model it on `test_checkout_rewrite_resolves_with_and_without_trailing_slash` (lines 169-192).
   - Arrange: `unset( $_SERVER['HTTP_X_WCPOS'] )`, `$this->set_permalink_structure( '/%postname%/' )`, `new Template_Router()`, `$wp_rewrite->flush_rules()`.
   - Act: `$this->go_to( '/wcpos-login/' )`.
   - Assert, reading through `$GLOBALS['wp']` because `go_to()` replaces it:
     - `assertNotSame( '^wcpos-login/?', $GLOBALS['wp']->matched_rule )`. This is the fail-first assertion: before the change the router's `top` rule matches.
     - `assertArrayNotHasKey( 'wcpos-login', $GLOBALS['wp']->query_vars )`.
     - `assertTrue( is_404() )`. Before the change, `go_to()` drops the unregistered query var and the request renders the home page, so this also fails first.
     - `assertFalse( woocommerce_pos_request() )`. With the predicate narrowed to `woocommerce_pos_request()` alone, this is the whole condition under which `Init::remove_x_frame_options()` strips X-Frame-Options, so the header is not removed for this path. A comment must say why the header itself is not asserted: `headers_list()` is always empty under the CLI SAPI.
   - The existing `tearDown()` already resets the permalink structure. Leave it alone.
2. `test_rewrite_rules_cache_holding_retired_login_rule_is_rejected()`.
   - Arrange: `$router = new Template_Router()`. Build `$live` from the router's current rules: call `$router->rewrite_rules()` on `get_option( 'rewrite_rules' )` after `$wp_rewrite->flush_rules()` with `/%postname%/`, or assemble the three live regex keys literally, with `Admin\Permalink::get_slug()` for the POS slug. `$stale = $live + array( '^wcpos-login/?' => 'index.php?wcpos-login=1' )`.
   - Act and Assert: `assertSame( $live, $router->rewrite_rules( $live ) )`. `assertFalse( $router->rewrite_rules( $stale ) )`, which pins the upgrade self-heal.

The orchestrator runs these PHPUnit files in wp-env afterwards, **one file per wp-env call**:
1. `tests/includes/Test_Template_Router.php`
2. `tests/includes/Test_Init_Hook_Wiring.php`
3. `tests/includes/Templates/Test_Auth_Redirect_Uri.php`
4. `tests/includes/Templates/Test_Frontend.php`
5. `tests/includes/Test_Lazy_Service_Construction.php`

## Budget (from `wc -l` on `main`)
- Deleted whole files: `includes/Templates/Login.php` 106 + `templates/login.php` 76 = 182 lines.
- In-place deletions: about 12 lines (`Template_Router.php` property block 6 plus 4 statement lines plus 1 map row; `Init.php` 0 net).
- Non-test additions: constant with docblock about 9, `rewrite_rules()` body and docblock about 8, docblock rewording in `Init.php` about 3, regex line 1. Ceiling **40 added non-test lines**, lint included. Net non-test change must be **≤ −150**.
- Test additions: two methods with docblocks, about 60 lines. Ceiling **110 added test lines**.
- Total-diff ceiling (added + deleted, all files): **360**.

If you are about to exceed either, STOP and report why instead of continuing. Splitting the work into more files or commits does not raise this budget.

## Verification Codex can run (no Docker)
- `php -l` on `includes/Template_Router.php`, `includes/Init.php`, `woocommerce-pos.php`, `tests/includes/Test_Template_Router.php`.
- `/Users/kilbot/Projects/woocommerce-pos/vendor/bin/phpcs --standard=.phpcs.xml.dist includes/Template_Router.php includes/Init.php woocommerce-pos.php tests/includes/Test_Template_Router.php`
- `git grep -n -i -E "wcpos-login|Templates.{1,2}Login|pos_login_regex|woocommerce_pos_login_(init|form)" -- . ':!.claude'`. The only expected hits are `templates/auth.php:44` (`wcpos-loginform`), the new `RETIRED_LOGIN_REGEX` constant, and the new tests.
- `git status` shows exactly: two deletions, three modified source files, one modified test file.

## Release note (for the release commit's `readme.txt` changelog, not this PR)
Put this in the PR body under **Release note**. Do not edit `readme.txt` or `CHANGELOG.md` in this PR (releases write them; compare `17f3b9de`):

> - Removed the unused /wcpos-login/ page; the POS has used /wcpos-auth/ since 1.8.0.

Optional developer line for the PR body only: "The `woocommerce_pos_login_init` and `woocommerce_pos_login_form_login` actions and the `login.php` template override went with it."

## Fixed lines
Stop and report only if: this brief contradicts itself, something it names is missing, or a test was already failing before you started. A test you wrote failing first is expected, not a surprise. Anything else you fix and list at the end.

Do not push; do not open or edit a PR; do not reply on any PR.

Proceed; do not stop to ask again — make the smallest reasonable choice and record it in your final report.

Git is readable from the sandbox (`git log -S`, `git show HEAD:path`); only writes fail. Commit nothing; the orchestrator commits with `git add -u`. The two deletions are staged by `git add -u` because both files are tracked.
