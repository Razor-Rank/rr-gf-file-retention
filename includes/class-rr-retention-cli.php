<?php
// This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public
// License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.

/**
 * WP-CLI commands: wp rr-retention run|status|preview|log.
 *
 * Provides full plugin functionality from the command line for
 * server-side automation and cron-based scheduling.
 *
 * @package RR_GF_File_Retention
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RR_Retention_CLI {

    private RR_Retention_Engine $engine;
    private RR_Retention_Settings $settings;
    private RR_Retention_Logger $logger;

    /**
     * @param RR_Retention_Engine   $engine   Purge engine instance.
     * @param RR_Retention_Settings $settings Global settings instance.
     * @param RR_Retention_Logger   $logger   Audit logger instance.
     */
    public function __construct(
        RR_Retention_Engine $engine,
        RR_Retention_Settings $settings,
        RR_Retention_Logger $logger
    ) {
        $this->engine   = $engine;
        $this->settings = $settings;
        $this->logger   = $logger;
    }

    /**
     * Register all WP-CLI commands.
     */
    public function register(): void {
        \WP_CLI::add_command( 'rr-retention run', [ $this, 'cmd_run' ] );
        \WP_CLI::add_command( 'rr-retention status', [ $this, 'cmd_status' ] );
        \WP_CLI::add_command( 'rr-retention preview', [ $this, 'cmd_preview' ] );
        \WP_CLI::add_command( 'rr-retention log', [ $this, 'cmd_log' ] );
    }

    /**
     * Execute a purge run.
     *
     * ## OPTIONS
     *
     * [--form=<id>]
     * : Limit purge to a specific form ID.
     *
     * [--dry-run]
     * : Preview what would be deleted without actually deleting.
     *
     * [--live]
     * : Force a live (non-dry-run) purge regardless of global setting.
     * Mutually exclusive with --dry-run.
     *
     * [--days=<n>]
     * : Override the retention period for this run.
     *
     * [--batch-size=<n>]
     * : Override the batch size for this run.
     *
     * [--verbose]
     * : Show detailed per-file output.
     *
     * ## EXAMPLES
     *
     *     wp rr-retention run
     *     wp rr-retention run --form=5 --dry-run --verbose
     *     wp rr-retention run --live --days=90
     *     wp rr-retention run --batch-size=500
     *
     * @param array $positional Positional arguments.
     * @param array $assoc      Associative arguments.
     */
    public function cmd_run( array $positional, array $assoc ): void {
        $has_dry_run = \WP_CLI\Utils\get_flag_value( $assoc, 'dry-run', null );
        $has_live    = \WP_CLI\Utils\get_flag_value( $assoc, 'live', null );

        // Mutual exclusion check.
        if ( $has_dry_run && $has_live ) {
            \WP_CLI::error( 'Cannot use --dry-run and --live together. Pick one.' );
        }

        $args = [
            'form_id'    => isset( $assoc['form'] ) ? (int) $assoc['form'] : null,
            'days'       => isset( $assoc['days'] ) ? (int) $assoc['days'] : null,
            'batch_size' => isset( $assoc['batch-size'] ) ? (int) $assoc['batch-size'] : null,
        ];

        // Set dry_run only when explicitly flagged; otherwise engine defers to global setting.
        if ( $has_dry_run ) {
            $args['dry_run'] = true;
        } elseif ( $has_live ) {
            $args['dry_run'] = false;
        }

        $effective_dry_run = $args['dry_run'] ?? $this->settings->get( 'dry_run', true );
        $mode              = $effective_dry_run ? 'DRY RUN' : 'LIVE';
        \WP_CLI::log( "Starting purge run ({$mode})..." );

        $stats = $this->engine->run( $args );

        if ( ! empty( $assoc['verbose'] ) && ! empty( $stats['details'] ) ) {
            foreach ( $stats['details'] as $detail ) {
                foreach ( $detail['files'] as $file ) {
                    \WP_CLI::log( sprintf(
                        '  [%s] Entry #%d, Field #%d: %s (%s)',
                        strtoupper( $file['action'] ),
                        $detail['entry_id'],
                        $file['field_id'],
                        $file['filename'],
                        size_format( $file['size'] )
                    ) );
                }
            }
        }

        \WP_CLI::success( sprintf(
            'Run %s complete. Entries: %d | Files: %d | Freed: %s | Errors: %d',
            $stats['run_id'],
            $stats['entries_processed'],
            $stats['files_deleted'],
            size_format( $stats['bytes_freed'] ),
            $stats['errors']
        ) );
    }

    /**
     * Show current plugin configuration and status.
     *
     * ## EXAMPLES
     *
     *     wp rr-retention status
     *
     * @param array $positional Positional arguments.
     * @param array $assoc      Associative arguments.
     */
    public function cmd_status( array $positional, array $assoc ): void {
        $all = $this->settings->get_all();

        \WP_CLI::log( 'Global Settings:' );
        \WP_CLI::log( sprintf( '  Enabled:      %s', $all['enabled'] ? 'Yes' : 'No' ) );
        \WP_CLI::log( sprintf( '  Dry Run:      %s', $all['dry_run'] ? 'Yes' : 'No' ) );
        \WP_CLI::log( sprintf( '  Retention:    %d %s', $all['retention_days'], $all['retention_unit'] ) );
        \WP_CLI::log( sprintf( '  Batch Size:   %d', $all['batch_size'] ) );
        \WP_CLI::log( sprintf( '  Target:       %s', is_array( $all['target_forms'] ) ? implode( ', ', $all['target_forms'] ) : $all['target_forms'] ) );
        \WP_CLI::log( sprintf( '  Excluded:     %s', ! empty( $all['excluded_forms'] ) ? implode( ', ', $all['excluded_forms'] ) : 'None' ) );
        \WP_CLI::log( sprintf( '  Logging:      %s', $all['log_actions'] ? 'Yes' : 'No' ) );
        \WP_CLI::log( sprintf( '  Email:        %s', $all['email_notification'] ?: 'None' ) );
    }

    /**
     * Preview what would be purged (alias for run --dry-run --verbose).
     *
     * ## OPTIONS
     *
     * [--form=<id>]
     * : Limit preview to a specific form ID.
     *
     * [--days=<n>]
     * : Override the retention period for this preview.
     *
     * [--batch-size=<n>]
     * : Override the batch size for this preview.
     *
     * ## EXAMPLES
     *
     *     wp rr-retention preview
     *     wp rr-retention preview --form=5 --days=60
     *     wp rr-retention preview --batch-size=500
     *
     * @param array $positional Positional arguments.
     * @param array $assoc      Associative arguments.
     */
    public function cmd_preview( array $positional, array $assoc ): void {
        $assoc['dry-run'] = true;
        $assoc['verbose'] = true;
        unset( $assoc['live'] );

        $this->cmd_run( $positional, $assoc );
    }

    /**
     * Display recent purge log entries.
     *
     * ## OPTIONS
     *
     * [--limit=<n>]
     * : Number of entries to show. Default 20.
     *
     * [--form=<id>]
     * : Filter by form ID.
     *
     * ## EXAMPLES
     *
     *     wp rr-retention log
     *     wp rr-retention log --limit=50 --form=5
     *
     * @param array $positional Positional arguments.
     * @param array $assoc      Associative arguments.
     */
    public function cmd_log( array $positional, array $assoc ): void {
        $entries = $this->logger->query( [
            'limit'   => isset( $assoc['limit'] ) ? (int) $assoc['limit'] : 20,
            'form_id' => isset( $assoc['form'] ) ? (int) $assoc['form'] : null,
        ] );

        if ( empty( $entries ) ) {
            \WP_CLI::log( 'No log entries found.' );
            return;
        }

        $table_data = array_map( function ( $row ) {
            return [
                'Date'     => $row['created_at'],
                'Action'   => $row['action'],
                'Form'     => $row['form_id'],
                'Entry'    => $row['entry_id'],
                'Filename' => $row['filename'],
                'Size'     => size_format( $row['file_size'] ),
                'Run ID'   => substr( $row['run_id'], 0, 8 ) . '...',
            ];
        }, $entries );

        \WP_CLI\Utils\format_items( 'table', $table_data, array_keys( $table_data[0] ) );
    }
}
