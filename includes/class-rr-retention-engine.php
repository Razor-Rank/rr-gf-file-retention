<?php
/**
 * Core purge engine.
 *
 * Queries eligible entries, identifies file upload fields, deletes files
 * from disk, updates entry metadata, and annotates entries with removal notes.
 *
 * Supports dry-run mode where all logic executes but no files are deleted
 * and no entries are modified.
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
     * @param array{
     *     dry_run?: bool,
     *     days?: int,
     *     form_id?: int|null,
     *     verbose?: bool,
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
        $run_id  = wp_generate_uuid4();
        $dry_run = $args['dry_run'] ?? $this->settings->get( 'dry_run', true );
        $days    = $args['days'] ?? null;
        $form_id = $args['form_id'] ?? null;

        $cutoff = $this->settings->get_cutoff_date( $days );

        $stats = [
            'run_id'            => $run_id,
            'dry_run'           => $dry_run,
            'entries_processed' => 0,
            'files_deleted'     => 0,
            'bytes_freed'       => 0,
            'errors'            => 0,
            'details'           => [],
        ];

        $form_ids = $this->resolve_target_forms( $form_id );

        if ( empty( $form_ids ) ) {
            return $stats;
        }

        $entries = $this->query_eligible_entries( $form_ids, $cutoff );

        foreach ( $entries as $entry ) {
            $result = $this->process_entry( $entry, $run_id, $dry_run );

            $stats['entries_processed']++;
            $stats['files_deleted'] += $result['files_deleted'];
            $stats['bytes_freed']   += $result['bytes_freed'];
            $stats['errors']        += $result['errors'];
            $stats['details'][]      = $result;
        }

        $this->maybe_send_notification( $stats );

        return $stats;
    }

    /**
     * Determine which form IDs to target for this run.
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
     * Query GF entries older than the cutoff date on the target forms.
     *
     * @param int[]  $form_ids Target form IDs.
     * @param string $cutoff   MySQL datetime cutoff string.
     * @return array GF entry arrays.
     */
    private function query_eligible_entries( array $form_ids, string $cutoff ): array {
        $search_criteria = [
            'status'        => 'active',
            'field_filters' => [],
            'start_date'    => null,
            'end_date'      => $cutoff,
        ];

        $entries = \GFAPI::get_entries( $form_ids, $search_criteria );

        return is_array( $entries ) ? $entries : [];
    }

    /**
     * Process a single entry: find file fields, delete files, annotate.
     *
     * @param array  $entry  GF entry array.
     * @param string $run_id UUID for this run.
     * @param bool   $dry_run Whether this is a dry run.
     * @return array Per-entry result stats.
     */
    private function process_entry( array $entry, string $run_id, bool $dry_run ): array {
        $result = [
            'entry_id'      => $entry['id'],
            'form_id'       => $entry['form_id'],
            'files_deleted' => 0,
            'bytes_freed'   => 0,
            'errors'        => 0,
            'files'         => [],
        ];

        $form         = \GFAPI::get_form( $entry['form_id'] );
        $upload_fields = $this->get_upload_fields( $form );

        if ( empty( $upload_fields ) ) {
            return $result;
        }

        foreach ( $upload_fields as $field ) {
            $field_id = $field->id;
            $value    = rgar( $entry, (string) $field_id );

            if ( empty( $value ) ) {
                continue;
            }

            $file_urls = $this->parse_file_value( $value, $field );

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
                        $deleted = wp_delete_file( $file_path );
                        if ( file_exists( $file_path ) ) {
                            $action    = 'error';
                            $error_msg = 'wp_delete_file() did not remove the file.';
                            $result['errors']++;
                        } else {
                            $result['files_deleted']++;
                            $result['bytes_freed'] += $file_size;
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

            // Clear the field value and add entry note (only if not dry run and files were deleted).
            if ( ! $dry_run && $result['files_deleted'] > 0 ) {
                \GFAPI::update_entry_field( $entry['id'], $field_id, '' );

                $filenames   = array_column( $result['files'], 'filename' );
                $note_text   = $this->build_annotation( $entry['form_id'], implode( ', ', $filenames ) );
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
