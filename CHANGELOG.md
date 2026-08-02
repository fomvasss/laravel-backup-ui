# Changelog

All notable changes to `laravel-backup-ui` will be documented in this file.

## [Unreleased]

## [2.1.0] - 2026-08-02

### Added
- Restore now supports PostgreSQL connections, in addition to MySQL/MariaDB — requires `psql` on `PATH`
- The UI and `/backup/diagnostics` now show the current database connection (driver, database, host) and whether restore supports it

## [2.0.0] - 2026-07-31

### Added
- `backup-ui:restore {path}` console command — restores a database dump from a local backup zip file (`APP_ENV=local` only)
- "Restore" button in the UI backup list, next to Download/Delete — restores directly from a listed backup; only rendered and only functional when `APP_ENV=local`
- Restore runs synchronously or via queue, same as backup creation, depending on `backup-ui.queue.enabled`
- The progress modal now survives page reloads instead of losing its state after one reload

### Changed
- **Breaking:** dropped support for `spatie/laravel-backup` v8; now requires `^9.0|^10`
- **Breaking:** raised minimum PHP to `^8.2`
- **Breaking:** raised minimum Laravel to `^10.10|^11|^12|^13` (dropped Laravel 9)
- **Breaking:** `CreateBackupJob::__construct()` now takes `$progressKey` first and `$option` second (was reversed) — only relevant if you construct/dispatch this job directly instead of through the UI
- Default route prefix now matches the shipped config (`backup`, was `admin/backup`)
- A disk with a missing driver package (e.g. S3 without `league/flysystem-aws-s3-v3`) is now reported as unreachable instead of crashing the page
- Loading modal wording changed from "Creating Backup" to generic "Processing..."

### Removed
- Unused `BackupUiAuth` middleware class — was never registered by the package itself
- Unused, non-functional config keys `per_page` and `timeout`

### Fixed
- Downloading a missing backup file now correctly returns a `404` instead of a redirect
- Restore now finds backup files stored in date-organized subdirectories — previously failed with "Backup file not found" for anything but a disk-root file

## [1.1.0] - 2026-01-27

### Added
- Asynchronous backup creation via Laravel queues, with real-time progress tracking
- Queue can be enabled/disabled and given a custom name via `backup-ui.queue` config; disabled by default (synchronous)
- Loading modal updated with a progress bar and live status messages

### Fixed
- Timeout issues for large database or file backups

## [1.0.0] - 2026-01-17

### Added
- Initial release: Bootstrap 5 web interface for `spatie/laravel-backup` (v8.x and v9.x)
- Create (full / database-only / files-only), download, delete, and clean backups
- Support for multiple backup destinations, including external disks (S3, GCS, FTP, SFTP, Dropbox) with driver-specific reachability checks
- Configurable route prefix, middleware, allowed users, and custom auth callback
- Backup file search across spatie's date-based subdirectories, including filenames with special characters
- CSRF protection and path traversal protection
- Full test suite (Feature + Unit, 95+ cases)
