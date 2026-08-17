<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Global plugin helper functions.
 *
 * @package   ICC_GG_Sign_In_OpenID_Connect
 * @author    Ivan Carlos
 * @copyright 2007-2026 Ivan Carlos Consultoria
 * @license   http://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */

/**
 * Return a single use authentication URL.
 *
 * @return string
 */
function icc_gg_sign_in_openid_connect_get_authentication_url() {
	return \ICC_GG_Sign_In_OpenID_Connect::instance()->client_wrapper->get_authentication_url();
}

/**
 * Refresh a user claim and update the user metadata.
 *
 * @param WP_User $user             The user object.
 * @param array   $token_response   The token response.
 *
 * @return WP_Error|array
 */
function icc_gg_sign_in_openid_connect_refresh_user_claim( $user, $token_response ) {
	return \ICC_GG_Sign_In_OpenID_Connect::instance()->client_wrapper->refresh_user_claim( $user, $token_response );
}

/**
 * Add a Settings link to the plugin action links on the Plugins screen.
 *
 * @param array $links The existing plugin action links.
 *
 * @return array
 */
function icc_gg_sign_in_openid_connect_add_settings_link( $links ) {
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'options-general.php?page=icc-gg-sign-in-openid-connect-settings' ) ),
		esc_html__( 'Settings', 'icc-gg-sign-in-openid-connect' )
	);

	return array_merge( array( $settings_link ), $links );
}

/**
 * Add a Check for updates link to the plugin row meta on the Plugins screen.
 *
 * @param array  $plugin_meta An array of the plugin's metadata.
 * @param string $plugin_file Path to the plugin file relative to the plugins directory.
 *
 * @return array
 */
function icc_gg_sign_in_openid_connect_add_plugin_row_meta( $plugin_meta, $plugin_file ) {
	if ( strpos( $plugin_file, 'icc-gg-sign-in-openid-connect.php' ) === false ) {
		return $plugin_meta;
	}

	$plugin_meta[] = sprintf(
		'<a href="%1$s" target="_blank" rel="noopener">%2$s</a>',
		'https://github.com/ivancarlosti/wordpressiccopenidclient/releases',
		esc_html__( 'Check for updates', 'icc-gg-sign-in-openid-connect' )
	);

	return $plugin_meta;
}
