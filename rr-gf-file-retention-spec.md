# RR GF File Retention - Plugin Specification

Version: 1.0.0-draft
Date: 2026-03-22
Author: Razor Rank LLC

---

## Overview

**RR GF File Retention** is a WordPress plugin that automatically purges uploaded files attached to Gravity Forms entries after a configurable retention period. It preserves the form entry and submission metadata while removing files from disk and updating the entry to note the removal.

Built by Razor Rank for internal use across client sites. Not intended for public distribution at this time.

---

## Problem Statement

Client sites that accept file uploads via Gravity Forms accumulate files indefinitely. Resumes, documents, and attachments grow into gigabytes of dead storage over months and years. Clients typically download or process these files immediately, but the copies on the WordPress server persist forever. There is no built-in retention mechanism in Gravity Forms.

---

## Design Principles

1. **Files only.** Delete uploaded files from disk. Never delete form entries or non-file field data.
2. **Annotate, don't destroy.** Update the entry metadata to record what was removed, when, and why.
3. **Global defaults, per-form overrides.** One settings page for site-wide defaults. Per-form settings override when needed.
4. **Dry run first.** Always support a preview/dry-run mode before any destructive action.
5. **WP-CLI native.** Full functionality available via WP-CLI for server-side automation and cron.
6. **Gravity Forms only (v1).** Built specifically for GF. Architecture should not over-abstract for hypothetical future form plugins.

---

## Functional Requirements

### FR-1: Settings Page (WP Admin > Settings > GF File Retention)

Global defaults that apply to all forms unless overridden:

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| Retention Period | Integer + unit (days/months) | 30 days | Files older than this are eligible for purging |
| Enabled | Toggle | Off | Master switch. Nothing runs until explicitly enabled. |
| Target Forms | Multi-select or "All" | All | Which forms the global policy applies to |
| Excluded Forms | Multi-select | None | Forms explicitly excluded from any retention |
| Annotation Template | Text | `File removed on {date} per {days}-day retention policy.` | Message written to the entry when a file is purged |
| Dry Run Mode | Toggle | On | When enabled, logs what would be deleted but does not delete |
| Log Retention Actions | Toggle | On | Write to a custom log table for audit trail |
| Email Notification | Email address (optional) | Empty | Send summary email after each purge run |

### FR-2: Per-Form Override (Gravity Forms > Form Settings > File Retention)

Each form gets an additional settings tab:

| Setting | Options | Default |
|---------|---------|---------|
| Override Global | Toggle | Off |
| Retention Period | Integer + unit | Inherit from global |
| Enabled/Disabled | Toggle | Inherit from global |
| Annotation Template | Text | Inherit from global |

When "Override Global" is off, the form uses global settings. When on, the form's settings take full precedence.

### FR-3: File Purge Engine

The core purge logic:

1. Query `gf_entry` for entries on target forms where `date_created` is older than the retention period and `status = 'active'`.
2. For each entry, identify file upload fields (field type `fileupload` or `post_image`).
3. For each file field value in `gf_entry_meta`:
   a. Parse the file URL(s) -- single upload is a URL string, multi-file upload is a JSON array.
   b. Convert URL to server filesystem path.
   c. Verify the file exists on disk.
   d. Delete the file from disk.
   e. Update the entry meta value to empty string or empty array.
   f. Add an entry note (via `GFAPI::add_note()`) with the annotation template, including original filename(s).
4. Log the action to the custom log table.
5. Track per-run statistics: files deleted, bytes freed, entries processed, errors.

### FR-4: Dry Run Mode

When dry run is active (default):

- Execute the full query and file identification logic.
- Log everything that *would* be deleted (filenames, sizes, entry IDs, form IDs).
- Do not delete any files or modify any entries.
- Display results in WP Admin and via WP-CLI output.

### FR-5: WP-CLI Commands

```
wp rr-retention run [--form=<id>] [--dry-run] [--days=<n>] [--verbose]
```
Execute a purge run. `--dry-run` overrides the global setting for this run. `--days` overrides the retention period for this run. `--form` limits to a specific form.

```
wp rr-retention status
```
Show current configuration: global settings, per-form overrides, last run date, files pending purge.

```
wp rr-retention preview [--form=<id>] [--days=<n>]
```
Alias for `run --dry-run --verbose`. Shows exactly what would be purged.

```
wp rr-retention log [--limit=<n>] [--form=<id>]
```
Display recent purge log entries.

### FR-6: Audit Log

Custom database table `{prefix}rr_file_retention_log`:

| Column | Type | Description |
|--------|------|-------------|
| id | BIGINT AUTO_INCREMENT | Primary key |
| entry_id | BIGINT | Gravity Forms entry ID |
| form_id | INT | Gravity Forms form ID |
| field_id | INT | GF field ID (the upload field) |
| filename | VARCHAR(500) | Original filename |
| file_size | BIGINT | File size in bytes |
| file_path | VARCHAR(1000) | Full server path of deleted file |
| action | ENUM('deleted', 'dry_run', 'error') | What happened |
| error_message | TEXT NULL | Error details if action = 'error' |
| annotation | TEXT | The note added to the entry |
| created_at | DATETIME | Timestamp of the action |
| run_id | VARCHAR(36) | UUID grouping all actions in a single run |

### FR-7: Scheduled Execution

Register a daily WP-Cron event (`rr_gf_file_retention_daily`) that runs the purge engine with global settings. Fires only when the master Enabled toggle is on and Dry Run is off.

For sites where WP-Cron is unreliable, document the server cron alternative:
```
0 3 * * * /usr/local/bin/wp rr-retention run --path=/home/{user}/public_html/ --allow-root
```

### FR-8: Admin Dashboard Widget (Optional, v1.1)

A small dashboard widget showing:
- Last purge run date and results
- Files pending purge (count and total size)
- Quick link to settings
- Quick link to run dry-run preview

---

## Technical Architecture

### File Structure

```
rr-gf-file-retention/
  rr-gf-file-retention.php          # Plugin header, bootstrap, activation/deactivation hooks
  uninstall.php                      # Clean removal (drop custom table, delete options)
  readme.txt                         # Standard WP readme
  includes/
    class-rr-retention-settings.php  # Global settings page registration and rendering
    class-rr-retention-form.php      # Per-form settings (GF form_settings filter)
    class-rr-retention-engine.php    # Core purge logic
    class-rr-retention-logger.php    # Audit log table creation and queries
    class-rr-retention-cli.php       # WP-CLI command registration
    class-rr-retention-cron.php      # WP-Cron scheduling
    class-rr-retention-notice.php    # Admin notices (activation, dependency check)
  assets/
    css/
      admin.css                      # Settings page styling (minimal)
    js/
      admin.js                       # Settings page interactivity (minimal)
  languages/
    rr-gf-file-retention.pot         # Translation template (future)
```

### Dependencies

- WordPress 6.0+
- Gravity Forms 2.5+ (checks on activation, deactivates gracefully if GF missing)
- PHP 8.0+

### Database

One custom table created on activation via `dbDelta()`. Dropped on uninstall (not deactivation).

### Options

All settings stored under a single option key: `rr_gf_file_retention_settings` (serialized array).

Per-form settings stored via GF's form meta API: `gform_get_meta($form_id, 'rr_file_retention')`.

---

## Security Considerations

- Settings page requires `manage_options` capability.
- Per-form settings require `gravityforms_edit_forms` capability.
- WP-CLI commands require shell access (inherently privileged).
- File deletion uses `wp_delete_file()` with path validation to prevent directory traversal.
- All file paths validated against `wp_upload_dir()` base -- never delete files outside the uploads directory.

---

## Rollout Plan

### Phase 1: Build and Test (Current)

1. Create GitHub repo `Razor-Rank/rr-gf-file-retention`
2. Scaffold plugin structure
3. Build settings page and purge engine
4. Build WP-CLI commands
5. Test on Klein Hersh staging site

### Phase 2: Klein Hersh Production Deploy

1. Install on kleinhersh.com production
2. Run in dry-run mode for 1 week, review logs
3. Disable dry-run, enable 30-day retention
4. Monitor first automated purge
5. Confirm with client (via Ryan Moore)

### Phase 3: Roll Out to Other Clients

1. Identify other client sites with Gravity Forms file uploads
2. Deploy with dry-run enabled
3. Client-by-client retention policy confirmation
4. Enable per site

---

## Future Considerations (Not in v1)

- **Adapter pattern for other form plugins** (WPForms, Formidable, etc.)
- **S3 offload integration** -- move files to S3 instead of deleting, with lifecycle policies
- **Bulk retroactive purge UI** in WP Admin for initial cleanup
- **Per-field retention** -- different retention periods for different upload fields on the same form
- **wp.org distribution** -- would require renaming, removing RR branding, adding full i18n
