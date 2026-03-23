---
name: wp-api
description: Use when working on WCPOS REST API endpoints, debugging auth issues, or understanding the plugin's API structure.
---

# WCPOS REST API Reference

## IMPORTANT: X-WCPOS Header Required

The WCPOS REST API routes (`/wcpos/v1/`) are **ONLY registered when the `X-WCPOS: 1` header is present**.

```bash
# WITHOUT header - wcpos/v1 NOT in namespaces
curl http://localhost:8888/wp-json/

# WITH header - wcpos/v1 IS in namespaces
curl -H "X-WCPOS: 1" http://localhost:8888/wp-json/
```

## Authentication

WCPOS uses JWT tokens, not WordPress cookies or application passwords.

### Token Types
1. **Access Token**: Short-lived (30 min), used for API requests
2. **Refresh Token**: Long-lived (30 days), used to get new access tokens

### Generating Tokens
```bash
npx wp-env run cli -- wp eval '
$auth = WCPOS\WooCommercePOS\Services\Auth::instance();
$user = get_user_by("ID", 1);
echo json_encode($auth->generate_token_pair($user));
'
```

### Sending Tokens

1. **Header** (preferred): `Authorization: Bearer <token>`
2. **Query param** (fallback): `?authorization=Bearer%20<token>`

### Apache Authorization Header Issue

Apache CGI/FastCGI strips the `Authorization` header by default. Add to `.htaccess`:
```apache
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
```

**IMPORTANT**: The `.htaccess` SetEnvIf rule sets `HTTP_AUTHORIZATION` to empty string `""` when no Authorization header is sent. The code must use `!empty()` instead of `isset()` to check for auth headers.

## URL Formats

```bash
# Pretty permalinks (needs .htaccess rewrite rules)
/wp-json/wcpos/v1/products

# Query string (always works)
/?rest_route=/wcpos/v1/products
```

## Testing API Endpoints

```bash
curl -s \
  -H "Authorization: Bearer $JWT_TOKEN" \
  -H "X-WCPOS: 1" \
  "http://localhost:8888/?rest_route=/wcpos/v1/products"
```

## Auth Matrix (2x2)

| URL Format | Header Auth | Param Auth |
|------------|-------------|------------|
| Query String (`?rest_route=`) | Yes | Yes |
| Pretty Permalink (`/wp-json/`) | Yes | Yes |

## Common Mistakes

1. Forgetting `X-WCPOS: 1` header -> 404 or empty namespace
2. Using `/wp-json/` without working Apache rewrite -> 404
3. Missing `.htaccess` SetEnvIf -> Authorization header stripped -> 401
4. Using `isset()` instead of `!empty()` for `$_SERVER['HTTP_AUTHORIZATION']` -> param auth fails

## Architecture: WordPress Hook Order

```
plugins_loaded -> init (determine_current_user) -> rest_api_init
```

1. **`Init.php`**: `determine_current_user` filter at priority 20 (handles JWT auth, runs during `init` BEFORE `rest_api_init`)
2. **`API.php`**: Routes registered on `rest_api_init` (user already authenticated by this point)
