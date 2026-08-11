=== ICC OpenID Client ===
Contributors: ivancarlosti
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

**Languages:**

* English (default)
* Portuguese (Brazil) — `pt_BR`
* Spanish (Mexico) — `es_MX`

Much of the documentation can be found on the **Settings > ICC OpenID Client** dashboard page.

Please submit issues to the Github repo: https://github.com/ivancarlosti/wordpressiccopenidclient

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to **Settings > ICC OpenID Client** and configure the plugin to meet your needs.

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

On the settings page (**Settings > ICC OpenID Client**) there is a checkbox for **Alternate Redirect URI**. When checked, the plugin will use the Redirect URI:

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

== Upgrade Notice ==

= 3.11.3 =

SECURITY UPDATE: 3.11.x branch - Fixes authentication vulnerabilities including JWT signature bypass and SSRF protection. Update immediately and configure JWKS endpoint in settings.

== Changelog ==

= 3.11.4 =

* Improvement: Enhanced Email Domain Restriction to support full email addresses in addition to domain names (e.g., `company.com specificuser@gmail.com`). Case-insensitive matching for both.

= 3.11.3 =

* Feature/improvement: Added configurable issuer setting for JWT validation.

= 3.11.2 =

* Improvement: Support identity providers that omit algorithm parameter in JWKS (Microsoft Entra ID).

= 3.11.1 =

* Fix bug created in 3.11.0 release when comparing issuer to derived expected value.

= 3.11.0 =

**SECURITY RELEASE**

* Security: Added JWT signature verification using JWKS to prevent token forgery
* Security: Enhanced token claim validation (exp, aud, iss, iat, nonce)
* Security: Replaced weak state generation with cryptographically secure random_bytes()
* Security: Fixed open redirect vulnerability in authentication flow
* Security: Restricted SSL verification bypass to local development environments only
* Security: Added nonce protection to debug mode to prevent information disclosure
* Security: Added SSRF protection by default through use of wp_safe_remote_* functions
* Feature: Added JWKS endpoint configuration setting
* Feature: Added OpenID Connect discovery document support
* Feature: Added customizable login button text setting
* Improvement: Migrated to Composer-managed dependencies
* Fix: Corrected issuer validation to properly extract base URL from endpoints
* Fix: Identity token timestamp tracking

= 3.10.4 =

* Fix issue with finding users on multisite after switch to user options in place of user meta.
* Improvement: Retry logins for some IDP errors to bypass issue with Safari ITP. Also improves display of error messages that come from the IDP.

= 3.10.3 =

* Fix issue with log corruption causing fatal error.
* Fix: Fallback to a POST request for userinfo when GET fails.
* Fix: Improves multisite compatibility by switching to *_user_options() functions.
* Fix: Fix for WordPress user session length being very short when refresh tokens are enabled.

= 3.10.2 =

* Fix: @socialmedialabs - Regression affecting SSO Auto Login with url handling improvement changes.

= 3.10.1 =

* Chore: @daggerhart - Readme updates and clarifications.
* Chore: @daggerhart - Release workflow updates.
* Improved error handling for malformed urls.
* Fix: @JUVOJustin - Change request for userinfo to GET.
* Feature: @JUVOJustin - New filter for settings values `icc-openid-client-settings`.
* Feature: @JUVOJustin - New filter for state values `icc-openid-client-new-state-value`.

= 3.10.0 =

* Chore: @timnolte - Dependency updates.
* Fix: @drzraf - Prevents running the auth url filter twice.
* Fix: @timnolte - Updates the log cleanup handling to properly retain the configured number of log entries.
* Fix: @timnolte - Updates the log display output to reflect the log retention policy.
* Chore: @timnolte - Adds Unit Testing & New Local Development Environment.
* Feature: @timnolte - Updates logging to allow for tracking processing time.
* Feature: @menno-ll - Adds a remember me feature via a new filter.
* Improvement: @menno-ll - Updates WP Cookie Expiration to Same as Session Length.

= 3.9.1 =

* Improvement: @timnolte - Refactors Composer setup and GitHub Actions.
* Improvement: @timnolte - Bumps WordPress tested version compatibility.

= 3.9.0 =

* Feature: @matchaxnb - Added support for additional configuration constants.
* Feature: @schanzen - Added support for agregated claims.
* Fix: @rkcreation - Fixed access token not updating user metadata after login.
* Fix: @danc1248 - Fixed user creation issue on Multisite Networks.
* Feature: @RobjS - Added plugin singleton to support for more developer customization.
* Feature: @jkouris - Added action hook to allow custom handling of session expiration.
* Fix: @tommcc - Fixed admin CSS loading only on the plugin settings screen.
* Feature: @rkcreation - Added method to refresh the user claim.
* Feature: @Glowsome - Added acr_values support & verification checks that it when defined in options is honored.
* Fix: @timnolte - Fixed regression which caused improper fallback on missing claims.
* Fix: @slykar - Fixed missing query string handling in redirect URL.
* Fix: @timnolte - Fixed issue with some user linking and user creation handling.
* Improvement: @timnolte - Fixed plugin settings typos and screen formatting.
* Security: @timnolte - Updated build tooling security vulnerabilities.
* Improvement: @timnolte - Changed build tooling scripts.

= 3.8.5 =

* Fix: @timnolte - Fixed missing URL request validation before use & ensure proper current page URL is setup for Redirect Back.
* Fix: @timnolte - Fixed Redirect URL Logic to Handle Sub-directory Installs.
* Fix: @timnolte - Fixed issue with redirecting user back when the icc_openid_client_auth_url shortcode is used.

= 3.8.4 =

* Fix: @timnolte - Fixed invalid State object access for redirection handling.
* Improvement: @timnolte - Fixed local wp-env Docker development environment.
* Improvement: @timnolte - Fixed Composer scripts for linting and static analysis.

= 3.8.3 =

* Fix: @timnolte - Fixed problems with proper redirect handling.
* Improvement: @timnolte - Changes redirect handling to use State instead of cookies.
* Improvement: @timnolte - Refactored additional code to meet coding standards.

= 3.8.2 =

* Fix: @timnolte - Fixed reported XSS vulnerability on WordPress login screen.

= 3.8.1 =

* Fix: @timnolte - Prevent SSO redirect on password protected posts.
* Fix: @timnolte - CI/CD build issues.
* Fix: @timnolte - Invalid redirect handling on logout for Auto Login setting.

= 3.8.0 =

* Feature: @timnolte - Ability to use 6 new constants for setting client configuration instead of storing in the DB.
* Improvement: @timnolte - Plugin development & contribution updates.
* Improvement: @timnolte - Refactored to meet WordPress coding standards.
* Improvement: @timnolte - Refactored to provide localization.

--------

[See the previous changelogs here](https://github.com/oidc-wp/openid-connect-generic/blob/main/CHANGELOG.md#changelog)
