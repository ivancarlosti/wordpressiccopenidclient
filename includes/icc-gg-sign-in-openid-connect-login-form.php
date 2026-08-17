<?php
/**
 * Login form and login button handling class.
 *
 * @package   ICC_GG_Sign_In_OpenID_Connect
 * @category  Login
 * @author    Ivan Carlos
 * @copyright 2007-2026 Ivan Carlos Consultoria
 * @license   http://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */

/**
 * ICC_GG_Sign_In_OpenID_Connect_Login_Form class.
 *
 * Login form and login button handling.
 *
 * @package ICC_GG_Sign_In_OpenID_Connect
 * @category  Login
 */
class ICC_GG_Sign_In_OpenID_Connect_Login_Form {

	/**
	 * Plugin settings object.
	 *
	 * @var ICC_GG_Sign_In_OpenID_Connect_Option_Settings
	 */
	private $settings;

	/**
	 * Plugin client wrapper instance.
	 *
	 * @var ICC_GG_Sign_In_OpenID_Connect_Client_Wrapper
	 */
	private $client_wrapper;

	/**
	 * The client object instance.
	 *
	 * @var ICC_GG_Sign_In_OpenID_Connect_Client
	 */
	private $client;

	/**
	 * Whether the login form should be removed from the DOM.
	 *
	 * Set when login-error is present in the URL during auto-login mode.
	 *
	 * @var bool
	 */
	private $should_remove_form = false;

	/**
	 * The class constructor.
	 *
	 * @param ICC_GG_Sign_In_OpenID_Connect_Option_Settings $settings       A plugin settings object instance.
	 * @param ICC_GG_Sign_In_OpenID_Connect_Client_Wrapper  $client_wrapper A plugin client wrapper object instance.
	 * @param ICC_GG_Sign_In_OpenID_Connect_Client          $client         A plugin client object instance.
	 */
	public function __construct( $settings, $client_wrapper, $client ) {
		$this->settings = $settings;
		$this->client_wrapper = $client_wrapper;
		$this->client = $client;
	}

	/**
	 * Create an instance of the ICC_GG_Sign_In_OpenID_Connect_Login_Form class.
	 *
	 * @param ICC_GG_Sign_In_OpenID_Connect_Option_Settings $settings       A plugin settings object instance.
	 * @param ICC_GG_Sign_In_OpenID_Connect_Client_Wrapper  $client_wrapper A plugin client wrapper object instance.
	 * @param ICC_GG_Sign_In_OpenID_Connect_Client          $client         A plugin client object instance.
	 *
	 * @return void
	 */
	public static function register( $settings, $client_wrapper, $client ) {
		$login_form = new self( $settings, $client_wrapper, $client );

		// Alter the login form as dictated by settings.
		add_filter( 'login_message', array( $login_form, 'handle_login_page' ), 99 );

		// Add a shortcode for the login button.
		add_shortcode( 'icc_gg_sign_in_openid_connect_login_button', array( $login_form, 'make_login_button' ) );

		// Enqueue scripts for the login page.
		add_action( 'login_enqueue_scripts', array( $login_form, 'enqueue_login_scripts' ) );

		$login_form->handle_redirect_login_type_auto();
	}

	/**
	 * Auto Login redirect.
	 *
	 * @return void
	 */
	public function handle_redirect_login_type_auto() {

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth login flow; nonces not applicable.
		if ( 'wp-login.php' == $GLOBALS['pagenow']
			&& ( 'auto' == $this->settings->login_type || ! empty( $_GET['force_redirect'] ) )
			// Don't send users to the IDP on logout or post password protected authentication.
			&& ( ! isset( $_GET['action'] ) || ! in_array( $_GET['action'], array( 'logout', 'postpass' ) ) )
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WP Login Form doesn't have a nonce.
			&& ! isset( $_POST['wp-submit'] ) ) {
			if ( ! isset( $_GET['login-error'] ) ) {
				wp_redirect( $this->client_wrapper->get_authentication_url() ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Redirect to external IDP authentication URL.
				exit;
			} else {
				$this->should_remove_form = true;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Implements filter login_message.
	 *
	 * @param string $message The text message to display on the login page.
	 *
	 * @return string
	 */
	public function handle_login_page( $message ) {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Error display on login form; nonces not applicable.
		if ( isset( $_GET['login-error'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Error display on login form; nonces not applicable.
			$error_code = sanitize_text_field( wp_unslash( $_GET['login-error'] ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Error display on login form; nonces not applicable.
			$error_message = ! empty( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : __( 'Unknown error.', 'icc-gg-sign-in-openid-connect' );
			$message .= $this->make_error_output( $error_code, $error_message );

			// If the user is already logged in with another account at the IDP,
			// or the email domain is not allowed, provide a link to logout from
			// the IDP first so they can try with a different account.
			if ( in_array( $error_code, array( 'cannot-authorize', 'email-domain-not-allowed' ), true ) && ! empty( $this->settings->endpoint_end_session ) ) {
				$message .= $this->make_idp_logout_link();
			}
		}

		// Login button is appended to existing messages in case of error.
		$message .= $this->make_login_button();

		return $message;
	}

	/**
	 * Display an error message to the user.
	 *
	 * @param string $error_code    The error code.
	 * @param string $error_message The error message test.
	 *
	 * @return string
	 */
	public function make_error_output( $error_code, $error_message ) {

		ob_start();
		?>
		<div id="login_error"><?php // translators: %1$s is the error code from the IDP. ?>
			<strong><?php printf( esc_html__( 'ERROR (%1$s)', 'icc-gg-sign-in-openid-connect' ), esc_html( $error_code ) ); ?>: </strong>
			<?php print esc_html( $error_message ); ?>
		</div>
		<?php
		return wp_kses_post( ob_get_clean() );
	}

	/**
	 * Display a link to logout from the Identity Provider.
	 *
	 * Used when the user is already logged in with a different account
	 * at the IDP and needs to logout before trying again.
	 *
	 * @return string
	 */
	public function make_idp_logout_link() {

		$end_session_url = $this->settings->endpoint_end_session;
		$separator = ( false === strpos( $end_session_url, '?' ) ) ? '?' : '&';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Error display on login form; nonces not applicable.
		if ( ! empty( $_GET['idp-logout-id'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Error display on login form; nonces not applicable.
			$transient_key = sanitize_text_field( wp_unslash( $_GET['idp-logout-id'] ) );
			$id_token = get_transient( 'icc-gg-sign-in-openid-connect-idp-logout--' . $transient_key );
			if ( ! empty( $id_token ) ) {
				$logout_url = $end_session_url . $separator . 'id_token_hint=' . urlencode( $id_token ) . '&post_logout_redirect_uri=' . urlencode( wp_login_url() );
			} else {
				$logout_url = $end_session_url . $separator . 'post_logout_redirect_uri=' . urlencode( wp_login_url() );
			}
		} else {
			$logout_url = $end_session_url . $separator . 'post_logout_redirect_uri=' . urlencode( wp_login_url() );
		}

		ob_start();
		?>
		<div class="message" style="margin-top: 10px;">
			<p>
				<?php esc_html_e( 'You may be logged in with a different account on the identity provider. Please logout from the identity provider first, then try again.', 'icc-gg-sign-in-openid-connect' ); ?>
			</p>
			<p style="text-align: center;">
				<a href="<?php echo esc_url( $logout_url ); ?>" class="button">
					<?php esc_html_e( 'Logout from Identity Provider', 'icc-gg-sign-in-openid-connect' ); ?>
				</a>
			</p>
		</div>
		<?php
		return wp_kses_post( ob_get_clean() );
	}

	/**
	 * Create a login button (link).
	 *
	 * @param array $atts Array of optional attributes to override login buton
	 * functionality when used by shortcode.
	 *
	 * @return string
	 */
	public function make_login_button( $atts = array() ) {

		// Use admin-configured button text, or fall back to default.
		$default_button_text = ! empty( trim( $this->settings->login_button_text ?? '' ) )
			? $this->settings->login_button_text
			: __( 'Login with OpenID Connect', 'icc-gg-sign-in-openid-connect' );

		$atts = shortcode_atts(
			array(
				'button_text' => $default_button_text,
				'endpoint_login' => $this->settings->endpoint_login,
				'scope' => $this->settings->scope,
				'client_id' => $this->settings->client_id,
				'redirect_uri' => $this->client->get_redirect_uri(),
				'redirect_to' => $this->client_wrapper->get_redirect_to(),
				'acr_values' => $this->settings->acr_values,
			),
			$atts,
			'icc_gg_sign_in_openid_connect_login_button'
		);

		$text = apply_filters( 'icc_gg_sign_in_openid_connect_login_button_text', $atts['button_text'] );
		$text = esc_html( $text );

		$href = $this->client_wrapper->get_authentication_url(
			array(
				'endpoint_login' => $atts['endpoint_login'],
				'scope' => $atts['scope'],
				'client_id' => $atts['client_id'],
				'redirect_uri' => $atts['redirect_uri'],
				'redirect_to' => $atts['redirect_to'],
				'acr_values' => $atts['acr_values'],
			)
		);
		$href = esc_url( $href );

		$login_button = sprintf(
			'<div class="icc-gg-sign-in-openid-connect-login-button" style="margin: 1em 0; text-align: center;"><a class="button button-large" href="%1$s">%2$s</a></div>',
			$href,
			$text
		);

		return $login_button;
	}

	/**
	 * Enqueue inline script to remove the login form from the DOM.
	 *
	 * Only enqueues the script when login-error is present during auto-login mode.
	 *
	 * @return void
	 */
	public function enqueue_login_scripts() {
		if ( ! $this->should_remove_form ) {
			return;
		}

		wp_register_script( 'icc-gg-sign-in-openid-connect-login-form', false, array(), ICC_GG_Sign_In_OpenID_Connect::VERSION, true );
		wp_enqueue_script( 'icc-gg-sign-in-openid-connect-login-form' );
		wp_add_inline_script(
			'icc-gg-sign-in-openid-connect-login-form',
			'(function(){var f=document.getElementById("user_login").form;f.parentNode.removeChild(f);})();'
		);
	}
}
