# REST lane coverage

## What "ported" means

> A test counts as coverage for current behaviour **only if it exercises the lane the app
> actually calls.** Tests that dispatch to `wcpos/v1` are **legacy pins**: they document what
> the frozen namespace used to do. They do not count as coverage, and a green `wcpos/v1` test
> is not evidence that the behaviour it describes still works.

This is the whole point of the artifact in this directory. It exists because "is there an
equivalent test?" is the wrong question — it cannot see a lane switch. A test can be present,
green, and maintained while dispatching to a namespace the client abandoned.

Worked example, still in the tree at the time of writing:
`tests/includes/API/Test_Products_Controller.php` carries 57 cases that pass on every run and
dispatch to `/wcpos/v1/products`. The app reads products over `wcpos/v2`. Those behaviours
have no current-lane coverage; the suite reports otherwise. The sibling repo has a sharper
version of the same problem — `woocommerce-pos-pro`'s `Test_Products_Update.php` is the only
coverage per-store price writes have, and all of it is on v1.

## The lanes

The client (`wcpos/monorepo`, `next`) calls three namespaces:

| Lane | Status | Notes |
| --- | --- | --- |
| `wcpos/v2` | **current** | The POS lane. ~375 client references. |
| `wc/v3` | **current** | Called directly by the client, and proxied to by v2. ~109 references. |
| `wcpos/v1` | **frozen** | Legacy. Exactly one route is still live: `wcpos/v1/products/variations`, used by the sync census for its `X-WP-Total` head-count. |
| — | pure unit | Dispatches no REST route; classification is not applicable. |

`wcpos/v1/products/variations` is allowlisted in the scanner (`CURRENT_V1_ROUTES`). A test
that touches only that route is current-lane, not legacy. Everything else under `wcpos/v1` is
a legacy pin.

## Files here

| File | Written by | Purpose |
| --- | --- | --- |
| `inventory.json` | generator | Machine-readable classification. Diffed in CI. |
| `inventory.md` | generator | The human inventory: behaviour → file:case → lane → verdict, with the v1-only list first. |
| `annotations.json` | **humans** | Behaviour names and verdicts. The only file here you edit by hand. |

Regenerate after any change under `tests/`:

```bash
php scripts/lane-coverage.php --write
```

The scanner supports the repository's PHP range (PHP 7.4 and newer); CI runs it on PHP 8.1.
If `--check` produces an unexpected difference, verify the interpreter with `php --version`
before regenerating the inventory.

## How the classification works

Deliberately simple, because the failure mode being prevented is false confidence. The
scanner (`scripts/lane-coverage.php`) tokenises every file under `tests/` with PHP's own
`token_get_all()` and records, per test case, two kinds of lane signal:

1. **Route literals** — any string containing `wcpos/v1`, `wcpos/v2`, `wc/v3` or `wp/v2`.
2. **Controller references** — any `use` import or FQCN mentioning `\API\V1\` or `\API\V2\`.

Signal 2 matters more than it looks. A case can carry no route literal at all and still be
squarely on the legacy lane: every `Test_*_Controller::test_rest_base` case in this repo is
classified purely by its class's `use ...\API\V1\...` import, because what it asserts is the
**V1** controller's rest base. Route literals alone would have missed all of them.

A case's lanes are the union of the signals in its own body and those in class scope
(properties, constants, `setUp`, helpers, data providers), because a helper that dispatches a
route dispatches it on behalf of every test in the class.

A case is **v1-only** when it has a `v1` signal, has no `v2` or `wc3` signal, and is not
provably confined to the allowlisted live v1 route.

### Known limitations — read these before trusting a number

- **Class-scope attribution over-reports coverage.** If any helper in a class mentions
  `wcpos/v2`, every case in that class gains the `v2` signal. A class can therefore look
  covered when only one of its cases really is. This is the one place the tool is optimistic,
  and it is the first thing to check when a class looks surprisingly clean.
- **Routes built at runtime are invisible.** A route assembled from variables is not a
  literal. Such a case is treated as legacy rather than current — deliberately, since we
  cannot prove it is current.
- **Inheritance is not followed.** Signals in a parent test-case class are not inherited by
  subclasses.
- **`summary.by_lane` counts cases per lane and overlaps.** A case touching v1 and v2 is
  counted under both. Only `unit` is exclusive. The totals do not sum to `summary.cases`.

Every one of these was chosen to err towards reporting a case as legacy. A false positive
costs one row in the inventory. A false negative is the silent "already ported" claim that
produced this artifact in the first place.

## Verdicts

The lane is a mechanical fact. The verdict is human judgement, recorded in
`annotations.json`, and it **never** changes whether a case is v1-only — the two are kept
strictly apart in the generator so that no amount of relabelling can quiet the gate.

| Verdict | Meaning |
| --- | --- |
| `covered` | Current-lane coverage exists for this behaviour. |
| `gap` | v1-only, and the behaviour is known or strongly suspected to diverge on v2. Real risk. |
| `unverified` | v1-only, but the production path is plausibly shared (e.g. a WP hook that fires on both lanes). Probably fine; nothing proves it. |
| `legacy-pin` | v1-only by design. The v1 route is deliberately retained and frozen. |
| `unreviewed` | Nobody has judged this yet. The default. |

`unreviewed` is deliberately the default. It is honest about what has not been looked at,
which is what the previous audit's output could not express.

## The CI gate

`.github/workflows/lane-coverage.yml` runs two checks and one advisory.

1. **The inventory is not stale.** `--check` regenerates and compares byte-for-byte. Any
   change under `tests/` that shifts the classification must land with a regenerated
   inventory, so the checked-in artifact can never drift away from the code.
2. **The v1-only list must not grow.** `--compare` diffs the set of v1-only case keys
   against the PR's **merge-base**, not against a checked-in baseline file. A baseline file
   can be edited in the same PR that grows the list; git history cannot. New v1-only cases
   fail the build. Removing them — by porting the behaviour to `wcpos/v2` or by retiring the
   legacy test — is always allowed and is how the number goes down.
3. **Blind-test warnings are printed and never fail the build** (see below).

## Blind-test warnings

Two shapes of test are structurally incapable of failing, because they manufacture the
condition they then assert. Both were found by hand in the 2026-08-10 audit; the scanner now
flags them so they do not have to be rediscovered.

- **`self-installed-hook`** — the test calls a production hook-installing method itself
  (`wcpos_dispatch_request`, `register_routes`, `register_hooks`, `init_hooks`) and then
  asserts that hook's effect. The assertion passes whether or not the real dispatch path
  installs the hook. The reference case is in the sibling Pro repo:
  `Test_Coupons_Controller::test_coupon_patch_updates_date_modified_gmt` installs the V1
  coupon filter by hand via a `trigger_dispatch()` helper, so it proves only that the filter
  works *when installed* — never that the lane installs it.
- **`asserted-stubbed-response`** — the test queues a canned `WP_REST_Response` (or a
  `$GLOBALS[...response...]` fixture) and then asserts against the values it just supplied.
  `tests/includes/Sync/Test_Rest_Dispatch_Write_Contract.php` is the reference case: it sets
  `$GLOBALS['wcpos_sync_contract_responses']` and asserts route, CAS and amount against its
  own fixture. That is a legitimate way to test dispatch mechanics, but it is not evidence
  about what WooCommerce actually does with the write — which is exactly the confusion that
  let the coupon `date_modified_gmt` gap survive on both lanes.

These are **warnings, not failures**, and they always will be. Plenty of stubbing is
legitimate; the goal is that a reviewer can see it rather than having to infer it. Run them
locally with:

```bash
php scripts/lane-coverage.php --warnings
```

## Scope

This directory covers the **PHP suites in this plugin repo only**. The client-side test
suites live in `wcpos/monorepo` and are not classified here.
