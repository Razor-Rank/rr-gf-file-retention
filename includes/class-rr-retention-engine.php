<?php
// This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public
// License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.

/**
 * Core purge engine.
 *
 * Queries eligible entries, identifies file upload fields, deletes files
 * from disk, updates entry metadata, and annotates entries with removal notes.
 *
 * Supports dry-run mode where all logic executes but no files are deleted
 * and no entries are modified.
 *
 * Processes one batch per execution. The next scheduled run picks up
 * remaining entries naturally since the query is date-based.
 *
 * Per-form overrides are respected: each form is queried with its own
 * effective retention period and enabled state.
 *
 * @package RR_GF_File_Retention
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RR_Retention_Engine {

    private RR_Retention_Settings $settings;
    private RR_Retention_Logger $logger;

    /**
     * @param RR_Retention_Settings $settings Global settings instance.
     * @param RR_Retention_Logger   $logger   Audit logger instance.
     */
    public function __construct( RR_Retention_Settings $settings, RR_Retention_Logger $logger ) {
        $this->settings = $settings;
        $this->logger   = $logger;
    }

    /**
     * Execute a purge run.
     *
     * Iterates each targeted form individually, resolving per-form overrides
     * for retention period and enabled state before querying entries.
     *
     * @param array{
     *     dry_run?: bool,
     *     days?: int,
     *     form_id?: int|null,
     *     batch_size?: int|null,
     * } $args Run-time overrides.
     * @return array{
     *     run_id: string,
     *     dry_run: bool,
     *     entries_processed: int,
     *     files_deleted: int,
     *     bytes_freed: int,
     *     errors: int,
     *     details: array<int, array>,
     * } Run statistics.
     */
    public function run( array $args = [] ): array {
        $run_id      = wp_generate_uuid4();
        $dry_run     = $args['dry_run'] ?? $this->settings->get( 'dry_run', true );
        $days_arg    = $args['days'] ?? null;
        $form_id_arg = $args['form_id'] ?? null;
        $batch_size  = $args['batch_size'] ?? (int) $this->settings->get( 'batch_size', 200 );

        $stats = [
            'run_id'            => $run_id,
            'dry_run'           => $dry_run,
            'entries_processed' => 0,
            'files_deleted'     => 0,
            'bytes_freed'       => 0,
            'errors'            => 0,
            'details'           => [],
        ];

        $candidate_form_ids = $this->resolve_target_forms( $form_id_arg );

        if ( empty( $candidate_form_ids ) ) {
            return $stats;
        }

        $form_helper  = new RR_Retention_Form();
        $remaining    = $batch_size;

        // Process each form with its own effective settings.
        foreach ( $candidate_form_ids as $form_id ) {
            if ( $remaining <= 0 ) {
                break;
            }

            // Skip forms that have no file upload fields.
            $form          = \GFAPI::get_form( $form_id );
            $upload_fields = is_array( $form ) ? $this->get_upload_fields( $form ) : [];

            if ( empty( $upload_fields ) ) {
                continue;
            }

            $effective = $form_helper->get_effective_settings( $form_id, $this->settings );

            // Skip forms disabled via per-form override.
            if ( ! empty( $effective['override_global'] ) && empty( $effective['enabled'] ) ) {
                continue;
            }

            // Determine the cutoff date for this form.
            // CLI --days override takes precedence over per-form settings.
            if ( $days_arg !== null ) {
                $cutoff = $this->settings->get_cutoff_date( $days_arg );
            } else {
                $form_days = (int) ( $effective['retention_days'] ?? $this->settings->get( 'retention_days', 30 ) );
                $form_unit = $effective['retention_unit'] ?? $this->settings->get( 'retention_unit', 'days' );
                $cutoff    = $this->calculate_cutoff( $form_days, $form_unit );
            }

            // Query only entries that have non-empty file upload values.
            $upload_field_ids = array_map( fn( $f ) => (string) $f->id, $upload_fields );
            $entry_ids        = $this->query_entry_ids_with_files( $form_id, $upload_field_ids, $cutoff, $remaining );

            if ( empty( $entry_ids ) ) {
                continue;
            }

            $entries = array_filter( array_map( '\\GFAPI::get_entry', $entry_ids ), 'is_array' );

            foreach ( $entries as $entry ) {
                $result = $this->process_entry( $entry, $run_id, $dry_run );

                $stats['entries_processed']++;
                $stats['files_deleted'] += $result['files_deleted'];
                $stats['bytes_freed']   += $result['bytes_freed'];
                $stats['errors']        += $result['errors'];
                $stats['details'][]      = $result;
                $remaining--;
            }
        }

        $this->maybe_send_notification( $stats );

        return $stats;
    }

    /**
     * Calculate a cutoff date from a retention period.
     *
     * @param int    $days Number of days or months.
     * @param string $unit 'days' or 'months'.
     * @return string MySQL datetime string.
     */
    private function calculate_cutoff( int $days, string $unit ): string {
        $interval = ( $unit === 'months' ) ? $days * 30 : $days;

        return gmdate( 'Y-m-d H:i:s', strtotime( "-{$interval} days" ) );
    }

    /**
     * Determine which form IDs to target for this run.
     *
     * Returns candidate form IDs based on global target/exclude lists.
     * Per-form enabled checks happen in run() after resolving effective settings.
     *
     * @param int|null $override_form_id Limit to a specific form.
     * @return int[] Array of form IDs.
     */
    private function resolve_target_forms( ?int $override_form_id ): array {
        if ( $override_form_id ) {
            return [ $override_form_id ];
        }

        $target   = $this->settings->get( 'target_forms', 'all' );
        $excluded = $this->settings->get( 'excluded_forms', [] );

        if ( $target === 'all' ) {
            $forms    = \GFAPI::get_forms();
            $form_ids = array_column( $forms, 'id' );
        } else {
            $form_ids = (array) $target;
        }

        return array_diff( array_map( 'intval', $form_ids ), array_map( 'intval', $excluded ) );
    }

    /**
     * Query entry IDs that have non-empty file upload values.
     *
     * Uses a direct database query joining gf_entry with gf_entry_meta
     * to skip entries whose file fields are empty or have been cleared by
     * previous runs. Only entries with actual file URLs count against the
     * batch limit.
     *
     * @param int      $form_id          Target form ID.
     * @param string[] $upload_field_ids Meta keys for file upload fields.
     * @param string   $cutoff           MySQL datetime cutoff string.
     * @param int      $batch_size       Max entries to return.
     * @return int[] Entry IDs.
     */
    private function query_entry_ids_with_files( int $form_id, array $upload_field_ids, string $cutoff, int $batch_size ): array {
        global $wpdb;

        $entry_table = $wpdb->prefix . 'gf_entry';
        $meta_table  = $wpdb->prefix . 'gf_entry_meta';

        $field_placeholders = implode( ',', array_fill( 0, count( $upload_field_ids ), '%s' ) );

        $query_args = array_merge(
            [ $form_id, $cutoff ],
            $upload_field_ids,
            [ $batch_size ]
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $wpdb->prepare(
            "SELECT DISTINCT e.id
            FROM {$entry_table} e
            INNER JOIN {$meta_table} em ON e.id = em.entry_id
            WHERE e.form_id = %d
              AND e.status = 'active'
              AND e.date_created < %s
              AND em.meta_key IN ({$field_placeholders})
              AND em.meta_value IS NOT NULL
              AND em.meta_value != ''
            ORDER BY e.date_created ASC
            LIMIT %d",
            $query_args
        );

        $results = $wpdb->get_col( $sql );

        return array_map( 'intval', $results );
    }

    /**
     * Process a single entry: find file fields, delete files, annotate.
     *
     * Clears each file field individually but writes only one entry note
     * listing all removed filenames across all fields.
     *
     * @param array  $entry   GF entry array.
     * @param string $run_id  UUID for this run.
     * @param bool   $dry_run Whether this is a dry run.
     * @return array Per-entry result stats.
     */
    private function process_entry( array $entry, string $run_id, bool $dry_run ): array {
        $result = [
            'entry_id'      => $entry['id'],
            'form_id'       => $entry['form_id'],
            'date_created'  => $entry['date_created'] ?? '',
            'files_deleted' => 0,
            'bytes_freed'   => 0,
            'errors'        => 0,
            'files'         => [],
        ];

        $form          = \GFAPI::get_form( $entry['form_id'] );
        $upload_fields = $this->get_upload_fields( $form );

        if ( empty( $upload_fields ) ) {
            return $result;
        }

        // Track which field IDs had successful deletions.
        $fields_with_deletions = [];

        foreach ( $upload_fields as $field ) {
            $field_id = $field->id;
            $value    = rgar( $entry, (string) $field_id );

            if ( empty( $value ) ) {
                continue;
            }

            $file_urls          = $this->parse_file_value( $value, $field );
            $field_had_deletion = false;

            foreach ( $file_urls as $file_url ) {
                $file_path = $this->url_to_path( $file_url );

                if ( ! $file_path || ! file_exists( $file_path ) ) {
                    continue;
                }

                $file_size = filesize( $file_path );
                $filename  = basename( $file_path );
                $action    = $dry_run ? 'dry_run' : 'deleted';
                $error_msg = null;

                if ( ! $dry_run ) {
                    if ( ! $this->is_safe_path( $file_path ) ) {
                        $action    = 'error';
                        $error_msg = 'Path outside uploads directory, skipped.';
                        $result['errors']++;
                    } else {
                        wp_delete_file( $file_path );
                        if ( file_exists( $file_path ) ) {
                            $action    = 'error';
                            $error_msg = 'wp_delete_file() did not remove the file.';
                            $result['errors']++;
                        } else {
                            $result['files_deleted']++;
                            $result['bytes_freed'] += $file_size;
                            $field_had_deletion = true;
                        }
                    }
                } else {
                    $result['files_deleted']++;
                    $result['bytes_freed'] += $file_size;
                }

                $annotation = $this->build_annotation( $entry['form_id'], $filename );

                // Log to audit table.
                if ( $this->settings->get( 'log_actions', true ) ) {
                    $this->logger->log( [
                        'entry_id'      => $entry['id'],
                        'form_id'       => $entry['form_id'],
                        'field_id'      => $field_id,
                        'filename'      => $filename,
                        'file_size'     => $file_size,
                        'file_path'     => $file_path,
                        'action'        => $action,
                        'error_message' => $error_msg,
                        'annotation'    => $annotation,
                        'run_id'        => $run_id,
                    ] );
                }

                $result['files'][] = [
                    'field_id' => $field_id,
                    'filename' => $filename,
                    'size'     => $file_size,
                    'action'   => $action,
                ];
            }

            // Clear this field's value if files were deleted from it.
            if ( ! $dry_run && $field_had_deletion ) {
                \GFAPI::update_entry_field( $entry['id'], $field_id, '' );
                $fields_with_deletions[] = $field_id;
            }
        }

        // Add ONE note per entry listing all deleted filenames across all fields.
        if ( ! $dry_run && ! empty( $fields_with_deletions ) ) {
            $deleted_filenames = array_map(
                fn( array $f ): string => $f['filename'],
                array_filter( $result['files'], fn( array $f ): bool => $f['action'] === 'deleted' )
            );

            if ( ! empty( $deleted_filenames ) ) {
                $note_text = $this->build_annotation(
                    $entry['form_id'],
                    implode( ', ', $deleted_filenames )
                );
                \GFAPI::add_note( $entry['id'], 0, 'RR File Retention', $note_text );
            }
        }

        return $result;
    }

    /**
     * Get file upload fields from a form.
     *
     * @param array $form GF form array.
     * @return array Upload field objects.
     */
    private function get_upload_fields( array $form ): array {
        $upload_fields = [];

        if ( empty( $form['fields'] ) ) {
            return $upload_fields;
        }

        foreach ( $form['fields'] as $field ) {
            if ( in_array( $field->type, [ 'fileupload', 'post_image' ], true ) ) {
                $upload_fields[] = $field;
            }
        }

        return $upload_fields;
    }

    /**
     * Parse a file field value into an array of URLs.
     *
     * Single file uploads store a URL string. Multi-file uploads store a JSON array.
     *
     * @param string $value Raw field value.
     * @param object $field GF field object.
     * @return string[] File URLs.
     */
    private function parse_file_value( string $value, object $field ): array {
        if ( ! empty( $field->multipleFiles ) ) {
            $decoded = json_decode( $value, true );
            return is_array( $decoded ) ? $decoded : [];
        }

        return [ $value ];
    }

    /**
     * Convert a file URL to a local filesystem path.
     *
     * @param string $url File URL.
     * @return string|null Local path or null if conversion fails.
     */
    private function url_to_path( string $url ): ?string {
        $upload_dir = wp_upload_dir();
        $base_url   = $upload_dir['baseurl'];
        $base_dir   = $upload_dir['basedir'];

        if ( strpos( $url, $base_url ) === false ) {
            return null;
        }

        $relative = str_replace( $base_url, '', $url );
        $path     = $base_dir . $relative;

        return realpath( $path ) ?: null;
    }

    /**
     * Validate that a path is within the WordPress uploads directory.
     *
     * Prevents directory traversal attacks.
     *
     * @param string $path Filesystem path to validate.
     * @return bool True if safe.
     */
    private function is_safe_path( string $path ): bool {
        $upload_dir = wp_upload_dir();
        $base_dir   = realpath( $upload_dir['basedir'] );

        if ( ! $base_dir ) {
            return false;
        }

        $real_path = realpath( $path );

        return $real_path && strpos( $real_path, $base_dir ) === 0;
    }

    /**
     * Build the annotation string for an entry note.
     *
     * @param int    $form_id  Form ID (to check per-form template).
     * @param string $filename Filename(s) being removed.
     * @return string Rendered annotation.
     */
    private function build_annotation( int $form_id, string $filename ): string {
        $form_handler = new RR_Retention_Form();
        $effective    = $form_handler->get_effective_settings( $form_id, $this->settings );

        $template = ! empty( $effective['annotation_template'] )
            ? $effective['annotation_template']
            : $this->settings->get( 'annotation_template' );

        $days = $effective['retention_days'] ?? $this->settings->get( 'retention_days', 30 );

        return str_replace(
            [ '{date}', '{days}', '{filename}' ],
            [ current_time( 'Y-m-d' ), $days, $filename ],
            $template
        );
    }

    /**
     * Send email notification if configured.
     *
     * @param array $stats Run statistics.
     */
    private function maybe_send_notification( array $stats ): void {
        $email = $this->settings->get( 'email_notification', '' );

        if ( empty( $email ) || ! is_email( $email ) ) {
            return;
        }

        $mode    = $stats['dry_run'] ? 'DRY RUN' : 'LIVE';
        $subject = sprintf( '[%s] GF File Retention - %d files, %s freed',
            $mode,
            $stats['files_deleted'],
            size_format( $stats['bytes_freed'] )
        );

        $body = sprintf(
            "Run ID: %s\nMode: %s\nEntries processed: %d\nFiles deleted: %d\nSpace freed: %s\nErrors: %d",
            $stats['run_id'],
            $mode,
            $stats['entries_processed'],
            $stats['files_deleted'],
            size_format( $stats['bytes_freed'] ),
            $stats['errors']
        );

        wp_mail( $email, $subject, $body );
    }
}
