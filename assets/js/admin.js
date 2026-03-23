/**
 * Gravity Forms File Retention by Razor Rank - Settings page interactivity.
 *
 * Handles "Run Preview" (dry-run) and "Run Cleanup Now" (live deletion)
 * buttons with a proper state machine controlling button enabled/disabled
 * state and Clear Results visibility.
 *
 * States:
 *   idle         - Preview enabled, Cleanup disabled, Clear hidden
 *   loading      - Both disabled, spinner active
 *   has_results  - Preview enabled, Cleanup enabled, Clear visible
 *   after_cleanup - Preview enabled, Cleanup disabled, Clear visible
 *
 * @package RR_GF_File_Retention
 * @since   0.4.1
 */
(function ($) {
    'use strict';

    // -----------------------------------------------------------------
    // State machine
    // -----------------------------------------------------------------

    function setState(state) {
        var $preview = $('#rr-retention-preview-btn');
        var $cleanup = $('#rr-retention-run-now-btn');
        var $clear   = $('#rr-retention-clear-results');
        var $spinner = $('#rr-retention-spinner');

        switch (state) {
            case 'idle':
                $preview.prop('disabled', false);
                $cleanup.prop('disabled', true);
                $clear.hide();
                $spinner.removeClass('is-active');
                break;

            case 'loading':
                $preview.prop('disabled', true);
                $cleanup.prop('disabled', true);
                $clear.hide();
                $spinner.addClass('is-active');
                break;

            case 'has_results':
                $preview.prop('disabled', false);
                $cleanup.prop('disabled', false);
                $clear.show();
                $spinner.removeClass('is-active');
                break;

            case 'after_cleanup':
                $preview.prop('disabled', false);
                $cleanup.prop('disabled', true);
                $clear.show();
                $spinner.removeClass('is-active');
                break;
        }
    }

    // Initialize: Cleanup disabled until a preview has run.
    $(function () {
        setState('idle');
    });

    // -----------------------------------------------------------------
    // Run Preview (dry run)
    // -----------------------------------------------------------------
    $(document).on('click', '#rr-retention-preview-btn', function (e) {
        e.preventDefault();

        var nonce = $(this).data('nonce');

        setState('loading');
        $('#rr-retention-results').empty();

        $.post(ajaxurl, {
            action: 'rr_retention_preview',
            nonce:  nonce
        })
        .done(function (response) {
            if (!response.success) {
                setState('idle');
                showError(response.data || 'Preview failed.');
                return;
            }

            var hasFiles = renderResults(response.data, true);
            setState(hasFiles ? 'has_results' : 'idle');
        })
        .fail(function () {
            setState('idle');
            showError('Request failed. Check your connection and try again.');
        });
    });

    // -----------------------------------------------------------------
    // Run Cleanup Now (live deletion)
    // -----------------------------------------------------------------
    $(document).on('click', '#rr-retention-run-now-btn', function (e) {
        e.preventDefault();

        var $btn       = $(this);
        var nonce      = $btn.data('nonce');
        var days       = $btn.data('retention-days');
        var unit       = $btn.data('retention-unit');
        var overrides  = $btn.data('form-overrides') || [];

        // Build confirm message with per-form overrides.
        var msg = 'This will permanently delete uploaded files using these retention settings:\n';

        if (overrides.length) {
            overrides.forEach(function (o) {
                msg += '  - ' + o.form + ': ' + o.days + ' ' + o.unit + '\n';
            });
            msg += '  - All other forms: ' + days + ' ' + unit + '\n';
        } else {
            msg += '  - All forms: ' + days + ' ' + unit + '\n';
        }

        msg += '\nThis cannot be undone. Continue?';

        if (!confirm(msg)) {
            return;
        }

        setState('loading');
        $('#rr-retention-results').empty();

        $.post(ajaxurl, {
            action: 'rr_retention_run_now',
            nonce:  nonce
        })
        .done(function (response) {
            if (!response.success) {
                setState('idle');
                showError(response.data || 'Cleanup failed.');
                return;
            }

            renderResults(response.data, false);
            setState('after_cleanup');
        })
        .fail(function () {
            setState('idle');
            showError('Request failed. Check your connection and try again.');
        });
    });

    // -----------------------------------------------------------------
    // Clear Results
    // -----------------------------------------------------------------
    $(document).on('click', '#rr-retention-clear-results', function (e) {
        e.preventDefault();
        $('#rr-retention-results').empty();
        setState('idle');
    });

    // -----------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------

    function showError(msg) {
        $('#rr-retention-results').html(
            '<div class="notice notice-error inline"><p>' + esc(msg) + '</p></div>'
        );
    }

    /**
     * Render the results table for both preview and live runs.
     *
     * @param {Object}  data      Response data from the engine.
     * @param {boolean} isDryRun  True for preview, false for live cleanup.
     * @return {boolean} True if files were found/displayed.
     */
    function renderResults(data, isDryRun) {
        var $container = $('#rr-retention-results');
        var files = [];

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
            var emptyMsg = isDryRun
                ? 'No files eligible for purging with current settings.'
                : 'No files were found to clean up.';
            $container.html(
                '<div class="notice notice-info inline"><p>' + emptyMsg + '</p></div>'
            );
            return false;
        }

        // Header banner with summary inside it.
        var headerClass = isDryRun ? 'rr-retention-header-preview' : 'rr-retention-header-live';
        var headerTitle = isDryRun ? 'Preview Results' : 'Cleanup Complete';
        var verb        = isDryRun ? 'found' : 'deleted';
        var summaryText = files.length + ' file' + (files.length !== 1 ? 's' : '') + ' ' + verb
            + ', ' + formatBytes(data.bytes_freed)
            + (isDryRun ? ' total size' : ' freed')
            + ' from ' + data.entries_processed + ' entr'
            + (data.entries_processed !== 1 ? 'ies' : 'y');

        var html = '<div class="rr-retention-results-header ' + headerClass + '">'
            + '<span class="rr-retention-header-title">' + esc(headerTitle) + '</span>'
            + '<span class="rr-retention-header-summary">' + esc(summaryText) + '</span>'
            + '</div>';

        // Table
        html += '<table class="rr-retention-preview-table widefat striped">'
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

        // Bottom summary
        var bottomVerb = isDryRun ? 'would be deleted' : 'deleted';
        html += '<p class="rr-retention-preview-note">'
            + files.length + ' file' + (files.length !== 1 ? 's' : '') + ' ' + bottomVerb
            + ', ' + formatBytes(data.bytes_freed) + ' freed'
            + ' from ' + data.entries_processed + ' entr'
            + (data.entries_processed !== 1 ? 'ies' : 'y')
            + (isDryRun ? ' (dry run - nothing was deleted)' : '')
            + '.</p>';

        $container.html(html);
        return true;
    }

    function formatBytes(bytes) {
        if (!bytes || bytes === 0) return 'N/A';
        var k     = 1024;
        var sizes = ['B', 'KB', 'MB', 'GB'];
        var i     = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }

    function daysAgo(dateStr) {
        if (!dateStr) return '-';
        var created = new Date(dateStr.replace(' ', 'T') + 'Z');
        var diff    = Math.floor((Date.now() - created.getTime()) / 86400000);
        if (diff < 0)  return '-';
        if (diff === 0) return 'today';
        if (diff === 1) return '1 day';
        return diff + ' days';
    }

    function esc(str) {
        var el = document.createElement('span');
        el.appendChild(document.createTextNode(str));
        return el.innerHTML;
    }

})(jQuery);
