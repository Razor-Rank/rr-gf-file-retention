<?php
/**
 * Plugin Name: Gravity Forms File Retention by Razor Rank
 * Plugin URI:  https://github.com/Razor-Rank/rr-gf-file-retention
 * Description: Automatically purges uploaded files attached to Gravity Forms entries after a configurable retention period. Preserves entry data and annotates removals.
 * Version:     1.0.0
 * Author:      Razor Rank LLC
 * Author URI:  https://razorrank.com
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: rr-gf-file-retention
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'RR_GF_FILE_RETENTION_VERSION', '1.0.0' );
define( 'RR_GF_FILE_RETENTION_FILE', __FILE__ );
define( 'RR_GF_FILE_RETENTION_DIR', plugin_dir_path( __FILE__ ) );
define( 'RR_GF_FILE_RETENTION_URL', plugin_dir_url( __FILE__ ) );
define( 'RR_GF_FILE_RETENTION_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoloader for plugin classes.
 *
 * Maps class names to file paths in the includes/ directory.
 * Convention: RR_Retention_Settings -> includes/class-rr-retention-settings.php
 */
spl_autoload_register( function ( string $class_name ): void {
    $prefix = 'RR_Retention_';

    if ( strpos( $class_name, $prefix ) !== 0 ) {
        return;
    }

    // Strip the full prefix, then build the filename with the standard base.
    $relative = substr( $class_name, strlen( $prefix ) );
    $filename = 'class-rr-retention-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
    $filepath = RR_GF_FILE_RETENTION_DIR . 'includes/' . $filename;

    if ( file_exists( $filepath ) ) {
        require_once $filepath;
    }
} );

/**
 * Register the GF Add-On once Gravity Forms has loaded its framework.
 *
 * The addon's init() method boots the engine, cron, and CLI components.
 */
add_action( 'gform_loaded', function (): void {
    if ( ! method_exists( 'GFForms', 'include_addon_framework' ) ) {
        return;
    }

    \GFForms::include_addon_framework();
    \GFAddOn::register( 'RR_Retention_Addon' );
}, 5 );

/**
 * Show admin notice when Gravity Forms is not active.
 *
 * gform_loaded never fires if GF is deactivated, so the addon silently
 * does nothing. This notice tells the admin why.
 */
add_action( 'plugins_loaded', function (): void {
    if ( class_exists( 'GFForms' ) ) {
        return;
    }

    add_action( 'admin_notices', function (): void {
        $notice = new RR_Retention_Notice();
        $notice->dependency_missing();
    } );
} );

/**
 * Load text domain for translations.
 */
add_action( 'plugins_loaded', function (): void {
    load_plugin_textdomain(
        'rr-gf-file-retention',
        false,
        dirname( RR_GF_FILE_RETENTION_BASENAME ) . '/languages'
    );
} );

/**
 * Activation hook.
 *
 * Creates the custom log table. GFAddOn handles its own settings storage.
 */
function rr_gf_file_retention_activate(): void {
    RR_Retention_Logger::create_table();
}
register_activation_hook( RR_GF_FILE_RETENTION_FILE, 'rr_gf_file_retention_activate' );

/**
 * Deactivation hook.
 *
 * Clears scheduled cron events. Does NOT drop the log table.
 */
function rr_gf_file_retention_deactivate(): void {
    RR_Retention_Cron::clear_schedule();
}
register_deactivation_hook( RR_GF_FILE_RETENTION_FILE, 'rr_gf_file_retention_deactivate' );
