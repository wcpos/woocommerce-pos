## 1. Claim check

**Evidence labels:** Observed = source/history inspected; Inferred = execution traced, not run; Unverified = requires runtime evidence. No tests, network calls, or file changes were made.

Paths below are relative to **Free next**, unless prefixed **Main**, **Pro**, **Client**, or **Wiki**, referring to the supplied trees. Observed tips: Free `92b5d970`, Main `9b9f7613`, Pro `82cb93e`, Client `c5def3c61`.

| Issue claim | Verdict | Evidence and trace |
|---|---|---|
| `record()` merges raw client input | **HOLDS** | `includes/Payments/Contract/Ledger.php:433–435,485–509`: validation precedes `array_merge($input, …)`, but `validate_input():1058–1074` validates only UUID, amount and currency; it does not return a sanitized payment. |
| `refunds` and `refunded_amount` are overwritten | **HOLDS** | `Ledger.php:500–503` also overwrites `seen_events` and `expires_at`. These cannot be injected through this record path. |
| `closure_id` is dropped | **HOLDS; CHANGED since the review** | `Ledger.php:434` removes it; the order-save keeper also removes client stamps and restores persisted stamps (`:232–328`). |
| `tip` survives | **HOLDS** | `Ledger.php:1583,1619` normalizes the supplied monetary value. Recording it does **not** create a matching fee; fee creation belongs to provider-result processing (`:788–829`). |
| `events` survives | **HOLDS, bounded but not authenticated** | `Ledger.php:1633` keeps array entries, capped at `EVENTS_MAX = 100` (`:36`). It does not validate event contents or apply Pro redaction. |
| `provider_refs` and `receipt` survive | **HOLDS** | `Ledger.php:1627–1628` requires arrays, but does not select their keys. `to_wire():1480–1486` only supplies empty-object encoding; it is not an allowlist. |
| `void_requested_at` survives | **HOLDS** | A parseable time survives `Ledger.php:1632`; downstream code treats it as evidence of an actual cancellation request. |
| All `*_at_gmt` stamps survive | **WRONG as a blanket claim** | Valid `created_at_gmt`, `captured_at_gmt`, and `authorized_at_gmt` survive (`Ledger.php:1600–1607,1621–1623`). `updated_at_gmt` is server-stamped (`:1634`); unchanged existing rows retain their stored timestamp (`:1338–1355`). |
| Arbitrary keys survive | **HOLDS—and at least one affects figures** | `Ledger.php:1610` preserves extras. An injected `method` overrides `method_id` in `Session_Figures::tender_key()` (`includes/Services/Session_Figures.php:187–190`), changing the reporting bucket for a non-cash leg. |
| A forged tip inflates receipt/closure totals | **WRONG for the inspected readers** | Receipts use order totals and ledger `amount`, not `row.tip` (`includes/Services/Receipt_Data_Builder.php:676–695,719–735`). Closure sums also use `amount` (`Session_Figures.php:214–219`). **However, tip affects replay**, below. |
| Released `main` has the same `record()` | **WRONG** | Main has no `includes/Payments/Contract/Ledger.php`. Its controller map has legacy checkout but no payment-ledger controller: **Main** `includes/API/Controller_Registry.php:115–117,141–149`; compare next `:150–151`. No main backport is indicated. |

**History since October 12 — Observed:** `c0f3fbb0` fixed unchanged-row timestamps; `7e84681b` centralized ledger writers; `b411bbba` centralized session figures; `aae0337d`, `190b8da3`, `f4ee6946`, and `67c00afe` added/hardened closure stamps; `ac5b075b` and `29ee6211` changed fiscal sale storage. Pro `35f422b` moved refund/event writes onto Ledger verbs. None removed the raw-input merge.

### Cashier-level entry point

`POST /wcpos/v2/orders/{order_id}/payments`, with `X-WCPOS: 1` and the cashier’s authenticated session/token:

```json
{
  "payment": {
    "id": "11111111-1111-4111-8111-111111111111",
    "method_id": "pos_cash",
    "amount": "92.95",
    "currency": "USD",
    "tip": "7.05",
    "events": [{"t":"2026-10-14T10:00:00Z","level":"info","message":"Forged approval"}],
    "provider_refs": {"transaction_id":"FORGED-REFERENCE"}
  }
}
```

The controller forwards the payment object directly (`includes/API/V2/Payments_Controller.php:90–106`). Permission requires `publish_shop_orders` (`:77–80`) plus the central POS-access gate (`includes/API.php:406–417`); cashier capabilities include order publication (`includes/Activator.php:585–600`). This is not an anonymous attack.

### Minimal failing tests to write first — Inferred, not executed

Extend existing `tests/includes/API/V2/Test_Payments_Controller.php`, using its `create_pos_order()`, `payment()`, and `record()` helpers (`:463–503`). Explicitly switch to a cashier fixture: the base class otherwise signs in an administrator (`tests/includes/API/WCPOS_REST_Unit_Test_Case.php:64–69`).

1. **`test_record_client_tip_does_not_break_identical_replay`**
   - Fixture: existing helper’s USD 92.95 open POS order; cashier user.
   - POST the body above twice with the same UUID.
   - Assert both responses are 200 and only one row exists.
   - **Predicted failure:** second response is `409 wcpos_payment_conflict`. `Ledger::replay()` subtracts the stored 7.05 tip from 92.95, then compares 85.90 with the unchanged request amount 92.95 (`Ledger.php:1098–1129`).
   - Receipt totals remain 92.95; this is a replay defect, not proof of inflated takings.

2. **`test_record_client_events_are_not_server_audit_events`**
   - Same fixture; omit `tip`, retain the forged event.
   - POST, then GET `/wcpos/v2/orders/{id}/payments/{uuid}/status`.
   - Assert returned `payment.events` is empty.
   - **Predicted failure:** the supplied event is returned through `payment_response()` unchanged in content (`Payments_Controller.php:182–195,299–304`).
   - This proves audit-row contamination, not insertion into WooCommerce’s log.

3. **`test_record_unknown_method_key_cannot_reclassify_card_figures`**
   - Same order; send `method_id: "pos_card"`, `amount: "92.95"`, and extra `method: "cash"`.
   - Reload the persisted row; assert `Session_Figures::tender_totals([$row])` reports card 92.9500 and cash 0.0000.
   - **Predicted failure:** it reports cash 92.9500 because the extra `method` wins.
   - This is the same summation used by session expectations and closure corrections (`Session_Figures.php:57–72,187–219`; `Closure_Store.php:230,496–499`). Existing ledger tests demonstrate manual `pos_card` recording (`tests/includes/Payments/Contract/Test_Ledger.php:1155–1173`).

**Receipt/fiscal observation:** the supplied `provider_refs.transaction_id` becomes the receipt payment’s transaction ID (`Receipt_Data_Builder.php:722–728`) and the order transaction ID (`Ledger.php:1436–1437`). Fiscal sale creation snapshots that receipt (`includes/Services/Fiscal_Record_Writers.php:100–129`). Whether accepting that reference is forbidden requires the ownership decision below—it is not automatically a defect for an already-taken payment.

## 2. Impact

**Severity: Medium overall; High-stakes payment area.** There is concrete reporting/audit contamination and a replay failure, but no demonstrated anonymous exploit, inflated total, or released-client incident.

- **Merchant:** a crafted non-cash payment can be classified as cash in drawer/closure figures. The amount is unchanged; the tender allocation is wrong.
- **Cashier:** a recorded client tip can make an identical retry conflict. No supplied shipped client was found sending it.
- **Authenticated attacker or faulty integration:** can submit invented row events and transaction references. Existing authorization already permits recording manual money; this does not establish a new ability to claim that cash was received.
- **Developer/support:** injected events bypass Pro’s event construction/redaction. Pro `includes/Payments/Server/Event_Log.php:24–26,39–61` redacts newly generated events; it does not cleanse previously supplied ones.
- **Frequency:** deterministic for the crafted inputs; occurrence in production is unverified.

**Shipped-client reach — Observed:** the supplied Client tree contains no matches for `_wcpos_payments`, `provider_refs`, or `void_requested_at` under `apps/` and `packages/`. Its checkout sends `gateway_id`, `action`, and empty `payment_data` to `orders/{id}/checkout`, not `/payments`: **Client** `packages/core/src/screens/main/pos/checkout/hooks/use-checkout-session.ts:221–259`. Thus this tree cannot establish which keys the **2.0** till sends.

No record-route caller was found in the supplied Free/Pro admin packages. The production Free call found is `Payments_Controller.php:106`. This is not a claim that arbitrary extensions cannot call the public method.

**Sweeper reach — Inferred and conditional:**
- Captured records are skipped; pending/authorized rows are considered (`includes/Payments/Contract/Payments_Sweeper.php:61–65`).
- Record permits `authorized` (`Ledger.php:469–471`).
- On a device authorization, forged `void_requested_at` can suppress the sweep’s capture branch (`Payments_Sweeper.php:130–141`) or a later device cancel (`Pro includes/Payments/Device/Device_Handler.php:173–175`).
- This needs a registered device adapter and a still-authorized status response. No hardware/provider reproduction was performed.
- The Pro refund sweep reads pending refund allocations, not `tip` or injected events as control inputs (**Pro** `includes/Payments/Server/Refund_Sweep.php:131–160`); record resets those allocations.

## 3. Cost and risk

**Smallest useful fix:** a record-specific top-level allowlist, retaining the documented payment facts but excluding server audit/control state and unknown keys.

- **Production file:** `includes/Payments/Contract/Ledger.php`, around `record():433–509`; approximately **30–45 changed non-test lines**.
- **Tests:** extend `Test_Payments_Controller.php` for event rejection and tender-bucket contamination; extend `tests/includes/Payments/Contract/Test_Ledger.php` for preserved offline metadata, references, receipt maps, replay and server-generated events. Cover CPT and HPOS.
- **Lane:** Free **next** only. Pro consumes the corrected Free bundle; no Pro production changes are required for this narrow boundary change.
- **Pro coupling:** inspected Device_Handler, Server_Handler, Event_Log, Refund_Sweep, and OrderPay/Order_Pay_Gateway. No Pro `Ledger::record()` caller was found. Pro nevertheless consumes persisted row fields; do not place the allowlist in `normalize_row()`, `save()`, or `to_wire()`, which also handle legitimate handler output.
- **Public exposure:** retain `record()` signature, existing hooks and response shape. Removing previously accepted arbitrary input is a behavioral restriction for integrations, even without changing a PHP signature.
- **Placement:** `validate_input()` takes its array by value and returns only amount/currency (`Ledger.php:1058–1074`). Merely filtering its local array would fix nothing. Filter at the record-row construction boundary, or explicitly return sanitized input; do not silently change the shared intent validator.

**Behavior changes / regressions**
- Recommended narrow change: client `events`, `void_requested_at`, and unknown top-level keys stop persisting.
- Preserve offline device times, transport, references and receipt data initially; blanket removal could discard actual payment evidence.
- The narrow change **does not fix tip replay** or establish that all provider references are independently verified.
- An allowlist in `record()` is **not proof that every raw order-meta write is protected**. The closure keeper examines typed ledger meta but chiefly protects closure stamps (`Ledger.php:232–328`).

No locking, migration, retry mechanism, or generalized validation subsystem is needed.

## 4. Options

### Ruling: allowlist or document a trusted till?

1. **Document the entire row as trusted.**
   - **Pro:** smallest change; preserves all existing payloads.
   - **Con:** contradicts handler-owned cancellation and server-truth event semantics; leaves concrete tender-bucket contamination and tip replay untouched.

2. **Record-specific allowlist; preserve documented client facts.**
   - **Pro:** blocks unknown-key effects and forged server audit/control fields without changing handler persistence.
   - **Con:** requires an explicit policy for mixed-ownership fields; does not independently authenticate manual payments or device references.

3. **Strictly reconstruct records from identity, money and method only.**
   - **Pro:** smallest trusted input surface.
   - **Con:** discards offline timestamps, transport and SDK evidence; conflicts with the documented offline payment contract.

**Evidence favours option 2.** Excluding arbitrary `method`, handler cancellation stamps and server events is an engineering correction, not a substantial product call. Offline field ownership remains a real contract question.

### Proposed field policy

**Observed shipped-client allowlist: none.** The supplied Client main uses the older checkout request, not ledger records. The following is a **proposed contract-preserving policy**, not an assertion about unseen 2.0 client code.

| Treatment | Fields | Evidence |
|---|---|---|
| Allow as record inputs | `id`, `method_id`, `amount`, `currency`, `status`, `tendered`, `register_id`, `session_id`, `store_id` | Existing validation/construction: `Ledger.php:435–507`; controller forwards store context at `Payments_Controller.php:102–105`. |
| Preserve documented device facts | `recorded_offline`, `transport`, `created_at_gmt`, `captured_at_gmt`, `authorized_at_gmt` | **Wiki** `architecture/client/payments-contract/ledger.md:26–27,41–44`; current normalization at `Ledger.php:1577–1578,1600–1626`. These timestamps are **not all server-only**. |
| Preserve pending the device-contract check | `provider_refs`, `receipt` | SDK capture accepts both: **Pro** `Device_Handler.php:112–144`; offline forwarding is described in **Wiki** `architecture/client/payments-contract/tender-flows.md:32`. |
| Preserve pending a separate tip decision | `tip` | **Wiki** `tender-flows.md:48–53` explicitly permits an at-till tip portion; unconditionally declaring it server-only would contradict that contract. |
| Server-derived; never trust supplied values | `source`, `order_id`, `provider`, `kind`, `capture_mode`, `cashier_id`, `change`, `failure_reason`, `refunds`, `refunded_amount`, `seen_events`, `expires_at`, `updated_at_gmt`, `closure_id` | Already overwritten, derived or removed by record/normalization/stamp protection. |
| Newly exclude | `events`, `void_requested_at`, all other top-level keys—including `method` | **Wiki** `ledger.md:47–48` assigns cancellation/events to handlers; `Session_Figures.php:187–190` demonstrates an unknown-key consequence. |

### Ruling: what to do with `tip` and provider evidence?

1. **Preserve them while fixing unequivocally server-owned fields.**
   - **Pro:** no undocumented removal of offline/payment evidence.
   - **Con:** tip replay remains defective; references remain client assertions on record.

2. **Remove them from record and require verified provider/fee paths.**
   - **Pro:** clearer authority boundary.
   - **Con:** may break offline forwarding and documented at-till tips; supplied client code cannot establish compatibility.

3. **Implement mode-specific validation now.**
   - **Pro:** could retain legitimate inputs while rejecting inconsistent ones.
   - **Con:** requires the missing 2.0 sender and a settled distinction between at-till tips already included in amount and on-reader tips added later.

**Evidence favours option 1 for the first PR, followed by option 3 when the actual 2.0 payloads are available.** Do not silently remove fields based on the older review’s blanket “server-only” premise.

The architecture verdict’s unrelated “Needs Paul” questions about paid-date suppression, refund verbs and legacy Pro APIs are not decisions required by this issue.

## 5. Recommendation

**PURSUE NOW, narrowed.** The unknown `method` key reaches financial tender figures, and handler-owned cancellation/events survive a cashier-controlled record request. That is sufficient evidence for a small allowlist; it is not merely hygiene.

Do **not** describe this as a released-line exploit, a forged-tip-total defect, or comprehensive ledger-write hardening. Keep the tip replay finding explicit and retain the issue’s remaining ownership work until the 2.0 sender is inspected.

**PR 1 on next: allowlist top-level record inputs, excluding client events, cancellation stamps and arbitrary keys while preserving documented offline/payment fields (~40 non-test lines, tests: cashier REST event rejection, forged-method tender figures, offline-field preservation, existing replay/provider-result coverage on CPT and HPOS).**

Run the proposed failing tests first through Docker/wp-env during implementation. None was run for this report.

## 6. Open questions

- **What does the actual 2.0 till send?** Best guess: substantially more than supplied Client main. A checkout of its ledger-writing implementation or captured fixtures would settle references, receipts, timestamps and tip ownership.
- **Are at-till tips implemented by an extension today?** Core manual defaults say `tips: none` (`includes/Payments/Contract/Manual_Handler.php:34`), while the wiki specifies `at_till`. The implementation and its fee/replay fixtures would settle how to fix tip replay without deleting supported behavior.
- **Does the conditional cancellation-stamp sweep failure occur with a real device adapter?** Best guess: yes when status remains authorized. A Pro adapter fixture returning an authorization, with capture/cancel call counters, would settle it without hardware.
- **Do other order-write lanes validate these fields equivalently?** Best guess: record-only filtering is insufficient to claim a universal trust boundary. A focused typed-meta write trace and test would settle that; it should not be hidden inside this small PR.