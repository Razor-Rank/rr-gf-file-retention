<?php
/**
 * Uninstall handler for RR GF File Retention.
 *
 * Runs when the plugin is deleted via WP Admin > Plugins.
 * Drops the custom log table and removes the settings option.
 * Does NOT modify any Gravity Forms data or entry notes.
 *
 * @package RR_GF_File_Retention
 * @since   1.0.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Drop the custom log table.
global $wpdb;
$table = $wpdb->prefix . 'rr_file_retention_log';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// Delete the global settings option.
delete_option( 'rr_gf_file_retention_settings' );

// Delete per-form meta entries.
// These are stored via gform_update_meta() with key 'rr_file_retention'.
// Clean them up if Gravity Forms is still active.
if ( class_exists( 'GFAPI' ) ) {
    $forms = GFAPI::get_forms();
    foreach ( $forms as $form ) {
        gform_delete_meta( $form['id'], 'rr_file_retention' );
    }
}
