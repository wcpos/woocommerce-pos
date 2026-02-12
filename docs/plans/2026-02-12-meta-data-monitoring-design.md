# Meta Data Monitoring & Performance Safety

**Date:** 2026-02-12
**Status:** Design approved (v2 — updated with pre-flight checks and retry loop fix)

## Problem

Users are hitting out-of-memory errors (`allocated 1279365120, tried to allocate 16777224 bytes`) during WC REST API calls. The crash happens in `abstract-wc-data.php:723` — WC core's `read_meta_data()`.

Our code calls `$object->get_meta_data()` on every product, order, variation, and customer in every API response — with no bounds, no limits, no monitoring. When third-party plugins dump hundreds or thousands of meta entries onto objects, we load it all into memory and serialize it for the response.

### The Retry Loop

Evidence from user logs shows a critical secondary issue:

```
2026-02-12T08:57:00+00:00 Info UUID already in use, return existing order. | Context: 11607
2026-02-12T08:57:20+00:00 Info UUID already in use, return existing order. | Context: 11607
2026-02-12T08:59:09+00:00 Info UUID already in use, return existing order. | Context: 11607
```

The POS app retries order creation every 30-60s because:
1. Order gets created in DB successfully
2. Response preparation calls `get_meta_data()` → OOM
3. Client never receives the response, retries with same UUID
4. Our `create_item()` catches the duplicate UUID, calls `get_item()` to return existing order
5. `get_item()` loads the order → `get_meta_data()` → OOM again
6. Infinite loop

This means monitoring alone is insufficient — we need a **pre-flight check** that prevents the OOM from happening in the first place.

## Approach

Two layers of defense:
1. **Pre-flight COUNT check** — before WC loads the full object, check meta count with a cheap SQL query. If over threshold, bypass WC's response pipeline and return a minimal response with only the meta keys POS needs.
2. **Response-level monitoring** — in our response filters, count meta entries and log warnings/errors for objects that are bloated but not OOM-level.

Plus:
3. Response size estimation (lightweight, no serialize)
4. PHPUnit performance tests

## Essential Meta Keys (POS Requirements)

These are the meta keys that must always be present in responses for POS to function. When the pre-flight check triggers, we query ONLY these keys.

### Order

| Meta Key | Purpose |
|----------|---------|
| `_woocommerce_pos_uuid` | Unique identifier for deduplication |
| `_pos_user` | Cashier who created the order |
| `_pos_store` | Store ID (pro) |
| `_pos_cash_amount_tendered` | Cash payment amount |
| `_pos_cash_change` | Cash change given |
| `_pos_card_cashback` | Card cashback amount |
| `_woocommerce_pos_tax_based_on` | Tax calculation mode |

### Order Line Item

| Meta Key | Purpose |
|----------|---------|
| `_woocommerce_pos_uuid` | Line item UUID |
| `_woocommerce_pos_data` | JSON: price, regular_price, tax_status |
| `_sku` | Product SKU |
| `pa_*` (wildcard) | Variation attribute meta |

### Product / Variation

| Meta Key | Purpose |
|----------|---------|
| `_woocommerce_pos_uuid` | Unique identifier |
| Barcode field (configurable) | Barcode scanning |
| `_woocommerce_pos_variable_prices` | Min/max price ranges for variable products |
| `_pos_price*` | Store-specific prices (pro, includes `_store_{id}` suffix variants) |
| `_pos_regular_price*` | Store-specific regular prices (pro) |
| `_pos_sale_price*` | Store-specific sale prices (pro) |
| `_pos_tax_status*` | Store-specific tax status (pro) |
| `_pos_tax_class*` | Store-specific tax class (pro) |
| `_pos_price_fields*` | Store-specific price field flags (pro) |
| `_pos_tax_fields*` | Store-specific tax field flags (pro) |

### Customer

| Meta Key | Purpose |
|----------|---------|
| `_woocommerce_pos_uuid` | Unique identifier |

## 1. Pre-Flight Meta Count Check (Early Hook)

### Location

Runs in `wcpos_dispatch_request()` and `create_item()` — before WC loads the full object and triggers `read_meta_data()`.

### How It Works

For single-item requests where we know the object ID:

1. Extract the ID from the request (or from UUID lookup in `create_item()`)
2. Run `SELECT COUNT(*) FROM {meta_table} WHERE {id_column} = %d` — single indexed query, near-zero cost
3. If count is **under** the error threshold → proceed normally, WC handles everything
4. If count is **over** the error threshold → bypass WC's response pipeline:
   a. Query only the essential meta keys: `SELECT meta_id, meta_key, meta_value FROM {meta_table} WHERE {id_column} = %d AND meta_key IN (...)`
   b. Build a minimal response manually with the essential data
   c. Log an **error** with object type, ID, count, and top meta keys
   d. Return the minimal response directly

### Where It Hooks

| Entry Point | When It Fires | Object ID Source |
|-------------|---------------|------------------|
| `create_item()` UUID retry | Before `$this->get_item()` at line 235 | From `get_order_ids_by_uuid()` |
| `wcpos_dispatch_request()` | Before calling `parent::wcpos_dispatch_request()` | From `$request->get_param('id')` for single-item routes |

### HPOS Compatibility

Must detect whether orders use CPT or HPOS storage:
- CPT: `SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d`
- HPOS: `SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders_meta WHERE order_id = %d`
- Products: always `{$wpdb->postmeta}` (WC doesn't have HPOS for products)
- Customers: `{$wpdb->usermeta}`

### Collection Requests

For collection requests (GET /products?per_page=10), we can't pre-check because the IDs come from WC's query. These requests fall through to the response-level monitoring in Section 2. If individual items in the collection trigger OOM during WC's `prepare_item_for_response()`, that's a WC-level issue we can't intercept.

## 2. Response-Level Monitoring (Post-Processing)

For objects that WC successfully loaded (meta count is elevated but not OOM-level), monitor and warn in our response filters.

### Location

`wcpos_parse_meta_data()` in `includes/API/Traits/WCPOS_REST_API.php` and the customer meta path in `includes/API/Customers_Controller.php`.

### Meta Count Thresholds

| Level   | Default | Filter                                         |
|---------|---------|-------------------------------------------------|
| Warning | 50      | `woocommerce_pos_meta_data_warning_threshold`    |
| Error   | 500     | `woocommerce_pos_meta_data_error_threshold`      |

### Behavior

1. Get the meta array from `$object->get_meta_data()` (already loaded at this point)
2. Count the entries
3. If count exceeds warning threshold, log a **warning** with object type, ID, and count
4. If count exceeds error threshold, log an **error** with object type, ID, and count
5. Include context: top 10 most frequent meta key names

### Response Size Estimation

Replace the commented-out `serialize()`-based `wcpos_log_large_rest_response()` with a lightweight estimator:

1. Count `meta_data` array length
2. Estimate content size: `strlen()` on description/short_description/content fields
3. Rough total: `(meta_count * 200) + content_length`

| Level   | Default | Filter                                          |
|---------|---------|--------------------------------------------------|
| Warning | 100KB   | `woocommerce_pos_response_size_warning_threshold` |
| Error   | 500KB   | `woocommerce_pos_response_size_error_threshold`   |

### Log Message Format

```
[warning] Product #123 has 87 meta_data entries (threshold: 50). This may indicate plugin meta bloat.
[error] Order #456 has 1247 meta_data entries (threshold: 500). This is likely causing performance issues.
[warning] Product #789 estimated response size 142KB exceeds 100KB threshold.
```

### Context Field

```
Top meta keys: _yoast_seo (12), _elementor_data (8), _wp_old_slug (7), ...
```

### Throttling

Static array tracks which object IDs have been logged in the current request. One log per object per request.

## 3. PHPUnit Performance Tests

### New Test Class

`tests/includes/API/Test_Meta_Data_Performance.php`

### Test Cases

1. **Product with 200 meta entries** — hit the product endpoint, assert 200 OK, measure memory delta stays under 10MB
2. **Order with 200 meta entries** — same for orders
3. **Customer with 200 meta entries** — same for customers
4. **Variable product with 50 variations, each with 50 meta** — stress test variation endpoint
5. **Batch of 10 products with 100 meta each** — simulates a sync page
6. **Pre-flight bypass test** — create an order with 600+ meta entries, assert the pre-flight check fires and returns a minimal response
7. **UUID retry with bloated order** — simulate the retry loop scenario, assert the order is returned successfully (doesn't OOM)

### Assertions

- Response returns 200 OK (doesn't crash)
- Peak memory delta stays under configurable cap
- Warning/error logs fire when thresholds are exceeded
- Pre-flight bypass returns all essential POS meta keys
- UUID retry path completes successfully for bloated orders

## 4. Logging UI Integration

### No Frontend Changes Needed

The existing React Logs component handles all levels. New monitoring logs flow naturally into the UI.

### What Users See

1. Navigate to POS Settings > Logs
2. See amber/red entries for objects with excessive meta
3. Expand an entry to see the "top meta keys" context identifying the offending plugin
4. Unread badge shows count of new warnings/errors

## Files to Modify

### Free Plugin (`woocommerce-pos`)

| File | Changes |
|------|---------|
| `includes/API/Traits/WCPOS_REST_API.php` | Add `wcpos_check_meta_count()` pre-flight method, add monitoring to `wcpos_parse_meta_data()`, replace `wcpos_log_large_rest_response()` with lightweight estimator |
| `includes/API/Products_Controller.php` | Add pre-flight check in dispatch, enable response size logging |
| `includes/API/Product_Variations_Controller.php` | Add pre-flight check, response size logging |
| `includes/API/Orders_Controller.php` | Add pre-flight check in `create_item()` UUID path and dispatch, enable response size logging |
| `includes/API/Customers_Controller.php` | Add pre-flight check, meta monitoring, response size logging |
| `tests/includes/API/Test_Meta_Data_Performance.php` | New test class |

### Pro Plugin (`woocommerce-pos-pro`)

No changes needed. Pro's `Orders_Controller` delegates to the free plugin's base methods where all monitoring lives.

## Non-Goals

- **Filtering/capping meta for normal responses** — only bypass when over the error threshold. Below that, return everything as-is.
- **Frontend threshold settings** — PHP filters are sufficient.
- **Intercepting collection requests** — pre-flight only works for single-item requests where we know the ID. Collection OOMs are a WC-level issue.
