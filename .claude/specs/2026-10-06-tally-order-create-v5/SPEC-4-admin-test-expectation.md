# Codex job spec — fix one admin render test expectation (kses'd price markup)

## Goal

`Test_Single_Order::test_render_pos_payments_lists_each_tender_for_pos_order` compares against raw
`wc_price( 20, ... )`, but `Single_Order::render_pos_payments()` deliberately echoes prices through
`wp_kses_post()` (as `includes/Gateways/Cash.php::calculate_change` does), which drops WooCommerce's `<bdi>`
wrapper and `translate="no"` attribute. Make the test expect the kses'd markup. Production code stays as is.

## Stakes

Test only; no runtime change.

## In scope (the only files you may edit)

- `tests/includes/Admin/Test_Single_Order.php`

## Out of scope (do not edit)

- `includes/Admin/Orders/Single_Order.php` and every other file

## Context

Observed failure (wp-env, WC 11.1.2): the output contains
`<span class="woocommerce-Price-amount amount"><span class="woocommerce-Price-currencySymbol">&#036;</span>20.00</span>`
while the test expects
`<span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol" translate="no">&#36;</span>20.00</bdi></span>`.

## Interfaces and constraints

In that one test, change the last assertion's expected value from
`wc_price( 20, array( 'currency' => $order->get_currency() ) )` to
`wp_kses_post( wc_price( 20, array( 'currency' => $order->get_currency() ) ) )`, and add a one-line comment above
it: the admin render escapes prices with wp_kses_post, as the Cash gateway's change note does. Nothing else.

## Do not

- Edit production code or any other test
- Weaken the assertion (keep `assertStringContainsString`, keep the 20.00 price)
- Commit or push

## Pre-authorised actions

- Edit the in-scope file; run the acceptance command

## Budget

- Max non-test lines changed: 0
- Max total lines changed: 6

If you are about to exceed either, STOP and report why instead of continuing.
The budget is a stop rule, not a target.
Run every acceptance command in the foreground and wait for its exit code.

## Acceptance criteria (runnable)

```sh
php -l tests/includes/Admin/Test_Single_Order.php
grep -c "wp_kses_post( wc_price( 20" tests/includes/Admin/Test_Single_Order.php   # prints 1
```

### Reviewer reruns (not for Codex)

```sh
vendor/bin/phpunit -c .phpunit.xml.dist tests/includes/Admin/Test_Single_Order.php
```

## Environment

- Network: no
- Writable outside the worktree: none

## Output expected

End with the report format from ~/.codex/AGENTS.md (STATUS / CHANGED /
ACCEPTANCE / NOT DONE / QUESTIONS / BEHAVIOUR CHANGES).
