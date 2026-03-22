<?php
/**
 * Audit log: custom table creation, insert, and query.
 *
 * Manages the {prefix}rr_file_retention_log table that records every
 * file deletion (or dry-run/error) for audit and reporting purposes.
 *
 * @package RR_GF_File_Retention
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RR_Retention_Logger {

    /**
     * Get the full table name with prefix.
     *
     * @return string
     */
    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'rr_file_retention_log';
    }

    /**
     * Create the log table via dbDelta().
     *
     * Called on plugin activation. Safe to call multiple times.
     */
    public static function create_table(): void {
        global $wpdb;

        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entry_id BIGINT UNSIGNED NOT NULL,
            form_id INT UNSIGNED NOT NULL,
            field_id INT UNSIGNED NOT NULL,
            filename VARCHAR(500) NOT NULL DEFAULT '',
            file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            file_path VARCHAR(1000) NOT NULL DEFAULT '',
            action VARCHAR(20) NOT NULL DEFAULT 'deleted',
            error_message TEXT NULL,
            annotation TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            run_id VARCHAR(36) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY idx_entry_id (entry_id),
            KEY idx_form_id (form_id),
            KEY idx_run_id (run_id),
            KEY idx_created_at (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Drop the log table.
     *
     * Called on plugin uninstall only, never on deactivation.
     */
    public static function drop_table(): void {
        global $wpdb;
        $table = self::table_name();
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Insert a log entry.
     *
     * @param array{
     *     entry_id: int,
     *     form_id: int,
     *     field_id: int,
     *     filename: string,
     *     file_size: int,
     *     file_path: string,
     *     action: string,
     *     error_message: string|null,
     *     annotation: string,
     *     run_id: string,
     * } $data Log entry data.
     * @return int|false Inserted row ID or false on failure.
     */
    public function log( array $data ): int|false {
        global $wpdb;

        $result = $wpdb->insert(
            self::table_name(),
            [
                'entry_id'      => $data['entry_id'],
                'form_id'       => $data['form_id'],
                'field_id'      => $data['field_id'],
                'filename'      => $data['filename'],
                'file_size'     => $data['file_size'],
                'file_path'     => $data['file_path'],
                'action'        => $data['action'],
                'error_message' => $data['error_message'],
                'annotation'    => $data['annotation'],
                'run_id'        => $data['run_id'],
            ],
            [ '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Query recent log entries.
     *
     * @param array{
     *     limit?: int,
     *     form_id?: int|null,
     *     run_id?: string|null,
     *     action?: string|null,
     * } $args Query arguments.
     * @return array Log entry rows.
     */
    public function query( array $args = [] ): array {
        global $wpdb;

        $table  = self::table_name();
        $limit  = min( absint( $args['limit'] ?? 50 ), 500 );
        $where  = [];
        $values = [];

        if ( ! empty( $args['form_id'] ) ) {
            $where[]  = 'form_id = %d';
            $values[] = $args['form_id'];
        }

        if ( ! empty( $args['run_id'] ) ) {
            $where[]  = 'run_id = %s';
            $values[] = $args['run_id'];
        }

        if ( ! empty( $args['action'] ) ) {
            $where[]  = 'action = %s';
            $values[] = $args['action'];
        }

        $where_sql = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';

        $sql = "SELECT * FROM {$table} {$where_sql} ORDER BY created_at DESC LIMIT %d";
        $values[] = $limit;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A ) ?: [];
    }

    /**
     * Get aggregate stats for a specific run.
     *
     * @param string $run_id Run UUID.
     * @return array{files: int, bytes: int, errors: int, entries: int}
     */
    public function get_run_stats( string $run_id ): array {
        global $wpdb;

        $table = self::table_name();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*) as files,
                COALESCE(SUM(file_size), 0) as bytes,
                SUM(CASE WHEN action = 'error' THEN 1 ELSE 0 END) as errors,
                COUNT(DISTINCT entry_id) as entries
            FROM {$table}
            WHERE run_id = %s",
            $run_id
        ), ARRAY_A );

        return $row ?: [ 'files' => 0, 'bytes' => 0, 'errors' => 0, 'entries' => 0 ];
    }
}
