#!/usr/bin/env php
<?php
/**
 * i18n bootstrap check.
 *
 * Loads the plugin with a small set of WordPress stubs to verify that the text
 * domain is hooked on `plugins_loaded`, that the bundled catalogs are loaded
 * from `languages/`, that the locale mapping resolves the bundled locales and
 * that the RTL helpers behave.
 *
 * Runs without WordPress.
 *
 * Usage: php tests/check-i18n-bootstrap.php
 *
 * @package ICC_GG_Sign_In_OpenID_Connect
 */

// phpcs:disable

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

$GLOBALS['icc_test_actions']    = array();
$GLOBALS['icc_test_filters']    = array();
$GLOBALS['icc_test_textdomain'] = array();
$GLOBALS['icc_test_locale']     = 'en_US';
$GLOBALS['failures']            = 0;

/**
 * Record a hook registration.
 *
 * @param array<string,array<int,array{hook:callable,accepted: int}>> $store Storage array.
 * @param string                                                     $hook  Hook name.
 * @param callable                                                   $cb    Callback.
 * @param int                                                        $prio  Priority.
 * @param int                                                        $args  Accepted args.
 * @return void
 */
function icc_test_add_hook( &$store, $hook, $cb, $prio, $args ) {
	$store[ $hook ][ $prio ][] = array(
		'hook'     => $cb,
		'accepted' => $args,
	);
}

/**
 * Stub of add_action().
 *
 * @param string   $hook     Hook name.
 * @param callable $callback Callback.
 * @param int      $priority Priority.
 * @param int      $args     Accepted args.
 * @return void
 */
function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	icc_test_add_hook( $GLOBALS['icc_test_actions'], $hook, $callback, $priority, $args );
}

/**
 * Stub of add_filter().
 *
 * @param string   $hook     Hook name.
 * @param callable $callback Callback.
 * @param int      $priority Priority.
 * @param int      $args     Accepted args.
 * @return void
 */
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	icc_test_add_hook( $GLOBALS['icc_test_filters'], $hook, $callback, $priority, $args );
}

/**
 * Stub of apply_filters(): runs the registered callbacks in priority order.
 *
 * @param string $hook  Hook name.
 * @param mixed  $value Value being filtered.
 * @return mixed
 */
function apply_filters( $hook, $value ) {
	$args = func_get_args();

	if ( empty( $GLOBALS['icc_test_filters'][ $hook ] ) ) {
		return $value;
	}

	$callbacks = $GLOBALS['icc_test_filters'][ $hook ];
	ksort( $callbacks );

	foreach ( $callbacks as $registered ) {
		foreach ( $registered as $entry ) {
			$call_args = array_slice( $args, 1, $entry['accepted'] );
			if ( ! array_key_exists( 0, $call_args ) ) {
				$call_args[0] = $value;
			}
			$value = call_user_func_array( $entry['hook'], $call_args );
		}
	}

	return $value;
}

/**
 * Stub of load_plugin_textdomain().
 *
 * @param string       $domain Text domain.
 * @param string|false $path   Deprecated path argument.
 * @param string       $rel    Relative path to the catalog directory.
 * @return bool
 */
function load_plugin_textdomain( $domain, $path = false, $rel = '' ) {
	$GLOBALS['icc_test_textdomain'] = array(
		'domain' => $domain,
		'path'   => $path,
		'rel'    => $rel,
	);

	return true;
}

/**
 * Stub of plugin_basename().
 *
 * @param string $file Plugin file path.
 * @return string
 */
function plugin_basename( $file ) {
	return 'wordpressiccopenidclient/' . basename( $file );
}

/**
 * Stub of plugin_dir_url().
 *
 * @param string $file Plugin file path.
 * @return string
 */
function plugin_dir_url( $file ) {
	return 'https://example.test/wp-content/plugins/wordpressiccopenidclient/';
}

/**
 * Stub of get_option().
 *
 * @param string $name    Option name.
 * @param mixed  $default Default value.
 * @return mixed
 */
function get_option( $name, $default = false ) {
	return $default;
}

/**
 * Stub of update_option().
 *
 * @param string $name  Option name.
 * @param mixed  $value Option value.
 * @return bool
 */
function update_option( $name, $value ) {
	return true;
}

/**
 * Stub of current_user_can().
 *
 * @param string $capability Capability name.
 * @return bool
 */
function current_user_can( $capability ) {
	return false;
}

/**
 * Stub of register_activation_hook().
 *
 * @param string   $file     Plugin file.
 * @param callable $callback Callback.
 * @return void
 */
function register_activation_hook( $file, $callback ) {}

/**
 * Stub of register_deactivation_hook().
 *
 * @param string   $file     Plugin file.
 * @param callable $callback Callback.
 * @return void
 */
function register_deactivation_hook( $file, $callback ) {}

/**
 * Stub of determine_locale(): returns the locale the test sets.
 *
 * @return string
 */
function determine_locale() {
	return $GLOBALS['icc_test_locale'];
}

/**
 * Stub of get_locale().
 *
 * @return string
 */
function get_locale() {
	return $GLOBALS['icc_test_locale'];
}

/**
 * Compare two values strictly and report the result.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $message  Assertion description.
 * @return void
 */
function assert_same( $expected, $actual, $message ) {
	global $failures;

	if ( $expected === $actual ) {
		echo "  ok   {$message}\n";
		return;
	}

	++$failures;
	echo "  FAIL {$message}\n";
	echo '       expected: ' . var_export( $expected, true ) . "\n";
	echo '       actual:   ' . var_export( $actual, true ) . "\n";
}

/**
 * Report a boolean condition.
 *
 * @param bool   $condition Whether the assertion holds.
 * @param string $message   Assertion description.
 * @return void
 */
function assert_true( $condition, $message ) {
	assert_same( true, (bool) $condition, $message );
}

// phpcs:enable

require_once dirname( __DIR__ ) . '/icc-gg-sign-in-openid-connect.php';

$domain = 'icc-gg-sign-in-openid-connect';

echo "Text domain bootstrap:\n";

assert_true( defined( 'ICC_GG_SIGN_IN_OPENID_CONNECT_PLUGIN_DIR' ), 'the plugin directory constant is defined' );
assert_same(
	'wordpressiccopenidclient/icc-gg-sign-in-openid-connect.php',
	ICC_GG_SIGN_IN_OPENID_CONNECT_BASENAME,
	'the plugin basename comes from plugin_basename()'
);

assert_true(
	isset( $GLOBALS['icc_test_actions']['plugins_loaded'][5] ),
	'the textdomain loader runs on plugins_loaded'
);

$loader = $GLOBALS['icc_test_actions']['plugins_loaded'][5][0]['hook'];
assert_same( 'icc_gg_sign_in_openid_connect_load_textdomain', $loader, 'the loader is the documented callback' );

$loader();

assert_same( $domain, $GLOBALS['icc_test_textdomain']['domain'], 'load_plugin_textdomain gets the plugin text domain' );
assert_same( false, $GLOBALS['icc_test_textdomain']['path'], 'the deprecated path argument stays false' );
assert_same(
	'wordpressiccopenidclient/languages',
	$GLOBALS['icc_test_textdomain']['rel'],
	'the catalogs are loaded from the bundled languages/ directory'
);

echo "\nLocale mapping (plugin_locale filter):\n";

/**
 * Run the plugin_locale filter for a locale.
 *
 * @param string $locale Locale to map.
 * @param string $text_domain Text domain.
 * @return string
 */
function mapped_locale( $locale, $text_domain ) {
	return apply_filters( 'plugin_locale', $locale, $text_domain );
}

$mapping = array(
	'pt_BR'  => 'pt_BR',
	'pt-BR'  => 'pt_BR',
	'es_MX'  => 'es_MX',
	'es-MX'  => 'es_MX',
	'fr_FR'  => 'fr_FR',
	'fr-FR'  => 'fr_FR',
	'zh_CN'  => 'zh_CN',
	'zh-CN'  => 'zh_CN',
	'hi_IN'  => 'hi_IN',
	'hi-IN'  => 'hi_IN',
	'en_US'  => 'en_US',
	'en-US'  => 'en_US',
	'en_GB'  => 'en_US',
	'en'     => 'en_US',
	'ar'     => 'ar',
	'ar_SA'  => 'ar',
	'ar-EG'  => 'ar',
	'de_DE'  => 'de_DE',
	'pt_PT'  => 'pt_PT',
);

foreach ( $mapping as $locale => $expected ) {
	assert_same( $expected, mapped_locale( $locale, $domain ), "{$locale} resolves to {$expected}" );
}

assert_same( 'ar_SA', mapped_locale( 'ar_SA', 'akismet' ), 'other text domains keep their locale' );

echo "\nRight-to-left helpers:\n";

$rtl_locales     = array( 'ar', 'ar_SA', 'ar-EG', 'he_IL', 'fa_IR', 'ur_PK' );
$non_rtl_locales = array( 'en_US', 'pt_BR', 'es_MX', 'fr_FR', 'zh_CN', 'hi_IN', 'de_DE' );

foreach ( $rtl_locales as $locale ) {
	$GLOBALS['icc_test_locale'] = $locale;
	assert_true( icc_gg_sign_in_openid_connect_is_rtl_locale(), "{$locale} is right-to-left" );
}

foreach ( $non_rtl_locales as $locale ) {
	$GLOBALS['icc_test_locale'] = $locale;
	assert_same( false, icc_gg_sign_in_openid_connect_is_rtl_locale(), "{$locale} is left-to-right" );
}

echo "\nDirection and body classes:\n";

$GLOBALS['icc_test_locale'] = 'ar_SA';

assert_same(
	'lang="ar-SA" dir="rtl"',
	apply_filters( 'language_attributes', 'lang="ar-SA"', 'html' ),
	'the language attributes get dir="rtl"'
);

assert_same(
	'lang="ar-SA" dir="rtl"',
	apply_filters( 'language_attributes', 'lang="ar-SA" dir="rtl"', 'html' ),
	'an existing dir attribute is not duplicated'
);

assert_same(
	'wp-admin rtl',
	apply_filters( 'admin_body_class', 'wp-admin' ),
	'the admin body gets the rtl class'
);

assert_same(
	'wp-admin rtl',
	apply_filters( 'admin_body_class', 'wp-admin rtl' ),
	'the rtl admin body class is not duplicated'
);

assert_same(
	array( 'login', 'rtl' ),
	apply_filters( 'login_body_class', array( 'login' ) ),
	'the login body gets the rtl class'
);

assert_same(
	array( 'login', 'rtl' ),
	apply_filters( 'login_body_class', array( 'login', 'rtl' ) ),
	'the rtl login body class is not duplicated'
);

$GLOBALS['icc_test_locale'] = 'pt_BR';

assert_same(
	'lang="pt-BR"',
	apply_filters( 'language_attributes', 'lang="pt-BR"', 'html' ),
	'left-to-right locales keep the language attributes untouched'
);

assert_same( 'wp-admin', apply_filters( 'admin_body_class', 'wp-admin' ), 'left-to-right admin bodies get no rtl class' );
assert_same(
	array( 'login' ),
	apply_filters( 'login_body_class', array( 'login' ) ),
	'left-to-right login bodies get no rtl class'
);

if ( $failures > 0 ) {
	echo "\n{$failures} i18n assertion(s) failed.\n";
	exit( 1 );
}

echo "\nAll i18n bootstrap assertions passed.\n";
