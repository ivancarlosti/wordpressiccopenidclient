<?php
/**
 * Two-factor authentication bypass compatibility class.
 *
 * @package   ICC_GG_Sign_In_OpenID_Connect
 * @category  Authentication
 * @author    Ivan Carlos
 * @copyright 2007-2026 Ivan Carlos Consultoria
 * @license   http://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */

/**
 * ICC_GG_Sign_In_OpenID_Connect_Two_Factor_Bypass class.
 *
 * Allows site administrators to skip the second-factor prompt of supported
 * 2FA plugins when a user has just authenticated through OpenID Connect.
 *
 * The bypass works by temporarily removing the selected plugin's callbacks
 * from the WordPress login hooks (wp_login, authenticate, login_redirect)
 * immediately before the SSO session is created, then restoring them right
 * after the login completes.
 *
 * @package  ICC_GG_Sign_In_OpenID_Connect
 * @category Authentication
 */
class ICC_GG_Sign_In_OpenID_Connect_Two_Factor_Bypass {

	/**
	 * Plugin settings object instance.
	 *
	 * @var ICC_GG_Sign_In_OpenID_Connect_Option_Settings
	 */
	private static $settings;

	/**
	 * Stack of callbacks removed from WordPress hooks so they can be restored.
	 *
	 * @var array<int, array<string,mixed>>
	 */
	private static $removed = array();

	/**
	 * Hook the bypass into the plugin's login flow.
	 *
	 * @param ICC_GG_Sign_In_OpenID_Connect_Option_Settings $settings A plugin settings object instance.
	 *
	 * @return void
	 */
	public static function register( $settings ) {
		self::$settings = $settings;

		add_action( 'icc_gg_sign_in_openid_connect_before_login', array( __CLASS__, 'suppress' ), 10, 1 );
		add_action( 'icc_gg_sign_in_openid_connect_after_login', array( __CLASS__, 'restore' ), 10, 1 );
	}

	/**
	 * Temporarily remove the selected 2FA plugin's login hooks.
	 *
	 * @param WP_User $user The user that is being logged in.
	 *
	 * @return void
	 */
	public static function suppress( $user ) {
		$slugs = self::get_target_plugin_slugs();
		if ( empty( $slugs ) ) {
			return;
		}

		$hooks = array( 'wp_login', 'authenticate', 'login_redirect' );

		foreach ( $slugs as $slug ) {
			self::remove_plugin_callbacks( $hooks, $slug );
		}
	}

	/**
	 * Restore any callbacks previously removed by suppress().
	 *
	 * @param WP_User $user The user that was logged in.
	 *
	 * @return void
	 */
	public static function restore( $user ) {
		if ( empty( self::$removed ) ) {
			return;
		}

		foreach ( array_reverse( self::$removed ) as $removed ) {
			add_filter( $removed['hook'], $removed['function'], $removed['priority'], $removed['accepted_args'] );
		}

		self::$removed = array();
	}

	/**
	 * Map the configured bypass setting value to plugin directory slugs.
	 *
	 * Both the free (admin-site-enhancements) and Pro
	 * (admin-site-enhancements-pro) editions are supported because ASE Pro
	 * ships its two-factor module in a differently named directory.
	 *
	 * @return array<string> The target plugin directory slugs, or empty array when disabled.
	 */
	private static function get_target_plugin_slugs() {
		$bypass = isset( self::$settings ) ? self::$settings->two_factor_bypass : 'none';

		$map = array(
			'ase'    => array( 'admin-site-enhancements-pro', 'admin-site-enhancements' ),
			'wp_2fa' => array( 'wp-2fa' ),
		);

		return isset( $map[ $bypass ] ) ? $map[ $bypass ] : array();
	}

	/**
	 * Remove callbacks that belong to the target plugin from the given hooks.
	 *
	 * @param array<string> $hooks The hook names to inspect.
	 * @param string        $slug  The plugin directory slug.
	 *
	 * @return void
	 */
	private static function remove_plugin_callbacks( $hooks, $slug ) {
		global $wp_filter;

		$to_remove = array();

		foreach ( $hooks as $hook ) {
			if ( ! isset( $wp_filter[ $hook ] ) || ! ( $wp_filter[ $hook ] instanceof WP_Hook ) ) {
				continue;
			}

			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $registered ) {
				foreach ( $registered as $callback ) {
					$file = self::get_callback_file( $callback['function'] );
					if ( $file && self::file_belongs_to_plugin( $file, $slug ) ) {
						$to_remove[] = array(
							'hook'          => $hook,
							'function'      => $callback['function'],
							'priority'      => $priority,
							'accepted_args' => $callback['accepted_args'],
						);
					}
				}
			}
		}

		// Remove in a separate pass to avoid mutating the array while iterating it.
		foreach ( $to_remove as $removed ) {
			remove_filter( $removed['hook'], $removed['function'], $removed['priority'] );
			self::$removed[] = $removed;
		}
	}

	/**
	 * Resolve the file in which a given callback is defined.
	 *
	 * @param callable $function The callback to inspect.
	 *
	 * @return string|false The absolute file path, or false if it cannot be determined.
	 */
	private static function get_callback_file( $function ) {
		try {
			if ( $function instanceof Closure ) {
				$reflection = new ReflectionFunction( $function );
				return $reflection->getFileName();
			}

			if ( is_array( $function ) && isset( $function[0], $function[1] ) ) {
				$reflection = new ReflectionMethod( $function[0], $function[1] );
				return $reflection->getFileName();
			}

			if ( is_string( $function ) && is_callable( $function ) ) {
				$reflection = new ReflectionFunction( $function );
				return $reflection->getFileName();
			}
		} catch ( ReflectionException $e ) {
			return false;
		}

		return false;
	}

	/**
	 * Determine whether a file path lives inside the target plugin directory.
	 *
	 * @param string $file The callback file path.
	 * @param string $slug The plugin directory slug.
	 *
	 * @return bool
	 */
	private static function file_belongs_to_plugin( $file, $slug ) {
		if ( empty( $file ) ) {
			return false;
		}

		$plugin_dir = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) . '/' . $slug );

		return 0 === strpos( wp_normalize_path( $file ), $plugin_dir );
	}
}
