---
status: accepted — enforcement (the parity test suite) ships with free#1753
---

# The hook-parity contract

Every v2 operation fires the same WordPress/WooCommerce hooks as the equivalent `wc/v3`
operation, in the same order and context. Exceptions live in this ADR, each with its
reason; the contract is enforced by the parity test suite, not by prose — an operation
without a parity pin is an unreviewed operation (free#1738; the audit that preceded this
found zero direct parity tests, which is how contracts rot).

**How writes and reads achieve parity:** every write and list read dispatches through the
real `wc/v3` controller (`rest_do_request`), so insert/delete/save/status/stock/coupon
hooks and `woocommerce_rest_prepare_*` filters fire natively. Serializers call the real
controller's `prepare_object_for_response()`, so `register_rest_field()` runs on every lane.

**The gateway contract (three paths, all deliberate):**
1. *Hosted pay page* (`wcpos-checkout/order-pay/{id}`, linked from every order) — a real
   WooCommerce checkout: gateway `payment_fields()` render, scripts load, WooCommerce core
   calls `process_payment()`. **The parity guarantee: any standard `WC_Payment_Gateway`
   gets standard WooCommerce treatment.** Verified live (Stripe Terminal, real charge).
2. *Native fast checkout* — contract-gated: a standard gateway that also registers
   `wcpos_process_checkout_action_{id}` (the `Abstract_POS_Gateway` pattern) gets the
   in-app flow; an unregistered gateway is refused with a 400, order untouched. Fail loud,
   never fake a payment. This is the contract's one checkout exception.
3. *`set_paid` push* — WooCommerce's own write-only REST flag, the designed offline-sale
   mechanism, cashier-JWT gated with `_pos_user` attribution. Orders paid this way carry
   an explicit assertion marker distinguishing till-asserted payment from gateway-taken.

**Accepted parity notes (not defects):** some hooks fire 2–3× per request — WooCommerce
core's own checkout is equally noisy, and suppressing hooks core fires would be
anti-parity; the augmentation keys wcpos writes after `woocommerce_rest_prepare_*`
(`tax_ids`, `links`, item uuids, `_rxdb_*`) are reserved — augmentations deliberately ride
outside the filtered/hashed payload (see free#1744's fix class).

**Known temporary exceptions, dying with their code:** the `/orders/pull` and
`/resolve/barcode` selection queries bypass `woocommerce_rest_*_object_query`; interim seam
filters cover them until lane retirement at the protocol boundary (free#1748, free#1750).
Change detection cannot see filter-only output changes; `woocommerce_pos_invalidate` is the
documented relief valve and the formula-fingerprint extension (free#1742) is the cure.

Decided 2026-08-26 with Paul in free#1738, part of the v2 Sync Engine Trust Audit
(free#1731).
