<?php
/**
 * Self-hosted update channel, backed by GitHub Releases.
 *
 * The plugin isn't in the wordpress.org directory, so WordPress has nothing
 * to check for new versions — a plugin installed from an uploaded zip is
 * invisible to core's updater. This class supplies the missing half.
 *
 * It uses the `Update URI` header (WP 5.8+) and the matching
 * `update_plugins_{$hostname}` filter rather than elbowing into
 * `pre_set_site_transient_update_plugins`. Two reasons: the header stops
 * wordpress.org from ever claiming this slug and pushing somebody else's
 * plugin at us, and core does the version comparison and transient
 * bookkeeping itself, so there's less here to get wrong.
 *
 * Nothing installs on its own. Core decides that, based on the site's
 * auto-update settings; all this does is make an update *visible*.
 *
 * @package WPCalendarCats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Reports new releases to WordPress.
 */
class WPCC_Updater {

	/** Repository the releases live in. */
	const REPO = 'GTNeill/wpcalendarcats';

	/**
	 * Release asset holding the installable build.
	 *
	 * GitHub's auto-generated source zipball is deliberately not used as a
	 * fallback: it unpacks to `wpcalendarcats-<tag>/`, so WordPress would
	 * install it as a second, separate plugin instead of upgrading this one.
	 * Only the built zip has the correct `wpcalendarcats/` folder inside.
	 */
	const ASSET = 'wpcalendarcats.zip';

	/** Plugin directory/slug, used for the "View details" modal. */
	const SLUG = 'wpcalendarcats';

	/** Cached release payload. */
	const CACHE_KEY = 'wpcc_latest_release';

	/** How long a successful lookup is trusted. */
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/** How long to sit still after a failed lookup. */
	const FAIL_TTL = 1 * HOUR_IN_SECONDS;

	/** Highest WordPress version this release was checked against. */
	const TESTED = '6.8';

	/**
	 * Hook in.
	 *
	 * Not gated behind is_admin(): update checks also run from wp-cron, which
	 * isn't an admin request.
	 */
	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_update' ), 10, 2 );
		add_action( 'admin_init', array( __CLASS__, 'handle_manual_check' ) );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
	}

	/**
	 * Tell core about the newest release.
	 *
	 * Core compares versions and files the result under `response` or
	 * `no_update` itself, and overwrites `id` and `plugin`. It reads the
	 * version from `version`, not `new_version` — it copies it across.
	 *
	 * @param array|false $update      Update payload from a previous filter.
	 * @param array       $plugin_data Headers of the plugin being checked.
	 * @param string      $plugin_file Plugin basename being checked.
	 * @return array|false
	 */
	public static function check( $update, $plugin_data, $plugin_file ) {
		// This filter fires for every plugin whose Update URI is on github.com,
		// not just ours.
		if ( plugin_basename( WPCC_FILE ) !== $plugin_file ) {
			return $update;
		}

		$release = self::latest_release();
		if ( ! $release || '' === $release['package'] ) {
			return $update;
		}

		return array(
			'slug'         => self::SLUG,
			'version'      => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'tested'       => self::TESTED,
			'requires'     => '5.8',
			'requires_php' => '7.4',
		);
	}

	/**
	 * Populate the "View details" modal, which would otherwise 404 against
	 * wordpress.org.
	 *
	 * @param false|object|array $result Result from a previous filter.
	 * @param string             $action API action being performed.
	 * @param object             $args   Arguments for the request.
	 * @return false|object|array
	 */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$release = self::latest_release();
		if ( ! $release ) {
			return $result;
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugin = get_plugin_data( WPCC_FILE, false, false );

		$info = array(
			'name'          => $plugin['Name'],
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => $plugin['Author'],
			'homepage'      => $plugin['PluginURI'],
			'requires'      => '5.8',
			'tested'        => self::TESTED,
			'requires_php'  => '7.4',
			'last_updated'  => $release['published'],
			'download_link' => $release['package'],
			'trunk'         => $release['package'],
			'sections'      => array(
				'description' => wpautop( esc_html( $plugin['Description'] ) ),
				'changelog'   => self::markdown( $release['changelog'] ),
			),
		);

		return (object) $info;
	}

	/**
	 * Fetch the newest published release, cached.
	 *
	 * @param bool $force Skip the cache.
	 * @return array|null Release data, or null when unavailable.
	 */
	protected static function latest_release( $force = false ) {
		if ( ! $force ) {
			$cached = get_site_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
			// A recent failure. Don't retry on every page load.
			if ( 'failed' === $cached ) {
				return null;
			}
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					// GitHub rejects requests without a User-Agent.
					'User-Agent' => 'WPCalendarCats/' . WPCC_VERSION . '; ' . home_url( '/' ),
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_site_transient( self::CACHE_KEY, 'failed', self::FAIL_TTL );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			set_site_transient( self::CACHE_KEY, 'failed', self::FAIL_TTL );
			return null;
		}

		// Tags are written v2.1.0; version_compare wants 2.1.0.
		$version = ltrim( (string) $body['tag_name'], 'vV' );

		$package = '';
		if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				if ( isset( $asset['name'] ) && self::ASSET === $asset['name'] ) {
					$package = $asset['browser_download_url'];
					break;
				}
			}
		}

		$release = array(
			'version'   => $version,
			'package'   => $package,
			'url'       => isset( $body['html_url'] ) ? $body['html_url'] : '',
			'changelog' => isset( $body['body'] ) ? (string) $body['body'] : '',
			'published' => isset( $body['published_at'] ) ? $body['published_at'] : '',
		);

		set_site_transient( self::CACHE_KEY, $release, self::CACHE_TTL );

		return $release;
	}

	/**
	 * Drop the cached release once an update has been installed, so the
	 * Plugins screen doesn't keep advertising the version just applied.
	 *
	 * @param WP_Upgrader $upgrader Upgrader instance.
	 * @param array       $extra    Update context.
	 */
	public static function after_update( $upgrader, $extra ) {
		if ( ! isset( $extra['type'] ) || 'plugin' !== $extra['type'] ) {
			return;
		}
		delete_site_transient( self::CACHE_KEY );
	}

	/**
	 * "Check for updates" on the Plugins screen, plus core's own
	 * "Check again" button on the Updates screen.
	 */
	public static function handle_manual_check() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		// Core's Updates screen: honour a forced re-check.
		if ( isset( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			delete_site_transient( self::CACHE_KEY );
			return;
		}

		if ( empty( $_GET['wpcc-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		check_admin_referer( 'wpcc-check-updates' );

		delete_site_transient( self::CACHE_KEY );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		wp_safe_redirect( self_admin_url( 'plugins.php?wpcc-checked=1' ) );
		exit;
	}

	/**
	 * Add the manual check link under the plugin's row.
	 *
	 * @param array  $links Existing meta links.
	 * @param string $file  Plugin basename for the row being rendered.
	 * @return array
	 */
	public static function row_meta( $links, $file ) {
		if ( plugin_basename( WPCC_FILE ) !== $file ) {
			return $links;
		}

		$url = wp_nonce_url(
			self_admin_url( 'plugins.php?wpcc-check=1' ),
			'wpcc-check-updates'
		);

		$links[] = '<a href="' . esc_url( $url ) . '">' .
			esc_html__( 'Check for updates', 'wpcalendarcats' ) . '</a>';

		return $links;
	}

	/**
	 * Render a GitHub release body as HTML for the details modal.
	 *
	 * Deliberately minimal — release notes here are headings, bullets, bold
	 * and inline code, and pulling in a Markdown parser for that would be
	 * out of keeping with a plugin that ships no dependencies.
	 *
	 * @param string $text Raw release body.
	 * @return string
	 */
	protected static function markdown( $text ) {
		$text = esc_html( trim( $text ) );
		if ( '' === $text ) {
			return '<p>' . esc_html__( 'No release notes provided.', 'wpcalendarcats' ) . '</p>';
		}

		$text = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );
		$text = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text );

		$out     = '';
		$in_list = false;

		foreach ( preg_split( '/\R/', $text ) as $line ) {
			$line = rtrim( $line );

			if ( preg_match( '/^\s*[*-]\s+(.*)$/', $line, $m ) ) {
				if ( ! $in_list ) {
					$out    .= '<ul>';
					$in_list = true;
				}
				$out .= '<li>' . $m[1] . '</li>';
				continue;
			}

			if ( $in_list ) {
				$out    .= '</ul>';
				$in_list = false;
			}

			if ( preg_match( '/^(#{1,6})\s+(.*)$/', $line, $m ) ) {
				$level = min( 6, max( 3, strlen( $m[1] ) + 2 ) );
				$out  .= '<h' . $level . '>' . $m[2] . '</h' . $level . '>';
				continue;
			}

			if ( '' !== trim( $line ) ) {
				$out .= '<p>' . $line . '</p>';
			}
		}

		if ( $in_list ) {
			$out .= '</ul>';
		}

		return $out;
	}
}
