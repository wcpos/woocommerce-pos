# Phase 1 — Backend: printer data model + lifecycle

> **For agentic workers:** Read `00-overview.md` first. Implement with `superpowers:subagent-driven-development` or `executing-plans`. TDD, bite-sized steps, commit after each green task. PHP tests run via wp-env (see overview). This phase is **backend only** — no React, no template rendering.

**Goal:** Establish the final cloud-print printer data model (3 providers, auto-derived immutable IDs), record printer last-seen + derive connection status, expose token regeneration, and add a test-print endpoint that enqueues a server-built diagnostic for Star/Epson.

**Architecture:** Printers live in option `woocommerce_pos_settings_cloud_print` under `printers[]`. A separate non-autoloaded option `woocommerce_pos_cloud_print_runtime` holds volatile last-seen timestamps (so the settings option isn't rewritten on every poll). `Cloud_Print_Registry` gains ID derivation, last-seen, and status helpers. Test-print enqueues a `wcpos_print_job` whose payload is built by a new `Cloud_Print_Diagnostic` service.

**Out of scope (later phases):** React UI (P2), template-driven rendering (P3), PrintNode job submission + PDF + PrintNode test-print (P4). PrintNode printers can be *registered/stored* here, but test-print for them returns a 400 until P4.

**Files:**
- Modify: `includes/Services/Cloud_Print_Registry.php`
- Create: `includes/Services/Cloud_Print_Diagnostic.php`
- Modify: `includes/API/Settings.php` (`sanitize_cloud_printer`, `update_cloud_print_settings`, `get_cloud_print_settings`)
- Modify: `includes/API/Print_Jobs_Controller.php` (record last-seen in poll handlers; add `test` route + handler)
- Tests: `tests/includes/Services/Cloud_Print_Registry_Test.php`, `tests/includes/Services/Cloud_Print_Diagnostic_Test.php`, `tests/includes/API/Settings_CloudPrint_Test.php` (create if absent), `tests/includes/API/Print_Jobs_Controller_Test.php`

**Final data shapes this phase establishes (record any change in the handoff):**
```
// option woocommerce_pos_settings_cloud_print
{
  "printers": [
    // star / epson:
    { "id":"kitchen", "name":"Kitchen printer", "provider":"star-cloudprnt"|"epson-sdp",
      "store_id":0, "poll_token_hash":"<sha256>" },
    // printnode:
    { "id":"bar", "name":"Bar", "provider":"printnode", "store_id":0,
      "printnode_api_key":"<secret>", "printnode_printer_id":12345 }
  ],
  "assignments": [ { "printer_id":"kitchen", "scope":"every"|"pos"|"online", "template_id":"" } ]
}
// option woocommerce_pos_cloud_print_runtime (autoload no)
{ "kitchen": 1716950400, "bar": 1716950000 }   // id => last_seen unix ts
// GET /settings/cloud-print response printers[] add: "status":"connected"|"waiting"|"offline","last_seen":<ts|null>
// secrets stripped from GET: poll_token_hash, printnode_api_key
```
> Note: `assignments[].template_id` replaces the old `format` field (decision #7). This phase only sanitizes/stores it as an opaque string; the trigger service still ignores it until P3. Keep the old `format` removed (no back-compat).

---

### Task 1: Auto-derive immutable printer IDs

**Files:** Modify `includes/Services/Cloud_Print_Registry.php`; Test `tests/includes/Services/Cloud_Print_Registry_Test.php`

- [ ] **Step 1: Write the failing test**

```php
public function test_derive_id_slugifies_name_and_dedupes(): void {
    $this->assertEquals( 'kitchen-printer', Cloud_Print_Registry::derive_id( 'Kitchen Printer', array() ) );
    $this->assertEquals( 'kitchen-printer-2', Cloud_Print_Registry::derive_id( 'Kitchen Printer!', array( 'kitchen-printer' ) ) );
    $this->assertEquals( 'kitchen-printer-3', Cloud_Print_Registry::derive_id( 'Kitchen Printer', array( 'kitchen-printer', 'kitchen-printer-2' ) ) );
    $this->assertEquals( 'printer', Cloud_Print_Registry::derive_id( '', array() ) );
}
```

- [ ] **Step 2: Run it, expect fail** (`Error: call to undefined method derive_id`).

Run: `pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Services/Cloud_Print_Registry_Test.php --filter test_derive_id_slugifies_name_and_dedupes`

- [ ] **Step 3: Implement** in `Cloud_Print_Registry`:

```php
/**
 * Derive a stable, URL-safe printer id from a display name, unique against existing ids.
 *
 * @param string        $name        Display name.
 * @param array<string> $existing_ids Already-used ids.
 *
 * @return string
 */
public static function derive_id( string $name, array $existing_ids ): string {
    $base = sanitize_title( $name );
    if ( '' === $base ) {
        $base = 'printer';
    }
    $candidate = $base;
    $suffix    = 2;
    while ( \in_array( $candidate, $existing_ids, true ) ) {
        $candidate = $base . '-' . $suffix;
        ++$suffix;
    }

    return $candidate;
}
```

- [ ] **Step 4: Run test, expect PASS.**
- [ ] **Step 5: Commit** — `git add -A && git commit -m "feat(cloud-print): derive stable immutable printer ids from name"`

---

### Task 2: Provider field + immutable-id preservation on save

Rename `protocol` → `provider`, add `printnode`, assign ids to new printers, preserve ids for existing ones (so renames don't change the id), and switch assignments from `format` to `template_id`.

**Files:** Modify `includes/API/Settings.php`; Test `tests/includes/API/Settings_CloudPrint_Test.php`

- [ ] **Step 1: Write failing tests**

```php
public function test_new_printer_gets_derived_id_and_provider(): void {
    wp_set_current_user( $this->user );
    $req = $this->wp_rest_post_request( '/wcpos/v1/settings/cloud-print' );
    $req->set_body_params( array(
        'printers'    => array( array( 'name' => 'Kitchen Printer', 'provider' => 'star-cloudprnt' ) ),
        'assignments' => array(),
    ) );
    $res = rest_do_request( $req );
    $data = $res->get_data();

    $this->assertEquals( 200, $res->get_status() );
    $this->assertEquals( 'kitchen-printer', $data['printers'][0]['id'] );
    $this->assertEquals( 'star-cloudprnt', $data['printers'][0]['provider'] );
    $this->assertArrayNotHasKey( 'poll_token_hash', $data['printers'][0] );
    $this->assertArrayHasKey( 'kitchen-printer', $data['generated'] ); // one-time token
}

public function test_existing_printer_id_is_preserved_on_rename(): void {
    update_option( 'woocommerce_pos_settings_cloud_print', array(
        'printers' => array( array(
            'id' => 'kitchen-printer', 'name' => 'Kitchen Printer',
            'provider' => 'star-cloudprnt', 'store_id' => 0,
            'poll_token_hash' => Cloud_Print_Registry::hash_token( 'tok' ),
        ) ),
        'assignments' => array(),
    ) );
    wp_set_current_user( $this->user );
    $req = $this->wp_rest_post_request( '/wcpos/v1/settings/cloud-print' );
    $req->set_body_params( array(
        'printers'    => array( array( 'id' => 'kitchen-printer', 'name' => 'Back Kitchen', 'provider' => 'star-cloudprnt' ) ),
        'assignments' => array(),
    ) );
    $data = rest_do_request( $req )->get_data();

    $this->assertEquals( 'kitchen-printer', $data['printers'][0]['id'] ); // id unchanged
    $this->assertEquals( 'Back Kitchen', $data['printers'][0]['name'] );  // name changed
    $this->assertArrayNotHasKey( 'kitchen-printer', $data['generated'] ?? array() ); // token NOT regenerated
}
```

- [ ] **Step 2: Run, expect fail.**

- [ ] **Step 3: Implement.** Replace `sanitize_cloud_printer()` (~`includes/API/Settings.php:735`):

```php
private function sanitize_cloud_printer( $printer ): array {
    $printer  = \is_array( $printer ) ? $printer : array();
    $provider = \in_array( $printer['provider'] ?? '', array( 'star-cloudprnt', 'epson-sdp', 'printnode' ), true )
        ? $printer['provider'] : 'star-cloudprnt';

    $clean = array(
        'id'               => sanitize_text_field( $printer['id'] ?? '' ),
        'name'             => sanitize_text_field( $printer['name'] ?? '' ),
        'provider'         => $provider,
        'store_id'         => isset( $printer['store_id'] ) ? (int) $printer['store_id'] : 0,
        'regenerate_token' => ! empty( $printer['regenerate_token'] ),
    );
    if ( 'printnode' === $provider ) {
        $clean['printnode_api_key']    = sanitize_text_field( $printer['printnode_api_key'] ?? '' );
        $clean['printnode_printer_id'] = isset( $printer['printnode_printer_id'] ) ? (int) $printer['printnode_printer_id'] : 0;
    }

    return $clean;
}
```

Update `sanitize_cloud_assignment()` (~`:754`) to use `template_id` instead of `format`:

```php
private function sanitize_cloud_assignment( $assignment ): array {
    $assignment = \is_array( $assignment ) ? $assignment : array();

    return array(
        'printer_id'  => sanitize_text_field( $assignment['printer_id'] ?? '' ),
        'scope'       => \in_array( $assignment['scope'] ?? '', array( 'every', 'pos', 'online' ), true ) ? $assignment['scope'] : 'every',
        'template_id' => sanitize_text_field( (string) ( $assignment['template_id'] ?? '' ) ),
    );
}
```

In `update_cloud_print_settings()` (~`:652`), after `$printer = $this->sanitize_cloud_printer( $printer )`, assign/preserve the id and gate token generation by provider. Replace the id/token block (~`:675-700`):

```php
$existing_ids = array_keys( $existing_hashes ); // collect from existing printers (see below)
// derive id for new printers (no id supplied); preserve supplied ids
if ( '' === $printer['id'] ) {
    $printer['id'] = Cloud_Print_Registry::derive_id( $printer['name'], array_merge( $existing_ids, array_keys( $seen_ids ) ) );
}
$id         = $printer['id'];
$regenerate = ! empty( $printer['regenerate_token'] );
unset( $printer['regenerate_token'] );

// duplicate guard (unchanged) ...

// Tokens only for polling providers.
if ( \in_array( $printer['provider'], array( 'star-cloudprnt', 'epson-sdp' ), true ) ) {
    if ( $regenerate || empty( $existing_hashes[ $id ] ) ) {
        $token                      = Cloud_Print_Registry::generate_token();
        $printer['poll_token_hash'] = Cloud_Print_Registry::hash_token( $token );
        $generated[ $id ]           = $token;
    } else {
        $printer['poll_token_hash'] = $existing_hashes[ $id ];
    }
}
```
> Build `$existing_ids` from `$existing['printers']` (all ids, not just those with hashes) so PrintNode ids are also preserved. Adjust the `$existing_hashes` loop (~`:662`) to additionally collect `$existing_ids[] = $printer['id']`.

In `get_cloud_print_settings()` and the response mapper of `update_cloud_print_settings()` (~`:709`), strip **both** secrets:

```php
unset( $printer['poll_token_hash'], $printer['printnode_api_key'] );
```

- [ ] **Step 4: Run tests, expect PASS.**
- [ ] **Step 5: Commit** — `git commit -am "feat(cloud-print): provider field, immutable ids, template_id assignments"`

---

### Task 3: Record printer last-seen on poll

**Files:** Modify `includes/Services/Cloud_Print_Registry.php`, `includes/API/Print_Jobs_Controller.php`; Test `Cloud_Print_Registry_Test.php`, `Print_Jobs_Controller_Test.php` (or the CloudPRNT test).

- [ ] **Step 1: Write failing test** (registry):

```php
public function test_record_and_get_seen_roundtrip(): void {
    $registry = new Cloud_Print_Registry();
    $this->assertEquals( 0, $registry->get_seen( 'kitchen' ) );
    $registry->record_seen( 'kitchen' );
    $this->assertGreaterThan( 0, $registry->get_seen( 'kitchen' ) );
}
```

- [ ] **Step 2: Run, expect fail.**

- [ ] **Step 3: Implement** in `Cloud_Print_Registry`:

```php
const RUNTIME_OPTION = 'woocommerce_pos_cloud_print_runtime';
const SEEN_TTL       = 150; // seconds; connected if seen within this window

public function record_seen( string $printer_id ): void {
    $runtime               = get_option( self::RUNTIME_OPTION, array() );
    $runtime               = \is_array( $runtime ) ? $runtime : array();
    $runtime[ $printer_id ] = time();
    update_option( self::RUNTIME_OPTION, $runtime, false ); // autoload no
}

public function get_seen( string $printer_id ): int {
    $runtime = get_option( self::RUNTIME_OPTION, array() );

    return ( \is_array( $runtime ) && isset( $runtime[ $printer_id ] ) ) ? (int) $runtime[ $printer_id ] : 0;
}
```

- [ ] **Step 4: Run registry test, expect PASS.**

- [ ] **Step 5: Hook into poll handlers.** In `Print_Jobs_Controller::cloudprnt()` and `epson_sdp()`, right after `$printer_id = sanitize_text_field(...)`, add:

```php
$this->registry->record_seen( $printer_id );
```
> Add a `Cloud_Print_Registry` instance to the controller (e.g. `$this->registry = new Cloud_Print_Registry();` in the constructor, or instantiate inline). `printer_token_permissions_check()` already proves the token is valid before the handler runs, so recording in the handler only logs authenticated polls.

- [ ] **Step 6: Write + run a handler test** asserting that a valid cloudprnt POST sets `get_seen()` > 0 (use the existing CloudPRNT test setup that registers a printer + token). PASS.

- [ ] **Step 7: Commit** — `git commit -am "feat(cloud-print): record printer last-seen on poll"`

---

### Task 4: Derive connection status in GET response

**Files:** Modify `includes/Services/Cloud_Print_Registry.php` (status helper), `includes/API/Settings.php` (`get_cloud_print_settings`); Test `Settings_CloudPrint_Test.php`.

- [ ] **Step 1: Write failing tests**

```php
public function test_status_waiting_when_never_seen(): void {
    $registry = new Cloud_Print_Registry();
    $this->assertEquals( 'waiting', $registry->status_for( 'kitchen' ) );
}
public function test_status_connected_when_recently_seen(): void {
    $registry = new Cloud_Print_Registry();
    $registry->record_seen( 'kitchen' );
    $this->assertEquals( 'connected', $registry->status_for( 'kitchen' ) );
}
public function test_get_settings_includes_status_and_strips_secrets(): void {
    update_option( 'woocommerce_pos_settings_cloud_print', array(
        'printers' => array( array(
            'id' => 'kitchen', 'name' => 'Kitchen', 'provider' => 'star-cloudprnt',
            'store_id' => 0, 'poll_token_hash' => Cloud_Print_Registry::hash_token( 'tok' ),
        ) ),
        'assignments' => array(),
    ) );
    wp_set_current_user( $this->user );
    $data = rest_do_request( $this->wp_rest_get_request( '/wcpos/v1/settings/cloud-print' ) )->get_data();

    $this->assertEquals( 'waiting', $data['printers'][0]['status'] );
    $this->assertArrayHasKey( 'last_seen', $data['printers'][0] );
    $this->assertArrayNotHasKey( 'poll_token_hash', $data['printers'][0] );
}
```

- [ ] **Step 2: Run, expect fail.**

- [ ] **Step 3: Implement** status helper in `Cloud_Print_Registry`:

```php
/**
 * Connection status for a printer: 'waiting' (never polled), 'connected'
 * (polled within SEEN_TTL), or 'offline' (polled, but stale).
 */
public function status_for( string $printer_id ): string {
    $seen = $this->get_seen( $printer_id );
    if ( 0 === $seen ) {
        return 'waiting';
    }

    return ( time() - $seen ) <= self::SEEN_TTL ? 'connected' : 'offline';
}
```

In `get_cloud_print_settings()`, when mapping printers for the response, attach status + last_seen (and strip secrets):

```php
$registry          = new Cloud_Print_Registry();
$response_printers = array_map(
    static function ( $printer ) use ( $registry ) {
        $id              = (string) ( $printer['id'] ?? '' );
        $seen            = $registry->get_seen( $id );
        $printer['status']    = $registry->status_for( $id );
        $printer['last_seen'] = $seen > 0 ? $seen : null;
        unset( $printer['poll_token_hash'], $printer['printnode_api_key'] );

        return $printer;
    },
    $printers
);
```
> PrintNode printers don't poll, so `status` will read `waiting`. That's acceptable for P1; P4 can set a PrintNode-specific status from the PrintNode API. Note this in the handoff.

- [ ] **Step 4: Run, expect PASS.**
- [ ] **Step 5: Commit** — `git commit -am "feat(cloud-print): expose printer connection status in settings response"`

---

### Task 5: Diagnostic builder service (Star + Epson)

**Files:** Create `includes/Services/Cloud_Print_Diagnostic.php`; Test `tests/includes/Services/Cloud_Print_Diagnostic_Test.php`.

- [ ] **Step 1: Write failing test**

```php
public function test_build_star_diagnostic_is_escpos_bytes(): void {
    $diag = ( new Cloud_Print_Diagnostic() )->build( 'star-cloudprnt', 'Kitchen' );
    $this->assertEquals( 'application/octet-stream', $diag['content_type'] );
    $bytes = base64_decode( $diag['payload'], true );
    $this->assertStringContainsString( 'WCPOS', $bytes );
    $this->assertStringContainsString( 'Kitchen', $bytes );
    $this->assertStringContainsString( "\x1B@", $bytes ); // ESC @ init
}
public function test_build_epson_diagnostic_is_epos_xml(): void {
    $diag = ( new Cloud_Print_Diagnostic() )->build( 'epson-sdp', 'Counter' );
    $this->assertEquals( 'application/xml', $diag['content_type'] );
    $xml = base64_decode( $diag['payload'], true );
    $this->assertStringContainsString( '<epos-print', $xml );
    $this->assertStringContainsString( 'WCPOS', $xml );
}
public function test_build_printnode_throws(): void {
    $this->expectException( \RuntimeException::class );
    ( new Cloud_Print_Diagnostic() )->build( 'printnode', 'Bar' );
}
```

- [ ] **Step 2: Run, expect fail.**

- [ ] **Step 3: Implement** `includes/Services/Cloud_Print_Diagnostic.php`:

```php
<?php
/**
 * Builds cloud-print test/diagnostic payloads per provider.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * Cloud_Print_Diagnostic class.
 */
class Cloud_Print_Diagnostic {
    /**
     * Build a base64 diagnostic payload + content type for a provider.
     *
     * @param string $provider     Provider key.
     * @param string $printer_name Display name.
     *
     * @return array{content_type:string, payload:string}
     *
     * @throws \RuntimeException When the provider has no server-side diagnostic (PrintNode: see Phase 4).
     */
    public function build( string $provider, string $printer_name ): array {
        $date = gmdate( 'Y-m-d H:i' );
        if ( 'star-cloudprnt' === $provider ) {
            return array( 'content_type' => 'application/octet-stream', 'payload' => base64_encode( $this->escpos( $printer_name, $date ) ) );
        }
        if ( 'epson-sdp' === $provider ) {
            return array( 'content_type' => 'application/xml', 'payload' => base64_encode( $this->epos( $printer_name, $date ) ) );
        }
        throw new \RuntimeException( 'No server-side diagnostic for provider: ' . $provider );
    }

    /**
     * Minimal ESC/POS capability check.
     */
    private function escpos( string $name, string $date ): string {
        $esc  = "\x1B@";        // init
        $esc .= "\x1Ba\x01";    // center
        $esc .= "WCPOS\n";
        $esc .= "Cloud Print Test\n";
        $esc .= "\x1Ba\x00";    // left
        $esc .= 'Printer: ' . $name . "\n";
        $esc .= 'Date: ' . $date . "\n";
        $esc .= "If you can read this, printing works!\n\n\n";
        $esc .= "\x1DV\x41\x00"; // full cut

        return $esc;
    }

    /**
     * Minimal ePOS-Print XML capability check.
     */
    private function epos( string $name, string $date ): string {
        $text  = "WCPOS - Cloud Print Test\n";
        $text .= 'Printer: ' . $name . "\n";
        $text .= 'Date: ' . $date . "\n";
        $text .= "If you can read this, printing works!\n";

        return '<epos-print xmlns="http://www.epson-pos.com/schemas/2011/03/epos-print">'
            . '<text>' . esc_html( $text ) . '</text>'
            . '<cut type="feed"/>'
            . '</epos-print>';
    }
}
```

- [ ] **Step 4: Run, expect PASS.**
- [ ] **Step 5: Commit** — `git commit -am "feat(cloud-print): server-side diagnostic builder for Star/Epson test print"`

---

### Task 6: Test-print REST endpoint

**Files:** Modify `includes/API/Print_Jobs_Controller.php`; Test `tests/includes/API/Print_Jobs_Controller_Test.php`.

- [ ] **Step 1: Write failing tests**

```php
public function test_test_print_enqueues_pending_job_for_star_printer(): void {
    update_option( 'woocommerce_pos_settings_cloud_print', array(
        'printers' => array( array( 'id' => 'kitchen', 'name' => 'Kitchen', 'provider' => 'star-cloudprnt',
            'poll_token_hash' => Cloud_Print_Registry::hash_token( 'tok' ) ) ),
        'assignments' => array(),
    ) );
    wp_set_current_user( $this->user );
    $req = $this->wp_rest_post_request( '/wcpos/v1/print-jobs/test' );
    $req->set_body_params( array( 'printer_id' => 'kitchen' ) );
    $res = rest_do_request( $req );

    $this->assertEquals( 201, $res->get_status() );
    $this->assertEquals( 'pending', $res->get_data()['status'] );
    $this->assertEquals( 'kitchen', $res->get_data()['printer_id'] );
}
public function test_test_print_unknown_printer_returns_404(): void {
    wp_set_current_user( $this->user );
    $req = $this->wp_rest_post_request( '/wcpos/v1/print-jobs/test' );
    $req->set_body_params( array( 'printer_id' => 'nope' ) );
    $this->assertEquals( 404, rest_do_request( $req )->get_status() );
}
public function test_test_print_printnode_returns_400_until_phase_4(): void {
    update_option( 'woocommerce_pos_settings_cloud_print', array(
        'printers' => array( array( 'id' => 'bar', 'name' => 'Bar', 'provider' => 'printnode',
            'printnode_api_key' => 'k', 'printnode_printer_id' => 1 ) ),
        'assignments' => array(),
    ) );
    wp_set_current_user( $this->user );
    $req = $this->wp_rest_post_request( '/wcpos/v1/print-jobs/test' );
    $req->set_body_params( array( 'printer_id' => 'bar' ) );
    $this->assertEquals( 400, rest_do_request( $req )->get_status() );
}
```

- [ ] **Step 2: Run, expect fail.**

- [ ] **Step 3: Register the route** in `Print_Jobs_Controller::register_routes()` (alongside the existing management routes, `manage_permissions_check`):

```php
register_rest_route(
    $this->namespace,
    '/' . $this->rest_base . '/test',
    array(
        'methods'             => \WP_REST_Server::CREATABLE,
        'callback'            => array( $this, 'test_print' ),
        'permission_callback' => array( $this, 'manage_permissions_check' ),
    )
);
```

- [ ] **Step 4: Implement the handler:**

```php
/**
 * Enqueue a diagnostic test print for a registered printer.
 *
 * @param \WP_REST_Request $request Request.
 *
 * @return \WP_REST_Response|\WP_Error
 */
public function test_print( $request ) {
    $printer_id = sanitize_text_field( (string) $request->get_param( 'printer_id' ) );
    $printer    = $this->registry->get_printer( $printer_id );
    if ( null === $printer ) {
        return new \WP_Error( 'wcpos_print_job_unknown_printer', __( 'Unknown printer.', 'woocommerce-pos' ), array( 'status' => 404 ) );
    }

    try {
        $diag = ( new Cloud_Print_Diagnostic() )->build( (string) $printer['provider'], (string) $printer['name'] );
    } catch ( \RuntimeException $e ) {
        return new \WP_Error( 'wcpos_print_job_no_diagnostic', __( 'Test print is not available for this printer yet.', 'woocommerce-pos' ), array( 'status' => 400 ) );
    }

    $id = $this->jobs->create( array(
        'printer_id'   => $printer_id,
        'content_type' => $diag['content_type'],
        'payload'      => $diag['payload'],
    ) );

    return new \WP_REST_Response( $this->jobs->get( $id ), 201 );
}
```
> Ensure `Cloud_Print_Diagnostic` is imported (`use WCPOS\WooCommercePOS\Services\Cloud_Print_Diagnostic;`) and `$this->registry` exists (added in Task 3). `Print_Job_Service::create()` already accepts `payload` (stored in post_content) + `content_type`; `render_payload()` base64-decodes the payload, so pass the base64 string straight through.

- [ ] **Step 5: Run tests, expect PASS.**
- [ ] **Step 6: Commit** — `git commit -am "feat(cloud-print): add test-print endpoint enqueuing a diagnostic job"`

---

### Task 7: Phase close-out — full suite, lint, handoff

- [ ] **Step 1: Run the full cloud-print test set, expect all PASS:**

```bash
pnpm exec wp-env run --env-cwd='wp-content/plugins/woocommerce-pos' tests-cli -- \
  vendor/bin/phpunit -c .phpunit.xml.dist \
  tests/includes/Services/Cloud_Print_Registry_Test.php \
  tests/includes/Services/Cloud_Print_Diagnostic_Test.php \
  tests/includes/API/Settings_CloudPrint_Test.php \
  tests/includes/API/Print_Jobs_Controller_Test.php \
  tests/includes/API/Print_Jobs_CloudPRNT_Test.php \
  tests/includes/API/Print_Jobs_EpsonSDP_Test.php
```

- [ ] **Step 2: Lint touched files, fix all:** `composer run lint`
- [ ] **Step 3: Open the PR** via the `/pr` skill. Document the exact commands + results in the body.
- [ ] **Step 4: Write `HANDOFF-phase-1.md`** (template in `00-overview.md`) capturing: final printer/assignment/runtime shapes, the GET response additions (`status`, `last_seen`), secret-stripping, the `/print-jobs/test` contract, and the note that PrintNode `status` is always `waiting` until P4. Flag for Phase 2: the FE `CloudPrinter` type must change `protocol` → `provider`, add `printnode` + its fields, and `CloudAssignment` must use `template_id` (not `format`).

---

## Phase 1 self-review checklist
- [ ] Old `format` assignment field fully removed; `template_id` used everywhere it was.
- [ ] `protocol` → `provider` renamed in every PHP touch point; values include `printnode`.
- [ ] Secrets (`poll_token_hash`, `printnode_api_key`) never appear in any GET/POST response.
- [ ] Method names consistent: `derive_id`, `record_seen`, `get_seen`, `status_for`, `Cloud_Print_Diagnostic::build`.
- [ ] No PrintNode job submission attempted (that's P4) — only registration + storage + a 400 on test-print.
