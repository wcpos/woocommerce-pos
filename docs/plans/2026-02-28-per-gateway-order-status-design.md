# Per-Gateway Order Status Setting

**Date:** 2026-02-28
**Context:** PR #544 (commit d2f008a) introduced a single global order status override for all POS gateways, including offline ones (BACS, Cheque, COD). This forces all gateways to use the same status (typically "completed"), but users expect BACS/Cheque to default to "on-hold" since payment hasn't been received. This design replaces the global setting with per-gateway configuration.

## Design

### Data Structure

Remove the global `order_status` from `checkout` settings. Add `order_status` to each gateway entry in `payment_gateways.gateways`:

```php
'payment_gateways' => array(
    'default_gateway' => 'pos_cash',
    'gateways' => array(
        'pos_cash' => array(
            'order'        => 0,
            'enabled'      => true,
            'order_status' => 'wc-completed',
        ),
        'bacs' => array(
            'order'        => 2,
            'enabled'      => true,
            'order_status' => 'wc-on-hold',
        ),
    ),
),
```

### Smart Defaults

- **BACS, Cheque** -> `wc-on-hold` (payment not yet received)
- **Everything else** (POS Cash, POS Card, COD, Stripe, Square, etc.) -> `wc-completed`

### UI Changes

- **Add** an "Order Status" column to the gateway table in checkout settings, with a select dropdown per row
- **Remove** the global order status select from above the gateway list
- Order statuses populated from `window.wcpos.settings.order_statuses` (same source as current global select)

### Backend Changes

**Settings.php:**
- Remove `order_status` from `$default_settings['checkout']`
- Add `order_status` to default gateway settings in `get_payment_gateways_settings()`
- Apply smart defaults: BACS/Cheque get `wc-on-hold`, others get `wc-completed`

**Orders.php:**
- `payment_complete_order_status()`: look up the order's payment method, fetch per-gateway `order_status` from payment_gateways settings, fall back to `wc-completed`
- `offline_process_payment_order_status()`: same lookup pattern — read from gateway-specific setting instead of global checkout setting
- Both methods share a common helper to resolve gateway order status

### Migration

On upgrade (or first settings load after update):
- If the old global `checkout.order_status` exists, copy its value to every gateway that doesn't already have a per-gateway `order_status`
- Remove `order_status` from checkout settings
- This preserves existing user configuration without behavior changes on upgrade

### Test Plan

**Unit tests for backend (PHPUnit):**
- Per-gateway status is applied correctly for POS orders using different gateways
- BACS order gets "on-hold" by default, COD/Cash get "completed"
- Custom per-gateway status (e.g., BACS set to "processing") is honored
- Non-POS orders are unaffected (gateway defaults preserved)
- Status normalization works (both `wc-completed` and `completed` formats accepted)
- Invalid status values fall back to gateway default
- Missing gateway config falls back to `wc-completed`
- Migration from global setting to per-gateway settings works correctly
- Filter hook registration covers all offline gateways

**Frontend (manual or integration):**
- Order status column visible in gateway table
- Each gateway shows its configured status
- Changing a gateway's status persists correctly via API
- Removing the global select doesn't break the checkout settings page
- Drag-and-drop reordering preserves order_status values
- Pro license gating still works correctly with the new column
