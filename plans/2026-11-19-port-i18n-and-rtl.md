# Port the multi-language (i18n) implementation and RTL support

## Context

The sibling plugin `wordpressiccllmfiles` ships a complete i18n layer: catalogs bundled in
`languages/`, a `plugin_locale` mapping for the documented locale codes, RTL helpers driven by
`determine_locale()` and a mirrored settings stylesheet, plus three validation harnesses. This fork
had a `Text Domain` header but no `Domain Path`, no `load_plugin_textdomain()`, no catalogs and no
RTL support, so every string rendered in English even with WordPress set to another language.

Unlike the reference plugin, the OpenID Connect client renders on three surfaces (settings page,
`wp-login.php` login button and the plugin's admin notices), and one string is pluralised
(`_n()` for the discovery import "N fields populated").

## Goal

- Ship the same seven locales: `en_US` (fallback), `pt_BR`, `es_MX`, `fr_FR`, `zh_CN`, `hi_IN` and
  `ar` (RTL), each with a `.po` source and a compiled `.mo`.
- Load the bundled catalogs explicitly (they are not language packs), map hyphenated/documented
  codes and English/Arabic variants to the shipped catalogs.
- Render right-to-left for Arabic on the settings page and the login screen.
- Validate the catalogs without WordPress: compile, freshness of the template, placeholder/markup
  integrity and the i18n wiring itself.

## Steps

1. **Bootstrap** (`icc-gg-sign-in-openid-connect.php`)
   - Add `Domain Path: /languages` to the plugin header.
   - Define `ICC_GG_SIGN_IN_OPENID_CONNECT_PLUGIN_DIR` (`plugin_dir_url()`, trailing slash) for the
     stylesheet URLs.
   - Add `icc_gg_sign_in_openid_connect_load_textdomain()` on `plugins_loaded` priority `5`, using
     `dirname( ICC_GG_SIGN_IN_OPENID_CONNECT_BASENAME ) . '/languages'` so a git clone and a release
     install both work.
   - Add `icc_gg_sign_in_openid_connect_plugin_locale()` on `plugin_locale`: normalise `-` to `_`,
     keep the shipped locales, send `en*` to `en_US` and `ar*` to `ar`, leave other domains and
     unsupported locales untouched.
   - Add `icc_gg_sign_in_openid_connect_is_rtl_locale()` plus the `language_attributes`,
     `admin_body_class` and `login_body_class` filters (never duplicating `dir=`/`rtl`).
2. **Strings**: wrap every user-facing string in `__()`, `esc_html_e()`, `esc_html__()` or `_n()` with
   the `icc-gg-sign-in-openid-connect` domain, and add `translators:` comments with positional
   arguments (`client`, `client-wrapper`, `jwt-validator`, `settings-page`, `login-form`).
3. **Stylesheets**: add `css/styles-admin-rtl.css` (logger table alignment, LTR JSON payloads,
   mirrored discovery accent border, LTR URL input) and `css/styles-login-rtl.css` (logo margin), and
   enqueue them only when `is_rtl_locale()` is true — after the base admin stylesheet, and from
   `enqueue_login_scripts()` on `login_enqueue_scripts`.
4. **Catalogs**: extract `languages/icc-gg-sign-in-openid-connect.pot` with `xgettext`
   (192 entries), generate each `.po` from the template and compile the `.mo` files.
5. **Tests**: add `tests/build-translations.sh` (msgfmt compile + statistics + msgid diff +
   `--check-pot`), `tests/check-translations.php` (parses the `.mo` files) and
   `tests/check-i18n-bootstrap.php` (stubs the WordPress API, loads the plugin, asserts the mapping
   and the RTL filters).
6. **Docs**: `docs/i18n.md`, a **Translations** section in `README.md` and an entry in
   `docs/testing.md`.

## Risks

- The catalogs were authored from the template through an index-keyed translation map kept outside
  the repository. Regenerating the template invalidates that map: future string changes must go
  through `msgmerge` on the committed `.po` files, as documented in `docs/i18n.md`.
- The translations are not native-reviewed. A wording review by native speakers is recommended
  before promoting the plugin in those markets; the technical checks only guarantee structure
  (compilable, complete, placeholders and markup preserved).
- Committed `.mo` files are binary and must be rebuilt whenever a `.po` changes — enforced by
  `tests/build-translations.sh`, which rewrites them in place.
- `en_*` now resolves to `en_US`. If `en_US.mo` is ever missing, gettext falls back to the source
  strings, which are English, so the fallback stays safe.
- RTL mirroring is limited to the selectors the plugin owns. WordPress core handles the admin
  chrome, but third-party screens embedding the login button shortcode are not mirrored.
- Plural forms differ per locale (`zh_CN` `nplurals=1`, `ar` `nplurals=6`): a wrong `Plural-Forms`
  header makes `msgfmt --check` fail, and `tests/check-translations.php` validates every form.
