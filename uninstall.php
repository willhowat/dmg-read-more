<?php
/**
 * Runs when the plugin is deleted via the WordPress admin.
 *
 * Drops the DMG Read More index table. Post meta and post content are left
 * untouched — the block markup in post_content remains valid and the frontend
 * render callback will simply return nothing once the plugin is gone.
 *
 * @package dmg-read-more
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}dmg_read_more_index" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
