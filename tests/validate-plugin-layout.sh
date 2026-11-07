#!/bin/sh
#
# Guards the packaged tree against a WordPress packaging bug:
# `get_plugins()` treats every *.php file in the plugin root and one directory
# below it that carries a "Plugin Name:" header as its own plugin. When a
# template in `includes/` has that header, `Plugin_Upgrader::plugin_info()`
# returns the template instead of the real plugin file and activation fails
# with "The plugin does not have a valid header.".
#
# Drop-in templates therefore live one level deeper (e.g. `includes/dropins/`),
# where the scanner never looks.
#
# Usage: sh tests/validate-plugin-layout.sh [path-to-plugin-root]

set -eu

cd "${1:-.}"

header_re='^[[:space:]]*(\*|//|/\*|#)?[[:space:]]*Plugin Name:'

main=''
for file in *.php; do
	[ -f "$file" ] || continue
	if grep -qE "$header_re" "$file"; then
		if [ -n "$main" ]; then
			echo "::error::Two plugin headers in the repository root: ${main} and ${file}." >&2
			exit 1
		fi
		main="$file"
	fi
done

if [ -z "$main" ]; then
	echo "::error::No WordPress plugin file with a 'Plugin Name:' header in the repository root." >&2
	exit 1
fi

echo "Main plugin file: ${main}"

# Depth 1 (./plugin.php) and depth 2 (./dir/plugin.php) are the two levels
# WordPress scans; anything deeper is invisible to it.
stray=$(grep -rlE --include='*.php' "$header_re" . 2>/dev/null \
	| grep -E '^\./[^/]+(/[^/]+)?\.php$' \
	| grep -vxF "./${main}" || true)

if [ -n "$stray" ]; then
	printf '%s\n' "$stray" | while IFS= read -r file; do
		[ -n "$file" ] || continue
		echo "::error file=${file}::'Plugin Name:' header outside the main plugin file; WordPress lists this file as a separate plugin and the updater offers it for activation. Move drop-in templates to a nested folder such as includes/dropins/." >&2
	done
	exit 1
fi

echo "✅ Only ${main} declares a plugin header in the levels WordPress scans."
