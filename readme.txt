=== Gravity Forms File Retention by Razor Rank ===
Contributors: razorrank
Tags: gravity forms, file uploads, retention, cleanup, storage
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically purges uploaded files attached to Gravity Forms entries after a configurable retention period while preserving entry data.

== Description ==

Sites that accept file uploads via Gravity Forms accumulate files indefinitely. Resumes, documents, and attachments grow into gigabytes of storage over time. Clients typically download or process these files immediately, but the copies on the WordPress server persist forever. There is no built-in retention mechanism in Gravity Forms.

**Gravity Forms File Retention** solves this by automatically deleting uploaded files after a configurable retention period. It preserves the form entry and all non-file submission data, adds a timestamped annotation noting what was removed, and logs every action to a custom audit table for accountability.

The plugin integrates natively with Gravity Forms as a GFAddOn, providing a settings page under Forms > Settings > File Retention. Global retention policies can be overridden on a per-form basis. A built-in dry-run mode lets you preview exactly what would be purged before enabling live deletion. For sites without reliable WP-Cron, full WP-CLI support enables server-side cron scheduling.

**Key capabilities:**

* Configurable retention period (days or months) with per-form overrides
* Dry-run mode enabled by default for safe previewing
* Run Preview and Run Cleanup Now buttons in the admin UI
* Purge History log with color-coded action badges
* Batch processing to handle large entry volumes efficiently
* WP-CLI commands: `run`, `status`, `preview`, `log`
* Daily WP-Cron scheduling with server cron fallback
* Entry annotation and email notification summaries
* Path validation to prevent directory traversal
* Single-file and multi-file upload field support

== Installation ==

1. Download the latest release zip from [GitHub](https://github.com/Razor-Rank/rr-gf-file-retention/releases)
2. In WP Admin, go to Plugins > Add New > Upload Plugin
3. Upload the zip file and click Install Now
4. Activate the plugin
5. Go to Forms > Settings > File Retention to configure
6. Use the Run Preview button to see what would be purged
7. Disable Dry Run and enable the master switch when ready

== Frequently Asked Questions ==

= Does this delete form entries? =

No. The plugin only deletes uploaded files from disk. Form entries, submission metadata, and all non-file field data are preserved. A timestamped note is added to each entry recording what was removed.

= What happens if I enable the plugin with the default settings? =

Nothing destructive. Dry Run Mode is enabled by default and the master switch is off. You must explicitly disable Dry Run and enable automatic cleanup before any files are deleted. Use Run Preview first to see exactly what would be affected.

= Can I set different retention periods for different forms? =

Yes. Each form has a File Retention tab under its form settings where you can override the global retention period, disable cleanup entirely for that form, or set a custom annotation template.

= Does WP-Cron need to be working for automatic cleanup? =

The daily scheduled cleanup uses WP-Cron, which depends on site traffic to trigger. For low-traffic sites or guaranteed scheduling, you can set up a server cron job instead: `0 3 * * * /usr/local/bin/wp rr-retention run --path=/path/to/site/ --allow-root`

= What WP-CLI commands are available? =

Four commands: `wp rr-retention run` (execute a purge), `wp rr-retention preview` (dry-run with verbose output), `wp rr-retention status` (show current config), and `wp rr-retention log` (view recent log entries). The `run` command supports `--live`, `--dry-run`, `--form`, `--days`, `--batch-size`, and `--verbose` flags.

== Screenshots ==

1. Global settings page under Forms > Settings > File Retention
2. Run Preview results showing files eligible for purging
3. Run Cleanup Now confirmation dialog with per-form overrides
4. Purge History log with color-coded action badges
5. Per-form override settings under form settings

== Changelog ==

= 1.0.0 =

Production release with the full feature set:

* Automatic daily file cleanup via WP-Cron with configurable retention period
* Native GFAddOn integration with settings page under Forms > Settings > File Retention
* Per-form retention overrides with custom retention period, enable/disable, and annotation template
* Dry-run mode enabled by default for safe previewing
* Run Preview and Run Cleanup Now admin buttons with confirmation dialog
* Purge History log viewer with color-coded action badges (deleted, dry_run, error)
* Batch processing with configurable batch size, oldest entries first
* Smart entry queries that skip forms without upload fields and entries with empty file values
* Per-entry annotation via GFAPI::add_note() with customizable template placeholders
* Custom audit log table tracking every file action with full metadata
* WP-CLI commands: run, status, preview, log with --live, --dry-run, --batch-size, --form, --days flags
* Email notification summaries after each purge run
* Path validation against wp_upload_dir() base to prevent directory traversal
* Support for single-file and multi-file upload fields
* Inline SVG icon in Gravity Forms settings sidebar
* GPLv2 or later licensing

= Pre-release Development =

* 0.5.0 - Purge History log viewer, WP-Cron guidance note, Clear Results positioning fix
* 0.4.1 - State machine for button management, Clear Results handler fix
* 0.4.0 - Per-form override confirm dialog, summary banners, N/A for unknown sizes
* 0.3.3 - Smart entry query via direct DB join to skip empty file fields
* 0.3.2 - Skip forms without file upload fields in purge loop
* 0.3.1 - Per-form override resolution in purge engine
* 0.3.0 - Run Cleanup Now button, renamed enable toggle
* 0.2.2 - AJAX handler registered in init_ajax() for GFAddOn compatibility
* 0.2.1 - GF 2.9 type hint compatibility fix
* 0.2.0 - Renamed plugin, inline SVG icon, Run Preview button
* 0.1.0 - GFAddOn refactor, batch processing, annotation fix, CLI flags
* 0.0.1 - Initial scaffold
