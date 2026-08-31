<?php
/**
 * Plugin Name:       WPCalendarCats
 * Plugin URI:        https://github.com/GTNeill/wpcalendarcats
 * Update URI:        https://github.com/GTNeill/wpcalendarcats
 * Description:       A self-contained community events calendar. Reads public iCalendar feeds directly, sorts events into keyword-driven categories, and renders them with a card view, a calendar grid, search and category filters — all via the [wpcalendarcats] shortcode. No external app or API key required.
 * Version:           2.1.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            George Neill
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wpcalendarcats
 * Domain Path:       /languages
 *
 * @package WPCalendarCats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'WPCC_VERSION', '2.1.1' );
define( 'WPCC_FILE', __FILE__ );
define( 'WPCC_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPCC_URL', plugin_dir_url( __FILE__ ) );

require_once WPCC_DIR . 'includes/default-categories.php';
require_once WPCC_DIR . 'includes/class-wpcc-settings.php';
require_once WPCC_DIR . 'includes/class-wpcc-categories.php';
require_once WPCC_DIR . 'includes/class-wpcc-ics.php';
require_once WPCC_DIR . 'includes/class-wpcc-rest.php';
require_once WPCC_DIR . 'includes/class-wpcc-shortcode.php';
require_once WPCC_DIR . 'includes/class-wpcc-updater.php';

if ( is_admin() ) {
	require_once WPCC_DIR . 'includes/class-wpcc-admin.php';
}

/**
 * Wire everything up.
 */
function wpcc_bootstrap() {
	load_plugin_textdomain( 'wpcalendarcats', false, dirname( plugin_basename( WPCC_FILE ) ) . '/languages' );

	WPCC_REST::init();
	WPCC_Shortcode::init();
	// Not gated behind is_admin(): update checks also run from wp-cron.
	WPCC_Updater::init();

	if ( is_admin() ) {
		WPCC_Admin::init();
	}
}
add_action( 'plugins_loaded', 'wpcc_bootstrap' );

/**
 * Seed options on activation. Existing values are left alone, so upgrading
 * from the iframe-only 1.x releases keeps the configured URL working.
 */
function wpcc_activate() {
	$existing = get_option( WPCC_Settings::OPTION, array() );
	if ( ! is_array( $existing ) ) {
		$existing = array();
	}
	update_option( WPCC_Settings::OPTION, wp_parse_args( $existing, WPCC_Settings::defaults() ) );

	if ( ! is_array( get_option( WPCC_Settings::OPTION_CATS, null ) ) ) {
		update_option( WPCC_Settings::OPTION_CATS, wpcc_default_categories(), false );
	}
}
register_activation_hook( __FILE__, 'wpcc_activate' );

/**
 * Cached feed bodies are worthless once the plugin is switched off.
 */
function wpcc_deactivate() {
	WPCC_ICS::flush_cache();
}
register_deactivation_hook( __FILE__, 'wpcc_deactivate' );

/**
 * Settings shortcut on the Plugins screen.
 *
 * @param array $links Existing action links.
 * @return array
 */
function wpcc_action_links( $links ) {
	$url = admin_url( 'admin.php?page=wpcalendarcats' );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'wpcalendarcats' ) . '</a>' );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wpcc_action_links' );
