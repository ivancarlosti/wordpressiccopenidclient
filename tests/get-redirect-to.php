#!/usr/bin/env php
<?php
/**
 * Regression test for ICC_GG_Sign_In_OpenID_Connect_Client_Wrapper::get_redirect_to().
 *
 * A non-string `redirect_to` request value (for example `?redirect_to[]=`) must be
 * ignored instead of being passed on to esc_url_raw(), and the method must fall
 * back to the dashboard/home URL. It must never return a non-string value.
 *
 * Runs without WordPress: the handful of core functions used by get_redirect_to()
 * are stubbed below.
 *
 * Usage: php tests/get-redirect-to.php
 *
 * @package ICC_GG_Sign_In_OpenID_Connect
 */

// phpcs:disable

$failures = 0;

/**
 * Compare two values strictly and report the result.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $message  Assertion description.
 *
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

// phpcs:disable WordPress.Functions -- Test doubles for the WordPress APIs.

/**
 * Stub of home_url().
 *
 * @param string $path Optional path.
 *
 * @return string
 */
function home_url( $path = '' ) {
	return 'https://example.test' . $path;
}

/**
 * Stub of admin_url().
 *
 * @param string $path Optional path.
 *
 * @return string
 */
function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . $path;
}

/**
 * Stub of esc_url_raw() mirroring WordPress core: a non-string value reaches
 * ltrim() inside esc_url() and throws a TypeError on PHP 8.
 *
 * @param string   $url       URL to clean.
 * @param string[] $protocols Optional allowed protocols.
 *
 * @throws TypeError When the URL is not a string.
 *
 * @return string
 */
function esc_url_raw( $url, $protocols = null ) {
	if ( '' === $url ) {
		return $url;
	}

	if ( ! is_string( $url ) ) {
		throw new TypeError( 'esc_url_raw() received ' . gettype( $url ) . ', expected string' );
	}

	return $url;
}

/**
 * Stub of sanitize_text_field() mirroring _sanitize_text_fields(): arrays and
 * objects are converted to an empty string by WordPress core.
 *
 * @param mixed $str Value to sanitize.
 *
 * @return string
 */
function sanitize_text_field( $str ) {
	if ( is_object( $str ) || is_array( $str ) ) {
		return '';
	}

	return trim( preg_replace( '/[\r\n\t ]+/', ' ', (string) $str ) );
}

/**
 * Stub of wp_unslash().
 *
 * @param mixed $value Value to unslash.
 *
 * @return mixed
 */
function wp_unslash( $value ) {
	return $value;
}

/**
 * Stub of apply_filters().
 *
 * @param string $hook_name Hook name.
 * @param mixed  $value     Filtered value.
 *
 * @return mixed
 */
function apply_filters( $hook_name, $value ) {
	return $value;
}

/**
 * Stub of apply_filters_deprecated().
 *
 * @param string $hook_name Hook name.
 * @param array  $args      Filter arguments.
 * @param string $version   Version.
 * @param string $replacement Replacement hook.
 *
 * @return mixed
 */
function apply_filters_deprecated( $hook_name, $args, $version, $replacement = '' ) {
	return $args[0];
}

// phpcs:enable WordPress.Functions

require_once __DIR__ . '/../includes/icc-gg-sign-in-openid-connect-client-wrapper.php';

// Instantiate the wrapper without its constructor: get_redirect_to() only needs
// the settings object.
$reflection = new ReflectionClass( 'ICC_GG_Sign_In_OpenID_Connect_Client_Wrapper' );
$wrapper = $reflection->newInstanceWithoutConstructor();

$settings = new stdClass();
$settings->redirect_user_back = false;

$settings_property = $reflection->getProperty( 'settings' );
$settings_property->setAccessible( true );
$settings_property->setValue( $wrapper, $settings );

$GLOBALS['wp'] = new stdClass();

/**
 * Reset the request globals used by get_redirect_to() between assertions.
 *
 * @return void
 */
function reset_request() {
	unset( $_REQUEST['redirect_to'], $_GET['action'] );
}

echo "get_redirect_to():\n";

// 1. An array must be ignored, falling back to the dashboard instead of ''.
reset_request();
$GLOBALS['pagenow'] = 'wp-login.php';
$_REQUEST['redirect_to'] = array( '' );

$result = $wrapper->get_redirect_to();

assert_same( true, is_string( $result ), 'array redirect_to returns a string' );
assert_same( 'https://example.test/wp-admin/', $result, 'array redirect_to falls back to the dashboard' );

// 2. An array carrying an external URL must not influence the redirect.
reset_request();
$GLOBALS['pagenow'] = 'wp-login.php';
$_REQUEST['redirect_to'] = array( 'https://evil.example/' );

assert_same(
	'https://example.test/wp-admin/',
	$wrapper->get_redirect_to(),
	'array redirect_to cannot inject an external redirect'
);

// 3. A string redirect_to is still honored.
reset_request();
$GLOBALS['pagenow'] = 'wp-login.php';
$_REQUEST['redirect_to'] = 'https://example.test/landing';

assert_same(
	'https://example.test/landing',
	$wrapper->get_redirect_to(),
	'string redirect_to is honored'
);

// 4. Without redirect_to the dashboard/home URL is used, as before.
reset_request();
$GLOBALS['pagenow'] = 'wp-login.php';

assert_same(
	'https://example.test/wp-admin/',
	$wrapper->get_redirect_to(),
	'no redirect_to falls back to the dashboard on wp-login.php'
);

reset_request();
$GLOBALS['pagenow'] = 'index.php';

assert_same(
	'https://example.test',
	$wrapper->get_redirect_to(),
	'no redirect_to falls back to the home page elsewhere'
);

// 5. Logging out still yields an empty redirect.
reset_request();
$GLOBALS['pagenow'] = 'wp-login.php';
$_GET['action'] = 'logout';

assert_same( '', $wrapper->get_redirect_to(), 'logout keeps the empty redirect' );

reset_request();

if ( $failures > 0 ) {
	echo "\n{$failures} assertion(s) failed.\n";
	exit( 1 );
}

echo "\nAll redirect_to assertions passed.\n";
