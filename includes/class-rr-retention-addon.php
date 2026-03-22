<?php
/**
 * Gravity Forms Add-On: registers global and per-form settings via GF's native framework.
 *
 * Extends GFAddOn to provide:
 * - Global settings page under Forms > Settings > File Retention
 * - Per-form settings tab under each form's Settings > File Retention
 * - RR chevron icon in the GF settings sidebar
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

    protected $_version                  = '1.0.0';
    protected $_min_gravityforms_version = '2.5';
    protected $_slug                     = 'rr-gf-file-retention';
    protected $_path                     = 'rr-gf-file-retention/rr-gf-file-retention.php';
    protected $_title                    = 'RR GF File Retention';
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
                        'label'         => esc_html__( 'Enable File Retention', 'rr-gf-file-retention' ),
                        'type'          => 'toggle',
                        'default_value' => false,
                        'tooltip'       => esc_html__( 'Master switch. Nothing runs until this is enabled.', 'rr-gf-file-retention' ),
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
        ];
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
     * Return the icon URL for the GF Settings sidebar.
     *
     * @return string URL to the RR chevron icon.
     */
    public function get_menu_icon(): string {
        return $this->get_base_url() . '/assets/images/icon-128.png';
    }
}
