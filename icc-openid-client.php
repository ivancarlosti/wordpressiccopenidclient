<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * ICC OpenID Client - WordPress OpenID Connect SSO Plugin
 *
 * This plugin provides the ability to authenticate users with Identity
 * Providers using the OpenID Connect OAuth2 API with Authorization Code Flow.
 *
 * @package   ICC_OpenID_Client
 * @category  General
 * @author    Ivan Carlos
 * @copyright 2023-2025 Ivan Carlos
 * @license   http://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 * @link      https://github.com/ivancarlosti/wordpressiccopenidclient
 *
 * @wordpress-plugin
 * Plugin Name:       ICC OpenID Client
 * Plugin URI:        https://github.com/ivancarlosti/wordpressiccopenidclient
 * Description:       Connect to an OpenID Connect identity provider using Authorization Code Flow. Features email domain restriction and SSO.
 * Version:           3.1.3
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * Author:            ivancarlosti
 * Author URI:        https://ivancarlos.me
 * Text Domain:       icc-openid-client
 * Domain Path:       /languages
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * GitHub Plugin URI: https://github.com/ivancarlosti/wordpressiccopenidclient
 */

/*
Notes
  Spec Doc - http://openid.net/specs/openid-connect-basic-1_0-32.html

  Filters
  - icc-openid-client-alter-request       - 3 args: request array, plugin settings, specific request op
  - icc-openid-client-settings-fields     - modify the fields provided on the settings page
  - icc-openid-client-settings            - modify settings values early in plugin bootstrap.
  - icc-openid-client-login-button-text   - modify the login button text
  - icc-openid-client-cookie-redirect-url - modify the redirect url stored as a cookie
  - icc-openid-client-user-login-test     - (bool) should the user be logged in based on their claim
  - icc-openid-client-user-creation-test  - (bool) should the user be created based on their claim
  - icc-openid-client-auth-url            - modify the authentication url
  - icc-openid-client-alter-user-claim    - modify the user_claim before a new user is created
  - icc-openid-client-alter-user-data     - modify user data before a new user is created
  - openid-connect-modify-token-response-before-validation - modify the token response before validation
  - openid-connect-modify-id-token-claim-before-validation - modify the token claim before validation
  - icc-openid-client-new-state-value     - modify the user's state value before it us saved.

  Actions
  - icc-openid-client-user-create                     - 2 args: fires when a new user is created by this plugin
  - icc-openid-client-user-update                     - 1 arg: user ID, fires when user is updated by this plugin
  - icc-openid-client-update-user-using-current-claim - 2 args: fires every time an existing user logs in and the claims are updated.
  - icc-openid-client-redirect-user-back              - 2 args: $redirect_url, $user. Allows interruption of redirect during login.
  - icc-openid-client-user-logged-in                  - 1 arg: $user, fires when user is logged in.
  - icc-openid-client-cron-daily                      - daily cron action
  - icc-openid-client-state-not-found                 - the given state does not exist in the database, regardless of its expiration.
  - icc-openid-client-state-expired                   - the given state exists, but expired before this login attempt.

  Callable actions

  User Meta (since v3.10.4 prefixed with the blog database prefix, for example wp_2_icc-openid-client-subject-identity)
  - [[BLOG_DB_PREFIX]]icc-openid-client-subject-identity    - the identity of the user provided by the idp
  - [[BLOG_DB_PREFIX]]icc-openid-client-last-id-token-claim - the user's most recent id_token claim, decoded
  - [[BLOG_DB_PREFIX]]icc-openid-client-last-user-claim     - the user's most recent user_claim
  - [[BLOG_DB_PREFIX]]icc-openid-client-last-token-response - the user's most recent token response

  Options
  - icc_openid_client_settings     - plugin settings
  - icc-openid-client-valid-states - locally stored generated states
*/


/**
 * ICC_OpenID_Client class.
 *
 * Defines plugin initialization functionality.
 *
 * @package ICC_OpenID_Client
 * @category  General
 */
if ( ! class_exists( 'ICC_OpenID_Client' ) ) {
class ICC_OpenID_Client {

	/**
	 * Singleton instance of self
	 *
	 * @var ICC_OpenID_Client
	 */
	protected static $_instance = null;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	const VERSION = '3.1.3';

	/**
	 * Plugin settings.
	 *
	 * @var ICC_OpenID_Client_Option_Settings
	 */
	private $settings;

	/**
	 * Plugin logs.
	 *
	 * @var ICC_OpenID_Client_Option_Logger
	 */
	private $logger;

	/**
	 * Openid Connect Generic client
	 *
	 * @var ICC_OpenID_Client_Client
	 */
	private $client;

	/**
	 * Client wrapper.
	 *
	 * @var ICC_OpenID_Client_Client_Wrapper
	 */
	public $client_wrapper;

	/**
	 * Setup the plugin
	 *
	 * @param ICC_OpenID_Client_Option_Settings $settings The settings object.
	 * @param ICC_OpenID_Client_Option_Logger   $logger   The loggin object.
	 *
	 * @return void
	 */
	public function __construct( ICC_OpenID_Client_Option_Settings $settings, ICC_OpenID_Client_Option_Logger $logger ) {
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
		$this->settings = apply_filters( 'icc-openid-client-settings', $this->settings );

		$this->client = new ICC_OpenID_Client_Client(
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

		$this->client_wrapper = ICC_OpenID_Client_Client_Wrapper::register( $this->client, $this->settings, $this->logger );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		ICC_OpenID_Client_Login_Form::register( $this->settings, $this->client_wrapper, $this->client );

		// Add a shortcode to get the auth URL.
		add_shortcode( 'icc_openid_client_auth_url', array( $this->client_wrapper, 'get_authentication_url' ) );

		// Add actions to our scheduled cron jobs.
		add_action( 'icc-openid-client-cron-daily', array( $this, 'cron_states_garbage_collection' ) );

		$this->upgrade();

		if ( is_admin() ) {
			ICC_OpenID_Client_Settings_Page::register( $this->settings, $this->logger );
			add_action( 'admin_notices', array( $this, 'admin_notice_jwks_required' ) );
		}
	}

	/**
	 * Get the default redirect URI.
	 *
	 * @param ICC_OpenID_Client_Option_Settings $settings The settings object.
	 *
	 * @return string
	 */
	public function get_redirect_uri( ICC_OpenID_Client_Option_Settings $settings ) {
		$redirect_uri = admin_url( 'admin-ajax.php?action=icc-openid-client-authorize' );

		if ( $settings->alternate_redirect_uri ) {
			$redirect_uri = site_url( '/icc-openid-client-authorize' );
		}

		return $redirect_uri;
	}

	/**
	 * Get the default state time limit.
	 *
	 * @param ICC_OpenID_Client_Option_Settings $settings The settings object.
	 *
	 * @return int
	 */
	public function get_state_time_limit( ICC_OpenID_Client_Option_Settings $settings ) {
		$state_time_limit = 180;
		// State time limit cannot be zero.
		if ( $settings->state_time_limit ) {
			$state_time_limit = intval( $settings->state_time_limit );
		}

		return $state_time_limit;
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
				! isset( $_GET['action'] ) ||
				'icc-openid-client-authorize' != $_GET['action'] ) {
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
			$content = __( 'Private site', 'icc-openid-client' );
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

		$settings_url = admin_url( 'options-general.php?page=icc-openid-client-settings' );
		?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'ICC OpenID Client - Security Configuration Required', 'icc-openid-client' ); ?></strong>
			</p>
			<p>
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: %s is a link to the settings page */
						__( 'Your OpenID Connect authentication is using an insecure fallback method. You must configure the <strong>JWKS endpoint</strong> in <a href="%s">plugin settings</a> as soon as possible.', 'icc-openid-client' ),
						esc_url( $settings_url )
					)
				);
				?>
			</p>
			<p>
				<?php esc_html_e( 'The current insecure fallback will be removed in version 3.12.0. After that update, authentication will fail until the JWKS endpoint is configured.', 'icc-openid-client' ); ?>
			</p>
			<p>
				<strong><?php esc_html_e( 'Common JWKS endpoints:', 'icc-openid-client' ); ?></strong><br>
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
		$last_version = get_option( 'icc-openid-client-plugin-version', 0 );
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
			update_option( 'icc-openid-client-plugin-version', self::VERSION );
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
		$states = $wpdb->get_col( $wpdb->prepare( "SELECT `option_name` FROM {$wpdb->options} WHERE `option_name` LIKE %s", '_transient_icc-openid-client-state--%' ) );

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
		if ( ! wp_next_scheduled( 'icc-openid-client-cron-daily' ) ) {
			wp_schedule_event( time(), 'daily', 'icc-openid-client-cron-daily' );
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
		wp_clear_scheduled_hook( 'icc-openid-client-cron-daily' );
	}

	/**
	 * Simple autoloader.
	 *
	 * @param string $class The class name.
	 *
	 * @return void
	 */
	public static function autoload( $class ) {
		$prefix = 'ICC_OpenID_Client_';

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

		$settings = new ICC_OpenID_Client_Option_Settings(
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

		$logger = new ICC_OpenID_Client_Option_Logger( 'error', $settings->enable_logging, $settings->log_limit );

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
	 * @return ICC_OpenID_Client
	 */
	public static function instance() {
		if ( null === self::$_instance ) {
			self::bootstrap();
		}
		return self::$_instance;
	}
}
ICC_OpenID_Client::instance();

register_activation_hook( __FILE__, array( 'ICC_OpenID_Client', 'activation' ) );
register_deactivation_hook( __FILE__, array( 'ICC_OpenID_Client', 'deactivation' ) );

// Provide publicly accessible plugin helper functions.
require_once 'includes/functions.php';

} // End if ( ! class_exists( 'ICC_OpenID_Client' ) )
