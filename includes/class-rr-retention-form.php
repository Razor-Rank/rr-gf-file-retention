<?php
/**
 * Per-form settings: Gravity Forms > Form Settings > File Retention tab.
 *
 * Adds a "File Retention" tab to each form's settings where the global
 * policy can be overridden with form-specific retention rules.
 *
 * Per-form settings stored via: gform_get_meta( $form_id, 'rr_file_retention' )
 *
 * @package RR_GF_File_Retention
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RR_Retention_Form {

    /** @var string GF form meta key for per-form settings. */
    const META_KEY = 'rr_file_retention';

    /**
     * Default per-form settings.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array {
        return [
            'override_global'     => false,
            'enabled'             => true,
            'retention_days'      => 30,
            'retention_unit'      => 'days',
            'annotation_template' => '',
        ];
    }

    /**
     * Get the effective settings for a form.
     *
     * Returns per-form overrides if enabled, otherwise global settings.
     *
     * @param int                  $form_id Form ID.
     * @param RR_Retention_Settings|null $global_settings Global settings instance.
     * @return array<string, mixed>
     */
    public function get_effective_settings( int $form_id, ?RR_Retention_Settings $global_settings = null ): array {
        $form_meta = gform_get_meta( $form_id, self::META_KEY );

        if ( is_array( $form_meta ) && ! empty( $form_meta['override_global'] ) ) {
            return wp_parse_args( $form_meta, self::defaults() );
        }

        if ( $global_settings ) {
            return $global_settings->get_all();
        }

        $stored = get_option( RR_Retention_Settings::OPTION_KEY, [] );
        return wp_parse_args( $stored, RR_Retention_Settings::defaults() );
    }

    /**
     * Save per-form settings.
     *
     * @param int                  $form_id Form ID.
     * @param array<string, mixed> $values  Settings to save.
     */
    public function save( int $form_id, array $values ): void {
        $clean = self::defaults();

        $clean['override_global'] = ! empty( $values['override_global'] );
        $clean['enabled']         = ! empty( $values['enabled'] );
        $clean['retention_days']  = max( 1, absint( $values['retention_days'] ?? 30 ) );
        $clean['retention_unit']  = in_array( $values['retention_unit'] ?? '', [ 'days', 'months' ], true )
            ? $values['retention_unit']
            : 'days';

        if ( ! empty( $values['annotation_template'] ) ) {
            $clean['annotation_template'] = sanitize_textarea_field( $values['annotation_template'] );
        }

        gform_update_meta( $form_id, self::META_KEY, $clean );
    }

    /**
     * Hook into Gravity Forms to register the settings tab.
     */
    public function init(): void {
        add_filter( 'gform_form_settings_menu', [ $this, 'add_menu_item' ], 10, 2 );
        add_action( 'gform_form_settings_page_rr-file-retention', [ $this, 'render_settings_page' ] );
    }

    /**
     * Add "File Retention" tab to the form settings menu.
     *
     * @param array $menu_items Existing menu items.
     * @param int   $form_id   Current form ID.
     * @return array Modified menu items.
     */
    public function add_menu_item( array $menu_items, int $form_id ): array {
        $menu_items[] = [
            'name'  => 'rr-file-retention',
            'label' => __( 'File Retention', 'rr-gf-file-retention' ),
            'icon'  => 'gform-icon--trash',
        ];

        return $menu_items;
    }

    /**
     * Render the per-form settings page.
     */
    public function render_settings_page(): void {
        if ( ! current_user_can( 'gravityforms_edit_forms' ) ) {
            return;
        }

        // TODO: Implement per-form settings UI in implementation phase.
        echo '<div class="wrap">';
        echo '<h3>' . esc_html__( 'File Retention Settings', 'rr-gf-file-retention' ) . '</h3>';
        echo '<p>' . esc_html__( 'Per-form file retention overrides will be configured here.', 'rr-gf-file-retention' ) . '</p>';
        echo '</div>';
    }
}
