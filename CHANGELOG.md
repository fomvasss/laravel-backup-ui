# Changelog

All notable changes to `laravel-backup-ui` will be documented in this file.

## [2.0.0] - 2026-07-31

### Added
- `backup-ui:restore {path}` console command — restores a database dump from a local backup zip file (`APP_ENV=local` only)
- "Restore" button in the UI backup list, next to Download/Delete — restores directly from a listed backup; only rendered and only functional when `APP_ENV=local`
- Restore runs synchronously or via queue depending on `backup-ui.queue.enabled`, reusing the same `RestoreBackupJob` → Cache → `/backup/status` polling mechanism as backup creation
- The progress modal now survives page reloads: the active job's progress key is tracked in session (`active_progress_key`) and re-checked against Cache on every page load, instead of relying on one-shot flash data that disappeared after a single reload

### Changed
- **Breaking:** dropped support for `spatie/laravel-backup` v8; now requires `^9.0|^10`
- **Breaking:** raised minimum PHP to `^8.2`
- **Breaking:** raised minimum Laravel to `^10.10|^11|^12|^13` (dropped Laravel 9)
- **Breaking:** `CreateBackupJob::__construct()` now takes `$progressKey` first and `$option` second (was reversed) — only relevant if you construct/dispatch this job directly instead of through the UI
- Default `route_prefix` fallback in the service provider now matches the shipped config (`backup`, was `admin/backup`)
- Controller error handling now catches `\Throwable` instead of `\Exception`, so a disk with a missing Flysystem adapter (e.g. S3 without `league/flysystem-aws-s3-v3`) is reported as unreachable instead of crashing the whole page
- Backup destination arrays (including the unreachable/error fallback) now always include a `driver` key
- The mysqldump-failure detection used by both sync creation and the queued job is now shared in one place (`BackupOutputAnalyzer`) instead of duplicated
- Loading modal wording is now generic ("Processing...") instead of "Creating Backup", since it's shared with restore too

### Removed
- Dead spatie/laravel-backup v8/v9 API-detection code from `BackupController` (`createBackupDestination()`, `isVersion9OrHigher()`, `getSpatieBackupApiVersion()`) — none of it was ever called
- Unused `BackupUiAuth` middleware — never registered on any route, duplicated the authorization check already done in `BackupController`
- Unused config keys `per_page` and `timeout` — never read anywhere in the codebase

### Fixed
- `download()` no longer turns a missing-file `404` into a `302` redirect (the `abort(404)` was being swallowed by the method's own catch block)
- Restore now locates backup files stored under a date-organized subdirectory by basename, the same way download/delete already did — previously restoring anything but a disk-root file failed with "Backup file not found"

## [1.1.0] - 2026-01-27

### Added
- Asynchronous backup creation via Laravel queues, with real-time progress tracking (Ajax polling backed by Cache) and a new `CreateBackupJob`
- Queue can be enabled/disabled and given a custom name via `backup-ui.queue` config; disabled by default (synchronous)
- Loading modal updated with a progress bar and live status messages

### Fixed
- Timeout issues for large database or file backups, by making creation queueable

## [1.0.0] - 2026-01-17

### Added
- Initial release: Bootstrap 5 web interface for `spatie/laravel-backup` (v8.x and v9.x)
- Create (full / database-only / files-only), download, delete, and clean backups
- Support for multiple backup destinations, including external disks (S3, GCS, FTP, SFTP, Dropbox) with driver-specific reachability checks
- Configurable route prefix, middleware, allowed users, and custom auth callback
- Backup file search across spatie's date-based subdirectories, including filenames with special characters
- CSRF protection and path traversal protection
- Full test suite (Feature + Unit, 95+ cases)
