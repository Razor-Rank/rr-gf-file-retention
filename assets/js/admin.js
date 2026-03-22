/**
 * Gravity Forms File Retention by Razor Rank - Settings page interactivity.
 *
 * Handles the "Run Preview" button: fires an AJAX dry-run, renders
 * a results table showing files that would be purged.
 *
 * @package RR_GF_File_Retention
 * @since   1.0.0
 */
(function ($) {
    'use strict';

    $(document).on('click', '#rr-retention-preview-btn', function (e) {
        e.preventDefault();

        var $btn     = $(this);
        var $spinner = $('#rr-retention-preview-spinner');
        var $results = $('#rr-retention-preview-results');
        var nonce    = $btn.data('nonce');

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        $results.empty();

        $.post(ajaxurl, {
            action: 'rr_retention_preview',
            nonce:  nonce
        })
        .done(function (response) {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');

            if (!response.success) {
                $results.html(
                    '<div class="notice notice-error inline"><p>'
                    + esc(response.data || 'Preview failed.')
                    + '</p></div>'
                );
                return;
            }

            renderResults($results, response.data);
        })
        .fail(function () {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');
            $results.html(
                '<div class="notice notice-error inline"><p>Request failed. Check your connection and try again.</p></div>'
            );
        });
    });

    /**
     * Render the preview results table.
     */
    function renderResults($container, data) {
        var files = [];
        var now   = Date.now();

        // Flatten entry details into individual file rows.
        (data.details || []).forEach(function (entry) {
            (entry.files || []).forEach(function (file) {
                files.push({
                    entry_id:     entry.entry_id,
                    form_name:    entry.form_name || 'Form ' + entry.form_id,
                    date_created: entry.date_created || '',
                    filename:     file.filename,
                    size:         file.size
                });
            });
        });

        if (files.length === 0) {
            $container.html(
                '<div class="notice notice-info inline"><p>No files eligible for purging with current settings.</p></div>'
            );
            return;
        }

        var html = '<table class="rr-retention-preview-table widefat striped">'
            + '<thead><tr>'
            + '<th>Entry</th>'
            + '<th>Form</th>'
            + '<th>Filename</th>'
            + '<th>Size</th>'
            + '<th>Age</th>'
            + '</tr></thead><tbody>';

        files.forEach(function (f) {
            html += '<tr>'
                + '<td>#' + f.entry_id + '</td>'
                + '<td>' + esc(f.form_name) + '</td>'
                + '<td>' + esc(f.filename) + '</td>'
                + '<td>' + formatBytes(f.size) + '</td>'
                + '<td>' + daysAgo(f.date_created) + '</td>'
                + '</tr>';
        });

        html += '</tbody><tfoot><tr>'
            + '<th colspan="3">Total: ' + files.length + ' file' + (files.length !== 1 ? 's' : '') + '</th>'
            + '<th>' + formatBytes(data.bytes_freed) + '</th>'
            + '<th></th>'
            + '</tr></tfoot></table>';

        html += '<p class="rr-retention-preview-note">'
            + 'Processed ' + data.entries_processed + ' entr'
            + (data.entries_processed !== 1 ? 'ies' : 'y')
            + ' (dry run - nothing was deleted).</p>';

        $container.html(html);
    }

    /**
     * Format bytes into a human-readable string.
     */
    function formatBytes(bytes) {
        if (!bytes || bytes === 0) return '0 B';
        var k     = 1024;
        var sizes = ['B', 'KB', 'MB', 'GB'];
        var i     = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }

    /**
     * Convert a date string to "X days ago".
     */
    function daysAgo(dateStr) {
        if (!dateStr) return '-';
        var created = new Date(dateStr.replace(' ', 'T') + 'Z');
        var diff    = Math.floor((Date.now() - created.getTime()) / 86400000);
        if (diff < 0)  return '-';
        if (diff === 0) return 'today';
        if (diff === 1) return '1 day';
        return diff + ' days';
    }

    /**
     * Minimal HTML escaping.
     */
    function esc(str) {
        var el = document.createElement('span');
        el.appendChild(document.createTextNode(str));
        return el.innerHTML;
    }

})(jQuery);
