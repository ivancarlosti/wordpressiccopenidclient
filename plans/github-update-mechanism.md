# GitHub-Based Plugin Update Mechanism

## Overview

This plan adds built-in update capability to the ICC OpenID Client plugin, replicating exactly how WordPress.org-hosted plugins handle updates — but using GitHub Releases as the source. Users will see update notifications, one-click updates, the version details popup, and the "Enable automatic updates" toggle in their WordPress admin.

**No external library dependency** — the entire update mechanism is a self-contained WordPress class using native hooks (`pre_set_site_transient_update_plugins`, `plugins_api`, `auto_update_plugin`).

## Architecture

### Update Flow

```
Developer creates GitHub Release
        │
        ▼
wordpress-deploy.yml workflow triggers
        │
        ├── Stamp version from manifest.json
        ├── Create WordPress ZIP (icc-openid-client.zip)
        ├── Upload ZIP to GitHub Release assets
        ├── Generate plugin-update-info.json metadata
        └── Upload plugin-update-info.json to GitHub Release assets
                │
                ▼
        (Optional: Deploy to WordPress.org SVN)

─── WordPress Site Side ───

Every 12 hours or on admin page visit:
        │
        ▼
ICC_OpenID_Client_Update_Checker::check_for_update()
        │
        ├── Fetch releases/latest/download/plugin-update-info.json
        ├── Compare version with installed version
        ├── If newer → inject into update_plugins transient
        └── Show update notification on Plugins screen
                │
                ▼
        User clicks "Update Now" or enables auto-updates
                │
                ▼
        WordPress downloads icc-openid-client.zip from GitHub Release
                │
                ▼
        WordPress unzips and replaces plugin files
```

### Key URLs

| Purpose | URL |
|---------|-----|
| **Metadata (always latest)** | `https://github.com/ivancarlosti/wordpressiccopenidclient/releases/latest/download/plugin-update-info.json` |
| **ZIP download (per release)** | `https://github.com/ivancarlosti/wordpressiccopenidclient/releases/download/v{version}/icc-openid-client.zip` |

The `releases/latest/download/` URL is a GitHub redirect that always points to the most recent release's asset — no commits or repo changes needed per release.

---

## Files Changed

| File | Change |
|------|--------|
| [`includes/icc-openid-client-update-checker.php`](includes/icc-openid-client-update-checker.php) | **NEW** — Self-contained update checker class using native WordPress hooks |
| [`icc-openid-client.php`](icc-openid-client.php) | Initialize `ICC_OpenID_Client_Update_Checker` in `bootstrap()` |
| [`.github/workflows/wordpress-deploy.yml`](.github/workflows/wordpress-deploy.yml) | Two new steps: generate & upload `plugin-update-info.json` as a release asset |

**No changes to `.github/workflows/build.yml`** — as required.
**No Composer dependency** — the update checker has zero external dependencies.

---

## Implementation Details

### Step 1: Native Update Checker Class

**File:** [`includes/icc-openid-client-update-checker.php`](includes/icc-openid-client-update-checker.php)

A self-contained class that hooks into three WordPress filters:

| WordPress Hook | Purpose |
|---------------|---------|
| `pre_set_site_transient_update_plugins` | Injects update info when a newer version is detected — this triggers the update notification |
| `plugins_api` | Provides plugin details for the "View version details" popup (changelog, description, author) |
| `auto_update_plugin` | Explicitly allows automatic updates for this plugin — this enables the "Enable automatic updates" toggle |
| `plugin_auto_update_setting_html` | Ensures the auto-update toggle HTML is rendered even when WordPress doesn't recognize the plugin's update source |

Key behaviors:
- Fetches metadata from GitHub with 10-second timeout
- Caches metadata in a WordPress transient for 12 hours (avoids hitting GitHub on every page load)
- Gracefully handles connection failures (returns existing transient data, no update offered)
- Parses readme.txt changelog format into HTML for the version details popup
- Uses `get_file_data()` to read the current version from the plugin header (no constant dependency)

### Step 2: Initialize in main plugin file

**File:** [`icc-openid-client.php`](icc-openid-client.php), in `bootstrap()`:

```php
require_once __DIR__ . '/includes/icc-openid-client-update-checker.php';
new ICC_OpenID_Client_Update_Checker(
    __FILE__,
    'icc-openid-client',
    'https://github.com/ivancarlosti/wordpressiccopenidclient/releases/latest/download/plugin-update-info.json'
);
```

The update checker initializes immediately during plugin load (not on `init` hook), ensuring its hooks are registered before WordPress processes update checks.

### Step 3: GitHub Actions workflow

**File:** [`.github/workflows/wordpress-deploy.yml`](.github/workflows/wordpress-deploy.yml)

Two new steps added after the WordPress ZIP is uploaded:

1. **Generate `plugin-update-info.json`**: Creates a JSON file with version, download URL, requirements parsed from plugin headers, and changelog extracted from readme.txt.

2. **Upload to GitHub Release**: Attaches the JSON as a release asset using `gh release upload --clobber`.

The WordPress.org SVN deploy step is unchanged.

---

## How It Works (User Perspective)

1. **"Enable automatic updates" toggle**: Appears in the Plugins list for ICC OpenID Client. Clicking it enables automatic background updates — WordPress will download and install new versions from GitHub without any user interaction.

2. **Update notification**: When a new GitHub release is published, the plugin shows the standard yellow update notice on the Plugins screen ("There is a new version of ICC OpenID Client available").

3. **One-click update**: Clicking "Update Now" downloads the ZIP from GitHub Releases, unpacks it, and replaces the plugin files — identical to WordPress.org-hosted plugins.

4. **View version details**: Clicking "View version x.y.z details" opens the plugin info popup showing the description and full changelog from the GitHub release.

5. **Automatic updates**: If enabled, WordPress periodically checks for updates via WP-Cron and installs them without user intervention.
