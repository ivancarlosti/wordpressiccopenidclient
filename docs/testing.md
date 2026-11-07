# Testing

## Static checks

Every PHP file must lint cleanly:

```bash
find . -name '*.php' -not -path './vendor/*' -exec php -l {} \;
```

The packaged tree must not look like several plugins. WordPress reads every `*.php` file in the plugin
root **and one directory below it** as a potential plugin, so a second `Plugin Name:` header would be
offered for activation ("The plugin does not have a valid header"):

```bash
sh tests/validate-plugin-layout.sh
```

## Release ZIP check

The only asset WordPress can install is `icc-gg-sign-in-openid-connect.zip`, whose single root folder
is the plugin folder:

```bash
unzip -Z1 icc-gg-sign-in-openid-connect.zip | cut -d/ -f1 | sort -u
# exactly one line: icc-gg-sign-in-openid-connect
```

Any other result (several lines) means the archive was built from the repository root and will fail
with *"The package contains more than one folder."*

## Forced update check (no WordPress needed)

`includes/icc-gg-sign-in-openid-connect-github-updater.php` only needs a handful of WordPress
functions, so the asset selection logic can be exercised with a stub harness in `/tmp`:

```php
<?php
define( 'ABSPATH', '/tmp/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'ICC_GG_SIGN_IN_OPENID_CONNECT_BASENAME', 'icc-gg-sign-in-openid-connect/icc-gg-sign-in-openid-connect.php' );

$transients = array();
function get_site_transient( $key ) { global $transients; return isset( $transients[ $key ] ) ? $transients[ $key ] : false; }
function set_site_transient( $key, $value, $ttl = 0 ) { global $transients; $transients[ $key ] = $value; return true; }
function delete_site_transient( $key ) { global $transients; unset( $transients[ $key ] ); return true; }
function wp_remote_get( $url, $args = array() ) { return $GLOBALS['http_response']; }
function is_wp_error( $value ) { return false; }
function wp_remote_retrieve_response_code( $response ) { return 200; }
function wp_remote_retrieve_body( $response ) { return $response; }

class ICC_GG_Sign_In_OpenID_Connect { const VERSION = '6.0.0'; }

require '/path/to/plugin/includes/icc-gg-sign-in-openid-connect-github-updater.php';

// Feed the real asset list captured from the GitHub API.
$assets = json_decode( '[{"name":"wordpressiccopenidclient-6.0.0.zip","browser_download_url":"https://github.com/ivancarlosti/wordpressiccopenidclient/releases/download/v6.0.0/wordpressiccopenidclient-6.0.0.zip"},{"name":"icc-gg-sign-in-openid-connect.zip","browser_download_url":"https://github.com/ivancarlosti/wordpressiccopenidclient/releases/download/v6.0.0/icc-gg-sign-in-openid-connect.zip"}]' );
$GLOBALS['http_response'] = json_encode( array( 'tag_name' => 'v6.0.1', 'html_url' => 'https://github.com/ivancarlosti/wordpressiccopenidclient/releases/tag/v6.0.1', 'assets' => $assets ) );

$result = ICC_GG_Sign_In_OpenID_Connect_Github_Updater::check_for_updates();
// state: update, version: 6.0.1, installed: 6.0.0
```

`get_package_url()` must return the `…/download/v6.0.1/icc-gg-sign-in-openid-connect.zip` URL even
though `wordpressiccopenidclient-6.0.0.zip` is listed first — that ordering is what made "Update now"
intermittently fail.

## Manual verification in WordPress

1. `wp plugin list --name=icc-gg-sign-in-openid-connect` and note the installed version.
2. **Plugins → Installed Plugins** → **Check for updates**. Expect the success notice and, when a newer
   tag exists, a warning notice with **Update now** and **Release notes**.
3. Click **Update now** → the native upgrader runs and ends on the plugin page with the new version.
4. Open **Dashboard → Updates** and confirm the plugin is gone from the list.
5. Open **Settings → OpenID Connect** and confirm the footer renders the version, the update state and
   both links.
