<?php
/**
 * Runs when CalendarCats is deleted from the Plugins screen.
 *
 * Removes both options and every cached feed transient. Deactivation leaves
 * settings alone on purpose — only an explicit delete clears them.
 *
 * @package CalendarCats
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete the plugin's options and feed cache for the current site.
 */
function wpcc_uninstall_site() {
	global $wpdb;

	delete_option( 'calendarcats_settings' );
	delete_option( 'calendarcats_categories' );
	delete_option( 'wpcc_cache_ver' );

	// Transient names are hashed per feed, so they have to be matched by prefix.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_wpcc_ics_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_wpcc_ics_' ) . '%'
		)
	);
}

if ( is_multisite() ) {
	$wpcc_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $wpcc_sites as $wpcc_site_id ) {
		switch_to_blog( $wpcc_site_id );
		wpcc_uninstall_site();
		restore_current_blog();
	}
} else {
	wpcc_uninstall_site();
}
