# Port the "Check for updates" feature and fix the release asset selection

- **Date:** 2026-11-07
- **Scope:** `wordpressiccopenidclient` (ported from `wordpressiccsupercache`)

## Context

The plugin updates itself from GitHub Releases through
`includes/icc-gg-sign-in-openid-connect-github-updater.php`, but the Plugins screen only offered a
passive link to the releases page: nothing forced a lookup, nothing reported the outcome, and the
settings footer showed the version without any update affordance.

`wordpressiccsupercache` already solved this with a real "Check for updates" action (row link →
`admin_post` handler → admin notice → native **Update now**), and its report exposed a packaging bug
that affects this repository too:

* `.github/workflows/build.yml` publishes `wordpressiccopenidclient-<version>.zip`, built from the
  repository root, so it unpacks **eleven top level entries**. WordPress refuses it with
  *"The package contains more than one folder."*
* `.github/workflows/wordpress-deploy.yml` publishes `icc-gg-sign-in-openid-connect.zip` with a single
  root folder — the only installable archive.
* Both workflows trigger on `release: published`, so the order of the assets in the GitHub API
  response is a race. `get_package_url()` returned the first `.zip` it found, which is why "Update
  now" intermittently failed.

## Goal

1. Give the plugin a real, forced update check with visible feedback, matching `wordpressiccsupercache`:
   - a **Check for updates** link and a **Release notes** link in the Plugins screen row,
   - an `admin_post` handler that bypasses the 12 hour cache,
   - an admin notice reporting *update available* / *up to date* / *could not reach GitHub*, with
     **Update now** and **Release notes** actions,
   - the same information in the settings page footer.
2. Make `get_package_url()` deterministic: prefer `<slug>.zip`, then any other `.zip` asset, then the
   tag archive.
3. Keep the WordPress-native behaviour intact (update row, cron, WP-CLI, details modal changelog).
4. Ship the preventive packaging guard and documentation (`tests/validate-plugin-layout.sh`,
   `docs/updates.md`, `docs/testing.md`).
5. Drop `readme.txt`: release notes now live in the GitHub release body and the plugin is not
   distributed on wordpress.org, so the file only duplicated content.

## Steps

1. **Updater hardening** — `includes/icc-gg-sign-in-openid-connect-github-updater.php`
   - add `PLUGIN_SLUG` and `RESULT_CACHE_KEY` constants;
   - rewrite `get_package_url()` with the preferred/fallback/archive order;
   - make `get_latest_release()`, `normalize_version()`, `request_recently_failed()` and
     `log_failed_request()` static so they can be used without an instance;
   - add the static API `flush_cache()`, `check_for_updates()`, `get_cached_update()`, `check_url()`
     and `update_url()`.
2. **Wiring**
   - main file: `define( 'ICC_GG_SIGN_IN_OPENID_CONNECT_BASENAME', plugin_basename( __FILE__ ) )`, use
     it for `plugin_action_links_*` and for the updater constructor argument, and register
     `admin_post_icc_gg_sign_in_openid_connect_check_updates` + `admin_notices`;
   - `includes/functions.php`: row meta with both links, the handler and the notice callback;
   - settings page footer: version, cached update state, **Update now**, **Release notes**.
3. **Packaging guard** — add `tests/validate-plugin-layout.sh` (copied from `wordpressiccsupercache`)
   and a `.dockerignore` that keeps `docs`, `plans`, `docker` and `.github` out of build contexts
   while still shipping `vendor/` (the plugin bundles `firebase/php-jwt`).
4. **Documentation** — `README.md` "Updating" section, `docs/updates.md`, `docs/testing.md`.
5. **Remove `readme.txt`** and the reference to it in the `Requires PHP` header comment.
6. **Verify** — `php -l` on every touched file, `sh tests/validate-plugin-layout.sh`, a `/tmp` harness
   proving the asset preference, and the GitHub security checks.

## Risks

| Risk | Mitigation |
|---|---|
| The forced check hits the GitHub API on every click (60 requests/hour unauthenticated) | It runs only for users with `update_plugins`, on an explicit click, and still respects the one hour failure back-off |
| Making helpers static breaks internal call sites | All call sites were updated to `self::`; `php -l` plus a grep audit confirms no `$this->` remains in the static methods |
| `plugin_basename()` differs between a git clone (`wordpressiccopenidclient/`) and a release install (`icc-gg-sign-in-openid-connect/`) | The basename is defined once in the main file instead of being hard-coded, so both layouts produce a working `update.php` URL |
| `vendor/` must stay in the released ZIP | `.dockerignore` only applies to Docker build contexts and deliberately does not exclude `vendor/` |
| Deleting `readme.txt` breaks the release workflows | Both workflows treat it conditionally (`if [ -f readme.txt ]`), verified by reading `semantic-release.yml` and `wordpress-deploy.yml` |
| The `build.yml` ZIP keeps publishing an unusable archive | `get_package_url()` no longer depends on asset order; the caveat is documented in `docs/updates.md` |
