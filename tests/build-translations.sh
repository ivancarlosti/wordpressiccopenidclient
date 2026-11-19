#!/bin/sh
#
# Compiles the bundled translation catalogs and verifies they are complete.
#
# The plugin ships its own catalogs in `languages/`, because WordPress only
# auto-loads language packs installed under `wp-content/languages/plugins`:
#
#   languages/icc-gg-sign-in-openid-connect.pot        template (source strings)
#   languages/icc-gg-sign-in-openid-connect-<locale>.po  human-edited catalog
#   languages/icc-gg-sign-in-openid-connect-<locale>.mo  compiled catalog (shipped)
#
# Every `.po` file must be fully translated: a partially translated catalog is
# accepted by WordPress but silently falls back to English per string, which is
# how missing keys go unnoticed.
#
# Usage:
#   sh tests/build-translations.sh              # compile and validate
#   sh tests/build-translations.sh --check-pot  # also fail when the POT is stale
#
# --check-pot re-extracts the strings with xgettext and compares them with the
# committed POT, so a source string that changed without regenerating the
# template fails the build instead of reaching translators.

set -eu

cd "$(dirname "$0")/.."

slug=icc-gg-sign-in-openid-connect
dir=languages
pot="$dir/$slug.pot"
check_pot=0

case "${1:-}" in
	--check-pot) check_pot=1 ;;
	'') ;;
	*)
		echo "::error::Unknown argument: $1" >&2
		exit 1
		;;
esac

for tool in msgfmt msgcat xgettext diff; do
	if ! command -v "$tool" >/dev/null 2>&1; then
		echo "::error::Missing required tool: ${tool} (install the gettext package)." >&2
		exit 1
	fi
done

if [ ! -f "$pot" ]; then
	echo "::error::Missing template ${pot}." >&2
	exit 1
fi

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT HUP INT TERM

# msgcat --no-wrap puts every msgid on one line, so the catalogs can be
# compared with plain diff. The header entry is dropped: it legitimately
# differs between the POT and each locale (Language, Plural-Forms, dates).
msgids() {
	msgcat --no-wrap --sort-output --no-location "$1" |
		awk 'BEGIN { in_header = 1 } in_header == 1 && $0 == "" { in_header = 0; next } in_header == 0 { print }' |
		grep '^msgid ' || true
}

msgids "$pot" > "$tmp/pot.msgids"

status=0

for po in "$dir"/*.po; do
	[ -f "$po" ] || continue
	mo="${po%.po}.mo"

	msgfmt --check --check-format -o "$mo" "$po" || {
		echo "::error file=${po}::msgfmt rejected ${po}." >&2
		status=1
		continue
	}

	# "192 translated messages." is the only accepted summary; anything that
	# mentions untranslated, fuzzy or obsolete entries is incomplete.
	summary=$(msgfmt --statistics -o /dev/null "$po" 2>&1 || true)
	case "$summary" in
		*fuzzy* | *untranslated* | *obsolete*)
			echo "::error file=${po}::Incomplete catalog: $(printf '%s' "$summary" | tr '\n' ' ')" >&2
			status=1
			;;
	esac

	# A .po that is missing an entry entirely is not reported as untranslated,
	# so the msgid sets are compared as well.
	msgids "$po" > "$tmp/po.msgids"
	if ! diff -u "$tmp/pot.msgids" "$tmp/po.msgids" > "$tmp/diff.txt"; then
		echo "::error file=${po}::Catalog does not match ${pot}:" >&2
		sed -n '1,40p' "$tmp/diff.txt" >&2
		status=1
	fi

	echo "✅ ${po} ($(printf '%s' "$summary" | tr '\n' ' ' | sed 's/ *$//'))"
done

# The compiled catalogs are validated as binaries as well: the harness parses the
# .mo files directly, so it works without the locales installed on the machine.
if command -v php >/dev/null 2>&1; then
	php tests/check-translations.php || status=1

	# Stubs the WordPress API the plugin touches while loading and asserts that the
	# textdomain points at this plugin's languages/ folder and that plugin_locale,
	# is_rtl_locale(), language_attributes and the body class filters behave.
	php tests/check-i18n-bootstrap.php || status=1
else
	echo "⚠️  php not found, skipping the compiled-catalog and bootstrap checks."
fi

if [ "$check_pot" -eq 1 ]; then
	xgettext \
		--language=PHP \
		--from-code=UTF-8 \
		--add-comments=translators: \
		--keyword=__ \
		--keyword=_e \
		--keyword=_x:1,2c \
		--keyword=_n:1,2 \
		--keyword=_nx:1,2,4c \
		--keyword=esc_html__ \
		--keyword=esc_attr__ \
		--keyword=esc_html_e \
		--keyword=esc_attr_e \
		-o "$tmp/fresh.pot" \
		"$slug.php" includes/*.php

	msgids "$tmp/fresh.pot" > "$tmp/fresh.msgids"
	if ! diff -u "$tmp/pot.msgids" "$tmp/fresh.msgids" > "$tmp/pot-diff.txt"; then
		echo "::error file=${pot}::Template is out of date; re-run the build to refresh it." >&2
		sed -n '1,60p' "$tmp/pot-diff.txt" >&2
		status=1
	else
		echo "✅ ${pot} matches the source strings."
	fi
fi

if [ "$status" -ne 0 ]; then
	exit 1
fi

echo "✅ All translation catalogs compiled."
