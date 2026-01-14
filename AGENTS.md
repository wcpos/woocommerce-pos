# WooCommerce POS Plugin Guidelines

This is the **free version** of the WooCommerce POS plugin, available on WordPress.org.

## Key Files

- `includes/Services/Auth.php` - JWT token handling, session management
- `includes/API/` - REST API controllers
- `includes/Admin/` - WordPress admin functionality
- `woocommerce-pos.php` - Main plugin file

## Before Making Changes

1. **Search for usages** before modifying any method
2. **Run tests**: `composer run test`
3. **Check code style**: `composer run phpcs`

## Don't

- Don't modify `vendor_prefixed/` - these are generated files
- Don't break backwards compatibility on public methods
- Don't use `error_log()` - use `Logger::log()` instead
- Don't commit without running tests

## Testing

```bash
composer run test                        # Run all tests
composer run test -- --filter=Test_Auth  # Run specific test
```

## Related

- `woocommerce-pos-pro` - Pro version (separate repo)
- `monorepo-v2` - The WCPOS app that connects to this plugin
