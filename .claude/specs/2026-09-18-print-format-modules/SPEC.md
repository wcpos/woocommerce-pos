# Delete the delegating print-format modules

Branch `codex/print-format-modules`, cut from `origin/main` (8b006d7a). PR targets `main`.
Candidate 15 of the 2026-09-17 architecture review (tracking issue #2007, closed; Paul picked
this card on 2026-09-18). A deletion-test refactor: no wire-shape change, no behaviour change.

## What the review found, corrected against the tree

Three shallow modules under `includes/Services/`:

| Module | Body | Production callers |
|---|---|---|
| `Print_Format_Resolver` (70 lines) | `resolve()` = `Provider::adapter( Provider::normalize( printer.provider ) )->format( printer, template )` with an empty pair when the adapter is null; `content_type_for_printer()` = the same lookup, `->content_type()` or `application/octet-stream` | `Cloud_Print_Trigger_Service::enqueue_order_job()` (resolve), `API\V1\Print_Jobs_Controller` reprint path (resolve + content_type_for_printer) |
| `Cloud_Print_Diagnostic` (72 lines) | `build()` / `build_pdf()` / `star_markup()` = `Provider::adapter( key )->diagnostic( name )` behind a throw | **none** — `Print_Jobs_Controller:1106`, `Printnode_Adapter:208` and `Star_Online_Adapter:169` call `->diagnostic()` on the adapter directly |
| `Receipt_Output_Adapter_Factory` (50 lines) | `format` string → one of six output adapters | `Print_Job_Service` fixed-layout render of a stored job `format` |

The review card proposed deleting all three. The third stays: the create-job endpoint accepts
any `format` for an order-based job (`validate_job_for_printer()` only rejects `escpos` /
`starprnt` on Star CloudPRNT and enforces `epos-xml` on Epson), so a client can still create a
`zpl`, `cpcl` or `tspl` job and the factory is the only thing that renders it, and the wiki
documents those adapters as supported. Removing them would be a product decision, not this
refactor. **Do not touch `Receipt_Output_Adapter_Factory`, the six output adapters or their tests.**

## Goal

- `Cloud_Print_Diagnostic` is deleted, with its test file. Its seven cases test adapter
  behaviour (StarPRNT bytes, control-byte stripping, ePOS XML shape, PrintNode PDF bytes, Star
  Document Markup and its bracket escaping); they move, one to one, into
  `tests/includes/Services/Provider_Adapter_Test.php` driving `Provider::adapter( key )->diagnostic( name )`
  directly. `test_build_printnode_throws` becomes an assertion that
  `Provider::supports_server_diagnostic( 'printnode' )` is false (that is the fact the throw
  encoded; the controller checks it before calling `diagnostic()`).
- `Print_Format_Resolver` is deleted, with its test file. Its logic moves next to the registry
  it wraps, as two statics on `Services\Provider` beside `adapter()`:
  - `public static function format( array $printer, array $template ): array` — normalise the
    printer's provider exactly as the resolver does (a non-string or missing `provider` is the
    default provider), return `array( 'kind' => '', 'content_type' => '' )` when there is no
    adapter, otherwise the adapter's `format( $printer, $template )`. Keep the resolver's
    one-line reason on the normalisation ("Printer rows saved before the provider field existed
    have none; they must behave as the default provider").
  - `public static function printer_content_type( array $printer ): string` — the printer-only
    answer, `application/octet-stream` without an adapter. Move the resolver's docblock about why
    this exists and why PrintNode reports its PDF default here onto this method, trimmed to the
    facts (two callers → now one; the `pn_kind` condition that keeps a raw job away from it).
  - The two `resolve()` callers call `Provider::format( $printer, $template )`; the reprint path's
    `content_type_for_printer()` call becomes `Provider::printer_content_type( $printer )`. Drop
    the `use` import and the `$resolver` local. Comments at both call sites that say "the
    resolver owns both halves" should now name `Provider::format()`.
  - The ten resolver cases move, one to one, into `tests/includes/Services/Provider_Test.php`
    (the registry's existing test), calling the two statics. Keep every assertion; only the
    subject changes.

No new options, env vars, filters, constants or parameters on existing public methods. No
`error_log()`. Nothing under `includes/Templates/` changes.

## Behavioural changes

None intended. If porting a test forces one, stop and report it rather than weaken the test.

## Tests

Run nothing through local PHPUnit (the sandbox has no Docker and no wp-env). Paul's agent runs
`Provider_Test`, `Provider_Adapter_Test`, `Test_Receipt_Output_Adapters`, the print-jobs and
cloud-print-trigger suites and then the full suite on the wp-env runner after you finish. Test
conventions: Arrange / Act / Assert, `assertSame` over `assertEquals` for new lines (keep the
ported lines' assertions as they are unless phpcs objects), `( expected, actual )`.

## Budget and rules

- NET production change: about −110 lines (two files deleted, roughly 30 added to `Provider`
  and a few changed at the three call sites). Tests: about −100 net (two files deleted, their
  cases re-homed). Report the real numbers.
- WordPress coding standards: run the host linter on every PHP file you change —
  `/Users/kilbot/Projects/woocommerce-pos/vendor/bin/phpcs --standard=.phpcs.xml.dist <files>`
  (readable from the sandbox; the worktree has no vendor). Zero errors before you finish.
- After deleting the two classes, grep `includes/` and `tests/` for both class names and for
  `content_type_for_printer` and confirm nothing is left. Check `composer.json` / any classmap
  or PHPStan config for explicit file references.
- Git is READABLE from the sandbox; writes are not. Do not commit; Paul's agent commits with
  explicit paths (say in your report which files you deleted).
- Proceed; do not stop to ask. If a stated assumption is wrong, make the smallest reasonable
  choice, record it in your final report, and continue.

## Final report

List: files deleted, the production diff summary with net line counts per file, phpcs result
per file, anything you chose differently from this spec and why.
