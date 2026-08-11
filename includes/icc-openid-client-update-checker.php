<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ICC OpenID Client - GitHub Update Checker
 *
 * Provides native WordPress update checking from GitHub Releases,
 * replicating exactly how WordPress.org-hosted plugins behave:
 * update notifications, one-click updates, version details popup,
 * and the "Enable automatic updates" toggle.
 *
 * @package   ICC_OpenID_Client
 * @category  General
 * @author    Ivan Carlos
 */

if ( ! class_exists( 'ICC_OpenID_Client_Update_Checker' ) ) {
class ICC_OpenID_Client_Update_Checker {

	/**
	 * Plugin slug used in update transient and plugin info API.
	 *
	 * @var string
	 */
	private $plugin_slug;

	/**
	 * Absolute path to the main plugin file.
	 *
	 * @var string
	 */
	private $plugin_file;

	/**
	 * URL to the JSON metadata file on GitHub Releases.
	 *
	 * @var string
	 */
	private $metadata_url;

	/**
	 * Plugin basename (e.g., "icc-openid-client/icc-openid-client.php").
	 *
	 * @var string
	 */
	private $plugin_basename;

	/**
	 * Current plugin version from the file header.
	 *
	 * @var string
	 */
	private $current_version;

	/**
	 * Transient key for caching metadata.
	 *
	 * @var string
	 */
	const TRANSIENT_KEY = 'icc_openid_client_update_info';

	/**
	 * How long to cache the metadata (12 hours).
	 *
	 * @var int
	 */
	const CACHE_DURATION = 12 * HOUR_IN_SECONDS;

	/**
	 * Constructor — registers all WordPress hooks.
	 *
	 * @param string $plugin_file  Absolute path to the main plugin file (__FILE__).
	 * @param string $plugin_slug  Plugin slug matching the directory name.
	 * @param string $metadata_url URL to the GitHub Releases JSON metadata.
	 */
	public function __construct( $plugin_file, $plugin_slug, $metadata_url ) {
		$this->plugin_file    = $plugin_file;
		$this->plugin_slug    = $plugin_slug;
		$this->metadata_url   = $metadata_url;
		$this->plugin_basename = plugin_basename( $plugin_file );

		// Read the current version from the plugin header.
		$plugin_data = get_file_data( $plugin_file, array( 'Version' => 'Version' ) );
		$this->current_version = $plugin_data['Version'];

		// Hook 1: Inject update info into the update transient (shows update notification).
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ), 10, 1 );

		// Hook 2: Provide plugin details for the "View version details" popup.
		add_filter( 'plugins_api', array( $this, 'plugin_information' ), 20, 3 );

		// Hook 3: Register our plugin in the list of plugins eligible for auto-updates.
		// This ensures the "Enable automatic updates" / "Disable automatic updates"
		// toggle link appears in the Plugins list. WordPress manages the on/off state
		// via the auto_update_plugins site option — no auto_update_plugin filter needed.
		add_filter( 'plugin_auto_update_setting_html', array( $this, 'auto_update_setting_html' ), 10, 3 );
	}

	/**
	 * Check for a new version by fetching the GitHub metadata JSON.
	 * Injects update info into the transient when a newer version exists.
	 *
	 * @param object $transient The update_plugins transient value.
	 * @return object Modified transient.
	 */
	public function check_for_update( $transient ) {
		// Bail if no checked data (first run or transient reset).
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$metadata = $this->fetch_metadata();
		if ( ! $metadata || empty( $metadata->version ) ) {
			return $transient;
		}

		// Compare versions — only offer update if remote version is newer.
		if ( version_compare( $metadata->version, $this->current_version, '>' ) ) {
			$plugin_data = get_file_data( $this->plugin_file, array( 'Name' => 'Plugin Name' ) );

			$transient->response[ $this->plugin_basename ] = (object) array(
				'slug'         => $this->plugin_slug,
				'plugin'       => $this->plugin_basename,
				'new_version'  => $metadata->version,
				'url'          => isset( $metadata->homepage ) ? $metadata->homepage : '',
				'package'      => $metadata->download_url,
				'requires'     => isset( $metadata->requires ) ? $metadata->requires : '',
				'requires_php' => isset( $metadata->requires_php ) ? $metadata->requires_php : '',
				'tested'       => isset( $metadata->tested ) ? $metadata->tested : '',
				'icons'        => isset( $metadata->icons ) ? (array) $metadata->icons : array(),
				'banners'      => isset( $metadata->banners ) ? (array) $metadata->banners : array(),
			);
		}

		return $transient;
	}

	/**
	 * Provide plugin information for the "View version details" popup.
	 *
	 * @param false|object|array $result The result object or false.
	 * @param string             $action The API action being performed.
	 * @param object             $args   Plugin information request arguments.
	 * @return false|object Modified result.
	 */
	public function plugin_information( $result, $action, $args ) {
		// Only handle plugin_information requests for our plugin.
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || $args->slug !== $this->plugin_slug ) {
			return $result;
		}

		$metadata = $this->fetch_metadata();
		if ( ! $metadata ) {
			return $result;
		}

		$plugin_data = get_file_data( $this->plugin_file, array(
			'Name'        => 'Plugin Name',
			'Author'      => 'Author',
			'AuthorURI'   => 'Author URI',
			'Description' => 'Description',
		) );

		$info = (object) array(
			'name'            => $plugin_data['Name'],
			'slug'            => $this->plugin_slug,
			'version'         => $metadata->version,
			'author'          => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $plugin_data['AuthorURI'] ),
				esc_html( $plugin_data['Author'] )
			),
			'author_profile'  => $plugin_data['AuthorURI'],
			'homepage'        => isset( $metadata->homepage ) ? $metadata->homepage : '',
			'requires'        => isset( $metadata->requires ) ? $metadata->requires : '',
			'requires_php'    => isset( $metadata->requires_php ) ? $metadata->requires_php : '',
			'tested'          => isset( $metadata->tested ) ? $metadata->tested : '',
			'last_updated'    => isset( $metadata->last_updated ) ? $metadata->last_updated : '',
			'download_link'   => $metadata->download_url,
			'sections'        => array(
				'description' => $plugin_data['Description'],
				'changelog'   => isset( $metadata->sections->changelog )
					? $this->format_changelog( $metadata->sections->changelog )
					: '',
			),
		);

		return $info;
	}

	/**
	 * Ensure the auto-update setting HTML is rendered for our plugin.
	 *
	 * WordPress normally only shows the auto-update toggle for plugins
	 * hosted on WordPress.org. This filter ensures our GitHub-hosted
	 * plugin also gets the toggle.
	 *
	 * @param string $html        The existing HTML for the auto-update setting.
	 * @param string $plugin_file The plugin basename.
	 * @param array  $plugin_data Plugin data array.
	 * @return string
	 */
	public function auto_update_setting_html( $html, $plugin_file, $plugin_data ) {
		// Only act on our plugin.
		if ( $plugin_file !== $this->plugin_basename ) {
			return $html;
		}

		// If WordPress is already showing the HTML, keep it.
		if ( ! empty( $html ) ) {
			return $html;
		}

		// Generate our own auto-update toggle HTML.
		$auto_updates = (array) get_site_option( 'auto_update_plugins', array() );
		$enabled      = in_array( $plugin_file, $auto_updates, true );

		if ( $enabled ) {
			$action = 'disable';
			$label  = __( 'Disable automatic updates', 'icc-openid-client' );
		} else {
			$action = 'enable';
			$label  = __( 'Enable automatic updates', 'icc-openid-client' );
		}

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'        => $action . '-auto-update',
					'plugin'        => $plugin_file,
					'paged'         => isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1,
					'plugin_status' => 'all',
				),
				'plugins.php'
			),
			'updates'
		);

		return sprintf(
			'<a href="%s" class="toggle-auto-update" data-wp-action="%s" aria-label="%s">%s</a>',
			esc_url( $url ),
			esc_attr( $action ),
			esc_attr( $label ),
			esc_html( $label )
		);
	}

	/**
	 * Fetch metadata from the GitHub Releases JSON endpoint.
	 * Results are cached in a transient for 12 hours.
	 *
	 * @return object|false Decoded metadata object, or false on failure.
	 */
	private function fetch_metadata() {
		$cached = get_transient( self::TRANSIENT_KEY );
		if ( false !== $cached ) {
			return $cached;
		}

		$response = wp_remote_get(
			$this->metadata_url,
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return false;
		}

		$body     = wp_remote_retrieve_body( $response );
		$metadata = json_decode( $body );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_object( $metadata ) ) {
			return false;
		}

		set_transient( self::TRANSIENT_KEY, $metadata, self::CACHE_DURATION );

		return $metadata;
	}

	/**
	 * Format raw changelog text into HTML for the version details popup.
	 *
	 * Converts WordPress readme.txt changelog format (lines starting with =)
	 * into <h4> headings and <ul> lists.
	 *
	 * @param string $changelog Raw changelog text from readme.txt.
	 * @return string HTML formatted changelog.
	 */
	private function format_changelog( $changelog ) {
		if ( empty( $changelog ) ) {
			return '';
		}

		$lines  = explode( "\n", $changelog );
		$output = '';
		$in_list = false;

		foreach ( $lines as $line ) {
			$line = trim( $line );

			// Skip empty lines and separators.
			if ( empty( $line ) || preg_match( '/^[-=]+$/', $line ) ) {
				if ( $in_list ) {
					$output  .= '</ul>';
					$in_list = false;
				}
				continue;
			}

			// Version headings: = x.y.z =
			if ( preg_match( '/^=\s+(.+?)\s*=\s*$/', $line, $matches ) ) {
				if ( $in_list ) {
					$output  .= '</ul>';
					$in_list = false;
				}
				$output .= '<h4>' . esc_html( $matches[1] ) . '</h4>';
				continue;
			}

			// List items: * text
			if ( preg_match( '/^\*\s+(.+)$/', $line, $matches ) ) {
				if ( ! $in_list ) {
					$output  .= '<ul>';
					$in_list = true;
				}
				$output .= '<li>' . esc_html( $matches[1] ) . '</li>';
				continue;
			}

			// Regular text lines.
			$output .= '<p>' . esc_html( $line ) . '</p>';
		}

		if ( $in_list ) {
			$output .= '</ul>';
		}

		return $output;
	}
}
} // End if ( ! class_exists( 'ICC_OpenID_Client_Update_Checker' ) )
