# WCPOS REST API v2

This directory contains the controllers for the `wcpos/v2` sync REST surface.
The sync engine and shared domain services remain under `includes/Sync`.

## Protocol gate (2.0.0)

A POS-client request (one carrying the `X-WCPOS` / `wcpos=1` marker) under
`/wcpos/v2/` must carry a client protocol signal of 3 or newer
(`X-WCPOS-Protocol` header, or its `wcpos_protocol` query twin on hosts that
strip headers). Anything older is refused with HTTP 426 and the stable
`wcpos_update_required` envelope (`Services\Protocol_Gate`); the client keys on
the body `code`, never the status. Carve-outs are one rule in
`Route_Classifier::is_protocol_exempt()`: every `public` route (the pre-login
connect probes: echo, ping, site, the relay callback) and every `printer_token`
route (printer polls carry no client signal) is exempt by derivation; anything
else must be declared in the `protocol_exempt` group, where an entry covers
itself and every route below it — today `/status`, the `/auth/` family, and the
service surfaces the wp-admin settings screen drives with cookie auth and no client
protocol claim: `/settings/`, `/templates/`, `/extensions/`, `/logs/` and
`/print-jobs/` (their wire shapes did not change at the boundary; each controller
declares its own entry in `wcpos_route_classifications()`).
`server_protocol` is 3: the push lane carries no money from 2.0.0 (ADR 0040), so the
released 1.x app, which pays cash with `set_paid` on protocol 2, gets the update screen
(D1; the one enforced break of free#1750 applied to the major).

## Version-skew stance for the sync journal (decided 2026-08-22, free#1560)

**1.10.0 is a hard cutover for this protocol, not a compatibility window.**

The whole surface is new in 1.10.0: tag `v1.9.17` ships no `includes/API/V2`
and no `includes/Sync` at all. No released client has ever called
`/changes/sequence-log`, `/changes/tick` or `/orders/pull`, and no released
client holds a cursor, an epoch or a horizon for them. The wire contract was
therefore free to be designed once, and it was:

- Journal rows carry `deleted: 0|1`. There is **no legacy `type` verb** and the
  server does **not** dual-emit one. A router keyed on a verb string belongs to
  no shipped client.
- `epoch` is present on every envelope from the first release: `/orders/pull`
  exposes it at the top level, while `/changes/sequence-log` and `/changes/tick`
  place it under `checkpoint`. A client therefore either stores an epoch beside
  its cursor or has never synced.
- Order tombstones are pruned on the same 90-day retention as catalogue
  tombstones, and the lossy boundary is served as `horizon`. Any client reading
  the order lane MUST consume `horizon`; the 1.10.0 client does.

Accepted consequences, stated rather than left implicit:

- A pre-release install that carries a cursor written before the unification is
  not migrated. The client treats a non-zero cursor it holds no epoch for as
  unproven and rebaselines, which costs one re-seed, never silent row loss.
- Mixing a 1.10.0 client with a 1.9.x plugin is not a skew case for this
  protocol — the routes 404, exactly as they did before 1.10.0.

**This stance expires at 1.10.0's release.** From the first shipped client
onwards, any change to a journal row's shape, to the `head` / `horizon` /
`epoch` checkpoint fields, or to their meaning needs an explicit compatibility
plan in its PR: a dual-emit window, or a client-minimum gate. "No released
client speaks this yet" stops being true the day 1.10.0 ships.

## Bare variation records (2.0.0, free#1869)

Behind the protocol gate (free#1868), variations are bare wc/v3 records on the flat list,
write-ack and barcode lanes, with `_rxdb_revision`, `_rxdb_digest` (when indexed)
and UUID metadata. `/resolve/barcode` returns a bare product or variation as
`match`, not a wrapper. Variation revisions are schema-scoped content hashes,
computed before transport stamps; `woocommerce_pos_sync_variation_revision_fields`
allows site-local fields to participate in the same recipe on reads and CAS.

## One checkpoint shape, schema-scoped order revisions (2.0.0, free#1870)

Behind the protocol gate, `/orders/pull` speaks the `/changes/*` envelope
vocabulary: `complete` replaces the inverted `hasMore`, and `epoch`, `head` and
`horizon` move inside `checkpoint` beside the lane's cursor interior
(`updatedAtGmt`, `orderId`, `revision`, `sequence`). The top-level copies are
gone and `checkpoint` is present on every response, empty page included; the
lane itself keeps serving for the whole 1.11.x line. Order revisions are now
schema-scoped on the variation recipe: only WooCommerce's own order schema keys
are hashed, generated dates are out (`date_paid*` / `date_completed*` stay), and
`meta_data` hashes as an id-less set at every depth. Third-party
`register_rest_field` keys ride outside the hash by construction (free#1744);
`woocommerce_pos_sync_order_revision_fields` opts site-local fields back in.

## Unsupported collections on the `/changes/*` read lanes (2.0.0, free#1740)

`/changes/revision-hash` and `/changes/range-checksum` serve `products` (variations
folded in) and `tax_rates`; `/changes/sequence-log` serves `all`, `products` and
`tax_rates`. Any other `collection` value is refused with HTTP 400 and the code
`woocommerce_pos_sync_unsupported_collection` before any query runs — it used to
collapse silently to the products rows under the requested name. Only an absent
parameter takes the `products` default; an explicit empty value (`?collection=`) is
refused too. The `all` stream
labels its rows by collection (`variations`, `coupons`, `customers`, …, from
`Sync_Journal::catalogue_object_types()`); those labels are NOT narrowing values.
`/changes/tick` and `/changes/config-fingerprint` take no collection narrowing and
ignore the parameter, and `/digests` keeps its documented 200-with-`note` answer for
an unknown collection because the client's boot prime reads it.

## Order journal rows carry no revision value (2.0.0, free#1757)

An order row in the sync journal is a change pointer — `(object_type, object_id, deleted,
modified_gmt, sequence)` — and the order observers always write its `revision` column empty
(the generic `Sync_Journal::record()` still accepts a value, but no order writer passes one);
the served revision is computed at pull from the payload (ADR 0033). `/orders/pull` no longer has a stored-wins
branch, tombstone and other non-document checkpoints carry `revision: ""` (the literal
`'deleted'` marker is gone), and the schema-6 upgrade blanks the pre-#1746 `sha256:` hashes
and `'deleted'` markers older rows still held.

## Closing a sale: `POST /orders/{id}/close` (2.0.0, ADR 0040)

`close` is a money command beside the payments family (same `publish_shop_orders`
permission, same order lock, same `wcpos_payment_locked` / `woo_rxdb_sync_record_locked`
409s). It moves no money: it re-derives the order from its payment rows and, when the signed
balance (total − captured rows + succeeded refund allocations) covers the total, or the total
is zero, runs the paid transition, which writes the next entry of the order's completion history
(`_wcpos_completions`). It is how a reopened sale completes again; the push lane cannot close a
sale. Retained money above a reduced total still completes and reads as `order.refund_due`.

- Body: an optional `provenance` object (`register_id`, `session_id`, `sale_time`, `sale_tz`,
  `sale_counter`), validated like the till meta; an invalid field is dropped, never refused.
  The same object on `record`, `intent`, `capture` and `submit` names the register that
  completes the sale, if that command does.
- 200: `{ order: summary, completion: { n, id }, revision }`. A sale already paid replays its
  last completion and writes nothing but the command's entry.
- 409 `wcpos_payment_in_flight` (with `payment_id` or `attempt_id`): a row `pending` or
  `authorized`, a refund allocation `pending`, or a gateway attempt sent and unanswered.
- 409 `wcpos_balance_outstanding` (with `order`): uncovered. A zero-total open order with nothing in motion is
  completed through `payment_complete()` with no payment method, as WooCommerce completes a zero-total checkout.
- 409 `wcpos_invalid_transition`: the order is in no open status and has no standing completion (never completed, or its latest completion was reopened, e.g. by a cancel).

## Money command envelope: `command_id` and `revision` (2.0.0, ADR 0040 rules 5 and 6)

Every body on the payments family except `GET …/payments/{uuid}/status` (a poll, not a command)
carries the envelope:

| Field | On | Missing or invalid |
|---|---|---|
| `command_id` (uuid) | `intent`, `capture`, `void`, `refund`, `submit`, `cancel`, `close` | 400 `rest_invalid_param` |
| `revision` (`sha256:…`) | the seven above and `record` | 428 `wcpos_revision_required` |

`record` has no `command_id`: it keeps its row id as the command id and its whole body as the
fingerprint, and a refused record keeps its refusal as its stored outcome. `revision` is the order revision the
push lane compares (`baseRevision`, ADR 0033). Under the order lock the server first looks the
command up, order-wide (every row's `commands` map and the order's `_wcpos_commands`): the same id
with the same body (route, target and body, keys sorted, values as sent) replays the stored
outcome **whatever revision it carries**, since a retry carries the revision from before its own
effect; the same id with another body is 422 `wcpos_command_conflict` (`command_id`, `kind`, and
for `record` the stored `payment`). A row with no entry of its own (a gateway mint, a webview or
adopted tender) owns its id too: a `record` or any command reusing it is the same 422. Only a fresh command meets the revision: a stale one is
409 `wcpos_revision_stale` with `currentRevision`, `current` (the document) and `order`, and
nothing runs. A failed read of the order (403, 404) is answered as itself.

Every command answered 2xx keeps an entry, never evicted; a refusal keeps none, so its retry runs
again. Every 2xx answer, replay included, carries top-level `revision`: the order's revision after
the command, so the till can chain commands without a pull. Entries stay in storage and off the
wire (`payment.commands` is never answered). `record`'s mismatched replay is 422
`wcpos_command_conflict` (was 409 `wcpos_payment_conflict`); `wcpos_payment_conflict` remains the
state conflict (a `submit` of a cancelled or superseded attempt, a live payment on the order).

## Gateway attempts whose outcome is unknown (2.0.0, ADR 0040 rule 7)

A `submit` attempt is stored `sent` before the gateway runs. When the gateway's answer is lost (it threw after
dispatch, or the row's write failed), the attempt stays `sent` and the sale stays in motion until the resolver
learns its outcome from the provider: the sweep (every ten minutes, for an attempt older than five), a `submit`
naming the attempt, and `cancel` ask first. Money the provider took is recorded as the attempt's row (a replay
then answers `recorded` with it); a refused attempt is cancelled; when the provider cannot say, the order gets
one note for staff and the attempt stays in motion, never cancelled by the server.

- A new `submit` while such an attempt stands: 409 `wcpos_payment_in_flight` with `attempt_id`, nothing
  dispatched. An attempt awaiting the customer (an invoice sent) does not block "send again".

## Refund allocations: two saves around the provider (2.0.0, ADR 0040 rule 8)

`POST …/payments/{uuid}/refund` (`refund_id`, `amount`) writes the allocation `pending`, with the
command's entry, **before** the provider is asked, then the provider's answer after. A `pending`
allocation in a 200 answer means "sent, unconfirmed": the settle did not reach storage, or the
provider threw or answered indeterminately. It is kept, never deleted; the order carries a note
("confirm it with the provider before retrying"), the order summary shows it as `refund_pending`
(never in `balance`), a reopen or cancel is refused while it stands, and a retry under the same or
a new command id answers it without asking the provider again. A developer settles it with
`Ledger::settle_allocation()` once a human has confirmed it at the provider.

- 409 `woo_rxdb_sync_record_locked`: the reservation could not be stored; nothing was sent, retry.
- A definite provider refusal (502 `wcpos_provider_error`, 400 `wcpos_refund_not_allocatable`, …)
  keeps nothing, so the same command id runs again.
