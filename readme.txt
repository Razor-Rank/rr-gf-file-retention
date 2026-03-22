=== RR GF File Retention ===
Contributors: razorrank
Tags: gravity forms, file uploads, retention, cleanup, storage
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.0.0
License: Proprietary

Automatically purges uploaded files attached to Gravity Forms entries after a configurable retention period.

== Description ==

RR GF File Retention removes uploaded files from disk after a configurable retention period while preserving the form entry data and submission metadata. Each removal is annotated on the entry with a timestamped note.

Built by Razor Rank LLC for internal use across client sites.

**Features:**

* Global retention policy with per-form overrides
* Dry-run mode for safe preview before any deletions
* Full audit log of every file action
* WP-CLI commands for server-side automation
* Daily WP-Cron scheduling
* Email notification summaries
* Entry annotation preserving the audit trail

**Requirements:**

* WordPress 6.0+
* Gravity Forms 2.5+
* PHP 8.0+

== Installation ==

1. Upload the `rr-gf-file-retention` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu
3. Go to Settings > GF File Retention to configure
4. Start with Dry Run mode enabled to preview what would be purged
5. Disable Dry Run and enable the master switch when ready

== Changelog ==

= 1.0.0 =
* Initial release
* Global settings page with retention period, dry run, and notification options
* Per-form override settings via Gravity Forms form settings
* Core purge engine with file deletion, entry annotation, and error handling
* Audit log with custom database table
* WP-CLI commands: run, status, preview, log
* Daily WP-Cron scheduling
