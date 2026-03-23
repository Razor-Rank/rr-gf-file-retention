<?php
// This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public
// License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.

/**
 * Thin settings wrapper around the GFAddOn settings API.
 *
 * Provides a stable interface for the engine, CLI, and cron to read
 * settings without coupling directly to GFAddOn internals. All actual
 * settings storage and UI are handled by RR_Retention_Addon (GFAddOn).
 *
 * @package RR_GF_File_Retention
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RR_Retention_Settings {

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
            'batch_size'          => 200,
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
     * Reads from the GFAddOn plugin settings store.
     *
     * @return array<string, mixed>
     */
    public function get_all(): array {
        $addon  = RR_Retention_Addon::get_instance();
        $stored = $addon->get_plugin_settings();

        if ( ! is_array( $stored ) ) {
            $stored = [];
        }

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
}
