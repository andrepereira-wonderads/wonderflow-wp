<?php
/**
 * Removes the plugin data: settings, send queue and log.
 *
 * @package Wonderflow
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
delete_option( 'wonderflow_settings' );
delete_option( 'wonderflow_db_version' );
$wonderflow_table = $wpdb->prefix . 'wonderflow_queue';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wonderflow_table}" );
