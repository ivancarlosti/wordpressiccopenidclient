# ICC Sign-In for OpenID Connect

A WordPress plugin that provides SSO (Single Sign-On) authentication against an OpenID Connect OAuth2 Identity Provider using Authorization Code Flow.

## About OpenID Connect

OpenID Connect (OIDC) is an open authentication protocol standardized by the **OpenID Foundation**. It extends the OAuth 2.0 authorization framework to provide identity verification and single sign-on capabilities. This plugin implements the OIDC Authorization Code Flow as defined in the [OpenID Connect Core 1.0 specification](https://openid.net/specs/openid-connect-core-1_0.html), enabling WordPress sites to delegate authentication to a trusted Identity Provider (IDP) rather than managing user credentials directly.

The OpenID Foundation is a non-profit international standardization organization that develops and maintains the OpenID Connect protocol and related specifications. **This plugin is an independent implementation and is not affiliated with, endorsed by, or sponsored by the OpenID Foundation.**

<!-- buttons -->
[![Stars](https://img.shields.io/github/stars/ivancarlosti/wordpressiccopenidclient?label=⭐%20Stars&color=gold&style=flat)](https://github.com/ivancarlosti/wordpressiccopenidclient/stargazers)
[![Watchers](https://img.shields.io/github/watchers/ivancarlosti/wordpressiccopenidclient?label=Watchers&style=flat&color=red)](https://github.com/sponsors/ivancarlosti)
[![Forks](https://img.shields.io/github/forks/ivancarlosti/wordpressiccopenidclient?label=Forks&style=flat&color=ff69b4)](https://github.com/sponsors/ivancarlosti)
[![Downloads](https://img.shields.io/github/downloads/ivancarlosti/wordpressiccopenidclient/total?label=Downloads&color=success)](https://github.com/ivancarlosti/wordpressiccopenidclient/releases)
[![GitHub commit activity](https://img.shields.io/github/commit-activity/m/ivancarlosti/wordpressiccopenidclient?label=Activity)](https://github.com/ivancarlosti/wordpressiccopenidclient/pulse)
[![GitHub Issues](https://img.shields.io/github/issues/ivancarlosti/wordpressiccopenidclient?label=Issues&color=orange)](https://github.com/ivancarlosti/wordpressiccopenidclient/issues)  
[![License](https://img.shields.io/github/license/ivancarlosti/wordpressiccopenidclient?label=License)](LICENSE)
[![GitHub last commit](https://img.shields.io/github/last-commit/ivancarlosti/wordpressiccopenidclient?label=Last%20Commit)](https://github.com/ivancarlosti/wordpressiccopenidclient/commits)
[![Security](https://img.shields.io/badge/Security-View%20Here-purple)](https://github.com/ivancarlosti/wordpressiccopenidclient/security)
[![Code of Conduct](https://img.shields.io/badge/Code%20of%20Conduct-2.1-4baaaa)](https://github.com/ivancarlosti/wordpressiccopenidclient?tab=coc-ov-file)
<!-- endbuttons -->

## Features

- **Auto Login (SSO)** — Automatically redirect users to the Identity Provider for authentication
- **Login Button** — Add a "Login with OpenID Connect" button to the WordPress login form
- **JWT Signature Verification** — JWKS-based JWT validation to prevent token forgery
- **User Auto-Creation** — Automatically create WordPress users from IDP claims
- **Link Existing Users** — Link existing WordPress accounts to IDP identities
- **Email Domain Restriction** 🔒 — Restrict login to specific email domains (e.g., `company.com`) or full email addresses (e.g., `specificuser@gmail.com`). Leave empty to allow all
- **Token Refresh** — Automatic access token refresh for supported IDPs
- **End Session Support** — Redirect to IDP logout endpoint on WordPress logout
- **Discovery Document Import** — Auto-populate settings from `.well-known/openid-configuration`
- **Shortcodes** — `[icc_openid_client_login_button]` and `[icc_openid_client_auth_url]`

## Requirements

- WordPress 5.0+
- PHP 8.1+
- An OpenID Connect Identity Provider (Keycloak, Auth0, Okta, Azure AD, Google, etc.)

## Installation

1. Download the plugin or clone this repository into `/wp-content/plugins/`
2. Activate the plugin through the WordPress admin panel
3. Go to **Settings > OpenID Connect** to configure

## Quick Setup

Use the **Discovery Document Import** feature on the settings page:

1. Enter your IDP's discovery URL (e.g., `https://your-idp.com/.well-known/openid-configuration`)
2. Click **Load Configuration**
3. Fill in your **Client ID** and **Client Secret**
4. Click **Save Changes**

Supported IDPs:
- Auth0: `https://{tenant}.{region}.auth0.com/.well-known/openid-configuration`
- Keycloak: `https://{domain}/realms/{realm}/.well-known/openid-configuration`
- Okta: `https://{domain}/.well-known/openid-configuration`
- Azure AD: `https://login.microsoftonline.com/{tenant}/v2.0/.well-known/openid-configuration`
- Google: `https://accounts.google.com/.well-known/openid-configuration`

## Configuration Reference

### OAuth Client Settings

| Setting | Description |
|---|---|
| **Client ID** | The ID your client is recognized as by the Identity Provider |
| **Client Secret** | The secret key the IDP expects from your client |
| **Scope** | Space-separated list of scopes (e.g., `openid profile email`) |
| **Login Endpoint URL** | The authorization endpoint of your IDP |
| **Token Validation Endpoint URL** | The token endpoint of your IDP |
| **Userinfo Endpoint URL** | The user information endpoint |
| **End Session Endpoint URL** | The logout endpoint (optional) |
| **JWKS URI** | JWKS endpoint for JWT signature verification (**strongly recommended**) |
| **Issuer** | IDP issuer URL for JWT validation (auto-derived if not set) |

### User Settings

| Setting | Description |
|---|---|
| **Email Domain Restriction** 🔒 | Space-separated list of allowed email domains (e.g., `company.com`) or full email addresses (e.g., `specificuser@gmail.com`). Leave empty to allow all |
| **Link Existing Users** | Match IDP identities to existing WordPress accounts by email |
| **Create user if does not exist** | Auto-create new WordPress users on first login |
| **Redirect Back to Origin Page** | Return users to the page they were on before login |

### Environment Variables / Constants

All settings can be defined as PHP constants for added security and CI/CD support:

```php
define( 'OIDC_CLIENT_ID', 'your-client-id' );
define( 'OIDC_CLIENT_SECRET', 'your-client-secret' );
define( 'OIDC_ENDPOINT_LOGIN_URL', 'https://idp.example.com/auth' );
define( 'OIDC_ENDPOINT_TOKEN_URL', 'https://idp.example.com/token' );
define( 'OIDC_ENDPOINT_USERINFO_URL', 'https://idp.example.com/userinfo' );
define( 'OIDC_ENDPOINT_LOGOUT_URL', 'https://idp.example.com/logout' );
define( 'OIDC_ENDPOINT_JWKS_URL', 'https://idp.example.com/certs' );
define( 'OIDC_CLIENT_SCOPE', 'openid profile email' );
define( 'OIDC_LOGIN_TYPE', 'button' );
define( 'OIDC_EMAIL_DOMAIN_RESTRICTION', 'company.com specificuser@gmail.com partner.org' );
define( 'OIDC_CREATE_IF_DOES_NOT_EXIST', true );
define( 'OIDC_LINK_EXISTING_USERS', true );
define( 'OIDC_ENFORCE_PRIVACY', false );
define( 'OIDC_REDIRECT_ON_LOGOUT', true );
define( 'OIDC_REDIRECT_USER_BACK', false );
define( 'OIDC_ENABLE_LOGGING', false );
define( 'OIDC_LOG_LIMIT', 1000 );
```

## Redirect URI

The default redirect URI registered with your IDP should be:

```
https://your-site.com/wp-admin/admin-ajax.php?action=icc-openid-client-authorize
```

If your IDP doesn't support query strings in redirect URIs, enable **Alternate Redirect URI** in settings to use:

```
https://your-site.com/icc-openid-client-authorize
```

## Hooks & Filters

The plugin provides many hooks for customization. See the main plugin file for the complete list including:

- `icc-openid-client-user-login-test` — Control whether a user can log in based on their claim
- `icc-openid-client-user-creation-test` — Control whether a new user can be created
- `icc-openid-client-alter-user-claim` — Modify user claim data before user creation
- `icc-openid-client-alter-user-data` — Modify user data before insertion
- `icc-openid-client-login-button-text` — Customize the login button text
- `icc-openid-client-user-logged-in` — Action fired after successful login

## Security

- JWKS-based JWT signature verification to prevent token forgery
- Cryptographically secure state generation (`random_bytes`)
- SSRF protection via `wp_safe_remote_*` by default
- SSL verification bypass restricted to local development environments only
- Nonce-protected settings forms
- Email domain restriction for access control

## User Authentication & Creation

This plugin creates and authenticates WordPress users as a technical necessity of its core function — OpenID Connect Single Sign-On. User sessions are established only after successful authentication by the configured Identity Provider. User accounts are created only when explicitly enabled by the site administrator and only after the IDP has verified the user's identity.

**Security measures protecting user login/creation:**

| Measure | Description |
|---------|-------------|
| JWT Signature Verification | All ID tokens are cryptographically verified via JWKS to prevent forgery |
| Secure State Generation | Anti-CSRF state values use `random_bytes()` to prevent code interception |
| SSRF Protection | All outbound requests use `wp_safe_remote_*()` by default |
| Core WordPress Functions | Uses `wp_create_user()`, `wp_update_user()`, `wp_signon()` — triggering all standard security plugin hooks |
| Email Domain Restriction | Administrators can restrict which email domains/addresses are allowed |
| Token Claim Validation | Validates exp, aud, iss, iat, and nonce claims on every token |
| Nonce Protection | All admin forms are protected against CSRF |

## Credits

**ICC Sign-In for OpenID Connect** is maintained by [Ivan Carlos](https://github.com/ivancarlosti).

Based on [OpenID Connect Generic](https://github.com/oidc-wp/openid-connect-generic) by [daggerhart](https://github.com/daggerhart).

<!-- footer -->
---

## 🧑‍💻 Consulting and technical support
* For personal support and queries, please submit a new issue to have it addressed.
* For commercial related questions, please [**contact me**][ivancarlos] for consulting costs.

[cc]: https://docs.github.com/en/communities/setting-up-your-project-for-healthy-contributions/adding-a-code-of-conduct-to-your-project
[contributing]: https://docs.github.com/en/articles/setting-guidelines-for-repository-contributors
[security]: https://docs.github.com/en/code-security/getting-started/adding-a-security-policy-to-your-repository
[support]: https://docs.github.com/en/articles/adding-support-resources-to-your-project
[it]: https://docs.github.com/en/communities/using-templates-to-encourage-useful-issues-and-pull-requests/configuring-issue-templates-for-your-repository#configuring-the-template-chooser
[prt]: https://docs.github.com/en/communities/using-templates-to-encourage-useful-issues-and-pull-requests/creating-a-pull-request-template-for-your-repository
[funding]: https://docs.github.com/en/articles/displaying-a-sponsor-button-in-your-repository
[ivancarlos]: https://ivancarlos.me
