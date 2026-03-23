<?php
// This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public
// License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.

/**
 * Admin notices for activation feedback and dependency checks.
 *
 * Displays a warning when Gravity Forms is not active, since the plugin
 * cannot function without it.
 *
 * @package RR_GF_File_Retention
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RR_Retention_Notice {

    /**
     * Render the "Gravity Forms is required" admin notice.
     */
    public function dependency_missing(): void {
        $message = sprintf(
            /* translators: %s: plugin name "Gravity Forms" */
            __( '<strong>Gravity Forms File Retention by Razor Rank</strong> requires %s to be installed and activated.', 'rr-gf-file-retention' ),
            '<strong>Gravity Forms</strong>'
        );

        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            wp_kses( $message, [ 'strong' => [] ] )
        );
    }
}
