<?php
/**
 * WP-Cron scheduling for automated daily purge runs.
 *
 * Registers the daily cron event rr_gf_file_retention_daily that fires
 * the purge engine with global settings. Only executes when the master
 * Enabled toggle is on and Dry Run is off.
 *
 * For sites where WP-Cron is unreliable, use a server cron instead:
 *     0 3 * * * /usr/local/bin/wp rr-retention run --path=/home/{user}/public_html/ --allow-root
 *
 * @package RR_GF_File_Retention
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RR_Retention_Cron {

    /** @var string Cron hook name. */
    const HOOK = 'rr_gf_file_retention_daily';

    private RR_Retention_Engine $engine;
    private RR_Retention_Settings $settings;

    /**
     * @param RR_Retention_Engine   $engine   Purge engine instance.
     * @param RR_Retention_Settings $settings Global settings instance.
     */
    public function __construct( RR_Retention_Engine $engine, RR_Retention_Settings $settings ) {
        $this->engine   = $engine;
        $this->settings = $settings;
    }

    /**
     * Hook into WordPress to register the cron event and handler.
     */
    public function init(): void {
        add_action( self::HOOK, [ $this, 'execute' ] );
        $this->maybe_schedule();
    }

    /**
     * Schedule the daily event if not already scheduled.
     */
    private function maybe_schedule(): void {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( strtotime( 'tomorrow 3:00am' ), 'daily', self::HOOK );
        }
    }

    /**
     * Clear the scheduled event.
     *
     * Called on plugin deactivation.
     */
    public static function clear_schedule(): void {
        $timestamp = wp_next_scheduled( self::HOOK );

        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::HOOK );
        }

        wp_clear_scheduled_hook( self::HOOK );
    }

    /**
     * Cron callback: execute the purge if enabled and not in dry-run mode.
     *
     * WARNING: On staging sites with no traffic, WP-Cron may not fire.
     * Use `wp rr-retention run` via server cron for reliable scheduling.
     */
    public function execute(): void {
        if ( ! $this->settings->get( 'enabled', false ) ) {
            return;
        }

        if ( $this->settings->get( 'dry_run', true ) ) {
            return;
        }

        $this->engine->run();
    }
}
