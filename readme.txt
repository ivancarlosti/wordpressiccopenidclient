=== ICC Sign-In for OpenID Connect ===
Contributors: ivancarlos
Tags: security, login, openidconnect, sso, authentication
Requires at least: 5.0
Tested up to: 7.0
Stable tag: 3.11.3
Requires PHP: 8.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

A WordPress plugin that provides SSO (Single Sign-On) authentication against an OpenID Connect OAuth2 Identity Provider using Authorization Code Flow.

== Description ==

This plugin allows you to authenticate users against any OpenID Connect OAuth2 API with Authorization Code Flow. Once installed, it can be configured to automatically authenticate users (SSO), or provide a "Login with OpenID Connect" button on the WordPress login form.

After consent has been obtained from the Identity Provider, an existing user is automatically logged into WordPress, while new users can be automatically created in the WordPress database based on IDP claims.

**Features:**

* **Auto Login (SSO)** — Automatically redirect users to the Identity Provider for authentication without visiting the WordPress login page.
* **Login Button** — Add a customizable "Login with OpenID Connect" button to the WordPress login form for opt-in authentication.
* **JWT Signature Verification** — JWKS-based JWT validation to prevent token forgery and ensure token authenticity.
* **User Auto-Creation** — Automatically create WordPress users from IDP claims when they log in for the first time.
* **Link Existing Users** — Match IDP identities to existing WordPress accounts by email address.
* **Email Domain Restriction** — Restrict login to specific email domains (e.g., `company.com`) or full email addresses (e.g., `specificuser@gmail.com`). Leave empty to allow all.
* **Token Refresh** — Automatic access token refresh for Identity Providers that support refresh tokens.
* **End Session Support** — Redirect users to the IDP logout endpoint when they log out of WordPress.
* **Discovery Document Import** — Auto-populate endpoint settings from your IDP's `.well-known/openid-configuration` URL for quick setup.
* **Shortcodes** — Use `[icc_openid_client_login_button]` to display a login button anywhere, or `[icc_openid_client_auth_url]` to get the authentication URL.
* **Alternate Redirect URI** — Option to use a clean redirect URI without query strings (`/icc-openid-client-authorize`) for IDPs that don't support query parameters in redirect URIs.
* **Environment Constants** — All settings can be defined as PHP constants (`wp-config.php`) for added security and CI/CD deployment support.
* **Developer Hooks** — Extensive action and filter hooks for customizing authentication behavior, user creation, claims modification, and more.
* **Multisite Compatible** — Full support for WordPress Multisite networks.

**Supported Identity Providers:**

The plugin works with any OpenID Connect compliant Identity Provider, including:

* Auth0
* Keycloak
* Okta
* Azure AD / Microsoft Entra ID
* Google
* And any other OAuth2/OpenID Connect provider

**Security:**

* JWKS-based JWT signature verification to prevent token forgery
* Cryptographically secure state generation (`random_bytes`)
* SSRF protection via `wp_safe_remote_*` functions by default
* SSL verification bypass restricted to local development environments only
* Nonce-protected settings forms for CSRF protection
* Email domain restriction for access control
* Token claim validation (exp, aud, iss, iat, nonce)

Translations are managed through the WordPress.org translation platform. The plugin is fully internationalized and ready for translation into any language.

Much of the documentation can be found on the **Settings > OpenID Connect** dashboard page.

Please submit issues to the Github repo: https://github.com/ivancarlosti/wordpressiccopenidclient

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to **Settings > OpenID Connect** and configure the plugin to meet your needs.

**Quick Setup with Discovery Document:**

1. On the settings page, enter your IDP's discovery URL (e.g., `https://your-idp.com/.well-known/openid-configuration`).
2. Click **Load Configuration** to auto-populate the endpoint settings.
3. Fill in your **Client ID** and **Client Secret** from your IDP.
4. Click **Save Changes**.

== Frequently Asked Questions ==

= What is the client's Redirect URI? =

Most OAuth2 servers require whitelisting a set of redirect URIs for security purposes. The default Redirect URI provided by this client is:

`https://example.com/wp-admin/admin-ajax.php?action=icc-openid-client-authorize`

Replace `example.com` with your domain name and path to WordPress.

= Can I change the client's Redirect URI? =

Some OAuth2 servers do not allow for a client redirect URI to contain a query string. The default URI provided by this plugin leverages WordPress's `admin-ajax.php` endpoint as an easy way to provide a route that does not include HTML, but this will naturally involve a query string.

On the settings page (**Settings > OpenID Connect**) there is a checkbox for **Alternate Redirect URI**. When checked, the plugin will use the Redirect URI:

`https://example.com/icc-openid-client-authorize`

= What Identity Providers are supported? =

The plugin works with any OpenID Connect compliant Identity Provider. It has been tested with Auth0, Keycloak, Okta, Azure AD (Microsoft Entra ID), and Google. Use the Discovery Document Import feature for quick configuration with any of these providers.

= Can I use environment variables instead of saving settings in the database? =

Yes. All plugin settings can be defined as PHP constants in your `wp-config.php` file or anywhere before the plugin initializes. This is useful for security (keeping secrets out of the database) and for CI/CD deployments. See the plugin documentation for the full list of available constants.

= How does Email Domain Restriction work? =

You can enter one or more email domains or full email addresses (space-separated) in the **Email Domain Restriction** field on the settings page. Only users whose email address matches one of the specified entries will be allowed to log in. Entries can be domain names (e.g., `company.com` matches any user at that domain) or full email addresses (e.g., `specificuser@gmail.com` matches only that exact address). Leave the field empty to allow all.

Example: `company.com specificuser@gmail.com partner.org` — only users with emails ending in `@company.com` or `@partner.org`, or the exact email `specificuser@gmail.com`, can authenticate.

= Does the plugin support WordPress Multisite? =

Yes. The plugin is fully compatible with WordPress Multisite networks and uses `*_user_options()` functions for proper multisite support.

= Why does this plugin need to create or log in users? =

User authentication and creation are technically essential for this plugin to function as an OpenID Connect SSO provider. The plugin's core purpose is to delegate authentication to an external Identity Provider — this inherently requires creating user sessions (logging in) and, when configured, creating WordPress user accounts that correspond to identities verified by the IDP. Without these capabilities, SSO integration would not be possible.

This behavior is standard for all OpenID Connect plugins and is the same mechanism used by enterprise SSO solutions. The plugin only creates users or establishes sessions after the Identity Provider has successfully authenticated the user and the site administrator has explicitly configured it to do so.

= What security measures protect the user login/creation process? =

The plugin implements multiple layers of security:

* **JWT Signature Verification**: All ID tokens are cryptographically verified using JWKS (JSON Web Key Set) to prevent token forgery.
* **Cryptographically Secure State**: Anti-CSRF state values are generated using `random_bytes()` to prevent authorization code interception attacks.
* **SSRF Protection**: All outbound requests use `wp_safe_remote_*()` functions by default, preventing requests to internal/private network endpoints.
* **Standard WordPress User Functions**: The plugin uses `wp_create_user()`, `wp_update_user()`, and `wp_signon()` — WordPress core functions that trigger all standard hooks used by security plugins for rate limiting, brute force protection, and audit logging.
* **Email Domain Restriction**: Administrators can restrict which email domains or specific addresses are allowed to authenticate, preventing unauthorized access.
* **Nonce-Protected Settings**: All admin forms are protected against CSRF attacks.
* **Token Claim Validation**: The plugin validates exp, aud, iss, iat, and nonce claims on every token to prevent replay and spoofing attacks.

**About OpenID Connect:**

OpenID Connect (OIDC) is an open authentication protocol standardized by the OpenID Foundation. It extends the OAuth 2.0 authorization framework to provide identity verification and single sign-on capabilities. This plugin implements the OIDC Authorization Code Flow as defined in the OpenID Connect Core 1.0 specification, enabling WordPress sites to delegate authentication to a trusted Identity Provider (IDP) rather than managing user credentials directly.

The OpenID Foundation is a non-profit international standardization organization that develops and maintains the OpenID Connect protocol and related specifications. This plugin is an independent implementation and is not affiliated with, endorsed by, or sponsored by the OpenID Foundation.

== Changelog ==

= 4.0.4 =
* Security: Upgraded the firebase/php-jwt dependency to v7.1.0.
* Security: Sanitized URL-based settings (endpoints and issuer) with esc_url_raw().

