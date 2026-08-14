<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Global plugin helper functions.
 *
 * @package   ICC_GG_Sign_In_OpenID_Connect
 * @author    Ivan Carlos
 * @copyright 2023-2025 Ivan Carlos
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
