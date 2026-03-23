<?php
/**
 * Gravity Forms Add-On: registers global and per-form settings via GF's native framework.
 *
 * Extends GFAddOn to provide:
 * - Global settings page under Forms > Settings > File Retention
 * - Per-form settings tab under each form's Settings > File Retention
 * - Inline SVG icon in the GF settings sidebar
 * - AJAX-powered dry-run preview and live cleanup on the settings page
 *
 * Boots the purge engine, cron scheduler, and WP-CLI commands on init.
 *
 * @package RR_GF_File_Retention
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RR_Retention_Addon extends \GFAddOn {

    protected $_version                  = '0.4.1';
    protected $_min_gravityforms_version = '2.5';
    protected $_slug                     = 'rr-gf-file-retention';
    protected $_path                     = 'rr-gf-file-retention/rr-gf-file-retention.php';
    protected $_title                    = 'Gravity Forms File Retention by Razor Rank';
    protected $_short_title              = 'File Retention';

    private static ?self $_instance = null;

    /**
     * Singleton accessor.
     */
    public static function get_instance(): self {
        if ( self::$_instance === null ) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    public function __construct() {
        $this->_full_path = RR_GF_FILE_RETENTION_FILE;

        parent::__construct();
    }

    /**
     * Boot operational components after GF and the addon are ready.
     */
    public function init(): void {
        parent::init();

        $logger   = new RR_Retention_Logger();
        $settings = new RR_Retention_Settings();
        $engine   = new RR_Retention_Engine( $settings, $logger );
        $cron     = new RR_Retention_Cron( $engine, $settings );

        $cron->init();

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            $cli = new RR_Retention_CLI( $engine, $settings, $logger );
            $cli->register();
        }
    }

    /**
     * AJAX initialization: register handlers for admin-ajax.php requests.
     *
     * GFAddOn calls init_ajax() (not init_admin()) when RG_CURRENT_PAGE
     * is admin-ajax.php, so AJAX actions must be registered here.
     */
    public function init_ajax(): void {
        parent::init_ajax();

        add_action( 'wp_ajax_rr_retention_preview', [ $this, 'ajax_preview' ] );
        add_action( 'wp_ajax_rr_retention_run_now', [ $this, 'ajax_run_now' ] );
    }

    /**
     * Minimum requirements for the addon.
     *
     * @return array
     */
    public function minimum_requirements(): array {
        return [
            'gravityforms' => [
                'version' => '2.5',
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Scripts and Styles
    // -------------------------------------------------------------------------

    /**
     * Register scripts for the plugin settings page.
     *
     * @return array Script definitions.
     */
    public function scripts(): array {
        $scripts = parent::scripts();

        $scripts[] = [
            'handle'  => 'rr-gf-file-retention-admin',
            'src'     => $this->get_base_url() . '/assets/js/admin.js',
            'version' => $this->_version,
            'deps'    => [ 'jquery' ],
            'enqueue' => [
                [ 'admin_page' => [ 'plugin_settings' ] ],
            ],
        ];

        return $scripts;
    }

    /**
     * Register styles for the plugin settings page.
     *
     * @return array Style definitions.
     */
    public function styles(): array {
        $styles = parent::styles();

        $styles[] = [
            'handle'  => 'rr-gf-file-retention-admin',
            'src'     => $this->get_base_url() . '/assets/css/admin.css',
            'version' => $this->_version,
            'enqueue' => [
                [ 'admin_page' => [ 'plugin_settings' ] ],
            ],
        ];

        return $styles;
    }

    // -------------------------------------------------------------------------
    // Global plugin settings (Forms > Settings > File Retention)
    // -------------------------------------------------------------------------

    /**
     * Define the global settings fields.
     *
     * @return array GF settings field arrays.
     */
    public function plugin_settings_fields(): array {
        return [
            [
                'title'  => esc_html__( 'File Retention Settings', 'rr-gf-file-retention' ),
                'fields' => [
                    [
                        'name'          => 'enabled',
                        'label'         => esc_html__( 'Enable Automatic File Cleanup', 'rr-gf-file-retention' ),
                        'type'          => 'toggle',
                        'default_value' => false,
                        'tooltip'       => esc_html__( 'Master switch. When enabled, uploaded files older than the retention period are automatically cleaned up on the daily schedule.', 'rr-gf-file-retention' ),
                    ],
                    [
                        'name'          => 'dry_run',
                        'label'         => esc_html__( 'Dry Run Mode', 'rr-gf-file-retention' ),
                        'type'          => 'toggle',
                        'default_value' => true,
                        'tooltip'       => esc_html__( 'When enabled, logs what would be deleted but does not actually delete files.', 'rr-gf-file-retention' ),
                    ],
                    [
                        'name'          => 'retention_days',
                        'label'         => esc_html__( 'Retention Period', 'rr-gf-file-retention' ),
                        'type'          => 'text',
                        'input_type'    => 'number',
                        'default_value' => '30',
                        'class'         => 'small',
                        'tooltip'       => esc_html__( 'Files older than this are eligible for purging.', 'rr-gf-file-retention' ),
                    ],
                    [
                        'name'          => 'retention_unit',
                        'label'         => esc_html__( 'Retention Unit', 'rr-gf-file-retention' ),
                        'type'          => 'select',
                        'default_value' => 'days',
                        'choices'       => [
                            [ 'label' => esc_html__( 'Days', 'rr-gf-file-retention' ), 'value' => 'days' ],
                            [ 'label' => esc_html__( 'Months', 'rr-gf-file-retention' ), 'value' => 'months' ],
                        ],
                    ],
                    [
                        'name'          => 'batch_size',
                        'label'         => esc_html__( 'Batch Size', 'rr-gf-file-retention' ),
                        'type'          => 'text',
                        'input_type'    => 'number',
                        'default_value' => '200',
                        'class'         => 'small',
                        'tooltip'       => esc_html__( 'Max entries to process per run. Remaining entries are processed on the next scheduled run.', 'rr-gf-file-retention' ),
                    ],
                    [
                        'name'          => 'annotation_template',
                        'label'         => esc_html__( 'Annotation Template', 'rr-gf-file-retention' ),
                        'type'          => 'textarea',
                        'default_value' => 'File removed on {date} per {days}-day retention policy.',
                        'class'         => 'medium',
                        'tooltip'       => esc_html__( 'Note added to entries when files are purged. Placeholders: {date}, {days}, {filename}', 'rr-gf-file-retention' ),
                    ],
                    [
                        'name'          => 'log_actions',
                        'label'         => esc_html__( 'Log Retention Actions', 'rr-gf-file-retention' ),
                        'type'          => 'toggle',
                        'default_value' => true,
                        'tooltip'       => esc_html__( 'Write to the audit log table for every file action.', 'rr-gf-file-retention' ),
                    ],
                    [
                        'name'          => 'email_notification',
                        'label'         => esc_html__( 'Email Notification', 'rr-gf-file-retention' ),
                        'type'          => 'text',
                        'input_type'    => 'email',
                        'default_value' => '',
                        'class'         => 'medium',
                        'tooltip'       => esc_html__( 'Send a summary email after each purge run. Leave empty to disable.', 'rr-gf-file-retention' ),
                    ],
                ],
            ],
            [
                'title'  => esc_html__( 'Actions', 'rr-gf-file-retention' ),
                'fields' => [
                    [
                        'name'  => 'action_buttons',
                        'label' => esc_html__( 'Run Manually', 'rr-gf-file-retention' ),
                        'type'  => 'rr_action_buttons',
                    ],
                ],
            ],
        ];
    }

    /**
     * Render the action buttons field (Preview + Cleanup Now).
     *
     * @param array|object $field Field definition.
     */
    public function settings_rr_action_buttons( $field ): void {
        $preview_nonce = wp_create_nonce( 'rr_retention_preview' );
        $run_nonce     = wp_create_nonce( 'rr_retention_run_now' );

        $settings       = new RR_Retention_Settings();
        $retention_days = (int) $settings->get( 'retention_days', 30 );
        $retention_unit = $settings->get( 'retention_unit', 'days' );

        // Build per-form override list for the confirm dialog.
        $form_overrides = [];
        $forms          = \GFAPI::get_forms();

        foreach ( $forms as $gf_form ) {
            $form_meta = $this->get_form_settings( $gf_form );

            if ( is_array( $form_meta ) && ! empty( $form_meta['override_global'] ) ) {
                $form_overrides[] = [
                    'form' => $gf_form['title'],
                    'days' => (int) ( $form_meta['retention_days'] ?? $retention_days ),
                    'unit' => $form_meta['retention_unit'] ?? $retention_unit,
                ];
            }
        }

        // Preview button (neutral).
        echo '<button type="button" id="rr-retention-preview-btn" class="button button-secondary" '
            . 'data-nonce="' . esc_attr( $preview_nonce ) . '">'
            . esc_html__( 'Run Preview', 'rr-gf-file-retention' )
            . '</button> ';

        // Cleanup Now button (destructive).
        echo '<button type="button" id="rr-retention-run-now-btn" class="button rr-retention-btn-danger" '
            . 'data-nonce="' . esc_attr( $run_nonce ) . '" '
            . 'data-retention-days="' . esc_attr( (string) $retention_days ) . '" '
            . 'data-retention-unit="' . esc_attr( $retention_unit ) . '" '
            . 'data-form-overrides="' . esc_attr( wp_json_encode( $form_overrides ) ) . '">'
            . esc_html__( 'Run Cleanup Now', 'rr-gf-file-retention' )
            . '</button> ';

        echo '<span id="rr-retention-spinner" class="spinner" style="float:none;vertical-align:middle;"></span> ';

        // Clear Results link (inline with buttons, hidden until results are displayed).
        echo '<a href="#" id="rr-retention-clear-results" class="rr-retention-clear-link" style="display:none;">'
            . esc_html__( 'Clear Results', 'rr-gf-file-retention' )
            . '</a>';

        echo '<p class="description">'
            . esc_html__( 'Preview shows what would be deleted. Cleanup Now permanently deletes files matching saved settings.', 'rr-gf-file-retention' )
            . '</p>';

        echo '<div id="rr-retention-results"></div>';
    }

    // -------------------------------------------------------------------------
    // AJAX handlers
    // -------------------------------------------------------------------------

    /**
     * Handle the AJAX preview request (dry run).
     */
    public function ajax_preview(): void {
        check_ajax_referer( 'rr_retention_preview', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
        }

        $stats = $this->run_engine( true );

        wp_send_json_success( $stats );
    }

    /**
     * Handle the AJAX "Run Cleanup Now" request (live deletion).
     */
    public function ajax_run_now(): void {
        check_ajax_referer( 'rr_retention_run_now', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
        }

        $stats = $this->run_engine( false );

        wp_send_json_success( $stats );
    }

    /**
     * Run the purge engine and enrich results with form names.
     *
     * @param bool $dry_run Whether to run in dry-run mode.
     * @return array Enriched run statistics.
     */
    private function run_engine( bool $dry_run ): array {
        $logger   = new RR_Retention_Logger();
        $settings = new RR_Retention_Settings();
        $engine   = new RR_Retention_Engine( $settings, $logger );

        $stats = $engine->run( [ 'dry_run' => $dry_run ] );

        // Enrich each entry detail with the form name.
        $form_names = [];

        foreach ( $stats['details'] as &$detail ) {
            $fid = (int) $detail['form_id'];

            if ( ! isset( $form_names[ $fid ] ) ) {
                $form = \GFAPI::get_form( $fid );
                $form_names[ $fid ] = is_array( $form ) ? $form['title'] : 'Form ' . $fid;
            }

            $detail['form_name'] = $form_names[ $fid ];
        }
        unset( $detail );

        return $stats;
    }

    // -------------------------------------------------------------------------
    // Per-form settings (Form Settings > File Retention tab)
    // -------------------------------------------------------------------------

    /**
     * Define the per-form settings fields.
     *
     * @param array $form Current form object.
     * @return array GF settings field arrays.
     */
    public function form_settings_fields( $form ): array {
        return [
            [
                'title'  => esc_html__( 'File Retention Overrides', 'rr-gf-file-retention' ),
                'fields' => [
                    [
                        'name'          => 'override_global',
                        'label'         => esc_html__( 'Override Global Settings', 'rr-gf-file-retention' ),
                        'type'          => 'toggle',
                        'default_value' => false,
                        'tooltip'       => esc_html__( 'When off, this form uses global settings. When on, the settings below take full precedence.', 'rr-gf-file-retention' ),
                    ],
                    [
                        'name'          => 'enabled',
                        'label'         => esc_html__( 'Enabled', 'rr-gf-file-retention' ),
                        'type'          => 'toggle',
                        'default_value' => true,
                        'dependency'    => [
                            'live'   => true,
                            'fields' => [ [ 'field' => 'override_global' ] ],
                        ],
                    ],
                    [
                        'name'          => 'retention_days',
                        'label'         => esc_html__( 'Retention Period', 'rr-gf-file-retention' ),
                        'type'          => 'text',
                        'input_type'    => 'number',
                        'default_value' => '30',
                        'class'         => 'small',
                        'dependency'    => [
                            'live'   => true,
                            'fields' => [ [ 'field' => 'override_global' ] ],
                        ],
                    ],
                    [
                        'name'          => 'retention_unit',
                        'label'         => esc_html__( 'Retention Unit', 'rr-gf-file-retention' ),
                        'type'          => 'select',
                        'default_value' => 'days',
                        'choices'       => [
                            [ 'label' => esc_html__( 'Days', 'rr-gf-file-retention' ), 'value' => 'days' ],
                            [ 'label' => esc_html__( 'Months', 'rr-gf-file-retention' ), 'value' => 'months' ],
                        ],
                        'dependency'    => [
                            'live'   => true,
                            'fields' => [ [ 'field' => 'override_global' ] ],
                        ],
                    ],
                    [
                        'name'          => 'annotation_template',
                        'label'         => esc_html__( 'Annotation Template', 'rr-gf-file-retention' ),
                        'type'          => 'textarea',
                        'default_value' => '',
                        'class'         => 'medium',
                        'tooltip'       => esc_html__( 'Leave empty to use the global template.', 'rr-gf-file-retention' ),
                        'dependency'    => [
                            'live'   => true,
                            'fields' => [ [ 'field' => 'override_global' ] ],
                        ],
                    ],
                ],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Branding
    // -------------------------------------------------------------------------

    /**
     * Return an inline SVG icon for the GF Settings sidebar.
     *
     * @return string Inline SVG markup.
     */
    public function get_menu_icon(): string {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">'
            . '<path d="M11 1H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h3.5a5.5 5.5 0 0 1-.5-2.3'
            . 'A5.5 5.5 0 0 1 13.5 11c.5 0 1 .07 1.5.2V7l-4-6ZM10 3v5h5"/>'
            . '<circle cx="13.5" cy="15.5" r="3.8" fill="none" stroke="currentColor" stroke-width="1.3"/>'
            . '<path d="M13.5 13.5v2l1.5 1.2" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>'
            . '</svg>';
    }
}
