# Port php-jwt bump and `redirect_to` hardening from upstream

## Context

`ivancarlosti/wordpressiccopenidclient` is a derivative (copy, no shared git ancestry) of
`oidc-wp/openid-connect-generic`. Upstream shipped three changes in November 2026:

- `bf763c23ef` — `firebase/php-jwt` `^6.10` → `^7.0` (GHSA-2x45-7fc3-mxwq / CVE-2025-45769, `< 7.0.0`).
- `73aae4013f` — composer security group lockfile refresh for the same dependency.
- `394ba6d4ad` — `get_redirect_to()` must ignore a non-string `redirect_to` request value.

This fork had already moved to `firebase/php-jwt` `^7.0` / `v7.1.0` in commit `55b0001`, so the
security advisory no longer applied. The upstream fix for `redirect_to` did not apply verbatim
because this fork also wraps the value in `sanitize_text_field()`, which makes WordPress core
return `''` for arrays instead of throwing. The functional gap remained: a non-string
`redirect_to` replaced the admin/home fallback with an empty redirect.

## Goal

- Keep the JWT dependency on the latest stable release compatible with PHP >= 8.2 and refresh
  the lockfile/vendored copy.
- Make `get_redirect_to()` explicitly ignore non-string `redirect_to` values while preserving
  the fork's extra sanitization and all local customizations.
- Cover the scenario with a runnable test and document it.

## Steps

1. `composer update firebase/php-jwt --with-dependencies` (`v7.1.0` → `v7.2.1`; `composer.json`
   already requires `^7.0`, so only the lockfile and `vendor/` change).
2. Add `&& is_string( $_REQUEST['redirect_to'] )` to the `get_redirect_to()` guard in
   `includes/icc-gg-sign-in-openid-connect-client-wrapper.php`.
3. Add `tests/get-redirect-to.php` (standalone WP-stub harness) and document it in
   `docs/testing.md`.
4. Validate: `composer validate --strict`, lockfile `content-hash`, `php -l`,
   `sh tests/validate-plugin-layout.sh`, `php tests/get-redirect-to.php`, and an RS256
   encode/JWKS/decode round trip against the vendored library.

## Risks

- `vendor/` is tracked and ships inside the release ZIP; the 7.2.1 diff is reviewed and limited
  to library metadata plus the 7.1.1 JWT segment-limit hardening.
- php-jwt >= 7.0 enforces a 2048-bit minimum RSA key; identity providers with weaker keys fail
  closed (already true with 7.1.0).
- The regression test is a standalone harness: the repository has no PHPUnit/WordPress test
  suite, so it does not run inside a WordPress bootstrap.
- A non-string `redirect_to` now falls back to the dashboard/home URL instead of an empty string;
  this is the intended behavior and is asserted by the test.
