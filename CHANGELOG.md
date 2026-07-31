# Changelog

All notable changes to `laravel-backup-ui` will be documented in this file.

## [2.0.0] - 2026-07-31

### Changed
- **Breaking:** dropped support for `spatie/laravel-backup` v8; now requires `^9.0|^10`
- **Breaking:** raised minimum PHP to `^8.2`
- **Breaking:** raised minimum Laravel to `^10.10|^11|^12|^13` (dropped Laravel 9)
- Default `route_prefix` fallback in the service provider now matches the shipped config (`backup`, was `admin/backup`)
- Controller error handling now catches `\Throwable` instead of `\Exception`, so a disk with a missing Flysystem adapter (e.g. S3 without `league/flysystem-aws-s3-v3`) is reported as unreachable instead of crashing the whole page
- Backup destination arrays (including the unreachable/error fallback) now always include a `driver` key

### Removed
- Dead spatie/laravel-backup v8/v9 API-detection code from `BackupController` (`createBackupDestination()`, `isVersion9OrHigher()`, `getSpatieBackupApiVersion()`) — none of it was ever called
- Unused `BackupUiAuth` middleware — never registered on any route, duplicated the authorization check already done in `BackupController`

### Fixed
- `download()` no longer turns a missing-file `404` into a `302` redirect (the `abort(404)` was being swallowed by the method's own catch block)

## [1.1.0] - 2026-01-27

### Added
- **Asynchronous backup creation via Laravel Queues**
- Real-time progress tracking with Ajax polling
- CreateBackupJob for background backup processing
- Progress tracking via Laravel Cache
- Queue configuration options (enable/disable, queue name)
- Status endpoint for Ajax progress checking
- Enhanced modal UI with progress bar
- Support for Laravel Horizon monitoring
- Automatic retry mechanism (up to 3 attempts)
- Detailed progress updates at different stages
- Backward compatibility - queues can be disabled for synchronous operation
- Comprehensive documentation (QUEUE_SUPPORT.md)

### Changed
- Updated loading modal with progress bar and real-time status updates
- Enhanced JavaScript for Ajax polling and progress tracking
- Improved error handling with detailed logging
- Updated configuration file with queue settings

### Fixed
- Timeout issues for large database or file backups

## [1.0.0] - 2026-01-17

### Added
- Initial release
- Bootstrap 5 web interface for spatie/laravel-backup
- Support for spatie/laravel-backup v8.x and v9.x
- Create backups (full, database-only, files-only)
- View backup status and information
- Download backup files with intelligent file search
- Delete individual backups
- Clean old backups functionality
- Flexible authentication system
- Responsive design
- Real-time status updates
- Support for multiple backup destinations
- Customizable configuration
- Integration examples for popular admin panels
- Comprehensive documentation
- Complete test suite (Feature & Unit tests)
- PHPUnit configuration with coverage support
- Orchestra Testbench integration
- Version compatibility testing
- Full support for external backup disks (S3, Google Cloud Storage, FTP, SFTP, Dropbox)
- Intelligent disk driver detection and optimization
- Driver-specific reachability checking for external disks
- Graceful error handling for network timeouts and connection issues
- Enhanced file size and modification time detection for remote files

### Features
- Modern Bootstrap 5 UI design
- Full backup destination monitoring
- Backup file management (download/delete)
- Configurable route prefix and middleware
- User access restriction options
- Custom authentication callbacks
- Multi-disk backup support
- Error handling and user feedback
- CSRF protection
- Mobile-responsive interface
- Version compatibility detection
- Improved path handling for special characters
- Intelligent backup file searching across all subdirectories
- Support for backup files with special characters in filenames
- Backup file search handles spatie's date-based directory structure (backup-name/YYYY/MM/DD/)
- Enhanced file path resolution for delete operations
- Driver-specific optimizations for external disks

### Testing
- 95+ test cases covering all functionality
- Feature tests for complete workflow testing
- Unit tests for isolated component testing
- Version compatibility tests for spatie/laravel-backup v8 & v9
- View rendering tests for UI components
- Authentication and authorization tests
- Error handling and edge case coverage
- Continuous integration ready
- Test documentation and guidelines
- Comprehensive test suite for external disk support
- Test suite for backup file path handling
- BackupController method testing

### Security
- Authentication middleware protection
- User authorization system
- CSRF token validation
- Path traversal protection
- File existence validation
- Configurable access restrictions

### Compatibility
- spatie/laravel-backup v8.0+ and v9.0+
- Laravel 9.x, 10.x, 11.x, and 12.x
- PHP 8.0+
- Automatic version detection and adaptation

### Configuration
- Simple configuration file without database tools management
- External disk support configuration
- Timeout settings for backup operations
- Detailed error output options
- Route and middleware customization
- User access control options

### Architecture Decisions
- Database tools (mysqldump, pg_dump) should be properly configured at the server/container level
- Package focuses on web interface functionality only
- Clean separation between application logic and infrastructure setup
- Simplified codebase without PATH manipulation logic

