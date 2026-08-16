<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * GitHub plugin updater for ICC.gg Sign-In for OpenID Connect.
 *
 * Checks the GitHub Releases API for newer versions and injects the result
 * into the WordPress update transient so the plugin behaves like a plugin
 * hosted on wordpress.org, including the native WordPress 5.5+
 * "Enable auto-updates" / "Disable auto-updates" toggle.
 *
 * @package   ICC_GG_Sign_In_OpenID_Connect
 * @author    Ivan Carlos
 * @copyright 2023-2025 Ivan Carlos
 * @license   http://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 */

if ( ! class_exists( 'ICC_GG_Sign_In_OpenID_Connect_Github_Updater' ) ) {

	class ICC_GG_Sign_In_OpenID_Connect_Github_Updater {

		/**
		 * GitHub repository in owner/repository format.
		 *
		 * @var string
		 */
		const GITHUB_REPO = 'ivancarlosti/wordpressiccopenidclient';

		/**
		 * GitHub Releases API endpoint for the latest release.
		 *
		 * @var string
		 */
		const GITHUB_API_URL = 'https://api.github.com/repos/ivancarlosti/wordpressiccopenidclient/releases/latest';

		/**
		 * Site transient key used to cache the latest release data.
		 *
		 * @var string
		 */
		const CACHE_KEY = 'icc_gg_sign_in_openid_connect_github_release';

		/**
		 * Site transient key used to mark a recently failed request.
		 *
		 * @var string
		 */
		const FAILED_CACHE_KEY = 'icc_gg_sign_in_openid_connect_github_failed_request';

		/**
		 * How long to cache the latest release data, in seconds.
		 *
		 * @var int
		 */
		const CACHE_DURATION = 43200;

		/**
		 * Plugin basename, e.g. icc-gg-sign-in-openid-connect/icc-gg-sign-in-openid-connect.php.
		 *
		 * @var string
		 */
		private $plugin_file;

		/**
		 * Plugin slug (folder name), e.g. icc-gg-sign-in-openid-connect.
		 *
		 * @var string
		 */
		private $plugin_slug;

		/**
		 * Currently installed plugin version.
		 *
		 * @var string
		 */
		private $current_version;

		/**
		 * Set up the updater and register WordPress hooks.
		 *
		 * @param string $plugin_file     Plugin basename.
		 * @param string $current_version Current installed plugin version.
		 */
		public function __construct( $plugin_file, $current_version ) {
			$this->plugin_file     = $plugin_file;
			$this->plugin_slug     = dirname( $plugin_file );
			$this->current_version = $current_version;

			/*
			 * Hook both the read filter and the set filter:
			 * - `site_transient_update_plugins` injects the plugin every time
			 *   WordPress reads the update transient. This makes the native
			 *   auto-update toggle appear immediately on the Plugins screen,
			 *   without waiting for the transient to be saved by cron.
			 * - `pre_set_site_transient_update_plugins` persists the data when
			 *   WordPress saves the transient, which the cron auto-updater uses.
			 */
			add_filter( 'site_transient_update_plugins', array( $this, 'check_update' ) );
			add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
			add_filter( 'plugins_api', array( $this, 'plugins_api_filter' ), 10, 3 );
		}

		/**
		 * Inject the latest GitHub release into the update_plugins transient.
		 *
		 * Populates both `response` (update available) and `no_update` (already
		 * current). Populating `no_update` is required for WordPress 5.5+
		 * automatic update support.
		 *
		 * Used for both `site_transient_update_plugins` (transient read) and
		 * `pre_set_site_transient_update_plugins` (transient save) so the plugin
		 * appears in the transient whenever WordPress reads or stores it.
		 *
		 * @param mixed $transient The update_plugins transient being read or saved.
		 * @return mixed
		 */
		public function check_update( $transient ) {
			if ( ! is_object( $transient ) ) {
				$transient = new stdClass();
			}

			$release = $this->get_latest_release();

			if ( ! $release ) {
				/*
				 * If a previous successful check already recorded this plugin,
				 * leave that entry untouched so a temporary GitHub failure never
				 * masks a known update.
				 */
				if ( isset( $transient->response[ $this->plugin_file ] ) || isset( $transient->no_update[ $this->plugin_file ] ) ) {
					return $transient;
				}

				/*
				 * Otherwise inject a minimal `no_update` entry so WordPress marks
				 * the plugin as update-supported and shows the native auto-update
				 * toggle even before the first successful GitHub lookup.
				 */
				$transient->no_update[ $this->plugin_file ] = $this->build_fallback_item();

				return $transient;
			}

			$new_version = $this->normalize_version( $release->tag_name );
			$item        = $this->build_item( $new_version, $this->get_package_url( $release ), isset( $release->html_url ) ? $release->html_url : '' );

			if ( version_compare( $this->current_version, $new_version, '<' ) ) {
				$transient->response[ $this->plugin_file ] = $item;
			} else {
				$transient->no_update[ $this->plugin_file ] = $item;
			}

			return $transient;
		}

		/**
		 * Build a transient item from a GitHub release.
		 *
		 * @param string $new_version The normalized new version.
		 * @param string $package     The direct ZIP download URL.
		 * @param string $url         The release page URL.
		 * @return stdClass
		 */
		private function build_item( $new_version, $package, $url ) {
			$item                = new stdClass();
			$item->slug          = $this->plugin_slug;
			$item->plugin        = $this->plugin_file;
			$item->new_version   = $new_version;
			$item->package       = $package;
			$item->url           = $url;
			$item->tested        = null;
			$item->requires      = '';
			$item->requires_php  = '8.1';

			return $item;
		}

		/**
		 * Build a fallback transient item used when GitHub is unreachable or the
		 * repository has no releases yet.
		 *
		 * @return stdClass
		 */
		private function build_fallback_item() {
			return $this->build_item(
				$this->current_version,
				'',
				'https://github.com/' . self::GITHUB_REPO
			);
		}

		/**
		 * Provide plugin information for the "View version details" modal.
		 *
		 * @param mixed  $data   Existing plugin API data.
		 * @param string $action The requested plugins_api action.
		 * @param object $args   API request arguments.
		 * @return mixed
		 */
		public function plugins_api_filter( $data, $action, $args ) {
			if ( 'plugin_information' !== $action ) {
				return $data;
			}

			if ( empty( $args->slug ) || $args->slug !== $this->plugin_slug ) {
				return $data;
			}

			$release = $this->get_latest_release();
			if ( ! $release ) {
				return $data;
			}

			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $this->plugin_file );

			$sections = array(
				'description' => isset( $plugin_data['Description'] ) ? $plugin_data['Description'] : '',
				'changelog'   => isset( $release->body ) ? $release->body : '',
			);

			return (object) array(
				'name'          => isset( $plugin_data['Name'] ) ? $plugin_data['Name'] : $this->plugin_slug,
				'slug'          => $this->plugin_slug,
				'version'       => $this->normalize_version( $release->tag_name ),
				'author'        => isset( $plugin_data['Author'] ) ? $plugin_data['Author'] : '',
				'homepage'      => isset( $release->html_url ) ? $release->html_url : '',
				'download_link' => $this->get_package_url( $release ),
				'sections'      => $sections,
			);
		}

		/**
		 * Fetch the latest GitHub release, using a cached copy when available.
		 *
		 * @return object|false Release object on success, false on failure.
		 */
		private function get_latest_release() {
			$cached = get_site_transient( self::CACHE_KEY );
			if ( false !== $cached ) {
				return $cached;
			}

			if ( $this->request_recently_failed() ) {
				return false;
			}

			$response = wp_remote_get(
				self::GITHUB_API_URL,
				array(
					'timeout' => 15,
					'headers' => array(
						'Accept'     => 'application/vnd.github.v3+json',
						'User-Agent' => 'icc-gg-sign-in-openid-connect/' . $this->current_version,
					),
				)
			);

			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				$this->log_failed_request();
				return false;
			}

			$release = json_decode( wp_remote_retrieve_body( $response ) );

			if ( ! is_object( $release ) || empty( $release->tag_name ) ) {
				$this->log_failed_request();
				return false;
			}

			set_site_transient( self::CACHE_KEY, $release, self::CACHE_DURATION );

			return $release;
		}

		/**
		 * Resolve the direct ZIP download URL for a release.
		 *
		 * Prefers a `.zip` release asset whose root is the plugin folder.
		 * Falls back to the GitHub archive ZIP when no zip asset exists.
		 *
		 * @param object $release The GitHub release object.
		 * @return string
		 */
		private function get_package_url( $release ) {
			if ( ! empty( $release->assets ) && is_array( $release->assets ) ) {
				foreach ( $release->assets as $asset ) {
					if ( ! empty( $asset->name ) && ! empty( $asset->browser_download_url ) && '.zip' === substr( $asset->name, -4 ) ) {
						return $asset->browser_download_url;
					}
				}
			}

			return sprintf(
				'https://github.com/%s/archive/refs/tags/%s.zip',
				self::GITHUB_REPO,
				rawurlencode( $release->tag_name )
			);
		}

		/**
		 * Strip a leading "v" from a GitHub tag so it compares cleanly with the
		 * plugin version.
		 *
		 * @param string $version The tag or version string.
		 * @return string
		 */
		private function normalize_version( $version ) {
			return ltrim( (string) $version, 'vV' );
		}

		/**
		 * Check whether a GitHub request failed within the last hour.
		 *
		 * @return bool
		 */
		private function request_recently_failed() {
			$failed = get_site_transient( self::FAILED_CACHE_KEY );

			return false !== $failed;
		}

		/**
		 * Mark a failed GitHub request so the API is not hammered.
		 *
		 * @return void
		 */
		private function log_failed_request() {
			set_site_transient( self::FAILED_CACHE_KEY, time(), HOUR_IN_SECONDS );
		}
	}
}
