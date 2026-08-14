<?php
/**
 * Plugin OIDC/oAuth client warpper class.
 *
 * @package   ICC_GG_Sign_In_OpenID_Connect
 * @category  Authentication
 * @author    Ivan Carlos
 * @copyright 2023-2025 Ivan Carlos
 * @license   http://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */

/**
 * ICC_GG_Sign_In_OpenID_Connect_Client_Wrapper class.
 *
 * Plugin OIDC/oAuth client wrapper class.
 *
 * @package  ICC_GG_Sign_In_OpenID_Connect
 * @category Authentication
 */
class ICC_GG_Sign_In_OpenID_Connect_Client_Wrapper {

	/**
	 * The user redirect cookie key.
	 *
	 * @deprecated Redirection should be done via state transient and not cookies.
	 *
	 * @var string
	 */
	const COOKIE_REDIRECT_KEY = 'icc-gg-sign-in-openid-connect-redirect';

	/**
	 * The token refresh info cookie key.
	 *
	 * @var string
	 */
	const COOKIE_TOKEN_REFRESH_KEY = 'icc-gg-sign-in-openid-connect-refresh';

	/**
	 * The client object instance.
	 *
	 * @var ICC_GG_Sign_In_OpenID_Connect_Client
	 */
	private $client;

	/**
	 * The settings object instance.
	 *
	 * @var ICC_GG_Sign_In_OpenID_Connect_Option_Settings
	 */
	private $settings;

	/**
	 * The logger object instance.
	 *
	 * @var ICC_GG_Sign_In_OpenID_Connect_Option_Logger
	 */
	private $logger;

	/**
	 * The return error onject.
	 *
	 * @example WP_Error if there was a problem, or false if no error
	 *
	 * @var bool|WP_Error
	 */
	private $error = false;

	/**
	 * Inject necessary objects and services into the client.
	 *
	 * @param ICC_GG_Sign_In_OpenID_Connect_Client          $client   A plugin client object instance.
	 * @param ICC_GG_Sign_In_OpenID_Connect_Option_Settings $settings A plugin settings object instance.
	 * @param ICC_GG_Sign_In_OpenID_Connect_Option_Logger   $logger   A plugin logger object instance.
	 */
	public function __construct( ICC_GG_Sign_In_OpenID_Connect_Client $client, ICC_GG_Sign_In_OpenID_Connect_Option_Settings $settings, ICC_GG_Sign_In_OpenID_Connect_Option_Logger $logger ) {
		$this->client = $client;
		$this->settings = $settings;
		$this->logger = $logger;
	}

	/**
	 * Hook the client into WordPress.
	 *
	 * @param \ICC_GG_Sign_In_OpenID_Connect_Client          $client   The plugin client instance.
	 * @param \ICC_GG_Sign_In_OpenID_Connect_Option_Settings $settings The plugin settings instance.
	 * @param \ICC_GG_Sign_In_OpenID_Connect_Option_Logger   $logger   The plugin logger instance.
	 *
	 * @return \ICC_GG_Sign_In_OpenID_Connect_Client_Wrapper
	 */
	public static function register( ICC_GG_Sign_In_OpenID_Connect_Client $client, ICC_GG_Sign_In_OpenID_Connect_Option_Settings $settings, ICC_GG_Sign_In_OpenID_Connect_Option_Logger $logger ) {
		$client_wrapper  = new self( $client, $settings, $logger );

		// Integrated logout.
		if ( $settings->endpoint_end_session ) {
			add_filter( 'allowed_redirect_hosts', array( $client_wrapper, 'update_allowed_redirect_hosts' ), 99, 1 );
			add_filter( 'logout_redirect', array( $client_wrapper, 'get_end_session_logout_redirect_url' ), 99, 3 );
		}

		// Alter the requests according to settings.
		add_filter( 'icc_gg_sign_in_openid_connect_alter_request', array( $client_wrapper, 'alter_request' ), 10, 2 );

		// Ensure tokens are refreshed before they expire.
		if ( $settings->token_refresh_enable ) {
			add_action( 'init', array( $client_wrapper, 'ensure_tokens_still_fresh' ) );
		}

		if ( is_admin() ) {
			/*
			 * Use the ajax url to handle processing authorization without any html output
			 * this callback will occur when then IDP returns with an authenticated value
			 */
			add_action( 'wp_ajax_icc-gg-sign-in-openid-connect-authorize', array( $client_wrapper, 'authentication_request_callback' ) );
			add_action( 'wp_ajax_nopriv_icc-gg-sign-in-openid-connect-authorize', array( $client_wrapper, 'authentication_request_callback' ) );
		}

		if ( $settings->alternate_redirect_uri ) {
			// Provide an alternate route for authentication_request_callback.
			add_rewrite_rule( '^icc-gg-sign-in-openid-connect-authorize/?', 'index.php?icc-gg-sign-in-openid-connect-authorize=1', 'top' );
			add_rewrite_tag( '%icc-gg-sign-in-openid-connect-authorize%', '1' );
			add_action( 'parse_request', array( $client_wrapper, 'alternate_redirect_uri_parse_request' ) );
		}

		return $client_wrapper;
	}

	/**
	 * Implements WordPress parse_request action.
	 *
	 * @param WP_Query $query The WordPress query object.
	 *
	 * @return void
	 */
	public function alternate_redirect_uri_parse_request( $query ) {
		if ( isset( $query->query_vars['icc-gg-sign-in-openid-connect-authorize'] ) &&
			 '1' === $query->query_vars['icc-gg-sign-in-openid-connect-authorize'] ) {
			$this->authentication_request_callback();
			exit;
		}
	}

	/**
	 * Get the client login redirect.
	 *
	 * @return string
	 */
	public function get_redirect_to() {
		/*
		 * @var WP $wp
		 */
		global $wp;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth redirect callback; nonces not applicable.
		if ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' == $GLOBALS['pagenow'] && isset( $_GET['action'] ) && 'logout' === sanitize_text_field( wp_unslash( $_GET['action'] ) ) ) {
			return '';
		}

		// Default redirect to the homepage.
		$redirect_url = home_url();

		// If using the login form, default redirect to the admin dashboard.
		if ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' == $GLOBALS['pagenow'] ) {
			$redirect_url = admin_url();
		}

		// Honor Core WordPress & other plugin redirects.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Login redirect flow; nonces not applicable.
		if ( isset( $_REQUEST['redirect_to'] ) ) {
			$redirect_url = esc_url_raw( sanitize_text_field( wp_unslash( $_REQUEST['redirect_to'] ) ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Capture the current URL if set to redirect back to origin page.
		if ( $this->settings->redirect_user_back ) {
			if ( ! empty( $wp->query_string ) ) {
				$redirect_url = home_url( '?' . $wp->query_string );
			}
			if ( ! empty( $wp->request ) ) {
				$redirect_url = home_url( add_query_arg( null, null ) );
				// @phpstan-ignore-next-line
				if ( $wp->did_permalink ) {
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Login redirect flow; nonces not applicable.
					$safe_get = array_map( 'sanitize_text_field', $_GET );
					$redirect_url = home_url( add_query_arg( $safe_get, trailingslashit( $wp->request ) ) );
				}
			}
		}

		// This hook is being deprecated with the move away from cookies.
		$redirect_url = apply_filters_deprecated(
			'icc_gg_sign_in_openid_connect_cookie_redirect_url',
			array( $redirect_url ),
			'3.8.2',
			'icc_gg_sign_in_openid_connect_client_redirect_to'
		);

		// This is the new hook to use with the transients version of redirection.
		return apply_filters( 'icc_gg_sign_in_openid_connect_client_redirect_to', $redirect_url );
	}

	/**
	 * Create a single use authentication url
	 *
	 * @param array<string> $atts An optional array of override/feature attributes.
	 *
	 * @return string
	 */
	public function get_authentication_url( $atts = array() ) {

		$atts = shortcode_atts(
			array(
				'endpoint_login' => $this->settings->endpoint_login,
				'scope' => $this->settings->scope,
				'client_id' => $this->settings->client_id,
				'redirect_uri' => $this->client->get_redirect_uri(),
				'redirect_to' => $this->get_redirect_to(),
				'acr_values' => $this->settings->acr_values,
			),
			$atts,
			'icc_gg_sign_in_openid_connect_auth_url'
		);

		// Validate the redirect to value to prevent a redirection attack.
		if ( ! empty( $atts['redirect_to'] ) ) {
			$atts['redirect_to'] = wp_validate_redirect( $atts['redirect_to'], home_url() );
		}

		$separator = '?';
		if ( stripos( $this->settings->endpoint_login, '?' ) !== false ) {
			$separator = '&';
		}

		$url_format = '%1$s%2$sresponse_type=code&scope=%3$s&client_id=%4$s&state=%5$s&redirect_uri=%6$s';
		if ( ! empty( $atts['acr_values'] ) ) {
			$url_format .= '&acr_values=%7$s';
		}

		$url = sprintf(
			$url_format,
			$atts['endpoint_login'],
			$separator,
			rawurlencode( $atts['scope'] ),
			rawurlencode( $atts['client_id'] ),
			$this->client->new_state( $atts['redirect_to'] ),
			rawurlencode( $atts['redirect_uri'] ),
			rawurlencode( $atts['acr_values'] )
		);

		$url = apply_filters( 'icc_gg_sign_in_openid_connect_auth_url', $url );
		$url = esc_url_raw( $url );
		$this->logger->log( $url, 'make_authentication_url' );
		return $url;
	}

	/**
	 * Handle retrieval and validation of refresh_token.
	 *
	 * @return void
	 */
	public function ensure_tokens_still_fresh() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id = wp_get_current_user()->ID;
		$last_token_response = get_user_option( 'icc-gg-sign-in-openid-connect-last-token-response', $user_id );

		if ( false === $last_token_response ) {
			$last_token_response = get_user_meta(
				$user_id,
				'icc-gg-sign-in-openid-connect-last-token-response',
				true
			);
		}

		if ( ! empty( $last_token_response['expires_in'] ) && ! empty( $last_token_response['time'] ) ) {
			/*
			 * @var int $expiration_time
			 */
			$expiration_time = intval( $last_token_response['time'] ) + intval( $last_token_response['expires_in'] );
			if ( time() < $expiration_time ) {
				// Access token is not expired so don't attempt to refresh.
				return;
			}
		}

		$manager = WP_Session_Tokens::get_instance( $user_id );
		$token = wp_get_session_token();
		$session = $manager->get( $token );

		if ( ! isset( $session[ self::COOKIE_TOKEN_REFRESH_KEY ] ) ) {
			// Not an OpenID-based session.
			return;
		}

		$refresh_token_info = $session[ self::COOKIE_TOKEN_REFRESH_KEY ];

		$refresh_token = $refresh_token_info['refresh_token'] ?? null;
		if ( empty( $refresh_token ) ) {
			// No valid refresh token.
			return;
		}

		$token_result = $this->client->request_new_tokens( $refresh_token );

		if ( is_wp_error( $token_result ) ) {
			wp_logout();
			$this->error_redirect( $token_result );
		}

		$token_response = $this->client->get_token_response( $token_result );
		if ( is_wp_error( $token_response ) ) {
			wp_logout();
			$this->error_redirect( $token_response );
		}

		// Capture the time so that access token expiration can be calculated later.
		$token_response['time'] = time();

		update_user_option( $user_id, 'icc-gg-sign-in-openid-connect-last-token-response', $token_response );
		$this->save_refresh_token( $manager, $token, $token_response );
	}

	/**
	 * Handle errors by redirecting the user to the login form along with an
	 * error code
	 *
	 * @param WP_Error $error A WordPress error object.
	 *
	 * @return void
	 */
	public function error_redirect( $error, $id_token = null ) {
		$this->logger->log( $error );

		$redirect_url = wp_login_url() .
			'?login-error=' . $error->get_error_code() .
			'&message=' . urlencode( $error->get_error_message() );

		// If we have an id_token available, store it in a short-lived transient
		// so the login form can build a proper end session URL with id_token_hint.
		if ( ! empty( $id_token ) ) {
			$transient_key = bin2hex( random_bytes( 8 ) );
			set_transient( 'icc-gg-sign-in-openid-connect-idp-logout--' . $transient_key, $id_token, 60 );
			$redirect_url .= '&idp-logout-id=' . $transient_key;
		}

		// Redirect user back to login page.
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Get the current error state.
	 *
	 * @return bool|WP_Error
	 */
	public function get_error() {
		return $this->error;
	}

	/**
	 * Add the end_session endpoint to WordPress core's whitelist of redirect hosts.
	 *
	 * @param array<string> $allowed The allowed redirect host names.
	 *
	 * @return array<string>|bool
	 */
	public function update_allowed_redirect_hosts( $allowed ) {
		$host = wp_parse_url( $this->settings->endpoint_end_session, PHP_URL_HOST );
		if ( ! $host ) {
			return false;
		}

		$allowed[] = $host;
		return $allowed;
	}

	/**
	 * Handle the logout redirect for end_session endpoint.
	 *
	 * @param string  $redirect_url          The requested redirect URL.
	 * @param string  $requested_redirect_to The user login source URL, or configured user redirect URL.
	 * @param WP_User $user                  The logged in user object.
	 *
	 * @return string
	 */
	public function get_end_session_logout_redirect_url( $redirect_url, $requested_redirect_to, $user ) {
		$url = $this->settings->endpoint_end_session;
		$query = wp_parse_url( $url, PHP_URL_QUERY );
		$url .= $query ? '&' : '?';

		// Prevent redirect back to the IDP when logging out in auto mode.
		if ( 'auto' === $this->settings->login_type && strpos( $redirect_url, 'wp-login.php?loggedout=true' ) ) {
			// By default redirect back to the site home.
			$redirect_url = home_url();
		}

		$token_response = get_user_option( 'icc-gg-sign-in-openid-connect-last-token-response', $user->ID );
		if ( ! $token_response ) {
			// Happens if non-openid login was used.
			return $redirect_url;
		} else if ( ! wp_parse_url( $redirect_url, PHP_URL_HOST ) ) {
			// Convert to absolute url if needed, site_url() to be friendly with non-standard (Bedrock) layout.
			$redirect_url = site_url( $redirect_url );
		}

		$claim = get_user_option( 'icc-gg-sign-in-openid-connect-last-id-token-claim', $user->ID );

		if ( isset( $claim['iss'] ) && 'https://accounts.google.com' == $claim['iss'] ) {
			/*
			 * Google revoke endpoint
			 * 1. expects the *access_token* to be passed as "token"
			 * 2. does not support redirection (post_logout_redirect_uri)
			 * So just redirect to regular WP logout URL.
			 * (we would *not* disconnect the user from any Google service even
			 * if he was initially disconnected to them)
			 */
			return $redirect_url;
		} else {
			return $url . sprintf( 'id_token_hint=%s&post_logout_redirect_uri=%s', $token_response['id_token'], urlencode( $redirect_url ) );
		}
	}

	/**
	 * Modify outgoing requests according to settings.
	 *
	 * @param array<mixed> $request   The outgoing request array.
	 * @param string       $operation The request operation name.
	 *
	 * @return mixed
	 */
	public function alter_request( $request, $operation ) {
		if ( ! empty( $this->settings->http_request_timeout ) ) {
			$request['timeout'] = intval( $this->settings->http_request_timeout );
		}

		// Only allow SSL bypass in local development environments.
		if (
			$this->settings->no_sslverify &&
			defined( 'WP_DEBUG' ) && WP_DEBUG === true &&
			( ! defined( 'WP_ENVIRONMENT_TYPE' ) || WP_ENVIRONMENT_TYPE === 'local' )
		) {

			$request['sslverify'] = false;

			// Log warning every time this is used.
			$this->logger->log(
				'SSL verification disabled - ONLY for development. NEVER use in production!',
				'ssl-bypass-warning'
			);
		}

		return $request;
	}

	/**
	 * Control the authentication and subsequent authorization of the user when
	 * returning from the IDP.
	 *
	 * @return void
	 */
	public function authentication_request_callback() {
		$client = $this->client;

		// Start the authentication flow.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth redirect from IDP; nonces not applicable.
		$authentication_request = $client->validate_authentication_request( $_GET );

		if ( is_wp_error( $authentication_request ) ) {
			// Check if this is a retryable IDP error (e.g. Safari ITP causing
			// Keycloak session cookies to be blocked on cross-site navigation).
			$retryable_idp_errors = array(
				'temporarily_unavailable',
				'authentication_expired',
				'login_required',
			);

			$error_code = $authentication_request->get_error_code();
			$is_retryable = in_array( $error_code, $retryable_idp_errors, true );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth redirect from IDP; nonces not applicable.
			$already_retried = isset( $_GET['icc-gg-sign-in-openid-connect-retry'] );

			if ( $is_retryable && ! $already_retried ) {
				// Log the original error before retrying.
				$this->logger->log( $authentication_request, 'retry' );
				$this->logger->log( "Retrying authentication due to IDP error: {$error_code}", 'retry' );

				// Build a fresh authentication URL and append a retry flag
				// to prevent infinite redirect loops (max 1 retry).
				$auth_url = $this->get_authentication_url();
				$auth_url = add_query_arg( 'icc-gg-sign-in-openid-connect-retry', '1', $auth_url );

				wp_redirect( $auth_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Redirect to external IDP authentication URL.
				exit;
			}

			$this->error_redirect( $authentication_request );
		}

		// Retrieve the authentication code from the authentication request.
		$code = $client->get_authentication_code( $authentication_request );

		if ( is_wp_error( $code ) ) {
			$this->error_redirect( $code );
		}

		// Retrieve the authentication state from the authentication request.
		$state = $client->get_authentication_state( $authentication_request );

		if ( is_wp_error( $state ) ) {
			$this->error_redirect( $state );
		}

		// Attempting to exchange an authorization code for an authentication token.
		$token_result = $client->request_authentication_token( $code );

		if ( is_wp_error( $token_result ) ) {
			$this->error_redirect( $token_result );
		}

		// Get the decoded response from the authentication request result.
		$token_response = $client->get_token_response( $token_result );

		// Allow for other plugins to alter data before validation.
		$token_response = apply_filters( 'icc_gg_sign_in_openid_connect_modify_token_response_before_validation', $token_response );

		if ( is_wp_error( $token_response ) ) {
			$this->error_redirect( $token_response );
		}

		// Ensure the that response contains required information.
		$valid = $client->validate_token_response( $token_response );

		if ( is_wp_error( $valid ) ) {
			$this->error_redirect( $valid );
		}

		/**
		 * The id_token is used to identify the authenticated user, e.g. for SSO.
		 * The access_token must be used to prove access rights to protected
		 * resources e.g. for the userinfo endpoint
		 */
		$id_token_claim = $client->get_id_token_claim( $token_response );

		// Allow for other plugins to alter data before validation.
		$id_token_claim = apply_filters( 'icc_gg_sign_in_openid_connect_modify_id_token_claim_before_validation', $id_token_claim );

		if ( is_wp_error( $id_token_claim ) ) {
			$this->error_redirect( $id_token_claim );
		}

		// Validate our id_token has required values.
		$valid = $client->validate_id_token_claim( $id_token_claim );

		if ( is_wp_error( $valid ) ) {
			$this->error_redirect( $valid );
		}

		// If userinfo endpoint is set, exchange the token_response for a user_claim.
		if ( ! empty( $this->settings->endpoint_userinfo ) && isset( $token_response['access_token'] ) ) {
			$user_claim = $client->get_user_claim( $token_response );
		} else {
			$user_claim = $id_token_claim;
		}

		if ( is_wp_error( $user_claim ) ) {
			$this->error_redirect( $user_claim );
		}

		// Validate our user_claim has required values.
		$valid = $client->validate_user_claim( $user_claim, $id_token_claim );

		if ( is_wp_error( $valid ) ) {
			$this->error_redirect( $valid );
		}

		// Validate email domain restriction before user lookup/creation.
		$domain_valid = $this->validate_email_domain( $user_claim );
		if ( is_wp_error( $domain_valid ) ) {
			$this->error_redirect( $domain_valid, $token_response['id_token'] );
		}

		/**
		 * End authorization
		 * -
		 * Request is authenticated and authorized - start user handling
		 */
		$subject_identity = $client->get_subject_identity( $id_token_claim );
		$user = $this->get_user_by_identity( $subject_identity );

		// A pre-existing IDP mapped user wasn't found.
		if ( ! $user ) {
			// If linking existing users or creating new ones call the `create_new_user` method which handles both cases.
			if ( $this->settings->link_existing_users || $this->settings->create_if_does_not_exist ) {
				$user = $this->create_new_user( $subject_identity, $user_claim );
				if ( is_wp_error( $user ) ) {
					$this->error_redirect( $user, $token_response['id_token'] );
				}
			} else {
				$this->error_redirect( new WP_Error( 'identity-not-map-existing-user', __( 'User identity is not linked to an existing WordPress user.', 'icc-gg-sign-in-openid-connect' ), $user_claim ) );
			}
		}

		// Validate the found / created user.
		$valid = $this->validate_user( $user );

		if ( is_wp_error( $valid ) ) {
			$this->error_redirect( $valid );
		}

		// Login the found / created user.
		$start_time = microtime( true );
		$this->login_user( $user, $token_response, $id_token_claim, $user_claim, $subject_identity );
		$end_time = microtime( true );
		// Log our success.
		$this->logger->log( "Successful login for: {$user->user_login} ({$user->ID})", 'login-success', $end_time - $start_time );

		// Allow plugins / themes to take action once a user is logged in.
		$start_time = microtime( true );
		do_action( 'icc_gg_sign_in_openid_connect_user_logged_in', $user );
		$end_time = microtime( true );
		$this->logger->log( 'icc_gg_sign_in_openid_connect_user_logged_in', 'do_action', $end_time - $start_time );

		// Default redirect to the homepage.
		$redirect_url = home_url();
		// Redirect user according to redirect set in state.
		$state_object = get_transient( 'icc-gg-sign-in-openid-connect-state--' . $state );
		// Get the redirect URL stored with the corresponding authentication request state.
		if ( ! empty( $state_object ) && ! empty( $state_object[ $state ] ) && ! empty( $state_object[ $state ]['redirect_to'] ) ) {
			$redirect_url = $state_object[ $state ]['redirect_to'];
		}

		// Provide backwards compatibility for customization using the deprecated cookie method.
		if ( ! empty( $_COOKIE[ self::COOKIE_REDIRECT_KEY ] ) ) {
			$redirect_url = wp_validate_redirect(
				esc_url_raw( wp_unslash( $_COOKIE[ self::COOKIE_REDIRECT_KEY ] ) ),
				home_url()
			);
		}

		// Only do redirect-user-back action hook when the plugin is configured for it.
		if ( $this->settings->redirect_user_back ) {
			do_action( 'icc_gg_sign_in_openid_connect_redirect_user_back', $redirect_url, $user );
		}

		wp_safe_redirect( $redirect_url );

		exit;
	}

	/**
	 * Validate the potential WP_User.
	 *
	 * @param WP_User|WP_Error|false $user The user object.
	 *
	 * @return true|WP_Error
	 */
	public function validate_user( $user ) {
		// Ensure the found user is a real WP_User.
		if ( ! is_a( $user, 'WP_User' ) || ! $user->exists() ) {
			return new WP_Error( 'invalid-user', __( 'Invalid user.', 'icc-gg-sign-in-openid-connect' ), $user );
		}

		return true;
	}

	/**
	 * Validate that the user's email matches the allowed domains or email addresses list.
	 *
	 * Each entry in the restriction list can be:
	 * - A domain name (e.g., "company.com") — matches any user at that domain.
	 * - A full email address (e.g., "specificuser@gmail.com") — matches only that exact address.
	 *
	 * Comparisons are case-insensitive. If the setting is empty, all are allowed.
	 *
	 * @param array $user_claim The authenticated user claim containing email data.
	 *
	 * @return true|WP_Error True if allowed, WP_Error otherwise.
	 */
	private function validate_email_domain( $user_claim ) {
		$restriction_setting = trim( $this->settings->email_domain_restriction );

		// Empty setting means allow all.
		if ( empty( $restriction_setting ) ) {
			return true;
		}

		// Extract email from the user claim.
		$email = $this->get_email_from_claim( $user_claim );
		if ( is_wp_error( $email ) || empty( $email ) ) {
			return new WP_Error(
				'email-domain-no-email',
				__( 'Unable to determine email address from user claim for domain validation.', 'icc-gg-sign-in-openid-connect' )
			);
		}

		// Normalize the user's email for case-insensitive comparison.
		$email_lower = strtolower( trim( $email ) );

		// Extract the domain part from the user's email.
		$parts = explode( '@', $email_lower );
		$domain = trim( $parts[1] ?? '' );

		if ( empty( $domain ) ) {
			return new WP_Error(
				'email-domain-invalid',
				__( 'Unable to extract domain from email address.', 'icc-gg-sign-in-openid-connect' )
			);
		}

		// Parse allowed entries (space-separated, case-insensitive).
		$allowed_entries = preg_split( '/\s+/', strtolower( trim( $restriction_setting ) ) );

		// Check each entry: full emails get exact match, domains get domain match.
		$matched = false;
		foreach ( $allowed_entries as $entry ) {
			if ( '' === $entry ) {
				continue;
			}

			if ( false !== strpos( $entry, '@' ) ) {
				// Full email address — compare against user's full email.
				if ( $email_lower === $entry ) {
					$matched = true;
					break;
				}
			} else {
				// Domain only — compare against user's email domain.
				if ( $domain === $entry ) {
					$matched = true;
					break;
				}
			}
		}

		if ( ! $matched ) {
			$this->logger->log(
				sprintf(
					'Email "%s" not in allowed restriction list. Allowed entries: %s',
					$email,
					implode( ', ', $allowed_entries )
				),
				'email-domain-restriction'
			);
			return new WP_Error(
				'email-domain-not-allowed',
				sprintf(
					/* translators: %s is the email address or domain that was rejected */
					__( 'Email "%s" is not allowed for login. Check the allowed email or domain restrictions.', 'icc-gg-sign-in-openid-connect' ),
					$email
				)
			);
		}

		return true;
	}

	/**
	 * Refresh user claim.
	 *
	 * @param WP_User $user             The user object.
	 * @param array   $token_response   The token response.
	 *
	 * @return WP_Error|array
	 */
	public function refresh_user_claim( $user, $token_response ) {
		$client = $this->client;

		/**
		 * The id_token is used to identify the authenticated user, e.g. for SSO.
		 * The access_token must be used to prove access rights to protected
		 * resources e.g. for the userinfo endpoint
		 */
		$id_token_claim = $client->get_id_token_claim( $token_response );

		// Allow for other plugins to alter data before validation.
		$id_token_claim = apply_filters( 'icc_gg_sign_in_openid_connect_modify_id_token_claim_before_validation', $id_token_claim );

		if ( is_wp_error( $id_token_claim ) ) {
			return $id_token_claim;
		}

		// Validate our id_token has required values.
		$valid = $client->validate_id_token_claim( $id_token_claim );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// If userinfo endpoint is set, exchange the token_response for a user_claim.
		if ( ! empty( $this->settings->endpoint_userinfo ) && isset( $token_response['access_token'] ) ) {
			$user_claim = $client->get_user_claim( $token_response );
		} else {
			$user_claim = $id_token_claim;
		}

		if ( is_wp_error( $user_claim ) ) {
			return $user_claim;
		}

		// Validate our user_claim has required values.
		$valid = $client->validate_user_claim( $user_claim, $id_token_claim );

		if ( is_wp_error( $valid ) ) {
			$this->error_redirect( $valid );
			return $valid;
		}

		// Store the tokens for future reference.
		update_user_option( $user->ID, 'icc-gg-sign-in-openid-connect-last-token-response', $token_response );
		update_user_option( $user->ID, 'icc-gg-sign-in-openid-connect-last-id-token-claim', $id_token_claim );
		update_user_option( $user->ID, 'icc-gg-sign-in-openid-connect-last-user-claim', $user_claim );

		return $user_claim;
	}

	/**
	 * Record user meta data, and provide an authorization cookie.
	 *
	 * @param WP_User $user             The user object.
	 * @param array   $token_response   The token response.
	 * @param array   $id_token_claim   The ID token claim.
	 * @param array   $user_claim       The authenticated user claim.
	 * @param string  $subject_identity The subject identity from the IDP.
	 *
	 * @return void
	 */
	public function login_user( $user, $token_response, $id_token_claim, $user_claim, $subject_identity ): void {
		// Store the tokens for future reference.
		update_user_option( $user->ID, 'icc-gg-sign-in-openid-connect-last-token-response', $token_response );
		update_user_option( $user->ID, 'icc-gg-sign-in-openid-connect-last-id-token-claim', $id_token_claim );
		update_user_option( $user->ID, 'icc-gg-sign-in-openid-connect-last-user-claim', $user_claim );
		// Allow plugins / themes to take action using current claims on existing user (e.g. update role).
		do_action( 'icc_gg_sign_in_openid_connect_update_user_using_current_claim', $user, $user_claim );

		// Determine the amount of days before the cookie expires.
		$remember_me = apply_filters( 'icc_gg_sign_in_openid_connect_remember_me', false, $user, $token_response, $id_token_claim, $user_claim, $subject_identity );
		$wp_expiration_days = $remember_me ? 14 : 2;

		// Create the WP session, so we know its token.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter, intentionally invoked.
		$expiration = time() + apply_filters( 'auth_cookie_expiration', $wp_expiration_days * DAY_IN_SECONDS, $user->ID, $remember_me );
		$manager = WP_Session_Tokens::get_instance( $user->ID );
		$token = $manager->create( $expiration );

		// Save the refresh token in the session.
		$this->save_refresh_token( $manager, $token, $token_response );

		// you did great, have a cookie!
		wp_set_auth_cookie( $user->ID, $remember_me, '', $token );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core action, intentionally invoked.
		do_action( 'wp_login', $user->user_login, $user );
	}

	/**
	 * Save refresh token to WP session tokens
	 *
	 * @param WP_Session_Tokens   $manager        A user session tokens manager.
	 * @param string              $token          The current users session token.
	 * @param array|WP_Error|null $token_response The authentication token response.
	 */
	public function save_refresh_token( $manager, $token, $token_response ): void {
		if ( ! $this->settings->token_refresh_enable ) {
			return;
		}

		$session = $manager->get( $token );

		$session[ self::COOKIE_TOKEN_REFRESH_KEY ] = array(
			'refresh_token' => $token_response['refresh_token'] ?? false,
		);

		$manager->update( $token, $session );
		return;
	}

	/**
	 * Get the user that has meta data matching a
	 *
	 * @param string $subject_identity The IDP identity of the user.
	 *
	 * @return false|WP_User
	 */
	public function get_user_by_identity( $subject_identity ) {
		global $wpdb;

		// Look for user by their icc-gg-sign-in-openid-connect-subject-identity value.
		$user_query = new WP_User_Query(
			array(
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Necessary identity lookup; meta key is indexed.
				'meta_query' => array(
					'relation' => 'OR',
					array(
						'key'   => 'icc-gg-sign-in-openid-connect-subject-identity',
						'value' => $subject_identity,
					),
					array(
						'key'   => $wpdb->get_blog_prefix() . 'icc-gg-sign-in-openid-connect-subject-identity',
						'value' => $subject_identity,
					),
				),
				// Override the default blog_id (get_current_blog_id) to find users on different sites of a multisite install.
				'blog_id' => 0,
			)
		);

		// If we found existing users, grab the first one returned.
		if ( $user_query->get_total() > 0 ) {
			$users = $user_query->get_results();
			return $users[0];
		}

		return false;
	}

	/**
	 * Avoid user_login collisions by incrementing.
	 *
	 * @param array $user_claim The IDP authenticated user claim data.
	 *
	 * @return string|WP_Error
	 */
	private function get_username_from_claim( $user_claim ) {

		// @var string $desired_username
		$desired_username = '';

		// Allow settings to take first stab at username.
		if ( ! empty( $this->settings->identity_key ) && isset( $user_claim[ $this->settings->identity_key ] ) ) {
			$desired_username = $user_claim[ $this->settings->identity_key ];
		}
		if ( empty( $desired_username ) && isset( $user_claim['preferred_username'] ) && ! empty( $user_claim['preferred_username'] ) ) {
			$desired_username = $user_claim['preferred_username'];
		}
		if ( empty( $desired_username ) && isset( $user_claim['name'] ) && ! empty( $user_claim['name'] ) ) {
			$desired_username = $user_claim['name'];
		}
		if ( empty( $desired_username ) && isset( $user_claim['email'] ) && ! empty( $user_claim['email'] ) ) {
			$tmp = explode( '@', $user_claim['email'] );
			$desired_username = $tmp[0];
		}
		if ( empty( $desired_username ) ) {
			// Nothing to build a name from.
			return new WP_Error( 'no-username', __( 'No appropriate username found.', 'icc-gg-sign-in-openid-connect' ), $user_claim );
		}

		// Don't use the full email address for a username.
		$_desired_username = explode( '@', $desired_username );
		$desired_username = $_desired_username[0];
		// Use WordPress Core to sanitize the IDP username.
		$sanitized_username = sanitize_user( $desired_username, true );
		if ( empty( $sanitized_username ) ) {
			// translators: %1$s is the santitized version of the username from the IDP.
			return new WP_Error( 'username-sanitization-failed', sprintf( __( 'Username %1$s could not be sanitized.', 'icc-gg-sign-in-openid-connect' ), $desired_username ), $desired_username );
		}

		return $sanitized_username;
	}

	/**
	 * Get a nickname.
	 *
	 * @param array $user_claim The IDP authenticated user claim data.
	 *
	 * @return string|WP_Error|null
	 */
	private function get_nickname_from_claim( $user_claim ) {
		$desired_nickname = null;
		// Allow settings to take first stab at nickname.
		if ( ! empty( $this->settings->nickname_key ) && isset( $user_claim[ $this->settings->nickname_key ] ) ) {
			$desired_nickname = $user_claim[ $this->settings->nickname_key ];
		}

		if ( empty( $desired_nickname ) ) {
			// translators: %1$s is the configured User Claim nickname key.
			return new WP_Error( 'no-nickname', sprintf( __( 'No nickname found in user claim using key: %1$s.', 'icc-gg-sign-in-openid-connect' ), $this->settings->nickname_key ), $this->settings->nickname_key );
		}

		return $desired_nickname;
	}

	/**
	 * Checks if $claimname is in the body or _claim_names of the userinfo.
	 * If yes, returns the claim value. Otherwise, returns false.
	 *
	 * @param string $claimname the claim name to look for.
	 * @param array  $userinfo the JSON to look in.
	 * @param string $claimvalue the source claim value ( from the body of the JWT of the claim source).
	 * @return true|false
	 */
	private function get_claim( $claimname, $userinfo, &$claimvalue ) {
		/**
		 * If we find a simple claim, return it.
		 */
		if ( array_key_exists( $claimname, $userinfo ) ) {
			$claimvalue = $userinfo[ $claimname ];
			return true;
		}
		/**
		 * If there are no aggregated claims, it is over.
		 */
		if ( ! array_key_exists( '_claim_names', $userinfo ) ||
			! array_key_exists( '_claim_sources', $userinfo ) ) {
			return false;
		}
		$claim_src_ptr = $userinfo['_claim_names'];
		if ( ! isset( $claim_src_ptr ) ) {
			return false;
		}
		/**
		 * No reference found
		 */
		if ( ! array_key_exists( $claimname, $claim_src_ptr ) ) {
			return false;
		}
		$src_name = $claim_src_ptr[ $claimname ];
		// Reference found, but no corresponding JWT. This is a malformed userinfo.
		if ( ! array_key_exists( $src_name, $userinfo['_claim_sources'] ) ) {
			return false;
		}
		$src = $userinfo['_claim_sources'][ $src_name ];
		// Source claim is not a JWT. Abort.
		if ( ! array_key_exists( 'JWT', $src ) ) {
			return false;
		}
		/**
		 * Extract claim from JWT with signature verification.
		 */
		$jwt = $src['JWT'];

		// Check if JWKS endpoint is configured for JWT signature verification.
		if ( ! empty( $this->settings->endpoint_jwks ) ) {
			// Use configured issuer if provided, otherwise derive from endpoint_login.
			$issuer = ! empty( $this->settings->issuer ) ?
				$this->settings->issuer :
				( ! empty( $this->settings->endpoint_login ) ? $this->client->get_issuer_from_endpoint( $this->settings->endpoint_login ) : '' );

			// Use JWT validator for secure signature verification.
			$jwt_validator = new ICC_GG_Sign_In_OpenID_Connect_JWT_Validator(
				$this->settings->endpoint_jwks,
				$this->settings->client_id,
				$issuer,
				$this->settings->jwks_cache_ttl,
				$this->settings->allow_internal_idp,
				$this->logger
			);

			// Validate JWT signature and claims.
			$body_json = $jwt_validator->validate_id_token( $jwt );

			if ( is_wp_error( $body_json ) ) {
				$this->logger->log( $body_json, 'aggregated-claim-jwt-validation-failed' );
				return false;
			}

			if ( ! array_key_exists( $claimname, $body_json ) ) {
				return false;
			}
			$claimvalue = $body_json[ $claimname ];
			return true;
		}

		$this->logger->log(
			'SECURITY WARNING: JWKS endpoint not configured. Aggregated claim JWT signatures are NOT being verified. This is a security vulnerability. Configure the JWKS endpoint to secure aggregated claims.',
			'aggregated-jwt-not-verified'
		);

		// Legacy JWT decoding without signature verification (INSECURE).
		list ( $header, $body, $rest ) = explode( '.', $jwt, 3 );
		$body_str = base64_decode( $body, false );
		if ( ! $body_str ) {
			return false;
		}
		$body_json = json_decode( $body_str, true );
		if ( ! isset( $body_json ) ) {
			return false;
		}
		if ( ! array_key_exists( $claimname, $body_json ) ) {
			return false;
		}
		$claimvalue = $body_json[ $claimname ];
		return true;
	}


	/**
	 * Build a string from the user claim according to the specified format.
	 *
	 * @param string $format               The format format of the user identity.
	 * @param array  $user_claim           The authorized user claim.
	 * @param bool   $error_on_missing_key Whether to return and error on a missing key.
	 *
	 * @return string|WP_Error
	 */
	private function format_string_with_claim( $format, $user_claim, $error_on_missing_key = false ) {
		$matches = null;
		$string = '';
		$info = '';
		$i = 0;
		if ( preg_match_all( '/\{[^}]*\}/u', $format, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[0] as $match ) {
				$key = substr( $match[0], 1, -1 );
				$string .= substr( $format, $i, $match[1] - $i );
				if ( ! $this->get_claim( $key, $user_claim, $info ) ) {
					if ( $error_on_missing_key ) {
						return new WP_Error(
							'incomplete-user-claim',
							__( 'User claim incomplete.', 'icc-gg-sign-in-openid-connect' ),
							array(
								'message'    => 'Unable to find key: ' . $key . ' in user_claim',
								'hint'       => 'Verify OpenID Scope includes a scope with the attributes you need',
								'user_claim' => $user_claim,
								'format'     => $format,
							)
						);
					}
				} else {
					$string .= $info;
				}
				$i = $match[1] + strlen( $match[0] );
			}
		}
		$string .= substr( $format, $i );
		return $string;
	}

	/**
	 * Get a displayname.
	 *
	 * @param array $user_claim           The authorized user claim.
	 * @param bool  $error_on_missing_key Whether to return and error on a missing key.
	 *
	 * @return string|null|WP_Error
	 */
	private function get_displayname_from_claim( $user_claim, $error_on_missing_key = false ) {
		if ( ! empty( $this->settings->displayname_format ) ) {
			return $this->format_string_with_claim( $this->settings->displayname_format, $user_claim, $error_on_missing_key );
		}
		return null;
	}

	/**
	 * Get an email.
	 *
	 * @param array $user_claim           The authorized user claim.
	 * @param bool  $error_on_missing_key Whether to return and error on a missing key.
	 *
	 * @return string|null|WP_Error
	 */
	private function get_email_from_claim( $user_claim, $error_on_missing_key = false ) {
		if ( ! empty( $this->settings->email_format ) ) {
			return $this->format_string_with_claim( $this->settings->email_format, $user_claim, $error_on_missing_key );
		}
		return null;
	}

	/**
	 * Create a new user from details in a user_claim.
	 *
	 * @param string $subject_identity The authenticated user's identity with the IDP.
	 * @param array  $user_claim       The authorized user claim.
	 *
	 * @return \WP_Error | \WP_User
	 */
	public function create_new_user( $subject_identity, $user_claim ) {
		$start_time = microtime( true );
		$user_claim = apply_filters( 'icc_gg_sign_in_openid_connect_alter_user_claim', $user_claim );

		// Default username & email to the subject identity.
		$username       = $subject_identity;
		$email          = $subject_identity;
		$nickname       = $subject_identity;
		$displayname    = $subject_identity;
		$values_missing = false;

		// Allow claim details to determine username, email, nickname and displayname.
		$_email = $this->get_email_from_claim( $user_claim, true );
		if ( is_wp_error( $_email ) || empty( $_email ) ) {
			$values_missing = true;
		} else {
			$email = $_email;
		}

		$_username = $this->get_username_from_claim( $user_claim );
		if ( is_wp_error( $_username ) || empty( $_username ) ) {
			$values_missing = true;
		} else {
			$username = $_username;
		}

		$_nickname = $this->get_nickname_from_claim( $user_claim );
		if ( is_wp_error( $_nickname ) || empty( $_nickname ) ) {
			$values_missing = true;
		} else {
			$nickname = $_nickname;
		}

		$_displayname = $this->get_displayname_from_claim( $user_claim, true );
		if ( is_wp_error( $_displayname ) || empty( $_displayname ) ) {
			$values_missing = true;
		} else {
			$displayname = $_displayname;
		}

		// Attempt another request for userinfo if some values are missing.
		if ( $values_missing && isset( $user_claim['access_token'] ) && ! empty( $this->settings->endpoint_userinfo ) ) {
			$user_claim_result = $this->client->request_userinfo( $user_claim['access_token'] );

			// Make sure we didn't get an error.
			if ( is_wp_error( $user_claim_result ) ) {
				return new WP_Error( 'bad-user-claim-result', __( 'Bad user claim result.', 'icc-gg-sign-in-openid-connect' ), $user_claim_result );
			}

			$user_claim = json_decode( $user_claim_result['body'], true );
		}

		$_email = $this->get_email_from_claim( $user_claim, true );
		if ( is_wp_error( $_email ) ) {
			return $_email;
		}
		// Use the email address from the latest userinfo request if not empty.
		if ( ! empty( $_email ) ) {
			$email = $_email;
		}

		$_username = $this->get_username_from_claim( $user_claim );
		if ( is_wp_error( $_username ) ) {
			return $_username;
		}
		// Use the username from the latest userinfo request if not empty.
		if ( ! empty( $_username ) ) {
			$username = $_username;
		}

		$_nickname = $this->get_nickname_from_claim( $user_claim );
		if ( is_wp_error( $_nickname ) ) {
			return $_nickname;
		}
		// Use the username as the nickname if the userinfo request nickname is empty.
		if ( empty( $_nickname ) ) {
			$nickname = $username;
		}

		$_displayname = $this->get_displayname_from_claim( $user_claim, true );
		if ( is_wp_error( $_displayname ) ) {
			return $_displayname;
		}
		// Use the nickname as the displayname if the userinfo request displayname is empty.
		if ( empty( $_displayname ) ) {
			$displayname = $nickname;
		}

		// Before trying to create the user, first check if a matching user exists.
		if ( $this->settings->link_existing_users ) {
			$uid = null;
			if ( $this->settings->identify_with_username ) {
				$uid = username_exists( $username );
			} else {
				$uid = email_exists( $email );
			}
			if ( ! empty( $uid ) ) {
				$user = $this->update_existing_user( $uid, $subject_identity );
				do_action( 'icc_gg_sign_in_openid_connect_update_user_using_current_claim', $user, $user_claim );
				$end_time = microtime( true );
				$this->logger->log( "Existing user updated: {$user->user_login} ($uid)", __METHOD__, $end_time - $start_time );
				return $user;
			}
		}

		/**
		 * Allow other plugins / themes to determine authorization of new accounts
		 * based on the returned user claim.
		 */
		$create_user = apply_filters( 'icc_gg_sign_in_openid_connect_user_creation_test', $this->settings->create_if_does_not_exist, $user_claim );

		if ( ! $create_user ) {
			return new WP_Error( 'cannot-authorize', __( 'Can not authorize.', 'icc-gg-sign-in-openid-connect' ), $create_user );
		}

		// Copy the username for incrementing.
		$_username = $username;
		// Ensure prevention of linking usernames & collisions by incrementing the username if it exists.
		// @example Original user gets "name", second user gets "name2", etc.
		$count = 1;
		while ( username_exists( $username ) ) {
			$count++;
			$username = $_username . $count;
		}

		$user_data = array(
			'user_login' => $username,
			'user_pass' => wp_generate_password( 32, true, true ),
			'user_email' => $email,
			'display_name' => $displayname,
			'nickname' => $nickname,
			'first_name' => isset( $user_claim['given_name'] ) ? $user_claim['given_name'] : '',
			'last_name' => isset( $user_claim['family_name'] ) ? $user_claim['family_name'] : '',
		);
		$user_data = apply_filters( 'icc_gg_sign_in_openid_connect_alter_user_data', $user_data, $user_claim );

		// Create the new user.
		$uid = wp_insert_user( $user_data );

		// Make sure we didn't fail in creating the user.
		if ( is_wp_error( $uid ) ) {
			return new WP_Error( 'failed-user-creation', __( 'Failed user creation.', 'icc-gg-sign-in-openid-connect' ), $uid );
		}

		// Retrieve our new user.
		$user = get_user_by( 'id', $uid );

		// Save some meta data about this new user for the future.
		update_user_option( $user->ID, 'icc-gg-sign-in-openid-connect-subject-identity', (string) $subject_identity, true );

		// Log the results.
		$end_time = microtime( true );
		$this->logger->log( "New user created: {$user->user_login} ($uid)", __METHOD__, $end_time - $start_time );

		// Allow plugins / themes to take action on new user creation.
		do_action( 'icc_gg_sign_in_openid_connect_user_create', $user, $user_claim );

		return $user;
	}

	/**
	 * Update an existing user with OpenID Connect meta data
	 *
	 * @param int    $uid              The WordPress User ID.
	 * @param string $subject_identity The subject identity from the IDP.
	 *
	 * @return WP_Error|WP_User
	 */
	public function update_existing_user( $uid, $subject_identity ) {
		// Add the OpenID Connect meta data.
		update_user_option( $uid, 'icc-gg-sign-in-openid-connect-subject-identity', strval( $subject_identity ), true );

		// Allow plugins / themes to take action on user update.
		do_action( 'icc_gg_sign_in_openid_connect_user_update', $uid );

		// Return our updated user.
		return get_user_by( 'id', $uid );
	}
}
