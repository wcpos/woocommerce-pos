## 1. Claim check

**Evidence:** source and local Git history only; no tests, network, Docker, or `gh` used. Runtime conclusions below are **Inferred**, not reproduced. No files changed. This final response is the report for the invoking `-o` capture.

Paths are relative to Free `next` unless prefixed **Main**, **Pro**, **Client**, or **Wiki**, referring to the supplied trees.

### Issue claims

| Claim | Verdict | Evidence and trace |
|---|---|---|
| Preview-document slice landed; Template_Type has not | **HOLDS** | Git records `85de9160` and merge `45b61a7d` (#2198). `Receipt_Preview_Fixture_Loader::for_template()` owns sample selection at `includes/Services/Receipt_Preview_Fixture_Loader.php:86–98`; REST calls it at `includes/API/V1/Templates_Controller.php:1034–1038`, editor at `includes/Admin/Templates/Single_Template.php:579–583,607–613`. No `Template_Type` class or proposed store accessors found in `includes/`. |
| “32 PHP sites spell out the four-type set” | **WRONG as phrased** | There is one supported-type list, `includes/Templates.php:38`, already reused by validation. The historical count measured selected conditional sites, not 32 repeated lists. A comparable current inventory still totals 32, but its composition changed; see below. |
| Approximately 31 TS sites and seven Pro sites remain | **CHANGED** | Current gallery/editor source has **44 matching lines: 39 comparisons and five type unions**, excluding tests. Pro has **six relevant template-type literal lines** in the cited display implementation, not seven. These are not all duplicated policy. |
| Engine rules are written twice | **HOLDS** | Metabox choices: `includes/Admin/Templates/Single_Template.php:243–249`; first-save enforcement: `:402–410`. Preserve the existing-legacy-closure exception; these are not unconditional “report/closure cannot use PHP” rules. |
| Core default path remains type-specific | **HOLDS**, location moved | `includes/Templates.php:679` chooses filesystem suffix; `:701` selects gallery defaults for report/closure. The issue’s `:640–662` predates the reference-lookup extraction. |
| Live pin and order-previewability remain separate rules | **HOLDS** | Global display GET pins at `includes/API/V1/Templates_Controller.php:389–392`; closure/report ignore order selection at `:987`. |
| D1 marks first enabled active instead of global Live | **HOLDS — statically inferred** | Store request chooses `resolve_templates()[0]` at `includes/API/V1/Templates_Controller.php:380–383`; absent override returns global order at `includes/Templates.php:1031–1036`. Pro instead falls back to global Live at **Pro** `includes/Services/Customer_Display.php:275–292`. |
| No shipped caller reads this particular list | **HOLDS within supplied source trees** | No caller found combining `type=display` and `store_id` on the templates collection. Concrete caller evidence is in §2; deployment and third-party usage remain unverified. |
| D1 belongs to the Template_Type refactor | **WRONG as a dependency** | The mismatch concerns active-selection semantics, not engine/path/preview policy. It can be corrected independently in the collection controller, using a small store-ID accessor. |
| Free accessor must precede Pro adoption | **HOLDS** | Free decodes the storage at `includes/Templates.php:1031–1033`; Pro directly reads **and writes** it at **Pro** `includes/API/V1/Stores.php:427–446,554–575` and `includes/Services/Customer_Display.php:275`. |

**History since 2026-10-12:** relevant Free changes include reference/provenance extraction `571148c7`, retired-code cleanup `0cb0eed4`, preview ownership `85de9160`, and formatter extraction `4c94de28`. None changes D1’s selection branch. Blame attributes the deliberate GET pin to `f48c4845`, whose comment explicitly protects Live from reordering and Pro activation. Pro’s `a2d5bda` replaces gallery scanning with keyed lookup; the store-resolution rule remains.

**Released line:** Main has the generic first-enabled store branch at **Main** `includes/API/V1/Templates_Controller.php:345–348`, but its collection enum accepts only receipt/report (`:1277–1283`), matching **Main** `includes/Templates.php:37`. It does **not** expose this display defect. All proposed work belongs on **next**.

### PHP inventory and ranking

Counting convention: one conditional source line, not each literal occurrence; exclude comments, default arguments, catalogue rows and fiscal-document/XML checks.

| Priority | Current sites | Count | Meaning |
|---|---|---:|---|
| 1: rendering/selection policy | `Templates.php:567,679,701,705,713`; `Admin/Templates/Single_Template.php:243,246,402,408`; `API/V1/Templates_Controller.php:389,987,1118`; `Templates/Receipt.php:573` | **13** | Engine restrictions, path selection, receipt-only integrations/filter, Live pin, order/legacy preview policy. |
| 2: authoring, metadata and sample policy | `Templates.php:427,432,513,566,598,602`; `Single_Template.php:602,607,625,629`; `Services/Receipt_Data_Schema.php:1290,1293,1586,2033`; `Receipt_Preview_Fixture_Loader.php:88,91` | **16** | Titles/categories, starter content, formatting choice, field/schema dispatch and Preview Document dispatch. |
| Caller-specific type guard | `API/V1/Receipts_Controller.php:298` | **1** | Rejects a non-closure template for closure output; not a generic supported-types list. |
| Fixture-profile dispatch, not Template Type | `Services/Receipt_Preview_Fixture_Loader.php:34,39` | **2** | Existing profile loader branches; counting these as scattered Template Type ownership overstates the case. |

**Total: 29 substantive per-type policy sites, one caller guard, two profile branches; zero repeated plain validation lists within that 32-site inventory.** There are seven generic validation consumers of the existing constant: `Templates.php:675`, `Single_Template.php:105`, and controller `:96,140,232,302,1370`.

The count is not a complete literal census: taxonomy registration separately names the four types at `Templates.php:1440–1474`. Adding a type also requires that registration work.

TS’s 44-line inventory includes gallery routing, labels and controls—not 44 backend rules. Examples: `packages/template-gallery/src/router.tsx:18–24`, `layouts/gallery-layout.tsx:44–65`, `components/active-templates-table.tsx:79,200,281,380,393`; editor `packages/template-editor/src/app.tsx:236–479`, `types.ts:18`, `hooks/use-preview-data.ts:21`.

Pro’s six relevant lines are **Pro** `includes/Services/Customer_Display.php:286,292` and `includes/Templates/Display.php:65,81,94,95`: four calls identifying display templates, two type guards. Replacing these literals with a module would not itself remove duplicated policy.

### Minimal failing test — proposed, not run

Add to existing `tests/includes/API/Test_Templates_Controller.php`, class `Test_Templates_Controller`, using its `create_template()` fixture (`:126–142`) and authenticated REST harness:

```php
public function test_get_items_store_scoped_display_marks_global_live_active(): void {
	// Arrange.
	$a = $this->create_template( 'Display A', 'display' );
	$b = $this->create_template( 'Display B', 'display' );
	$store_id = $this->factory->post->create(
		array(
			'post_type'   => 'wcpos_store',
			'post_status' => 'publish',
		)
	);
	Templates::save_template_order( array( $a, $b ), 'display' );
	Templates::set_active_template_id( $b, 'display' );
	delete_post_meta( $store_id, '_wcpos_active_display_templates' );

	// Act.
	$request = $this->wp_rest_get_request( '/wcpos/v2/templates' );
	$request->set_query_params(
		array( 'type' => 'display', 'store_id' => $store_id )
	);
	$response = $this->server->dispatch( $request );

	// Assert.
	$this->assertSame( 200, $response->get_status() );
	$items = array_column( $response->get_data(), null, 'id' );
	$this->assertTrue( $items[ $b ]['is_active'] );
	$this->assertFalse( $items[ $a ]['is_active'] );
}
```

**Predicted failure:** B is false and A true. The same mismatch occurs when a nonempty override contains only unavailable templates: Free falls back to global order (`Templates.php:1054–1056`), while Pro falls back to Live.

Pro already specifies the intended runtime result in **Pro** `tests/includes/API/Test_Display_Controller.php:300–320`. Neither that test nor the proposed test was executed.

## 2. Impact

**Severity: Low.** A developer/integration consuming the scoped display list receives incorrect `is_active` metadata whenever global Live differs from first enabled and no usable override exists. The mismatch is deterministic for that state, but its real request frequency is unknown.

No demonstrated cashier interruption, wrong live display selection, money loss or attacker benefit follows from D1. The collection requires `access_woocommerce_pos` (`includes/API/V1/Templates_Controller.php:1566–1584`).

Observed callers:

- **Free admin gallery:** lists by type without store scope, `packages/template-gallery/src/hooks/use-templates.ts:10–18`. Its explicit Live action posts `{ type, active }` (`:137–146`).
- **Pro store editor:** also fetches the **global** list, **Pro** `packages/store-edit/src/hooks/use-global-templates.ts:5–11`. It combines global `is_active` with the store’s separate `active_display_template`, **Pro** `components/store-display-section.tsx:9–25`.
- **Till:** **Client** `packages/core/src/screens/main/receipt/hooks/use-templates-sync.ts:55–57` requests templates without `type=display`; Free defaults that request to receipt (`includes/API/V1/Templates_Controller.php:366`).
- **Customer display:** **Pro** `packages/display/src/signaling.ts:38–40` requests its dedicated paired-display template route, whose controller calls the correct resolver at **Pro** `includes/API/V1/Display_Controller.php:262–274`.
- **Template Studio:** the old review’s REST-list description is stale. **Client** `apps/template-studio/vite.config.ts:46–52` serves bundled templates locally.

Thus **no supplied first-party screen exercises D1**. That is a source-search conclusion, not proof about external integrations.

The product rule is already explicit: one display template; store override when enabled, otherwise global Live. **Wiki** `architecture/client/customer-display-templates.md:13–18` agrees with Pro’s implementation and existing test.

## 3. Cost and risk

Estimates are changed non-test lines, including ordinary comments/docblocks—not implementation commitments.

| Piece | Likely scope | Estimate | Tests / coupling / lane |
|---|---|---:|---|
| D1 alone | Free `Templates.php`: extract existing override read into getter; controller: choose valid override or global Live for display | **~50** | Existing controller suite: absent, valid, unavailable override; receipt behavior unchanged. **next**; no Pro edit required. |
| Complete store accessor pair and Pro adoption | Free `Templates.php`; Pro `Services/Customer_Display.php`, `API/V1/Stores.php` | **~80–110 combined**, overlapping D1’s getter | Preserve JSON representation, empty-list deletion, ID ordering and Pro cache invalidation. Free first, then vendor/adopt in Pro. Both **next**. |
| Narrow Template_Type extraction | New module plus `Templates.php`, `Single_Template.php`, controller | **~180–250** | Engine/path/order-preview matrix, including legacy closure behavior. Does not include wholesale TS/schema migration or pin relocation. **next**. |
| Remove GET pin | Controller plus relevant write/initialization paths | **Not yet bounded** | Must cover existing unpinned installations and changes in filesystem/Pro availability—not merely REST batch updates. Separate scope required. |

Existing useful tests: Free `tests/includes/Test_Templates.php`, `tests/includes/Admin/Templates/Test_Single_Template_Save.php`, `tests/includes/Services/Test_Receipt_Preview_Fixture_Loader.php`; Pro `tests/includes/API/Test_Store_Templates.php`, `tests/includes/Test_Templates_Resolution.php`, and the display-controller test above.

**Back-compat exposure:**
- Keep public `Templates` methods/constants and `resolve_templates()`’s ordered-list contract; do not turn it into a one-template display resolver.
- D1 changes only display-list `is_active`, not list membership/order or receipt selection.
- Accessors must preserve existing meta keys and JSON data; no migration is needed.
- Pro owns the runtime cache; preserve its invalidation at **Pro** `includes/API/V1/Stores.php:446`.
- Engine-policy extraction must preserve existing PHP closure support (`Single_Template.php:246`, controller `:1118–1131`).
- Do not broaden the receipt-only public filter at `includes/Templates/Receipt.php:573`.

### Behavior changes / regressions

**Observed:** none introduced; this was read-only research.

**Proposed D1 change:** scoped display responses identify actual store/global Live rather than first global enabled. Runtime display selection remains untouched.

**Potential regression from bundled pin relocation:** an unpinned display can change when ordering/availability changes. Existing GET-pinning tests explicitly require persistence (`tests/includes/API/Test_Templates_Controller.php:1034–1091`). Removing those assertions is a behavior change, not evidence of equivalence.

## 4. Options

### Ruling A: move the Live pin write out of admin GET?

1. **Keep it for now; fix D1 independently.**
   - **Pros:** preserves documented stability and existing tests; smallest scope.
   - **Cons:** GET still persists state; the pin’s ownership remains awkward.
2. **Move pin establishment to all relevant writes/initialization events, preserving current effective Live.**
   - **Pros:** cleaner read semantics and centralized lifecycle ownership.
   - **Cons:** pinning after a reorder can preserve the wrong template; Pro activation can change available virtual templates without a template write (`Templates.php:689–696`). Requires explicit coverage of those cases.
3. **Stop automatic pinning; persist only explicit “Set Live”.**
   - **Pros:** simplest pure-read model.
   - **Cons:** deliberately permits selection drift before explicit choice, contrary to the existing comment and tests.

**Evidence favours option 1 for this issue.** Pure GET semantics are an engineering preference, not sufficient reason to change merchant-visible selection. Option 2 need not be a product call **if equivalence is demonstrated**; option 3 genuinely requires approval to change established behavior. A blanket “yes, move it” is not a ready implementation ruling.

### Ruling B: Free store-ID accessors, then Pro adoption?

1. **Add a thin getter/setter pair retaining current storage.**
   - **Pros:** Free already owns resolution; removes duplicate decoding and Pro’s knowledge of private keys. No REST-shape change.
   - **Cons:** adds public methods and requires a coordinated Pro vendor/adoption change.
2. **Add only the getter with D1; defer setter until Pro adoption is scheduled.**
   - **Pros:** smallest useful extraction; no unused write method.
   - **Cons:** Pro storage writes remain direct temporarily.
3. **Leave storage duplicated.**
   - **Pros:** no work now; current storage works.
   - **Cons:** a future representation change still requires synchronized edits across both plugins.

**Evidence favours option 2 for a D1-only PR, then option 1 when Pro adopts it.** This is not a product/policy decision: preserve existing semantics and it is a conventional ownership change. Neither accessor requires a Template_Type module.

### Overall module choice

- **Small selection fix:** corrects the demonstrated mismatch without moving unrelated rules.
- **Targeted policy extraction when rules next change:** can remove the duplicated engine decisions without attempting to eliminate every literal.
- **Whole-system type registry now:** spans PHP, TS, schemas, fixtures and Pro; risks becoming another table while callers retain their own decisions.

**Evidence favours the first two, sequenced by actual need.** The scattered rules are real, but #2198 already removed the demonstrated preview-ownership defects. Literal count alone does not justify the remaining broad refactor.

## 5. Recommendation

**PURSUE LATER.** Trigger: a first-party store-scoped display-list consumer is scheduled, or a merchant/integration reports relying on its incorrect active flag.

D1 is a credible, small defect, but it currently affects no identified first-party caller. Keep it independently actionable; do not make its fix depend on a Template_Type module or GET-pin relocation.

**PR 1 on next: correct store-scoped display `is_active` using a thin store-template-ID getter; preserve ordered-list, receipt and GET-pin behavior (~50 non-test lines, tests: absent/valid/unavailable display overrides and unchanged receipt selection).**

Defer the broader module until a new template type or concrete engine/preview policy change requires touching the duplicated rules. At that point, extract the specific policy—not every occurrence of `receipt`, `display`, `report`, or `closure`.

## 6. Open questions

- **External consumption:** does an integration request `templates?type=display&store_id=N`? Best guess: uncommon, given first-party callers. An integration report or access-log evidence would raise D1’s priority.
- **Runtime repro:** will the proposed test fail exactly as traced? Best guess: yes. Running it through Docker/wp-env would settle this; no suite was run here.
- **Pin lifecycle:** if relocation is still desired, what should happen to an already-used but unpinned display when Pro/template availability changes? Existing intent favours retaining the previously effective template. A concrete initialization/write-path design and before/after tests are needed before approving that change.