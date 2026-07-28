# ICC OpenID Client

A WordPress plugin that provides SSO (Single Sign-On) authentication against an OpenID Connect OAuth2 Identity Provider using Authorization Code Flow.

## Features

- **Auto Login (SSO)** — Automatically redirect users to the Identity Provider for authentication
- **Login Button** — Add a "Login with OpenID Connect" button to the WordPress login form
- **JWT Signature Verification** — JWKS-based JWT validation to prevent token forgery
- **User Auto-Creation** — Automatically create WordPress users from IDP claims
- **Link Existing Users** — Link existing WordPress accounts to IDP identities
- **Email Domain Restriction** 🔒 — Restrict login to specific email domains (e.g., only `company.com`). Leave empty to allow all domains
- **Token Refresh** — Automatic access token refresh for supported IDPs
- **End Session Support** — Redirect to IDP logout endpoint on WordPress logout
- **Discovery Document Import** — Auto-populate settings from `.well-known/openid-configuration`
- **Shortcodes** — `[icc_openid_client_login_button]` and `[icc_openid_client_auth_url]`

## Requirements

- WordPress 5.0+
- PHP 7.4+
- An OpenID Connect Identity Provider (Keycloak, Auth0, Okta, Azure AD, Google, etc.)

## Installation

1. Download the plugin or clone this repository into `/wp-content/plugins/`
2. Activate the plugin through the WordPress admin panel
3. Go to **Settings > ICC OpenID Client** to configure

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
| **Email Domain Restriction** 🔒 | Space-separated list of allowed email domains (e.g., `company.com partner.org`). Leave empty to allow all domains |
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
define( 'OIDC_EMAIL_DOMAIN_RESTRICTION', 'company.com partner.org' );
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
https://your-site.com/wp-admin/admin-ajax.php?action=icc-openid-authorize
```

If your IDP doesn't support query strings in redirect URIs, enable **Alternate Redirect URI** in settings to use:

```
https://your-site.com/icc-openid-authorize
```

## Hooks & Filters

The plugin provides many hooks for customization. See the main plugin file for the complete list including:

- `icc-openid-client-user-login-test` — Control whether a user can log in based on their claim
- `icc-openid-client-user-creation-test` — Control whether a new user can be created
- `icc-openid-client-alter-user-claim` — Modify user claim data before user creation
- `icc-openid-client-alter-user-data` — Modify user data before insertion
- `icc-openid-client-login-button-text` — Customize the login button text
- `icc-openid-client-user-logged-in` — Action fired after successful login

## Languages

- 🇺🇸 English (default)
- 🇧🇷 Portuguese (Brazil) — `pt_BR`
- 🇲🇽 Spanish (Mexico) — `es_MX`

Translations are managed through `.po` files in the `languages/` directory.

## Security

- JWKS-based JWT signature verification to prevent token forgery
- Cryptographically secure state generation (`random_bytes`)
- SSRF protection via `wp_safe_remote_*` by default
- SSL verification bypass restricted to local development environments only
- Nonce-protected settings forms
- Email domain restriction for access control

## Credits

**ICC OpenID Client** is maintained by [Ivan Carlos](https://github.com/ivancarlosti).

Based on [OpenID Connect Generic](https://github.com/oidc-wp/openid-connect-generic) by [daggerhart](https://github.com/daggerhart).

### Original Contributors
- [daggerhart](https://github.com/daggerhart)
- [tnolte](https://github.com/tnolte)

## License

GPL-2.0+ — See [LICENSE](http://www.gnu.org/licenses/gpl-2.0.txt) for details.
