<?php
/**
 * Global settings page: WP Admin > Settings > GF File Retention.
 *
 * Registers the settings page, renders the form, and manages the
 * rr_gf_file_retention_settings option (serialized array).
 *
 * @package RR_GF_File_Retention
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RR_Retention_Settings {

    /** @var string Option key for all global settings. */
    const OPTION_KEY = 'rr_gf_file_retention_settings';

    /**
     * Return the default settings array.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array {
        return [
            'enabled'             => false,
            'retention_days'      => 30,
            'retention_unit'      => 'days',
            'target_forms'        => 'all',
            'excluded_forms'      => [],
            'annotation_template' => 'File removed on {date} per {days}-day retention policy.',
            'dry_run'             => true,
            'log_actions'         => true,
            'email_notification'  => '',
        ];
    }

    /**
     * Get all settings merged with defaults.
     *
     * @return array<string, mixed>
     */
    public function get_all(): array {
        $stored = get_option( self::OPTION_KEY, [] );
        return wp_parse_args( $stored, self::defaults() );
    }

    /**
     * Get a single setting value.
     *
     * @param string $key     Setting key.
     * @param mixed  $default Fallback value.
     * @return mixed
     */
    public function get( string $key, mixed $default = null ): mixed {
        $settings = $this->get_all();
        return $settings[ $key ] ?? $default;
    }

    /**
     * Update settings.
     *
     * @param array<string, mixed> $values Key-value pairs to merge.
     */
    public function update( array $values ): void {
        $current = $this->get_all();
        $merged  = array_merge( $current, $values );
        update_option( self::OPTION_KEY, $merged );
    }

    /**
     * Calculate the retention cutoff date.
     *
     * @param int|null    $days Override retention period.
     * @param string|null $unit Override retention unit.
     * @return string MySQL datetime string.
     */
    public function get_cutoff_date( ?int $days = null, ?string $unit = null ): string {
        $days = $days ?? (int) $this->get( 'retention_days', 30 );
        $unit = $unit ?? $this->get( 'retention_unit', 'days' );

        $interval = ( $unit === 'months' ) ? $days * 30 : $days;

        return gmdate( 'Y-m-d H:i:s', strtotime( "-{$interval} days" ) );
    }

    /**
     * Hook into WordPress to register the settings page.
     */
    public function init(): void {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    /**
     * Register the admin menu item under Settings.
     */
    public function register_menu(): void {
        add_options_page(
            __( 'GF File Retention', 'rr-gf-file-retention' ),
            __( 'GF File Retention', 'rr-gf-file-retention' ),
            'manage_options',
            'rr-gf-file-retention',
            [ $this, 'render_page' ]
        );
    }

    /**
     * Register settings sections and fields.
     */
    public function register_settings(): void {
        register_setting( 'rr_gf_file_retention', self::OPTION_KEY, [
            'type'              => 'array',
            'sanitize_callback' => [ $this, 'sanitize' ],
        ] );

        // TODO: Add settings sections and fields in implementation phase.
    }

    /**
     * Sanitize settings on save.
     *
     * @param array<string, mixed> $input Raw input.
     * @return array<string, mixed> Sanitized settings.
     */
    public function sanitize( array $input ): array {
        $clean = self::defaults();

        $clean['enabled']        = ! empty( $input['enabled'] );
        $clean['dry_run']        = ! empty( $input['dry_run'] );
        $clean['log_actions']    = ! empty( $input['log_actions'] );
        $clean['retention_days'] = max( 1, absint( $input['retention_days'] ?? 30 ) );
        $clean['retention_unit'] = in_array( $input['retention_unit'] ?? '', [ 'days', 'months' ], true )
            ? $input['retention_unit']
            : 'days';

        if ( ! empty( $input['annotation_template'] ) ) {
            $clean['annotation_template'] = sanitize_textarea_field( $input['annotation_template'] );
        }

        if ( ! empty( $input['email_notification'] ) ) {
            $clean['email_notification'] = sanitize_email( $input['email_notification'] );
        }

        if ( isset( $input['excluded_forms'] ) && is_array( $input['excluded_forms'] ) ) {
            $clean['excluded_forms'] = array_map( 'absint', $input['excluded_forms'] );
        }

        $clean['target_forms'] = ( isset( $input['target_forms'] ) && $input['target_forms'] !== 'all' && is_array( $input['target_forms'] ) )
            ? array_map( 'absint', $input['target_forms'] )
            : 'all';

        return $clean;
    }

    /**
     * Enqueue admin CSS and JS on the settings page only.
     *
     * @param string $hook_suffix Current admin page hook.
     */
    public function enqueue_assets( string $hook_suffix ): void {
        if ( $hook_suffix !== 'settings_page_rr-gf-file-retention' ) {
            return;
        }

        wp_enqueue_style(
            'rr-gf-file-retention-admin',
            RR_GF_FILE_RETENTION_URL . 'assets/css/admin.css',
            [],
            RR_GF_FILE_RETENTION_VERSION
        );

        wp_enqueue_script(
            'rr-gf-file-retention-admin',
            RR_GF_FILE_RETENTION_URL . 'assets/js/admin.js',
            [],
            RR_GF_FILE_RETENTION_VERSION,
            true
        );
    }

    /**
     * Render the settings page.
     */
    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // TODO: Implement settings page HTML in implementation phase.
        echo '<div class="wrap">';
        echo '<h1>' . esc_html( get_admin_page_title() ) . '</h1>';
        echo '<form action="options.php" method="post">';
        settings_fields( 'rr_gf_file_retention' );
        do_settings_sections( 'rr-gf-file-retention' );
        submit_button();
        echo '</form>';
        echo '</div>';
    }
}
