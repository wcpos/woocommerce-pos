# Security Notes

## Open Dependency Advisories

### PKSA-y2cr-5h3j-g3ys: firebase/php-jwt

- Status: open until WCPOS can require PHP 8+ or a PHP 7.4-compatible php-jwt patch is available.
- Advisory: https://github.com/advisories/GHSA-2x45-7fc3-mxwq
- Last reviewed: 2026-05-07
- Affected dependency: `php-scoper/composer.json` currently requires `firebase/php-jwt` as `^6.10.0`.
- Upgrade blocker: `php-scoper/composer.json` pins Composer's platform PHP version to `7.4`; the patched `firebase/php-jwt` release line requires PHP 8+.
- Current parser paths:
  - `includes/Services/Auth.php::validate_token()` decodes access and refresh JWTs with local HS256 secrets and validates `iss`, token `type`, user ID, and revocation state.
  - `includes/API.php::authenticate()` accepts REST API bearer tokens and delegates validation to `Services\Auth::validate_token()`.
  - `includes/Init.php::determine_current_user_early()` accepts early REST bearer or authorization-parameter tokens and delegates validation to `Services\Auth::validate_token()`.
  - `includes/Form_Handler.php::pay_action()` accepts checkout cashier tokens from `wcpos_jwt` or legacy `token` query parameters and delegates validation to `Services\Auth::validate_token()`.
  - `includes/API/Auth.php::refresh_token()` and `includes/API/Auth.php::get_current_jti_from_request()` validate refresh and access tokens through `Services\Auth::validate_token()`.
- Required mitigations while pinned:
  - Continue using local HS256 secrets with explicit `Key` instances; do not use remote key sets or claim-provided key URLs.
  - Validate issuer claims against the current site URL before trusting a decoded token.
  - Validate token type, user ID, JTI, refresh JTI, and revocation state before authorizing requests.
  - Do not add audience, issuer, language-code, key ID, or URL-like JWT claims from user-controlled input without sanitizing and validating them before token generation or downstream use after decode.
  - Prefer `Authorization: Bearer <token>` headers. Query-parameter tokens are limited to controlled fallback paths and must be sanitized before validation.
