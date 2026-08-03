<?php
/**
 * Login form and login button handling class.
 *
 * @package   ICC_OpenID_Client
 * @category  Login
 * @author    Jonathan Daggerhart <jonathan@daggerhart.com>
 * @copyright 2015-2020 daggerhart
 * @license   http://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */

/**
 * ICC_OpenID_Client_Login_Form class.
 *
 * Login form and login button handling.
 *
 * @package ICC_OpenID_Client
 * @category  Login
 */
class ICC_OpenID_Client_Login_Form {

	/**
	 * Plugin settings object.
	 *
	 * @var ICC_OpenID_Client_Option_Settings
	 */
	private $settings;

	/**
	 * Plugin client wrapper instance.
	 *
	 * @var ICC_OpenID_Client_Client_Wrapper
	 */
	private $client_wrapper;

	/**
	 * The client object instance.
	 *
	 * @var ICC_OpenID_Client_Client
	 */
	private $client;

	/**
	 * The class constructor.
	 *
	 * @param ICC_OpenID_Client_Option_Settings $settings       A plugin settings object instance.
	 * @param ICC_OpenID_Client_Client_Wrapper  $client_wrapper A plugin client wrapper object instance.
	 * @param ICC_OpenID_Client_Client          $client         A plugin client object instance.
	 */
	public function __construct( $settings, $client_wrapper, $client ) {
		$this->settings = $settings;
		$this->client_wrapper = $client_wrapper;
		$this->client = $client;
	}

	/**
	 * Create an instance of the ICC_OpenID_Client_Login_Form class.
	 *
	 * @param ICC_OpenID_Client_Option_Settings $settings       A plugin settings object instance.
	 * @param ICC_OpenID_Client_Client_Wrapper  $client_wrapper A plugin client wrapper object instance.
	 * @param ICC_OpenID_Client_Client          $client         A plugin client object instance.
	 *
	 * @return void
	 */
	public static function register( $settings, $client_wrapper, $client ) {
		$login_form = new self( $settings, $client_wrapper, $client );

		// Alter the login form as dictated by settings.
		add_filter( 'login_message', array( $login_form, 'handle_login_page' ), 99 );

		// Add a shortcode for the login button.
		add_shortcode( 'icc_openid_client_login_button', array( $login_form, 'make_login_button' ) );

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
				add_action( 'login_footer', array( $this, 'remove_login_form' ), 99 );
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
			$error_message = ! empty( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : 'Unknown error.';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Error display on login form; nonces not applicable.
			$message .= $this->make_error_output( sanitize_text_field( wp_unslash( $_GET['login-error'] ) ), $error_message );
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
			<strong><?php printf( esc_html__( 'ERROR (%1$s)', 'icc-openid-client' ), esc_html( $error_code ) ); ?>: </strong>
			<?php print esc_html( $error_message ); ?>
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
			: __( 'Login with OpenID Connect', 'icc-openid-client' );

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
			'icc_openid_client_login_button'
		);

		$text = apply_filters( 'icc-openid-client-login-button-text', $atts['button_text'] );
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
		$href = esc_url_raw( $href );

		$login_button = sprintf(
			'<div class="icc-openid-login-button" style="margin: 1em 0; text-align: center;"><a class="button button-large" href="%1$s">%2$s</a></div>',
			$href,
			$text
		);

		return $login_button;
	}

	/**
	 * Removes the login form from the HTML DOM
	 *
	 * @return void
	 */
	public function remove_login_form() {
		?>
		<script type="text/javascript">
			(function() {
				var loginForm = document.getElementById("user_login").form;
				var parent = loginForm.parentNode;
				parent.removeChild(loginForm);
			})();
		</script>
		<?php
	}
}
