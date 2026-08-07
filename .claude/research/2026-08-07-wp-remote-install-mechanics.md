# WP remote-install mechanics and host failure modes

**Ticket:** [#1529](https://github.com/wcpos/woocommerce-pos/issues/1529) — research for the free plugin's "Connect & Install Pro" flow.
**Date:** 2026-08-07
**Question:** how should the free (WordPress.org) plugin install the ~10.5 MB Pro zip server-to-server from `https://updates.wcpos.com/pro/download/latest?key=X&instance=Y`, and what host conditions must the design survive?

## Sources

All WordPress core line numbers are from the local wp-env checkout of **WordPress 7.0.2**
(`/Users/kilbot/.wp-env/wp-env-woocommerce-pos-pro-692429bf/WordPress`). The same files live at
`https://github.com/WordPress/wordpress-develop/blob/trunk/src/<path>` — the code cited here has been
stable since WP 4.6 (skins) / 5.5 (`$hook_extra` on `upgrader_pre_download`) unless noted, so it is
safe for our declared floor of WP 5.6.

WooCommerce line numbers are from **WooCommerce 10.7.0**
(`/Users/kilbot/.wp-env/9db51f81ea621bb35eda95dbfcb6a9c3/woocommerce`).

Elementor references are pinned to `elementor/elementor@7ba85d50ebaf037c8d56d7736a82a04cb4991ad4` (`main`, 2026-08-06).

WCPOS Pro reference: `/Users/kilbot/Projects/woocommerce-pos-pro/includes/Admin/Updaters/Pro_Plugin_Updater.php`.

---

## 1. How `Plugin_Upgrader::install()` actually works

### The call chain

`wp-admin/includes/class-plugin-upgrader.php:118` `Plugin_Upgrader::install( $package, $args )`

```php
$this->run( array(
    'package'           => $package,
    'destination'       => WP_PLUGIN_DIR,
    'clear_destination' => $parsed_args['overwrite_package'],  // default false
    'clear_working'     => true,
    'hook_extra'        => array( 'type' => 'plugin', 'action' => 'install' ),
) );
```

`wp-admin/includes/class-wp-upgrader.php:774` `WP_Upgrader::run()` then does, in order:

| Step | Line | Notes |
|---|---|---|
| `apply_filters( 'upgrader_package_options', $options )` | 818 | last chance to rewrite the package URL |
| `fs_connect( array( WP_CONTENT_DIR, $destination ) )` | 825 | **returns `false`** (not `WP_Error`) when filesystem credentials are unavailable |
| `download_package( $package, false, $hook_extra )` | 849 | → `apply_filters( 'upgrader_pre_download', … )` then `download_url( $package, 300 )` (lines 322 / 337) |
| `unpack_package( $download, $delete_package )` | 887 | → `unzip_file()` |
| `install_package( … )` | 898 | calls `set_time_limit( 300 )` at line 533-534 — **only at this point, after the download** |

`install()` returns:
- `true` on success
- `WP_Error` on a real failure
- **`null` when `fs_connect()` returned `false`** — `run()` bails at line 831 before `$this->result` is ever set. This is the filesystem-credentials case and it is *not* a `WP_Error`.

`Plugin_Upgrader::plugin_info()` (`class-plugin-upgrader.php:530`) returns the installed
`dir/file.php` by scanning `get_plugins( '/' . $this->result['destination_name'] )` — use this rather
than hardcoding `woocommerce-pos-pro/woocommerce-pos-pro.php`.

### Package validation

`Plugin_Upgrader::check_package()` (`class-plugin-upgrader.php:462`) is hooked onto
`upgrader_source_selection` by `install()` and returns:
- `incompatible_archive_no_plugins` — zip has no file with a `Plugin Name:` header
- `incompatible_php_required_version` — zip's `Requires PHP` exceeds the server's PHP
- `incompatible_wp_required_version` — zip's `Requires at least` exceeds the site's WP

These three are the most actionable errors a merchant can receive; surface their messages verbatim.

### `folder_exists`

`install()` passes `clear_destination => false` and does **not** override
`abort_if_destination_exists` (default `true`, `class-wp-upgrader.php:781`), so if
`wp-content/plugins/woocommerce-pos-pro/` already exists (e.g. a manual, deactivated copy),
`install_package()` returns `WP_Error( 'folder_exists' )` (`class-wp-upgrader.php:668-676`).

WooCommerce.com treats this as success and skips to activation
(`includes/wccom-site/installation/installation-steps/class-wc-wccom-site-installation-step-move-product.php:56-65`).
Do the same. Do **not** reflexively pass `overwrite_package => true` — that maps to
`clear_destination` and will delete a merchant's existing directory.

### Required includes

A REST request does not load `wp-admin/includes/*`. Requiring `class-wp-upgrader.php` pulls in every
skin including `WP_Ajax_Upgrader_Skin` (`class-wp-upgrader.php:13-43`, `1303`). WooCommerce.com's
loader is the minimal correct set (`includes/wccom-site/class-wc-wccom-site-installer.php:98-101`):

```php
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
```

### Capability: one check covers three things

`install_plugins` is routed through `map_meta_cap()` (`wp-includes/capabilities.php:621-643`):

```php
case 'install_plugins':
    …
    if ( ! wp_is_file_mod_allowed( 'capability_update_core' ) ) {
        $caps[] = 'do_not_allow';
    } elseif ( is_multisite() && ! is_super_admin( $user_id ) ) {
        $caps[] = 'do_not_allow';
    } …
```

and `wp_is_file_mod_allowed()` (`wp-includes/load.php:1829-1838`) is
`! defined( 'DISALLOW_FILE_MODS' ) || ! DISALLOW_FILE_MODS`, filterable via `file_mod_allowed`.

**So `current_user_can( 'install_plugins' )` alone enforces the role check, `DISALLOW_FILE_MODS`, and
the multisite network-admin-only rule.** We only need to *decompose* it afterwards to produce a
useful error message (see §4).

### Skins

| Skin | File | Behaviour |
|---|---|---|
| `Automatic_Upgrader_Skin` | `class-automatic-upgrader-skin.php:21` | buffers all output (`header()`/`footer()` = `ob_start`/`ob_get_clean`, lines 120-134); wraps `request_filesystem_credentials()` in `ob_start()`/`ob_end_clean()` (lines 48-50) so the FTP form is swallowed; exposes only `get_upgrade_messages()` — an array of **strings** |
| `WP_Ajax_Upgrader_Skin` | `class-wp-ajax-upgrader-skin.php:19` | **extends** `Automatic_Upgrader_Skin`, so it inherits all of the above, and additionally accumulates a real `WP_Error` via `error()`/`feedback()` (lines 114-158), exposed as `get_errors()` / `get_error_messages()` (lines 77-102) |

`WP_Ajax_Upgrader_Skin` is strictly better for a JSON API: same output suppression, plus structured
error codes. Neither skin echoes, so both are REST-safe. (`WP_Upgrader_Skin` /
`Plugin_Installer_Skin` are not — they print HTML.)

Note `WP_Upgrader_Skin::request_filesystem_credentials()`
(`class-wp-upgrader-skin.php`) reads `$this->options['url']` and `request_filesystem_credentials()`
touches the `$pagenow` global (`file.php:2365`, `2550`) — undefined in REST, but it is only compared
against `'plugins.php'` for a heading, so it degrades harmlessly.

### Core's own AJAX handler — the canonical error-handling shape

`wp-admin/includes/ajax-actions.php:4459` `wp_ajax_install_plugin()`:

```php
check_ajax_referer( 'updates' );
…
if ( ! current_user_can( 'install_plugins' ) ) { … }
…
$skin     = new WP_Ajax_Upgrader_Skin();
$upgrader = new Plugin_Upgrader( $skin );
$result   = $upgrader->install( $api->download_link );

if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
    $status['debug'] = $skin->get_upgrade_messages();   // ← see §3, leaks the package URL
}

if ( is_wp_error( $result ) )                     { … }
elseif ( is_wp_error( $skin->result ) )           { … }
elseif ( $skin->get_errors()->has_errors() )      { … }
elseif ( is_null( $result ) )                     { 'unable_to_connect_to_filesystem' + $wp_filesystem->errors }
```

Note it **does not activate**. It returns an `activateUrl` with an `activate-plugin_{file}` nonce and
lets the user click (lines 4541-4556).

---

## 2. How Elementor and WooCommerce.com actually do it

### Elementor (two entry points, one installer)

The `upload_and_install_pro` handler (CVE-2022-1329, 3.6.x) is **gone**. Current `main` has:

- **REST:** `POST /wp-json/elementor/v1/onboarding/install-pro` —
  [`app/modules/onboarding/data/endpoints/install-pro.php`](https://github.com/elementor/elementor/blob/7ba85d50ebaf037c8d56d7736a82a04cb4991ad4/app/modules/onboarding/data/endpoints/install-pro.php).
  Nonce = `wp_rest` via `X-WP-Nonce`. Capability = **`manage_options` only**, inherited from
  `app/modules/onboarding/data/controller.php:38-44` — it never checks `install_plugins`. That is a
  bug we should not copy (the sibling `install-theme.php:47` *does* check `install_themes`).
- **admin-post:** a nonced GET link `admin-post.php?action=elementor_do_pro_install`, built in
  `modules/pro-install/connect-page-renderer.php:148`. Handler at
  `modules/pro-install/module.php:52-57` checks `current_user_can( 'install_plugins' )` **and**
  `check_admin_referer( 'elementor_do_pro_install' )`.

Both funnel into one shared class,
[`modules/pro-install/plugin-installer.php`](https://github.com/elementor/elementor/blob/7ba85d50ebaf037c8d56d7736a82a04cb4991ad4/modules/pro-install/plugin-installer.php):

```php
$upgrader = new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() );
return $upgrader->install( $package_url );      // remote URL string
```

then `activate_plugin( $plugin_path )` **in the same request** (lines 14-31, 73-87), with the plugin
file resolved by scanning `get_plugins()` rather than hardcoding.

**Package auth:** Elementor uses neither `upgrader_pre_download` nor `http_request_args` (0 hits
repo-wide). It makes a *separate*, HMAC-header-authenticated call to
`https://my.elementor.com/api/v2/artifacts/PLUGIN/latest/download-link`
(`modules/pro-install/connect.php:12-40`; signature via
`hash_hmac( 'sha256', wp_json_encode( $payload ), $access_token_secret )` at
`core/common/modules/connect/apps/base-app.php:534-540`) and hands the returned `downloadLink` to
`Plugin_Upgrader::install()` naked. **The license credential never appears in a URL Elementor
constructs.**

**What Elementor gets wrong** (and we must not): every failure — `DISALLOW_FILE_MODS`, FTP creds
required, disk full — collapses into `WP_Error( 'cant_installed', 'There are no available
subscriptions at the moment.' )`. It never checks `FS_METHOD`, `DISALLOW_FILE_MODS`, or calls
`request_filesystem_credentials` in this path (0 hits each). The JS
(`packages/apps/onboarding/src/hooks/use-install-pro.ts`) also silently retries the whole install once
and then shows a generic toast.

### WooCommerce.com marketplace (the stepped model)

`WC_REST_WCCOM_Site_Installer_Controller`
(`includes/wccom-site/rest-api/endpoints/class-wc-rest-wccom-site-installer-controller.php`):

- Routes: `POST wccom-site/v3/installer`, `GET …/installer/{product_id}/state`, `POST …/installer/reset`
- Capability: `user_can( $user, 'install_plugins' ) && user_can( $user, 'install_themes' )` (line 112)
- Args: `product-id`, **`run-until-step`** (enum), **`idempotency-key`** (all required, lines 62-76)

`WC_WCCOM_Site_Installation_Manager` (`includes/wccom-site/installation/class-wc-wccom-site-installation-manager.php:18-24`)
splits the install into five persisted, resumable steps:

```php
const STEPS = array( 'get_product_info', 'download_product', 'unpack_product', 'move_product', 'activate_product' );
```

Each step is a class implementing `run()`; state is saved before and after every step
(`run_step()`, lines 201-223), an idempotency-key mismatch aborts (line 77), a step already
`in_progress` aborts (line 135), and `can_run_installation()` pre-checks
`is_writable( WP_CONTENT_DIR )` (lines 155-157). It drives the low-level `WP_Upgrader` primitives
directly — `download_package()`, `unpack_package()`, `install_package()` — with a shared
`WP_Upgrader( new Automatic_Upgrader_Skin() )` (`class-wc-wccom-site-installer.php:93-109`), rather
than `Plugin_Upgrader::install()`.

Activation is its own step, in its own HTTP request
(`class-wc-wccom-site-installation-step-activate-product.php:87`).

**Package auth:** the `package` URL comes from the helper API. Where a credential must ride in a URL,
`WC_Helper_API::add_auth_parameters()` (`includes/admin/helper/class-wc-helper-api.php:107-123`)
appends `token` + a **per-URL HMAC `signature`**, not the raw secret:

```php
$signature = self::create_request_signature( (string) $auth['access_token_secret'], $url, 'GET' );
return add_query_arg( array( 'token' => $auth['access_token'], 'signature' => $signature ), $url );
```

For its own JSON API calls it sends `Authorization: Bearer` + `X-Woo-Signature` headers **and** the
same query args (`_authenticate()`, lines 133-165) — the query args exist precisely because
`download_url()` cannot carry headers.

WooCommerce also uses `upgrader_pre_download` — but only to *replace an error message*, not to
authenticate (`class-wc-helper-updater.php:30`, `861-880`).

### WooCommerce Admin (the simple model)

`Automattic\WooCommerce\Admin\API\Plugins` — `POST wc-admin/plugins/install`,
`permission_callback` = `current_user_can( 'install_plugins' )` (line 40 of
`update_item_permissions_check`), with an optional `async` flag that hands off to a scheduled job and
returns a `job_id` for polling. The synchronous path is
`PluginsHelper::install_plugins()` → `new Plugin_Upgrader( new Automatic_Upgrader_Skin() )`
(`src/Admin/PluginsHelper.php:328`).

`Automattic\WooCommerce\Internal\Utilities\PluginInstaller` is the hardened internal variant: it
hard-rejects any URL not starting with `https://downloads.wordpress.org/` (line 107), guards against
re-entrancy with a `woocommerce_autoinstalling_plugins` site option, and logs
`$skin->get_upgrade_messages()` on failure.

---

## 3. Auth on the package URL

### The constraint

`WP_Upgrader::download_package()` (`class-wp-upgrader.php:307-345`) calls
`download_url( $package, 300, $check_signatures )`, and `download_url()`
(`wp-admin/includes/file.php:1149`) calls:

```php
wp_safe_remote_get( $url, array( 'timeout' => $timeout, 'stream' => true, 'filename' => $tmpfname ) );
```

There is **no header parameter anywhere in that chain**. Any header-based auth must be injected
out-of-band.

### Options

**(A) Query-arg credential — current Pro pattern.**
`Pro_Plugin_Updater::update_plugins()` builds
`add_query_arg( array( 'key' => …, 'instance' => … ), $download_url )` (lines 147-153). Zero extra
machinery, works with core's update path unchanged.

**(B) `http_request_args` header injection.**
`apply_filters( 'http_request_args', $parsed_args, $url )` fires in `WP_Http::request()`
(`wp-includes/class-wp-http.php:252`) for *every* outbound request. You would have to match on host
**and** path before adding an `Authorization` header, or you leak the license key to third parties.
The filter must be added immediately before `install()` and removed immediately after.

**(C) `upgrader_pre_download` short-circuit.**
`apply_filters( 'upgrader_pre_download', false, $package, $this, $hook_extra )`
(`class-wp-upgrader.php:322`) — return a local file path and core skips `download_url()` entirely.
Full control over headers, but you reimplement `download_url()`'s response-code handling, temp-file
cleanup, `Content-Disposition` handling and error shapes (`file.php:1149-1290`).

**(D) Short-lived signed URL — recommended.**
The free plugin POSTs the license key to the update server over TLS *in headers*, receives a signed,
single-use, short-TTL package URL, and hands that to `Plugin_Upgrader::install()`. This is exactly
Elementor's `latest/download-link` shape and WooCommerce.com's `token`+`signature` shape. A leaked
log line then contains a dead token, not the merchant's license key.

### Where query-arg keys leak

1. **Update-server access logs.** Apache `combined` and nginx `$request` both log the full query
   string by default. A `?key=` license key ends up in log files, log shippers, and analytics.
2. **Intermediate proxies / CDNs** on the merchant's side.
3. **`http_api_debug`** — any HTTP-debug plugin (Query Monitor, WP Log HTTP Requests) records the
   full URL of every `wp_remote_*` call, including the download.
4. **⚠️ The skin's own feedback string.** `download_package()` calls
   `$this->skin->feedback( 'downloading_package', $package )` (`class-wp-upgrader.php:335`), and
   `Plugin_Upgrader::install_strings()` (`class-plugin-upgrader.php:76-77`) defines that string as
   *"Downloading installation package from %s…"*. `Automatic_Upgrader_Skin::feedback()`
   `vsprintf()`s the URL into the message (`class-automatic-upgrader-skin.php:87-91`) and stores it.
   Core's own AJAX handler then returns those messages to the browser under `WP_DEBUG`:

   ```php
   if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
       $status['debug'] = $skin->get_upgrade_messages();   // ajax-actions.php:4506-4507
   }
   ```

   **A naive copy of core's handler will echo the license key to the browser (and to
   `wc_get_logger()` if we log the messages).** Every message returned or logged must have the
   package query string redacted.

---

## 4. Failure modes

### 4.1 Filesystem credentials / `FS_METHOD`

`get_filesystem_method()` (`file.php:2260-2332`) returns `'direct'` **only** if a temp write test in
`WP_CONTENT_DIR` succeeds *and* `fileowner( __FILE__ ) === fileowner( $temp_file )`. Otherwise it
falls through to `ssh2` → `ftpext` → `ftpsockets`, all of which need credentials.

`request_filesystem_credentials()` (`file.php:2364`) returns `true` immediately for `'direct'`
(line 2399), otherwise reads `FTP_HOST`/`FTP_USER`/`FTP_PASS`/`FTP_PUBKEY`/`FTP_PRIKEY` constants
(lines 2429-2445) and only then prints a form. So **sites with FTP constants in `wp-config.php` work
headlessly**; sites without them cannot.

Result on a non-direct filesystem with no constants: `Automatic_Upgrader_Skin` swallows the form,
`fs_connect()` returns `false`, `run()` bails, **`install()` returns `null`**. There is no `WP_Error`
to report.

Handling: detect `null` explicitly, read `$GLOBALS['wp_filesystem']->errors` for detail (as core does
at `ajax-actions.php:4520-4534`), and surface a distinct `filesystem_credentials_required` error with
a manual-install fallback (a signed download link + "upload this zip via Plugins → Add New →
Upload"). Do **not** collapse it into a generic failure, which is Elementor's mistake.

The `request_filesystem_credentials` filter (`file.php:2388`) is also an escape hatch if we ever want
to accept credentials through our own UI — out of scope for v1.

### 4.2 `DISALLOW_FILE_MODS`

Already covered by `current_user_can( 'install_plugins' )` (§1). But test
`wp_is_file_mod_allowed( 'capability_update_core' )` separately so the message can say
*"your host has disabled plugin installation (`DISALLOW_FILE_MODS`)"* instead of
*"you don't have permission"* — those need different merchant actions. Managed hosts (WP Engine
legacy configs, Pantheon `live` env, WordPress.com Business) set this.

### 4.3 Outbound HTTPS blocked

- `WP_HTTP_BLOCK_EXTERNAL` + `WP_ACCESSIBLE_HOSTS` — `WP_Http::block_request()`
  (`class-wp-http.php:895-940`). Requests to non-allowlisted hosts return
  `WP_Error( 'http_request_failed', 'User has blocked requests through HTTP.' )`.
- `download_url()` uses `wp_safe_remote_get()` (`wp-includes/http.php:81-83`), which forces
  `reject_unsafe_urls = true` → `wp_http_validate_url()` (`http.php:559`). That rejects:
  - URLs containing userinfo (`https://user:pass@…`) — line 575
  - hosts resolving to `127.*`, `10.*`, `172.16-31.*`, `192.168.*`, `0.*` unless the host matches
    `get_option('home')` or `http_request_host_is_external` returns true — lines 597-620
  - **ports other than 80, 443, 8080** — line 639

  Consequence for Pro's dev branch: the `http://localhost:8080/pro/download/{version}` package URL
  (`Pro_Plugin_Updater.php:143-145`) only passes because the dev site's own `home` host is also
  `localhost` and 8080 is on the allowlist. Any other dev port would be rejected by
  `download_url()`. Worth documenting; do not widen it.

Handling: preflight with the license-validation call we are making anyway, and report
`http_request_failed` as its own error class ("your server cannot reach updates.wcpos.com").

### 4.4 Timeouts on ~10 MB

- `download_url()`'s socket timeout is **300 s** (`class-wp-upgrader.php:337` passes the default).
  That is not the binding constraint.
- `set_time_limit( 300 )` is called in `install_package()` (`class-wp-upgrader.php:533-534`) —
  **after** the download and unpack. Nothing raises PHP's time limit *during* the download.
- So on a host with `max_execution_time = 30` or PHP-FPM `request_terminate_timeout = 60`, a slow
  10.5 MB transfer is killed mid-request and the client sees a 502/504 with **no WP_Error at all**.
  10.5 MB at a realistic 200 KB/s is ~52 s.
- The browser side has its own ceilings: Cloudflare's 100 s proxy timeout, nginx `proxy_read_timeout`
  60 s.

Handling: call `set_time_limit( 0 )` (guarded by `function_exists`) and `wp_raise_memory_limit( 'admin' )`
before `install()`; keep the *client-visible* request short by splitting the work (§5); return a
`install_timeout` hint if the state store shows a step stuck `in_progress`.

### 4.5 Low disk

Core checks free space in exactly one place — `_unzip_file_ziparchive()`
(`file.php:1721-1740`) — and **only when `wp_doing_cron()`**:

```php
$required_space = $uncompressed_size * 2.1;
if ( wp_doing_cron() ) {
    $available_space = function_exists( 'disk_free_space' ) ? @disk_free_space( WP_CONTENT_DIR ) : false;
    …
}
```

In a web request there is no check at all; an out-of-space install fails with a generic copy error.
Also note `download_url()` writes to `get_temp_dir()` via `wp_tempnam()` (`file.php:673-676`,
`1161`), which may be a *different, smaller* filesystem than `WP_CONTENT_DIR`.

Handling: preflight `@disk_free_space( WP_CONTENT_DIR )` and `@disk_free_space( get_temp_dir() )`,
require ~3× the zip size in each, return `insufficient_disk_space`.

### 4.6 Multisite

`install_plugins` maps to `do_not_allow` for anyone who is not a super admin
(`capabilities.php:632-637`). So:
- The "Connect & Install Pro" affordance must be hidden (or shown as "ask your network
  administrator") for subsite admins.
- Both WooCommerce.com and core also gate on `manage_network_plugins` for the network-activation
  path (`ajax-actions.php:4553`).
- Decide activation scope explicitly: `activate_plugin( $file, '', $network_wide )`. Defaulting to
  per-site activation on a network install is a silent surprise; defaulting to network-wide is a
  bigger blast radius. Recommendation: install network-wide is fine, but **activate per-site by
  default** and offer network activation as an explicit choice.

### 4.7 Stale `update_plugins` transients under persistent object caches

- The `update_plugins_{hostname}` filter that Pro hooks (`Pro_Plugin_Updater.php:80`) only runs inside
  `wp_update_plugins()` (`wp-includes/update.php:355`), which early-returns unless `last_checked` is
  older than 1 h / 2 h / 12 h depending on context (lines 387-397).
- Under a persistent object cache, site transients live in the object cache, not the options table
  (`wp-includes/option.php:2586`, `2669` — `if ( wp_using_ext_object_cache() … )`). They can be
  served stale across requests and wiped wholesale by a cache flush.

**Design implication: the install flow must never read the package URL out of the `update_plugins`
transient.** Resolve it live against `updates.wcpos.com` inside the install request. This is what our
endpoint design already does, and it is why it is the right call.

After a successful install: `Plugin_Upgrader::install()` already calls `wp_clean_plugins_cache( true )`
(`class-plugin-upgrader.php:156`). Additionally delete `update_plugins` and Pro's own transients
(`woocommerce_pos_pro_update_data`, `woocommerce_pos_pro_license_status`) so the newly installed Pro
does not immediately advertise a phantom update or a stale license status.

### 4.8 Opcache

`wp_opcache_invalidate()` (`file.php:2714`) and `wp_opcache_invalidate_directory()` are called by
`copy_dir()`/`move_dir()` (`file.php:2046`, `2125`), so core does invalidate the newly written plugin
files. But on hosts with `opcache.restrict_api` set to a path outside WP, or
`opcache.validate_timestamps=0` with the API restricted, invalidation silently no-ops
(`file.php:2723-2745`). This is another argument for activating in a **second HTTP request**: a fresh
request is more likely to see the new files.

### 4.9 Response headers we should send from the endpoint

`download_url()` honours `Content-Disposition: attachment; filename=…` to rename the temp file
(`file.php:1214-1231`) and sniffs `Content-Type` against `get_allowed_mime_types()` to give it a real
extension (`file.php:1236-1252`). Neither is required for the install to work, but sending
`Content-Type: application/zip` and
`Content-Disposition: attachment; filename="woocommerce-pos-pro.zip"` makes the temp file
self-describing and the failure logs readable. Also: `download_url()` treats **any** non-200 as
`WP_Error( 'http_404', … )` and captures the first 1 KB of the body as `$data['body']`
(`file.php:1189-1208`) — so the update server should return license errors as small,
human-readable bodies with a non-200 status, and those strings will reach us.

---

## 5. Post-install: activation

`activate_plugin()` (`wp-admin/includes/plugin.php:641-742`):

```php
$valid        = validate_plugin( $plugin );            // 652
$requirements = validate_plugin_requirements( $plugin ); // 657
…
ob_start();
plugin_sandbox_scrape( $plugin );                       // 673 — include_once, IN THIS REQUEST
do_action( 'activate_plugin', $plugin, $network_wide );  // 688
do_action( "activate_{$plugin}", $network_wide );        // 703
update_option( 'active_plugins', $current );             // 717
do_action( 'activated_plugin', $plugin, $network_wide );  // 730
if ( ob_get_length() > 0 ) { return new WP_Error( 'unexpected_output', …, $output ); }  // 735
```

Two consequences for a one-request install+activate:

1. `plugin_sandbox_scrape()` **includes the Pro plugin file in the current PHP request**. If Pro
   fatals, the whole install request dies with a PHP fatal — the client sees a 500 and cannot tell
   an install failure from an activation failure. The install *did* succeed; the UI will say it
   didn't.
2. Even on success, Pro is being loaded into a request where free has already booted past
   `plugins_loaded`. Pro's normal bootstrap ordering never happens, and its activation-time
   migrations run against a half-initialized environment. WCPOS free/Pro already has a documented
   bootstrap-divergence class of bug here (`Vendor_Prefixed_Polyfills`, license-clear recursion), so
   this is not hypothetical.

Both core (`ajax-actions.php:4542-4553`, returns an `activateUrl`) and WooCommerce.com
(`activate_product` is its own `run-until-step`) split them.

**Recommendation: two HTTP requests.** Request 1 installs and returns the resolved
`plugin_file` from `$upgrader->plugin_info()`. Request 2 activates. If the UX wants a single button,
the *front end* chains the two calls — not PHP. That way an activation fatal is attributable, and the
activation runs in a request that starts clean with the new files on disk.

### License handoff — a real gap

The free plugin's `License_Section` (`includes/Services/Settings/License_Section.php:42-53`)
`read()`s from the `woocommerce_pos_license_settings` filter, and its inherited `write()` persists to
**`woocommerce_pos_settings_license`**.

Pro's `Settings::get_license_settings()`
(`/Users/kilbot/Projects/woocommerce-pos-pro/includes/Services/Settings.php:92-118`) reads
**`woocommerce_pos_pro_settings_license`** (`$db_prefix = 'woocommerce_pos_pro_settings_'`, line 24),
and generates a fresh `instance` on first run if the key is empty (lines 107-110).

**These are different options.** If the free install flow saves the pasted key through the free
settings path, Pro will not find it after activation and the merchant will have to re-enter it. The
endpoint must write the key to `woocommerce_pos_pro_settings_license` (the option Pro reads), and
should generate and persist the `instance` there too — the same value it used for the
`?instance=` download-URL argument — so the activation on the update server matches.

---

## 6. Recommended invocation pattern

### Entry point

A dedicated REST route in the existing free namespace:

```
POST /wp-json/wcpos/v1/pro/install     → { plugin_file, version }
POST /wp-json/wcpos/v1/pro/activate    → { active: true }
GET  /wp-json/wcpos/v1/pro/install/status  (only if we go async)
```

Rationale: the free admin/settings React app already speaks `@wordpress/api-fetch` with WP cookie
auth + `X-WP-Nonce`, which core's `rest_cookie_check_errors()` validates — CSRF is covered without a
separate `updates` nonce. This mirrors `wccom-site/v3/installer` and `wc-admin/plugins/install`.
(If we ever expose this from `admin-ajax.php` instead, we must `check_ajax_referer( 'updates' )` and
localize `wp.updates.ajaxNonce`, per `ajax-actions.php:4460`.)

Both routes carry the standard WCPOS route classification (`X-WCPOS: 1`) like the rest of `wcpos/v1`.

### Capability check

```php
'permission_callback' => static function () {
    // Plugin-scoped gate: this surface exposes the merchant's license key.
    if ( ! current_user_can( 'manage_woocommerce_pos' ) ) {
        return new WP_Error( 'wcpos_forbidden', …, array( 'status' => rest_authorization_required_code() ) );
    }
    // Core gate: covers role + DISALLOW_FILE_MODS + multisite super-admin, all in one.
    if ( ! current_user_can( 'install_plugins' ) ) {
        return new WP_Error( 'wcpos_cannot_install_plugins', …, array( 'status' => rest_authorization_required_code() ) );
    }
    return true;
},
```

and inside the handler, decompose for messaging only:

```php
if ( ! wp_is_file_mod_allowed( 'capability_update_core' ) )  → 'file_mods_disabled'
if ( is_multisite() && ! is_super_admin() )                  → 'multisite_network_admin_required'
```

The activate route additionally checks `current_user_can( 'activate_plugins' )` (and
`manage_network_plugins` when `network_wide` is requested).

### Skin and upgrader

```php
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 0 ); }
wp_raise_memory_limit( 'admin' );

$skin     = new WP_Ajax_Upgrader_Skin();
$upgrader = new Plugin_Upgrader( $skin );
$result   = $upgrader->install( $signed_package_url );   // no overwrite_package
```

### Auth mechanism

Preferred: **short-lived signed URL (option D)**.
1. Free POSTs `{ key, instance, site_url }` to `updates.wcpos.com` with the key in an
   `Authorization`/`X-WCPOS-License` header over TLS.
2. Server validates, activates the instance, returns
   `{ download_url, version, expires_at }` where `download_url` carries a single-use HMAC token
   scoped to that URL and ~5-minute TTL.
3. Free hands `download_url` to `Plugin_Upgrader::install()`.

Interim fallback (if the endpoint cannot mint tokens yet): keep the existing
`?key=…&instance=…` form, which is what Pro's updater already produces
(`Pro_Plugin_Updater.php:147-153`) — but **redact it everywhere** (see below).

Either way: **never** send `$skin->get_upgrade_messages()` to the client or to `wc_get_logger()`
without running them through a redactor that strips `key`/`instance`/`token` query values. Core's
`downloading_package` string embeds the full URL.

### Error surfaces (mirror core's four-branch shape, plus preflights)

| Code | Trigger | Merchant action |
|---|---|---|
| `wcpos_cannot_install_plugins` | cap check fails | log in as an admin / network admin |
| `file_mods_disabled` | `! wp_is_file_mod_allowed()` | ask host to remove `DISALLOW_FILE_MODS` |
| `multisite_network_admin_required` | multisite, not super admin | ask network administrator |
| `insufficient_disk_space` | preflight `disk_free_space()` < 3× zip | free up space |
| `license_invalid` / `license_expired` | non-200 from the update server (`download_url` → `http_404` carries the first 1 KB of body) | renew / check key |
| `http_request_failed` | `WP_HTTP_BLOCK_EXTERNAL`, DNS, TLS | allowlist `updates.wcpos.com` |
| `filesystem_credentials_required` | `install()` returned **`null`** | manual zip upload (offer the link) |
| `folder_exists` | directory already present | treat as installed → go to activate |
| `incompatible_php_required_version` / `incompatible_wp_required_version` / `incompatible_archive_no_plugins` | `check_package()` | upgrade PHP/WP |
| `unexpected_output` / activation `WP_Error` | `activate_plugin()` | separate request, separate message |

Structured error extraction:

```php
if ( is_wp_error( $result ) )                { $code = $result->get_error_code(); }
elseif ( is_wp_error( $skin->result ) )      { $code = $skin->result->get_error_code(); }
elseif ( $skin->get_errors()->has_errors() ) { $code = $skin->get_errors()->get_error_code(); }
elseif ( null === $result )                  { $code = 'filesystem_credentials_required'; /* + $wp_filesystem->errors */ }
```

### Post-install

1. `$plugin_file = $upgrader->plugin_info();` — return it; do not hardcode.
2. Persist the license key + instance to **`woocommerce_pos_pro_settings_license`** (§5).
3. `delete_site_transient( 'update_plugins' )`, `delete_transient( 'woocommerce_pos_pro_update_data' )`,
   `delete_transient( 'woocommerce_pos_pro_license_status' )`.
4. **Return.** Let the client issue a second request to `/pro/activate`, which calls
   `activate_plugin( $plugin_file )` and reports its own `WP_Error` separately.

### Optional hardening (if timeouts prove common in the field)

Adopt WooCommerce.com's stepped model: persist an install state keyed by an
`idempotency-key`, expose `run-until-step`, and let the client drive
`download → unpack → move → activate` as separate requests using
`WP_Upgrader::download_package()` / `unpack_package()` / `install_package()` directly. That converts a
single 60-second request into four short ones and makes a mid-install timeout resumable rather than
fatal. Do not build this up front — build the state seam (an install-state option + idempotency key)
so it can be added without an API break.

---

## Open questions for the endpoint-design ticket

1. Can `updates.wcpos.com` mint short-lived, single-use signed download URLs? If yes, option D; if
   not, we ship the query-arg key plus redaction and revisit.
2. Multisite: is network-wide activation ever right for WCPOS Pro, or always per-site?
3. Does Pro's activation path tolerate being included mid-request at all, or must the two-request
   split be a hard requirement? (Recommendation: treat it as a hard requirement regardless.)
4. Do we ship the manual-zip fallback (signed download link + "upload this") in v1? On FTP-credential
   hosts it is the only path that works.
