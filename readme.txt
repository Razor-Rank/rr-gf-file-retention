=== Gravity Forms File Retention by Razor Rank ===
Contributors: razorrank
Tags: gravity forms, file uploads, retention, cleanup, storage
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 0.2.2
License: Proprietary

Automatically purges uploaded files attached to Gravity Forms entries after a configurable retention period.

== Description ==

Gravity Forms File Retention removes uploaded files from disk after a configurable retention period while preserving the form entry data and submission metadata. Each removal is annotated on the entry with a timestamped note.

Built by Razor Rank LLC for internal use across client sites.

**Features:**

* Global retention policy with per-form overrides
* Dry-run mode for safe preview before any deletions
* Run Preview button on settings page for non-CLI users
* Full audit log of every file action
* WP-CLI commands for server-side automation
* Batch processing with configurable batch size
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
3. Go to Forms > Settings > File Retention to configure
4. Use the "Run Preview" button to see what would be purged
5. Disable Dry Run and enable the master switch when ready

== Changelog ==

= 0.2.2 =
* Fix: AJAX preview handler registered in init_ajax() for GFAddOn compatibility
* Version numbers synced across plugin header, addon class, and readme

= 0.2.1 =
* Fix: Remove array type hint on settings field callback for GF 2.9 compat

= 0.2.0 =
* Rename to "Gravity Forms File Retention by Razor Rank"
* Replace oversized PNG icon with inline SVG for proper sidebar scaling
* Add "Run Preview" button to settings page with AJAX dry-run
* Register scripts/styles via GFAddOn framework

= 0.1.0 =
* Refactor to extend GFAddOn for native GF settings integration
* Add batch processing with configurable batch size
* Fix annotation grouping: one note per entry, not per field
* Add --live and --batch-size CLI flags
* Fix autoloader class-to-file mapping

= 0.0.1 =
* Initial scaffold with plugin structure and class stubs
