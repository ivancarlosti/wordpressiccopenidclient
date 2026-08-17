<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * ICC.gg Sign-In for OpenID Connect - WordPress OpenID Connect SSO Plugin
 *
 * This plugin provides the ability to authenticate users with Identity
 * Providers using the OpenID Connect OAuth2 API with Authorization Code Flow.
 *
 * @package   ICC_GG_Sign_In_OpenID_Connect
 * @category  General
 * @author    Ivan Carlos
 * @copyright 2023-2025 Ivan Carlos
 * @license   http://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 * @link      https://github.com/ivancarlosti/wordpressiccopenidclient
 *
 * @wordpress-plugin
 * Plugin Name:       ICC.gg Sign-In for OpenID Connect
 * Plugin URI:        https://github.com/ivancarlosti/wordpressiccopenidclient
 * Description:       Connect to an OpenID Connect identity provider using Authorization Code Flow. Features email domain restriction and SSO.
 * Version:           3.1.5
 * Requires at least: 5.0
 * Requires PHP:      8.1
 * Author:            ivancarlosti
 * Author URI:        https://ivancarlos.me
 * Text Domain:       icc-gg-sign-in-openid-connect
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

/*
Notes
  Spec Doc - http://openid.net/specs/openid-connect-basic-1_0-32.html

  Filters
  - icc_gg_sign_in_openid_connect_alter_request       - 3 args: request array, plugin settings, specific request op
  - icc_gg_sign_in_openid_connect_settings_fields     - modify the fields provided on the settings page
  - icc_gg_sign_in_openid_connect_settings            - modify settings values early in plugin bootstrap.
  - icc_gg_sign_in_openid_connect_login_button_text   - modify the login button text
  - icc_gg_sign_in_openid_connect_cookie_redirect_url - modify the redirect url stored as a cookie
  - icc_gg_sign_in_openid_connect_user_login_test     - (bool) should the user be logged in based on their claim
  - icc_gg_sign_in_openid_connect_user_creation_test  - (bool) should the user be created based on their claim
  - icc_gg_sign_in_openid_connect_auth_url            - modify the authentication url
  - icc_gg_sign_in_openid_connect_alter_user_claim    - modify the user_claim before a new user is created
  - icc_gg_sign_in_openid_connect_alter_user_data     - modify user data before a new user is created
  - icc_gg_sign_in_openid_connect_modify_token_response_before_validation - modify the token response before validation
  - icc_gg_sign_in_openid_connect_modify_id_token_claim_before_validation - modify the token claim before validation
  - icc_gg_sign_in_openid_connect_new_state_value     - modify the user's state value before it us saved.

  Actions
  - icc_gg_sign_in_openid_connect_user_create                     - 2 args: fires when a new user is created by this plugin
  - icc_gg_sign_in_openid_connect_user_update                     - 1 arg: user ID, fires when user is updated by this plugin
  - icc_gg_sign_in_openid_connect_update_user_using_current_claim - 2 args: fires every time an existing user logs in and the claims are updated.
  - icc_gg_sign_in_openid_connect_redirect_user_back              - 2 args: $redirect_url, $user. Allows interruption of redirect during login.
  - icc_gg_sign_in_openid_connect_user_logged_in                  - 1 arg: $user, fires when user is logged in.
  - icc_gg_sign_in_openid_connect_cron_daily                      - daily cron action
  - icc_gg_sign_in_openid_connect_state_not_found                 - the given state does not exist in the database, regardless of its expiration.
  - icc_gg_sign_in_openid_connect_state_expired                   - the given state exists, but expired before this login attempt.

  Callable actions

  User Meta (since v3.10.4 prefixed with the blog database prefix, for example wp_2_icc-gg-sign-in-openid-connect-subject-identity)
  - [[BLOG_DB_PREFIX]]icc-gg-sign-in-openid-connect-subject-identity    - the identity of the user provided by the idp
  - [[BLOG_DB_PREFIX]]icc-gg-sign-in-openid-connect-last-id-token-claim - the user's most recent id_token claim, decoded
  - [[BLOG_DB_PREFIX]]icc-gg-sign-in-openid-connect-last-user-claim     - the user's most recent user_claim
  - [[BLOG_DB_PREFIX]]icc-gg-sign-in-openid-connect-last-token-response - the user's most recent token response

  Options
  - icc_gg_sign_in_openid_connect_settings     - plugin settings
  - icc-gg-sign-in-openid-connect-valid-states - locally stored generated states
*/


/**
 * ICC_GG_Sign_In_OpenID_Connect class.
 *
 * Defines plugin initialization functionality.
 *
 * @package ICC_GG_Sign_In_OpenID_Connect
 * @category  General
 */
if ( ! class_exists( 'ICC_GG_Sign_In_OpenID_Connect' ) ) {
class ICC_GG_Sign_In_OpenID_Connect {

	/**
	 * Singleton instance of self
	 *
	 * @var ICC_GG_Sign_In_OpenID_Connect
	 */
	protected static $_instance = null;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	const VERSION = '3.1.5';

	/**
	 * Plugin settings.
	 *
	 * @var ICC_GG_Sign_In_OpenID_Connect_Option_Settings
	 */
	private $settings;

	/**
	 * Plugin logs.
	 *
	 * @var ICC_GG_Sign_In_OpenID_Connect_Option_Logger
	 */
	private $logger;

	/**
	 * OpenID Connect client
	 *
	 * @var ICC_GG_Sign_In_OpenID_Connect_Client
	 */
	private $client;

	/**
	 * Client wrapper.
	 *
	 * @var ICC_GG_Sign_In_OpenID_Connect_Client_Wrapper
	 */
	public $client_wrapper;

	/**
	 * Setup the plugin
	 *
	 * @param ICC_GG_Sign_In_OpenID_Connect_Option_Settings $settings The settings object.
	 * @param ICC_GG_Sign_In_OpenID_Connect_Option_Logger   $logger   The loggin object.
	 *
	 * @return void
	 */
	public function __construct( ICC_GG_Sign_In_OpenID_Connect_Option_Settings $settings, ICC_GG_Sign_In_OpenID_Connect_Option_Logger $logger ) {
		$this->settings = $settings;
		$this->logger = $logger;
		self::$_instance = $this;
	}

	// @codeCoverageIgnoreStart

	/**
	 * WordPress Hook 'init'.
	 *
	 * @return void
	 */
	public function init() {

		// Allow altering the settings.
		$this->settings = apply_filters( 'icc_gg_sign_in_openid_connect_settings', $this->settings );

		$this->client = new ICC_GG_Sign_In_OpenID_Connect_Client(
			$this->settings->client_id,
			$this->settings->client_secret,
			$this->settings->scope,
			$this->settings->endpoint_login,
			$this->settings->endpoint_userinfo,
			$this->settings->endpoint_token,
			$this->get_redirect_uri( $this->settings ),
			$this->settings->acr_values,
			$this->settings->endpoint_jwks,
			$this->settings->issuer ?? '',
			$this->settings->jwks_cache_ttl,
			$this->get_state_time_limit( $this->settings ),
			$this->settings->allow_internal_idp,
			$this->logger
		);

		$this->client_wrapper = ICC_GG_Sign_In_OpenID_Connect_Client_Wrapper::register( $this->client, $this->settings, $this->logger );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		ICC_GG_Sign_In_OpenID_Connect_Login_Form::register( $this->settings, $this->client_wrapper, $this->client );

		// Add a shortcode to get the auth URL.
		add_shortcode( 'icc_gg_sign_in_openid_connect_auth_url', array( $this, 'shortcode_auth_url' ) );

		// Add actions to our scheduled cron jobs.
		add_action( 'icc_gg_sign_in_openid_connect_cron_daily', array( $this, 'cron_states_garbage_collection' ) );

		$this->upgrade();

		if ( is_admin() ) {
			ICC_GG_Sign_In_OpenID_Connect_Settings_Page::register( $this->settings, $this->logger );
			add_action( 'admin_notices', array( $this, 'admin_notice_jwks_required' ) );
		}
	}

	/**
	 * Get the default redirect URI.
	 *
	 * @param ICC_GG_Sign_In_OpenID_Connect_Option_Settings $settings The settings object.
	 *
	 * @return string
	 */
	public function get_redirect_uri( ICC_GG_Sign_In_OpenID_Connect_Option_Settings $settings ) {
		$redirect_uri = admin_url( 'admin-ajax.php?action=icc-gg-sign-in-openid-connect-authorize' );

		if ( $settings->alternate_redirect_uri ) {
			$redirect_uri = site_url( '/icc-gg-sign-in-openid-connect-authorize' );
		}

		return $redirect_uri;
	}

	/**
	 * Get the default state time limit.
	 *
	 * @param ICC_GG_Sign_In_OpenID_Connect_Option_Settings $settings The settings object.
	 *
	 * @return int
	 */
	public function get_state_time_limit( ICC_GG_Sign_In_OpenID_Connect_Option_Settings $settings ) {
		$state_time_limit = 180;
		// State time limit cannot be zero.
		if ( $settings->state_time_limit ) {
			$state_time_limit = intval( $settings->state_time_limit );
		}

		return $state_time_limit;
	}

	/**
	 * Shortcode callback for [icc_gg_sign_in_openid_connect_auth_url].
	 *
	 * Returns the authentication URL with proper output escaping.
	 *
	 * @return string
	 */
	public function shortcode_auth_url() {
		return esc_url( $this->client_wrapper->get_authentication_url() );
	}

	/**
	 * Check if privacy enforcement is enabled, and redirect users that aren't
	 * logged in.
	 *
	 * @return void
	 */
	public function enforce_privacy_redirect() {
		if ( $this->settings->enforce_privacy && ! is_user_logged_in() ) {
			// The client endpoint relies on the wp-admin ajax endpoint.
			if (
				! defined( 'DOING_AJAX' ) ||
				! boolval( constant( 'DOING_AJAX' ) ) ||
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OIDC callback from IDP; nonces not applicable.
				! isset( $_GET['action'] ) ||
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OIDC callback from IDP; nonces not applicable.
				'icc-gg-sign-in-openid-connect-authorize' != $_GET['action'] ) {
				auth_redirect();
			}
		}
	}

	/**
	 * Enforce privacy settings for rss feeds.
	 *
	 * @param string $content The content.
	 *
	 * @return mixed
	 */
	public function enforce_privacy_feeds( $content ) {
		if ( $this->settings->enforce_privacy && ! is_user_logged_in() ) {
			$content = __( 'Private site', 'icc-gg-sign-in-openid-connect' );
		}
		return $content;
	}

	/**
	 * Display admin notice when JWKS endpoint is not configured.
	 *
	 * @return void
	 */
	public function admin_notice_jwks_required() {
		// Only show to users who can manage options.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Check if JWKS endpoint is configured.
		if ( ! empty( $this->settings->endpoint_jwks ) ) {
			return;
		}

		// Check if any OIDC endpoints are configured (plugin is actually being used).
		if ( empty( $this->settings->endpoint_login ) ) {
			return;
		}

		$settings_url = admin_url( 'options-general.php?page=icc-gg-sign-in-openid-connect-settings' );
		?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'ICC.gg Sign-In for OpenID Connect - Security Configuration Required', 'icc-gg-sign-in-openid-connect' ); ?></strong>
			</p>
			<p>
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: %s is a link to the settings page */
						__( 'Your OpenID Connect authentication is using an insecure fallback method. You must configure the <strong>JWKS endpoint</strong> in <a href="%s">plugin settings</a> as soon as possible.', 'icc-gg-sign-in-openid-connect' ),
						esc_url( $settings_url )
					)
				);
				?>
			</p>
			<p>
				<?php esc_html_e( 'The current insecure fallback will be removed in version 3.12.0. After that update, authentication will fail until the JWKS endpoint is configured.', 'icc-gg-sign-in-openid-connect' ); ?>
			</p>
			<p>
				<strong><?php esc_html_e( 'Common JWKS endpoints:', 'icc-gg-sign-in-openid-connect' ); ?></strong><br>
				• Keycloak: <code>https://your-domain/realms/your-realm/protocol/openid-connect/certs</code><br>
				• Auth0: <code>https://your-domain.auth0.com/.well-known/jwks.json</code><br>
				• Okta: <code>https://your-domain.okta.com/oauth2/default/v1/keys</code><br>
				• Azure AD: <code>https://login.microsoftonline.com/your-tenant/discovery/v2.0/keys</code><br>
				• Google: <code>https://www.googleapis.com/oauth2/v3/certs</code>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle plugin upgrades
	 *
	 * @return void
	 */
	public function upgrade() {
		$last_version = get_option( 'icc-gg-sign-in-openid-connect-plugin-version', 0 );
		$settings = $this->settings;

		if ( version_compare( self::VERSION, $last_version, '>' ) ) {
			// An upgrade is required.
			self::setup_cron_jobs();

			// @todo move this to another file for upgrade scripts
			if ( isset( $settings->ep_login ) ) {
				$settings->endpoint_login = $settings->ep_login;
				$settings->endpoint_token = $settings->ep_token;
				$settings->endpoint_userinfo = $settings->ep_userinfo;

				unset( $settings->ep_login, $settings->ep_token, $settings->ep_userinfo );
				$settings->save();
			}

			// Update the stored version number.
			update_option( 'icc-gg-sign-in-openid-connect-plugin-version', self::VERSION );
		}
	}

	/**
	 * Expire state transients by attempting to access them and allowing the
	 * transient's own mechanisms to delete any that have expired.
	 *
	 * @return void
	 */
	public function cron_states_garbage_collection() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Transient garbage collection requires direct option table scan; caching not appropriate.
		$states = $wpdb->get_col( $wpdb->prepare( "SELECT `option_name` FROM {$wpdb->options} WHERE `option_name` LIKE %s", '_transient_icc-gg-sign-in-openid-connect-state--%' ) );

		if ( ! empty( $states ) ) {
			foreach ( $states as $state ) {
				$transient = str_replace( '_transient_', '', $state );
				get_transient( $transient );
			}
		}
	}

	/**
	 * Ensure cron jobs are added to the schedule.
	 *
	 * @return void
	 */
	public static function setup_cron_jobs() {
		if ( ! wp_next_scheduled( 'icc_gg_sign_in_openid_connect_cron_daily' ) ) {
			wp_schedule_event( time(), 'daily', 'icc_gg_sign_in_openid_connect_cron_daily' );
		}
	}

	/**
	 * Activation hook.
	 *
	 * @return void
	 */
	public static function activation() {
		self::setup_cron_jobs();
	}

	/**
	 * Deactivation hook.
	 *
	 * @return void
	 */
	public static function deactivation() {
		wp_clear_scheduled_hook( 'icc_gg_sign_in_openid_connect_cron_daily' );
	}

	/**
	 * Simple autoloader.
	 *
	 * @param string $class The class name.
	 *
	 * @return void
	 */
	public static function autoload( $class ) {
		$prefix = 'ICC_GG_Sign_In_OpenID_Connect_';

		if ( stripos( $class, $prefix ) !== 0 ) {
			return;
		}

		$filename = $class . '.php';

		// Internal files are all lowercase and use dashes in filenames.
		if ( false === strpos( $filename, '\\' ) ) {
			$filename = strtolower( str_replace( '_', '-', $filename ) );
		} else {
			$filename  = str_replace( '\\', DIRECTORY_SEPARATOR, $filename );
		}

		$filepath = __DIR__ . '/includes/' . $filename;

		if ( file_exists( $filepath ) ) {
			require_once $filepath;
		}
	}

	/**
	 * Instantiate the plugin and hook into WordPress.
	 *
	 * @return void
	 */
	public static function bootstrap() {
		require_once __DIR__ . '/vendor/autoload.php';

		// Register the plugin's custom autoloader before instantiating classes.
		spl_autoload_register( array( __CLASS__, 'autoload' ) );

		$settings = new ICC_GG_Sign_In_OpenID_Connect_Option_Settings(
			// Default settings values.
			array(
				// OAuth client settings.
				'login_type'           => defined( 'OIDC_LOGIN_TYPE' ) ? OIDC_LOGIN_TYPE : 'button',
				'login_button_text'    => '',
				'client_id'            => defined( 'OIDC_CLIENT_ID' ) ? OIDC_CLIENT_ID : '',
				'client_secret'        => defined( 'OIDC_CLIENT_SECRET' ) ? OIDC_CLIENT_SECRET : '',
				'scope'                => defined( 'OIDC_CLIENT_SCOPE' ) ? OIDC_CLIENT_SCOPE : '',
				'endpoint_login'       => defined( 'OIDC_ENDPOINT_LOGIN_URL' ) ? OIDC_ENDPOINT_LOGIN_URL : '',
				'endpoint_userinfo'    => defined( 'OIDC_ENDPOINT_USERINFO_URL' ) ? OIDC_ENDPOINT_USERINFO_URL : '',
				'endpoint_token'       => defined( 'OIDC_ENDPOINT_TOKEN_URL' ) ? OIDC_ENDPOINT_TOKEN_URL : '',
				'endpoint_end_session' => defined( 'OIDC_ENDPOINT_LOGOUT_URL' ) ? OIDC_ENDPOINT_LOGOUT_URL : '',
				'endpoint_jwks'        => defined( 'OIDC_ENDPOINT_JWKS_URL' ) ? OIDC_ENDPOINT_JWKS_URL : '',
				'jwks_cache_ttl'       => 3600,
				'acr_values'           => defined( 'OIDC_ACR_VALUES' ) ? OIDC_ACR_VALUES : '',

				// Non-standard settings.
				'no_sslverify'           => 0,
				'http_request_timeout'   => 5,
				'allow_internal_idp'     => 0,
				'identity_key'           => 'preferred_username',
				'nickname_key'           => 'preferred_username',
				'email_format'           => '{email}',
				'displayname_format'     => '',
				'identify_with_username' => false,
				'state_time_limit'       => 180,

				// Plugin settings.
				'enforce_privacy'          => defined( 'OIDC_ENFORCE_PRIVACY' ) ? intval( OIDC_ENFORCE_PRIVACY ) : 0,
				'alternate_redirect_uri'   => 0,
				'token_refresh_enable'     => 1,
				'link_existing_users'      => defined( 'OIDC_LINK_EXISTING_USERS' ) ? intval( OIDC_LINK_EXISTING_USERS ) : 0,
				'create_if_does_not_exist' => defined( 'OIDC_CREATE_IF_DOES_NOT_EXIST' ) ? intval( OIDC_CREATE_IF_DOES_NOT_EXIST ) : 1,
				'email_domain_restriction' => defined( 'OIDC_EMAIL_DOMAIN_RESTRICTION' ) ? OIDC_EMAIL_DOMAIN_RESTRICTION : '',
				'redirect_user_back'       => defined( 'OIDC_REDIRECT_USER_BACK' ) ? intval( OIDC_REDIRECT_USER_BACK ) : 0,
				'redirect_on_logout'       => defined( 'OIDC_REDIRECT_ON_LOGOUT' ) ? intval( OIDC_REDIRECT_ON_LOGOUT ) : 1,
				'enable_logging'           => defined( 'OIDC_ENABLE_LOGGING' ) ? intval( OIDC_ENABLE_LOGGING ) : 0,
				'log_limit'                => defined( 'OIDC_LOG_LIMIT' ) ? intval( OIDC_LOG_LIMIT ) : 1000,
			)
		);

		$logger = new ICC_GG_Sign_In_OpenID_Connect_Option_Logger( 'error', $settings->enable_logging, $settings->log_limit );

		$plugin = new self( $settings, $logger );

		add_action( 'init', array( $plugin, 'init' ) );

		// Privacy hooks.
		add_action( 'template_redirect', array( $plugin, 'enforce_privacy_redirect' ), 0 );
		add_filter( 'the_content_feed', array( $plugin, 'enforce_privacy_feeds' ), 999 );
		add_filter( 'the_excerpt_rss', array( $plugin, 'enforce_privacy_feeds' ), 999 );
		add_filter( 'comment_text_rss', array( $plugin, 'enforce_privacy_feeds' ), 999 );
	}

	/**
	 * Create (if needed) and return a singleton of self.
	 *
	 * @return ICC_GG_Sign_In_OpenID_Connect
	 */
	public static function instance() {
		if ( null === self::$_instance ) {
			self::bootstrap();
		}
		return self::$_instance;
	}
}
ICC_GG_Sign_In_OpenID_Connect::instance();

register_activation_hook( __FILE__, array( 'ICC_GG_Sign_In_OpenID_Connect', 'activation' ) );
register_deactivation_hook( __FILE__, array( 'ICC_GG_Sign_In_OpenID_Connect', 'deactivation' ) );

// Provide publicly accessible plugin helper functions.
require_once 'includes/functions.php';

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'icc_gg_sign_in_openid_connect_add_settings_link' );
add_filter( 'plugin_row_meta', 'icc_gg_sign_in_openid_connect_add_plugin_row_meta', 10, 2 );

/**
 * Initialize the GitHub updater.
 *
 * Runs on `init` and also during the WordPress cron job so the plugin can be
 * updated automatically, mirroring the updater bootstrap used by plugins that
 * rely on a custom update server.
 *
 * @return void
 */
function icc_gg_sign_in_openid_connect_init_github_updater() {
	$doing_cron = defined( 'DOING_CRON' ) && DOING_CRON;
	if ( ! current_user_can( 'manage_options' ) && ! $doing_cron ) {
		return;
	}

	new ICC_GG_Sign_In_OpenID_Connect_Github_Updater(
		plugin_basename( __FILE__ ),
		ICC_GG_Sign_In_OpenID_Connect::VERSION
	);
}
add_action( 'init', 'icc_gg_sign_in_openid_connect_init_github_updater' );

} // End if ( ! class_exists( 'ICC_GG_Sign_In_OpenID_Connect' ) )
