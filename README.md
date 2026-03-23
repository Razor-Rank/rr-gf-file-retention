# Gravity Forms File Retention by Razor Rank

Automatically purges uploaded files from Gravity Forms entries after a configurable retention period, while preserving form entry data and annotating every removal.

<!-- Badges -->
![Version](https://img.shields.io/badge/version-1.0.0-blue)
![License](https://img.shields.io/badge/license-GPLv2%2B-green)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue)
![Gravity Forms](https://img.shields.io/badge/Gravity%20Forms-2.5%2B-orange)
![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple)

Built by [Razor Rank](https://razorrank.com)

## Features

- **Automatic daily cleanup** via WP-Cron with configurable retention period
- **Global settings** under Forms > Settings > File Retention (native GFAddOn integration)
- **Per-form overrides** for retention period, enable/disable, and annotation template
- **Dry-run mode** to preview what would be purged without deleting anything
- **Run Preview** and **Run Cleanup Now** buttons on the settings page
- **Purge History** log viewer with color-coded action badges
- **Batch processing** with configurable batch size, one batch per run, oldest-first
- **Smart queries** that skip entries with empty file fields and forms without upload fields
- **Entry annotation** via `GFAPI::add_note()` with customizable templates
- **WP-CLI commands** for server-side automation and cron scheduling
- **Email notifications** with summary after each purge run
- **Path validation** against `wp_upload_dir()` to prevent directory traversal
- Supports both single-file and multi-file upload fields

## Requirements

- WordPress 6.0+
- Gravity Forms 2.5+
- PHP 8.0+

## Installation

1. Download the latest release zip from [GitHub Releases](https://github.com/Razor-Rank/rr-gf-file-retention/releases)
2. In WP Admin, go to **Plugins > Add New > Upload Plugin**
3. Upload the zip file and click **Install Now**
4. Activate the plugin
5. Configure under **Forms > Settings > File Retention**

## Usage

### Global Settings

Navigate to **Forms > Settings > File Retention** to configure:

- **Enable Automatic File Cleanup** - master switch for scheduled daily runs
- **Dry Run Mode** - logs actions without deleting (enabled by default)
- **Retention Period** - files older than this threshold are eligible for purging
- **Batch Size** - max entries processed per run (default: 200)
- **Annotation Template** - note added to entries when files are removed
- **Email Notification** - receive a summary after each purge run

### Per-Form Overrides

Each Gravity Form has a **File Retention** tab under its form settings. Toggle **Override Global Settings** to set a custom retention period or disable cleanup for that form entirely.

### Run Preview

Click **Run Preview** on the settings page to see exactly which files would be purged with current settings. Results display in a table showing entry ID, form name, filename, file size, and age. Nothing is deleted.

### Run Cleanup Now

After reviewing a preview, click **Run Cleanup Now** to execute a live purge. A confirmation dialog shows the retention settings (including per-form overrides) before proceeding. Results display with a count of files deleted and space freed.

### Purge History

The **Purge History** section at the bottom of the settings page shows the 50 most recent log entries with color-coded action badges: green (deleted), blue (dry_run), red (error).

### WP-CLI Commands

```bash
# Run a purge (respects saved settings)
wp rr-retention run

# Force a live run regardless of dry-run setting
wp rr-retention run --live

# Preview what would be purged (dry run with verbose output)
wp rr-retention preview

# Preview a specific form with custom retention
wp rr-retention preview --form=5 --days=60

# Run with a custom batch size
wp rr-retention run --batch-size=500

# Show current configuration
wp rr-retention status

# View recent log entries
wp rr-retention log
wp rr-retention log --limit=50 --form=5
```

**Available flags for `run`:**

| Flag | Description |
|------|-------------|
| `--form=<id>` | Limit to a specific form ID |
| `--dry-run` | Preview mode, no deletions |
| `--live` | Force live mode (mutually exclusive with `--dry-run`) |
| `--days=<n>` | Override retention period for this run |
| `--batch-size=<n>` | Override batch size for this run |
| `--verbose` | Show per-file output |

### Server Cron

WP-Cron depends on site traffic to trigger. For guaranteed scheduling on low-traffic sites, add a server cron:

```
0 3 * * * /usr/local/bin/wp rr-retention run --path=/home/user/public_html/ --allow-root
```

## Screenshots

*Screenshots will be added in a future update.*

## Contributing

This plugin is maintained by [Razor Rank LLC](https://razorrank.com). Issues and pull requests are welcome on [GitHub](https://github.com/Razor-Rank/rr-gf-file-retention).

## License

GPLv2 or later. See [LICENSE.txt](LICENSE.txt) for the full license text.
