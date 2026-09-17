# Unify v1 product/variation search-term parsing with the ratified literal splitter

Branch `codex/v1-search-literal-splitter`, cut from `origin/main` (bd708bf1). PR targets `main`.
This is a **semantics change on the frozen `wcpos/v1` read lanes**, shipped alone, as recorded
on the products rule row in `includes/Sync/Collection_Rules.php` ("unifying them is a product
decision"). The product decision is settled: Paul ratified the cashier search contract on
2026-09-15 (PR #1989) — AND across whitespace-split terms, OR across fields within a term,
substring per term, **every term counts including 1–2 character ones, punctuation inside a term
is literal, and `"` `,` `+` are NOT separators** (a European `0,4` is one term). The v2 lanes
already implement it through `Collection_Rules::search_terms()`. The v1 direct lanes still hand
the typed string to `WP_Query::parse_search()`, which:

- splits on tab, space, `"`, `,` and `+` (`0,4` → `0` AND `4`; `MY+code` → `MY` AND `code`);
- treats a `"…"` group as one phrase term;
- drops single A–Z letters and single dashes (`A B` → nothing left → whole string as one
  sentence term);
- drops English stopwords (`a an and are as at be by com for from how in is it of on or that
  the this to was what when where who will with www`) — `IT 5012` → `5012` alone;
- collapses to one sentence term when more than 9 terms remain (v2's cap is
  `Collection_Rules::SEARCH_TERM_CAP` = 10, applied by `Product_Search::posts_search`).

Every one of those is a divergence from the ratified rule, and the fleet that still reads
`wcpos/v1` gets a different fetch window from the one the current app gets on `wcpos/v2`.

## Goal

After this change the four direct lanes — `wcpos/v1/products`, `wcpos/v1/products/variations`
and `wcpos/v1/products/<id>/variations` — split search text with `Collection_Rules::search_terms()`
exactly like `wcpos/v2/products` and `wcpos/v2/variations` do, and the shared contract test
proves it on both lanes. The v1 **wire** stays frozen: no new 400s on v1. Over-cap term lists on
v1 collapse to the whole phrase (same policy `Product_Search::posts_search` already applies on
the v2 products path); the v2 flat variations route keeps rejecting with
`woocommerce_pos_variations_search_limit_exceeded` (that is validation in
`includes/API/V2/Variations_Controller.php`, not the rule, so it is untouched). A malformed-UTF-8
search on v1 yields no rows, as it does on v2.

## Failing tests first (the fixture-trap flip)

The house rule for a search-semantics change is: flip a named fixture trap before changing
code. `tests/includes/API/V2/Test_Product_Search_Contract.php` is the server twin of the
client catalogue (`packages/sync-core/src/searchFixtureCatalogue.ts` in wcpos/monorepo — it is
not in this checkout; the trap table in the PHP file is current). Today it dispatches only
`/wcpos/v2/products`. Change it so every case asserts **the v2 lane first, then the v1 lane**,
in the same test method (the lane-coverage CI gate — read `tests/lane-coverage/README.md` —
fails any NEW case that names `wcpos/v1` without also naming a current lane; one method that
dispatches both is the honest shape and passes the gate):

1. `read()` takes the route as a parameter (default `/wcpos/v2/products`). When dispatching
   `/wcpos/v1/products`, isolate v1's persistent hooks the way
   `tests/includes/Sync/Test_Collection_Rules_Search_Parity.php` does (snapshot
   `$GLOBALS['wp_filter']` with cloned `WP_Hook`s, restore in `finally`). Copy that snippet into
   a private helper in the contract test; do not make the parity test a base class.
2. `test_product_search_trap_returns_expected_ranked_names`: assert the expected names on v2,
   then on v1. The trap that flips v1 is `decimal-comma` (`0,4`): WP splits it into `0` AND `4`
   and every SKU containing both digits matches. Confirm which other traps fail on v1 before the
   production change and list them in your final report; do not weaken any expectation.
3. `test_product_search_preserves_literal_terms`: same, both lanes. Cases that flip on v1
   include `A B`, `MY+საბარგული`, `MY"code`. Add one new row for the stopword divergence:
   `array( 'IT 5012', array( '5012 only' ), array( '5012 IT' ) )` — with WP parsing, `IT` is
   a stopword and the decoy matches. Remember every fixture product created without an explicit
   `sku` gets `DUMMY SKU`; the literal-terms helper already passes `'sku' => ''` — keep that.
4. `test_product_search_collapses_an_over_long_term_list` and
   `test_product_search_rejects_malformed_utf8`: both lanes. Note WP collapses at 10+ terms and
   the shared cap collapses at 11+; after the change both lanes collapse at 11+. Use a phrase
   that distinguishes nothing at 10 terms or pick 11+ terms — do not encode the WP threshold.
5. Variations: in `tests/includes/API/V2/Test_Variations_Search.php` the
   `test_variation_phrase_matches_one_literal_carrier` data provider drives `/wcpos/v2/variations`.
   Extend that method to also dispatch the two direct routes (`/wcpos/v1/products/variations`
   and `/wcpos/v1/products/<parent>/variations`, hooks isolated as above) and assert the same
   ids. Cases with `,` `+` `"` or a single letter flip v1. If the provider has none, add
   `0,4` and `MY+code` rows.

Run nothing through local PHPUnit (the sandbox has no Docker and no wp-env). Paul's agent runs
the full suite on the wp-env runner after you finish; write the tests so they fail on the
current tree for the reasons above and pass after the production change.

## Production change (minimal — this is a semantics unification, not a refactor)

- `includes/Sync/Collection_Rules_Plan.php`, `apply_read_rule()`, the `HOOK_PREPARE_ARGS`
  branch: set `$value['wcpos_search_phrase'] = $this->search` on the direct products lane too
  (drop the `Pos_Visibility::PRODUCTS !== $this->visibility_type` guard and its comment). Check
  that `bindings()` already installs the `woocommerce_rest_query_vars` allow-list filter for
  the direct lane (it does today when `'products' === $this->collection && '' !== $this->search`);
  it must, or `wcpos_search_phrase` never reaches `WP_Query`.
- Direct variations lane (`'query' => 'wp_terms'` in the `lanes.direct` override): keep the
  `EXISTS` SQL shape and the collapse policy; change the term source.
  `Product_Search::variation_posts_search()` mirrors `posts_search()`: read
  `$q['wcpos_search_phrase'] ?? null`; when present, split it with
  `Collection_Rules::search_terms()`, return `' AND 1=0 '` on an empty split, collapse to the
  phrase above `$rule['term_cap']`; when absent, keep `(array) $q['search_terms']` so the
  deprecated `API\Product_Search` forwarders and Pro subclasses that call
  `wcpos_posts_search()` directly keep their behaviour. Get the phrase into the variations
  query vars: the v1 variations controller already routes its args through
  `$plan->filter( Collection_Rules_Plan::HOOK_PREPARE_ARGS, $args )`; in that branch for
  `'wp_terms'` set `$value['s'] = $this->search; $value['wcpos_search_phrase'] = $this->search;`
  and install the `woocommerce_rest_query_vars` allow-list filter for variations as well as
  products (the filter is global to WC's CRUD controllers, so one binding is enough — make the
  condition `'' !== $this->search` for both collections and keep it inside the existing
  visibility-type branch).
- Update the prose that recorded the divergence so it does not lie: the
  `Collection_Rules::search_terms()` docblock ("Direct product/variation lanes retain WP core
  parsing…"), the two rule-row comments (`// The cap binds the v2 phrase path only…` and
  `// v1 keeps WP-parsed terms/over-cap collapse…`), and the plan comment "The direct lane
  retains WP_Query's existing parsed-term contract." State the new fact in one line each: one
  splitter on every lane; v1 collapses over-cap where v2 flat variations reject.
- Do not touch `includes/API/V2/Variations_Controller.php`, the deprecated `API\Product_Search`
  forwarders, `Order_Search`, the client-date or visibility code, or any v1 wire shape.

## Budget and rules

- NET production change (additions minus deletions, non-test PHP): at most +30 lines. Tests:
  at most +180 lines net. Do not shrink completed work to satisfy a count; report the real
  numbers.
- No new options, env vars, constants, filters, or parameters on public methods. No type
  declarations the originals lack. No `error_log()`.
- WordPress coding standards: run the host linter on every PHP file you change —
  `/Users/kilbot/Projects/woocommerce-pos/vendor/bin/phpcs --standard=.phpcs.xml.dist <files>`
  (readable from the sandbox; the worktree itself has no vendor). Zero errors before you finish.
- Tests: Arrange / Act / Assert, `assertSame` over `assertEquals`, `( expected, actual )`
  argument order, `$this->wp_rest_get_request()` for requests, settings filters before
  `parent::setUp()`.
- Git is READABLE from the sandbox (`git diff`, `git show`, `git log -S`); writes are not.
  Do not commit; Paul's agent commits with explicit paths.
- Proceed; do not stop to ask. If a stated assumption is wrong, make the smallest reasonable
  choice, record it in your final report, and continue.

## Final report

List: every test case that fails on the current tree before the production change and why;
the production diff summary with net line counts; phpcs result per file; anything you chose
differently from this spec and why.
