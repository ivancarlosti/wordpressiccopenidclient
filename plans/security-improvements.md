# Security & Best Practice Improvements Plan

## Overview

Six recommendations were provided by automated plugin analysis. Below is the analysis and implementation plan for each.

---

## ✅ Recommendation 1: Use wp_enqueue for inline script

**File:** [`includes/icc-openid-client-login-form.php`](includes/icc-openid-client-login-form.php:253)

**Issue:** The `remove_login_form()` method (line 253-263) outputs a raw `<script type="text/javascript">` tag to remove the login form from the DOM when auto-login mode is active.

**Fix:**
- Create a small external JS file (e.g., [`js/login-form.js`](js/login-form.js)) or use `wp_add_inline_script()`.
- Since this script only runs on `wp-login.php` via the `login_footer` hook, use the `login_enqueue_scripts` action.
- The script is very small (6 lines), so `wp_add_inline_script()` is the cleanest approach: enqueue a dummy handle and attach the inline script to it.

**Implementation:**
```php
// In register() or constructor:
add_action('login_enqueue_scripts', array($login_form, 'enqueue_login_scripts'));

// New method:
public function enqueue_login_scripts() {
    wp_register_script('icc-openid-client-login-form', false);
    wp_enqueue_script('icc-openid-client-login-form');
    wp_add_inline_script('icc-openid-client-login-form', '
        (function() {
            var loginForm = document.getElementById("user_login").form;
            var parent = loginForm.parentNode;
            parent.removeChild(loginForm);
        })();
    ');
}
```

---

## ⚠️ Recommendation 2: Nonces and User Permissions (Already Adequate)

**Analysis:** The main request-processing endpoints are:
- `wp_ajax_icc-openid-client-authorize` — OIDC callback from IDP; **cannot** carry WordPress nonces
- `wp_ajax_nopriv_icc-openid-client-authorize` — same, for non-logged-in users
- Discovery document import — already has proper `wp_nonce_field()` / `wp_verify_nonce()` verification at lines 836-848

**Verdict:** No action needed. OIDC Authorization Code Flow callbacks are inherently cross-origin redirects from the IDP and cannot carry WordPress nonces. The discovery form already implements proper nonce verification.

---

## 🔴 Recommendation 3: Proper sanitization of inputs

### 3a. Raw `$_GET` in `add_query_arg()` 

**File:** [`includes/icc-openid-client-client-wrapper.php`](includes/icc-openid-client-client-wrapper.php:182)

**Issue:** `$redirect_url = home_url( add_query_arg( $_GET, trailingslashit( $wp->request ) ) );` passes raw `$_GET` values. While `add_query_arg()` does URL-encode values, raw unsanitized input is still embedded into a redirect URL.

**Fix:** Sanitize each `$_GET` value before passing to `add_query_arg()`. Use `array_map()` with `sanitize_text_field()`:

```php
$safe_get = array_map('sanitize_text_field', $_GET);
$redirect_url = home_url( add_query_arg( $safe_get, trailingslashit( $wp->request ) ) );
```

### 3b. `$_GET['action']` comparison

**File:** [`includes/icc-openid-client-client-wrapper.php`](includes/icc-openid-client-client-wrapper.php:153)

**Issue:** `$_GET['action'] === 'logout'` comparison without sanitization. Low risk since it's just a string comparison, but best practice to sanitize.

**Fix:** Wrap in `sanitize_text_field(wp_unslash(...))` or use `sanitize_key()`.

### 3c. `$_REQUEST['redirect_to']` sanitization

**File:** [`includes/icc-openid-client-client-wrapper.php`](includes/icc-openid-client-client-wrapper.php:167)

**Issue:** `$redirect_url = esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) );` — `wp_unslash()` is called but no sanitization before `esc_url_raw()`.

**Fix:** Already has `esc_url_raw()` which is a sanitizing function, but `sanitize_text_field()` before it would be more explicit. This is actually already safe since `esc_url_raw()` sanitizes URLs. Low priority.

---

## 🔴 Recommendation 4: Out of Date Library (firebase/php-jwt)

**Current:** `^6.0` in composer.json, installed v6.11.1  
**Recommended:** v7.1.0

**Problem:** 
- v6.11.1 already requires PHP `^8.0` (the vendor composer.json says so)
- v7.x requires PHP `^8.1`
- Plugin header says `Requires PHP: 7.4` — this is already misleading since v6 requires PHP 8.0+

**✅ DECISION: Option A chosen** — Bump minimum PHP to `8.1`, upgrade to `firebase/php-jwt:^7.0`.

**Changes required:**
- [`composer.json`](composer.json): Change `"php": ">=7.4"` → `"php": ">=8.1"`, change `"firebase/php-jwt": "^6.0"` → `"firebase/php-jwt": "^7.0"`
- [`icc-openid-client.php`](icc-openid-client.php:24): Change `Requires PHP: 7.4` → `Requires PHP: 8.1`
- [`readme.txt`](readme.txt): Update PHP requirement if present
- [`includes/icc-openid-client-jwt-validator.php`](includes/icc-openid-client-jwt-validator.php): Update `JWT::urlsafeB64Decode()` usage if removed in v7, update `JWT::decode()` and `JWK::parseKeySet()` calls to match v7 signatures.

**v7 API changes to verify during implementation:**
- `JWT::urlsafeB64Decode()` — need to check if still available or if alternative is needed
- `JWT::decode()` — signature changed; v7 may separate key parsing from decoding
- `JWK::parseKeySet()` — may return different types in v7
- `Key` class — constructor may differ
- Run `composer update firebase/php-jwt` and resolve any API breaks

---

## 🔴 Recommendation 5: Type-specific sanitization for `register_setting()`

**File:** [`includes/icc-openid-client-settings-page.php`](includes/icc-openid-client-settings-page.php:475)

**Issue:** `sanitize_settings()` applies `sanitize_text_field()` to **all** fields uniformly, which is not type-appropriate:
- URL fields (`endpoint_login`, `endpoint_token`, etc.) — should use `esc_url_raw()`
- Number fields (`http_request_timeout`, `state_time_limit`, `jwks_cache_ttl`, `log_limit`) — should use `absint()` or `intval()`
- Checkbox fields (`enforce_privacy`, `no_sslverify`, etc.) — should cast to `0`/`1`
- Text fields — `sanitize_text_field()` is correct
- Scope field — may contain spaces and special chars like `openid profile email offline_access`

**Fix:** Refactor `sanitize_settings()` to apply type-appropriate sanitization based on the `type` defined in `get_settings_fields()`:

```php
public function sanitize_settings($input) {
    $options = array();
    foreach ($this->settings_fields as $key => $field) {
        if (isset($input[$key])) {
            $value = $input[$key];
            switch ($field['type']) {
                case 'checkbox':
                    $options[$key] = ($value == '1') ? 1 : 0;
                    break;
                case 'number':
                    $options[$key] = absint($value);
                    break;
                case 'select':
                    $options[$key] = sanitize_text_field($value);
                    break;
                case 'text':
                default:
                    $options[$key] = sanitize_text_field(trim($value));
                    break;
            }
        } else {
            $options[$key] = ($field['type'] === 'checkbox') ? 0 : '';
        }
    }
    return $options;
}
```

---

## 🔴 Recommendation 6: Escaping for shortcode callbacks

### 6a. Login button shortcode

**File:** [`includes/icc-openid-client-login-form.php`](includes/icc-openid-client-login-form.php:237)

**Issue:** `$href = esc_url_raw( $href );` at line 237 — `esc_url_raw()` is for database storage. In output context (HTML `href` attribute), `esc_url()` should be used.

**Fix:** Change `esc_url_raw()` to `esc_url()` on line 237.

### 6b. Auth URL shortcode

**File:** [`includes/icc-openid-client-client-wrapper.php`](includes/icc-openid-client-client-wrapper.php:248)

**Issue:** `$url = esc_url_raw( $url );` at line 248 — `esc_url_raw()` used in `get_authentication_url()` which returns a URL used both for display (shortcode) and for redirect (`wp_redirect()`). 

**Analysis:** `esc_url_raw()` is the safer choice here because the URL is also used in `wp_redirect()` calls (in `handle_redirect_login_type_auto()` line 92). If we change to `esc_url()`, the URL may have ampersands converted to `&#038;` which breaks redirects. 

**Fix:** Keep `esc_url_raw()` in `get_authentication_url()` for compatibility with both contexts, but in the shortcode callback context, apply `esc_url()` at the point of output. Or, separate the concerns: the method returns a raw URL, and escaping happens at use-site.

**Better approach:** Remove `esc_url_raw()` from `get_authentication_url()` return (line 248), and instead apply:
- `esc_url()` in the shortcode callback output (login-form.php make_login_button line 237)
- `esc_url_raw()` in the redirect usage (login-form.php handle_redirect_login_type_auto line 92)

However, since `get_authentication_url()` is also a public helper via [`functions.php`](includes/functions.php:19), changing its return value could affect external consumers. The safest fix is to add the correct escaping at each call site.

---

## Summary of Changes

| # | File | Line(s) | Change | Risk |
|---|------|---------|--------|------|
| 1 | `login-form.php` | 253-263 | Replace raw `<script>` with `wp_add_inline_script()` | Low |
| 2 | Already adequate — no changes | — | — | — |
| 3a | `client-wrapper.php` | 182 | Sanitize `$_GET` before `add_query_arg()` | Low |
| 3b | `client-wrapper.php` | 153 | Sanitize `$_GET['action']` comparison | Low |
| 5 | `settings-page.php` | 475-488 | Type-aware sanitization in `sanitize_settings()` | Medium |
| 6a | `login-form.php` | 237 | `esc_url_raw()` → `esc_url()` for href output | Low |
| 6b | `client-wrapper.php` | 248 | Evaluate double-escaping in auth URL context | Medium |
| 4 | `composer.json` | 14 | Upgrade firebase/php-jwt (needs decision) | High |
