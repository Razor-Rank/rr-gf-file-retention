<?php
// This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public
// License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.

/**
 * Per-form settings helper.
 *
 * Reads per-form overrides stored by the GFAddOn form settings UI and
 * resolves the effective settings (per-form override vs global fallback).
 *
 * All settings storage and UI rendering are handled by RR_Retention_Addon.
 * This class is a read-only utility for the engine.
 *
 * @package RR_GF_File_Retention
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RR_Retention_Form {

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
     * Returns per-form overrides if the form has override_global enabled,
     * otherwise falls back to global settings.
     *
     * @param int                       $form_id         Form ID.
     * @param RR_Retention_Settings|null $global_settings Global settings instance.
     * @return array<string, mixed>
     */
    public function get_effective_settings( int $form_id, ?RR_Retention_Settings $global_settings = null ): array {
        $addon = RR_Retention_Addon::get_instance();
        $form  = \GFAPI::get_form( $form_id );

        if ( is_array( $form ) ) {
            $form_meta = $addon->get_form_settings( $form );

            if ( is_array( $form_meta ) && ! empty( $form_meta['override_global'] ) ) {
                return wp_parse_args( $form_meta, self::defaults() );
            }
        }

        if ( $global_settings ) {
            return $global_settings->get_all();
        }

        return ( new RR_Retention_Settings() )->get_all();
    }
}
