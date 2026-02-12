# Meta Data Monitoring & Performance Safety

**Date:** 2026-02-12
**Status:** Design approved

## Problem

Users are hitting out-of-memory errors (`allocated 1279365120, tried to allocate 16777224 bytes`) during WC REST API calls. The crash happens in `abstract-wc-data.php:723` — WC core's `get_meta_data()` / `read_meta_data()`.

Our code calls `$object->get_meta_data()` on every product, order, variation, and customer in every API response — with no bounds, no limits, no monitoring. When third-party plugins dump hundreds or thousands of meta entries onto objects, we load it all into memory and serialize it for the response.

We've already seen products with 1800+ meta entries (noted in code comments). The error trace shows ~1.2GB allocated before the final 16MB allocation fails.

## Approach

**Monitor and warn.** Don't change what meta we return (that risks breaking custom integrations). Instead, instrument the meta loading paths to detect and log when things get out of hand.

Three pieces:
1. Runtime meta count monitoring with warnings/errors
2. Response size estimation (replacing the commented-out `serialize()`-based approach)
3. PHPUnit performance tests to catch regressions in CI

## 1. Runtime Meta Data Monitoring

### Location

The main chokepoint is `wcpos_parse_meta_data()` in `includes/API/Traits/WCPOS_REST_API.php:60`. This handles products, orders, and variations. Customer meta is handled separately in `includes/API/Customers_Controller.php:280`.

### Thresholds

| Level   | Default | Filter                                         |
|---------|---------|-------------------------------------------------|
| Warning | 50      | `woocommerce_pos_meta_data_warning_threshold`    |
| Error   | 500     | `woocommerce_pos_meta_data_error_threshold`      |

### Behavior

In `wcpos_parse_meta_data()` (and the equivalent customer meta path):

1. Get the meta array from `$object->get_meta_data()`
2. Count the entries
3. If count exceeds warning threshold, log a **warning** with object type, ID, and count
4. If count exceeds error threshold, log an **error** with object type, ID, and count
5. Include context: top 10 most frequent meta key names to help identify the offending plugin

### Log Message Format

```
[warning] Product #123 has 87 meta_data entries (threshold: 50). This may indicate plugin meta bloat.
[error] Order #456 has 1247 meta_data entries (threshold: 500). This is likely causing performance issues.
```

### Context Field

```
Top meta keys: _yoast_seo (12), _elementor_data (8), _wp_old_slug (7), ...
```

### Throttling

Use a static array to track which object IDs have already been logged in the current request. Don't log the same object twice per request lifecycle.

## 2. Response Size Monitoring

### Problem with Current Code

`wcpos_log_large_rest_response()` exists but is commented out. It uses `serialize($response->data)` to measure size — which itself allocates as much memory as the response, doubling the problem.

### Replacement

Lightweight estimation instead of serialization:

1. Count `meta_data` array length (already have from Section 1)
2. Estimate content size: `strlen()` on description/short_description/content fields
3. Rough total: `(meta_count * 200) + content_length` — 200 bytes is a reasonable average for a meta entry's key + value + overhead

### Thresholds

| Level   | Default | Filter                                          |
|---------|---------|--------------------------------------------------|
| Warning | 100KB   | `woocommerce_pos_response_size_warning_threshold` |
| Error   | 500KB   | `woocommerce_pos_response_size_error_threshold`   |

### Where It Runs

In the existing response filters:
- `wcpos_product_response` (Products_Controller)
- `wcpos_order_response` (Orders_Controller)
- `wcpos_customer_response` (Customers_Controller)

Same throttling as meta count monitoring — once per object ID per request.

## 3. PHPUnit Performance Tests

### New Test Class

`tests/includes/API/Test_Meta_Data_Performance.php`

### Test Cases

1. **Product with 200 meta entries** — hit the product endpoint, assert 200 OK, measure memory delta stays under 10MB
2. **Order with 200 meta entries** — same for orders
3. **Customer with 200 meta entries** — same for customers
4. **Variable product with 50 variations, each with 50 meta** — stress test variation endpoint
5. **Batch of 10 products with 100 meta each** — simulates a sync page

### Assertions

- Response returns 200 OK (doesn't crash)
- Peak memory delta for the request stays under a configurable cap
- Warning/error logs are generated when thresholds are exceeded (verifies monitoring fires)

### Why 200, Not 1800

Tests need to run in CI without timing out. 200 entries per object is enough to verify the code path handles scale without making tests painfully slow.

## 4. Logging UI Integration

### No Frontend Changes Needed

The existing React Logs component handles all levels:
- **Red** for errors/critical/emergency/alert
- **Amber** for warnings
- **Blue** for info/notice
- **Gray** for debug

Expandable entries show context data. Unread count badges already track error + warning counts via `useUnreadLogCounts`.

### What Users See

1. Navigate to POS Settings > Logs
2. See amber/red entries for objects with excessive meta
3. Expand an entry to see the "top meta keys" context identifying the offending plugin
4. Unread badge in nav shows count of new warnings/errors since last viewed

## Files to Modify

### Free Plugin (`woocommerce-pos`)

| File | Changes |
|------|---------|
| `includes/API/Traits/WCPOS_REST_API.php` | Add meta count monitoring to `wcpos_parse_meta_data()`, replace `wcpos_log_large_rest_response()` with lightweight estimator |
| `includes/API/Products_Controller.php` | Uncomment/replace response size logging call |
| `includes/API/Product_Variations_Controller.php` | Add response size logging call |
| `includes/API/Orders_Controller.php` | Uncomment/replace response size logging call |
| `includes/API/Customers_Controller.php` | Add meta count monitoring to customer meta path, add response size logging |
| `tests/includes/API/Test_Meta_Data_Performance.php` | New test class |

### Pro Plugin (`woocommerce-pos-pro`)

No changes needed. Pro's `Orders_Controller` only adds a single `_pos_store` meta entry — monitoring is handled by the free plugin's base methods.

## Non-Goals

- **Filtering/capping meta** — too risky for custom integrations. Monitor first, consider filtering later based on real data.
- **Frontend threshold settings** — overkill. PHP filters are sufficient for power users.
- **Memory profiling in production** — `memory_get_usage()` calls in hot paths add overhead. Stick to counting entries and estimating sizes.
