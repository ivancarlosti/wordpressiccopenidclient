#!/usr/bin/env php
<?php
/**
 * Bundled translation catalog check.
 *
 * Verifies that every shipped locale has a `.po`/`.mo` pair, that the catalogs
 * compile cleanly with msgfmt, that translators kept the placeholders and the
 * HTML markup of the source strings and that the strings the plugin actually
 * renders are translated.
 *
 * Runs without WordPress: only the gettext tooling (`msgfmt`) and the small
 * .mo reader below are used.
 *
 * Usage: php tests/check-translations.php
 *
 * @package ICC_GG_Sign_In_OpenID_Connect
 */

// phpcs:disable

$slug          = 'icc-gg-sign-in-openid-connect';
$languages_dir = dirname( __DIR__ ) . '/languages';
$failures      = 0;

/**
 * Bundled locales, in catalog order.
 *
 * @var string[]
 */
$locales = array( 'en_US', 'pt_BR', 'es_MX', 'fr_FR', 'zh_CN', 'hi_IN', 'ar' );

/**
 * Right-to-left locales, which also need a mirrored stylesheet.
 *
 * @var string[]
 */
$rtl_locales = array( 'ar' );

/**
 * One string per locale that the catalog must translate.
 *
 * The English source renders "Settings"; getting the English string back means
 * the .mo file was not loaded or is incomplete.
 *
 * @var array<string,string>
 */
$probes = array(
	'en_US' => 'Settings',
	'pt_BR' => 'Configurações',
	'es_MX' => 'Ajustes',
	'fr_FR' => 'Réglages',
	'zh_CN' => '设置',
	'hi_IN' => 'सेटिंग्स',
	'ar'    => 'الإعدادات',
);

/**
 * Report the result of a single assertion.
 *
 * @param bool   $condition Whether the assertion holds.
 * @param string $message   Assertion description.
 * @return void
 */
function assert_true( $condition, $message ) {
	global $failures;

	if ( $condition ) {
		echo "  ok   {$message}\n";
		return;
	}

	++$failures;
	echo "  FAIL {$message}\n";
}

/**
 * Compare two lists and report a mismatch.
 *
 * @param array<mixed> $expected Expected list.
 * @param array<mixed> $actual   Actual list.
 * @param string       $message  Assertion description.
 * @return void
 */
function assert_same_list( $expected, $actual, $message ) {
	global $failures;

	if ( $expected === $actual ) {
		echo "  ok   {$message}\n";
		return;
	}

	++$failures;
	echo "  FAIL {$message}\n";
	echo '       expected: ' . implode( ', ', $expected ) . "\n";
	echo '       actual:   ' . implode( ', ', $actual ) . "\n";
}

/**
 * Collect the printf placeholders of a string.
 *
 * @param string $text Source or translated string.
 * @return string[]
 */
function icc_test_placeholders( $text ) {
	$text = str_replace( '%%', '', $text );

	preg_match_all( '/%(?:\d+\$)?[a-zA-Z]/', $text, $matches );

	$placeholders = array_values( array_unique( $matches[0] ) );
	sort( $placeholders );

	return $placeholders;
}

/**
 * Collect the HTML tags of a string.
 *
 * @param string $text Source or translated string.
 * @return string[]
 */
function icc_test_markup( $text ) {
	preg_match_all( '/<\/?[a-zA-Z][^>]*>/', $text, $matches );

	$tags = array_values( array_unique( $matches[0] ) );
	sort( $tags );

	return $tags;
}

/**
 * Read a binary .mo catalog into a msgid => msgstr map.
 *
 * Plural entries keep their NUL separated forms in the value, the same way
 * gettext stores them: "singular\0plural" => "one\0other".
 *
 * @param string $file Absolute path to the .mo file.
 * @return array<string,string>|null Null when the file is not a valid catalog.
 */
function icc_test_read_mo( $file ) {
	$data = file_get_contents( $file );
	if ( false === $data || strlen( $data ) < 28 ) {
		return null;
	}

	$magic = unpack( 'V', substr( $data, 0, 4 ) );
	$magic = $magic[1];

	if ( 0x950412de === $magic ) {
		$format = 'V';
	} elseif ( 0xde120495 === $magic ) {
		$format = 'N';
	} else {
		return null;
	}

	$header = unpack(
		"{$format}revision/{$format}count/{$format}offset",
		substr( $data, 4, 12 )
	);

	if ( ! is_array( $header ) || $header['count'] < 1 ) {
		return null;
	}

	$strings = array();

	for ( $i = 0; $i < $header['count']; $i++ ) {
		$entry = unpack(
			"{$format}length/{$format}offset",
			substr( $data, $header['offset'] + ( $i * 8 ), 8 )
		);
		$translation = unpack(
			"{$format}length/{$format}offset",
			substr( $data, $header['offset'] + ( ( $header['count'] + $i ) * 8 ), 8 )
		);

		// Untranslated entries are omitted: gettext then shows the source string.
		if ( 0 === $entry['length'] || 0 === $translation['length'] ) {
			continue;
		}

		if ( $entry['offset'] + $entry['length'] > strlen( $data ) ) {
			return null;
		}

		if ( $translation['offset'] + $translation['length'] > strlen( $data ) ) {
			return null;
		}

		$msgid  = substr( $data, $entry['offset'], $entry['length'] );
		$msgstr = substr( $data, $translation['offset'], $translation['length'] );

		if ( '' === $msgid ) {
			// The header entry.
			continue;
		}

		$strings[ $msgid ] = $msgstr;
	}

	return $strings;
}

echo "Bundled translation catalogs ({$slug}):\n";

assert_true( is_dir( $languages_dir ), 'languages/ directory exists' );
assert_true( file_exists( "{$languages_dir}/{$slug}.pot" ), 'the .pot template is present' );

$have_msgfmt = 0 === (int) trim( (string) shell_exec( 'command -v msgfmt >/dev/null 2>&1; echo $?' ) );

foreach ( $locales as $locale ) {
	echo "\n[{$locale}]\n";

	$po = "{$languages_dir}/{$slug}-{$locale}.po";
	$mo = "{$languages_dir}/{$slug}-{$locale}.mo";

	assert_true( file_exists( $po ), "{$locale}.po exists" );

	if ( ! file_exists( $po ) ) {
		continue;
	}

	if ( $have_msgfmt ) {
		$tmp_mo = sys_get_temp_dir() . "/{$slug}-{$locale}-check.mo";
		exec(
			'msgfmt -c -o ' . escapeshellarg( $tmp_mo ) . ' ' . escapeshellarg( $po ) . ' 2>&1',
			$compile_output,
			$compile_status
		);

		assert_true( 0 === $compile_status, "{$locale}.po compiles with msgfmt -c" );

		if ( 0 !== $compile_status ) {
			echo '       ' . implode( "\n       ", $compile_output ) . "\n";
		}

		if ( file_exists( $tmp_mo ) ) {
			unlink( $tmp_mo );
		}
	} else {
		echo "  skip msgfmt is not installed\n";
	}

	assert_true( file_exists( $mo ) && filesize( $mo ) > 0, "{$locale}.mo exists and is not empty" );

	if ( ! file_exists( $mo ) ) {
		continue;
	}

	$strings = icc_test_read_mo( $mo );
	assert_true( is_array( $strings ) && ! empty( $strings ), "{$locale}.mo is a readable catalog" );

	if ( ! is_array( $strings ) || empty( $strings ) ) {
		continue;
	}

	// The string the plugin renders must come back translated.
	$probe = $probes[ $locale ];
	assert_true( in_array( $probe, $strings, true ), "the catalog translates \"Settings\" as \"{$probe}\"" );

	$empty    = array();
	$mismatch = array();

	foreach ( $strings as $msgid => $msgstr ) {
		$id_forms  = explode( "\0", $msgid );
		$str_forms = explode( "\0", $msgstr );

		$expected_placeholders = icc_test_placeholders( $id_forms[0] );
		$expected_markup       = icc_test_markup( $id_forms[0] );

		foreach ( $str_forms as $form ) {
			if ( '' === trim( $form ) ) {
				$empty[] = $id_forms[0];
				continue;
			}

			if ( icc_test_placeholders( $form ) !== $expected_placeholders ) {
				$mismatch[] = $id_forms[0];
				continue;
			}

			if ( icc_test_markup( $form ) !== $expected_markup ) {
				$mismatch[] = $id_forms[0];
			}
		}
	}

	assert_same_list( array(), array_values( array_unique( $empty ) ), 'no empty translations' );
	assert_same_list( array(), array_values( array_unique( $mismatch ) ), 'placeholders and markup are preserved' );

	if ( in_array( $locale, $rtl_locales, true ) ) {
		assert_true(
			file_exists( dirname( __DIR__ ) . '/css/styles-admin-rtl.css' ),
			'the RTL admin stylesheet is bundled'
		);
		assert_true(
			file_exists( dirname( __DIR__ ) . '/css/styles-login-rtl.css' ),
			'the RTL login stylesheet is bundled'
		);
	}
}

if ( $failures > 0 ) {
	echo "\n{$failures} translation assertion(s) failed.\n";
	exit( 1 );
}

echo "\nAll translation assertions passed.\n";
