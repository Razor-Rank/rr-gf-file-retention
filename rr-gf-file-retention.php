<?php
/**
 * Plugin Name: RR GF File Retention
 * Plugin URI:  https://github.com/Razor-Rank/rr-gf-file-retention
 * Description: Automatically purges uploaded files attached to Gravity Forms entries after a configurable retention period. Preserves entry data and annotates removals.
 * Version:     1.0.0
 * Author:      Razor Rank LLC
 * Author URI:  https://razorrank.com
 * License:     Proprietary
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

    $relative = substr( $class_name, strlen( 'RR_' ) );
    $filename = 'class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
    $filepath = RR_GF_FILE_RETENTION_DIR . 'includes/' . $filename;

    if ( file_exists( $filepath ) ) {
        require_once $filepath;
    }
} );

/**
 * Check for Gravity Forms dependency.
 *
 * If GF is not active, show an admin notice and bail out.
 */
function rr_gf_file_retention_check_dependencies(): bool {
    if ( class_exists( 'GFForms' ) ) {
        return true;
    }

    add_action( 'admin_notices', function (): void {
        $notice = new RR_Retention_Notice();
        $notice->dependency_missing();
    } );

    return false;
}

/**
 * Initialize the plugin after all plugins have loaded.
 */
function rr_gf_file_retention_init(): void {
    if ( ! rr_gf_file_retention_check_dependencies() ) {
        return;
    }

    // Load text domain.
    load_plugin_textdomain(
        'rr-gf-file-retention',
        false,
        dirname( RR_GF_FILE_RETENTION_BASENAME ) . '/languages'
    );

    // Boot core components.
    $logger   = new RR_Retention_Logger();
    $settings = new RR_Retention_Settings();
    $form     = new RR_Retention_Form();
    $engine   = new RR_Retention_Engine( $settings, $logger );
    $cron     = new RR_Retention_Cron( $engine, $settings );

    $settings->init();
    $form->init();
    $cron->init();

    // Register WP-CLI commands when available.
    if ( defined( 'WP_CLI' ) && WP_CLI ) {
        $cli = new RR_Retention_CLI( $engine, $settings, $logger );
        $cli->register();
    }
}
add_action( 'plugins_loaded', 'rr_gf_file_retention_init' );

/**
 * Activation hook.
 *
 * Creates the custom log table and sets default options.
 */
function rr_gf_file_retention_activate(): void {
    // Create log table.
    RR_Retention_Logger::create_table();

    // Set default settings if none exist.
    if ( false === get_option( 'rr_gf_file_retention_settings' ) ) {
        update_option( 'rr_gf_file_retention_settings', RR_Retention_Settings::defaults() );
    }
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
