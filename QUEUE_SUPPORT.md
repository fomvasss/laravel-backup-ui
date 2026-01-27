# Queue Support for Laravel Backup UI

## Overview

The Laravel Backup UI package now supports asynchronous backup creation using Laravel queues. This is especially useful for large databases or file backups that might timeout in a web request.

## Features

- ✅ **Asynchronous backup creation** - Backups run in the background via Laravel queues
- ✅ **Real-time progress tracking** - Ajax polling shows backup progress
- ✅ **Backward compatible** - Can be disabled to use synchronous backups
- ✅ **Configurable queue** - Specify queue name and connection
- ✅ **Error handling** - Automatic retries and detailed error messages
- ✅ **Works with Horizon** - Full support for Laravel Horizon monitoring

## Configuration

Add the following configuration to your published `config/backup-ui.php`:

```php
'queue' => [
    // Enable queue processing (set to false for synchronous backups)
    'enabled' => true,

    // Queue name (leave null to use default queue)
    // Example: 'backups', 'default', etc.
    'name' => 'backups',
],
```

## Usage

### Enable Queue Mode

1. **Update configuration** (`config/backup-ui.php`):
```php
'queue' => [
    'enabled' => true,
    'name' => 'backups',
],
```

2. **Configure Horizon** (recommended) in `config/horizon.php`:
```php
'environments' => [
    'production' => [
        'backups' => [
            'connection' => 'redis',
            'queue' => ['backups'],
            'balance' => 'simple',
            'processes' => 1,
            'timeout' => 3600, // 1 hour for large backups
            'tries' => 2,
        ],
    ],
],
```

3. **Start queue worker**:
```bash
# Using Horizon
php artisan horizon

# Or standard queue worker
php artisan queue:work redis --queue=backups --timeout=3600
```

### Disable Queue Mode (Synchronous)

Set `enabled` to `false` to use traditional synchronous backups:

```php
'queue' => [
    'enabled' => false,
],
```

## How It Works

### With Queue Enabled

1. User clicks "Create Backup"
2. Job is dispatched to the queue with a unique progress key
3. Page redirects and shows progress modal
4. JavaScript polls status endpoint every 2 seconds
5. Progress bar updates in real-time
6. On completion, page reloads to show new backup

### Without Queue (Synchronous)

Traditional behavior - backup runs immediately in the web request.

## Progress Tracking

The job updates progress at different stages:

- **0%** - Starting backup process
- **10%** - Preparing backup (db/files/full)
- **20%** - Running backup command
- **90%** - Finalizing backup
- **100%** - Completed (success or error)

Progress is stored in Laravel Cache and retrieved via Ajax polling.

## Error Handling

- **Automatic retries**: Jobs retry up to 3 times on failure
- **Detailed logging**: All errors logged to Laravel log
- **User feedback**: Error messages shown in UI
- **Failed jobs**: Can be monitored in Horizon or `failed_jobs` table

## Monitoring with Horizon

With Horizon, you can:

- View real-time job progress
- Monitor job throughput and memory usage
- See failed jobs and retry them
- Track metrics and trends
- Filter by tags

Jobs are tagged with: `backup`, and the specific type (e.g., `database`, `files`).

## API Endpoint

### GET /backup-ui/status

Check backup progress for Ajax polling.

**Parameters:**
- `progress_key` (required) - Unique progress key

**Response:**
```json
{
    "success": true,
    "percentage": 50,
    "message": "Running backup command...",
    "status": "processing",
    "updated_at": "2024-01-27T10:30:00+00:00"
}
```

**Status values:**
- `queued` - Job waiting in queue
- `processing` - Job is running
- `success` - Job completed successfully
- `error` - Job failed

## Requirements

- Laravel Queue configured (database, Redis, etc.)
- Laravel Horizon (optional but recommended)
- spatie/laravel-backup ^8.0 or ^9.0

## Backward Compatibility

This feature is **100% backward compatible**:

- Default behavior: `enabled => false` (synchronous)
- Existing installations work without changes
- No breaking changes to API or UI

## Troubleshooting

### Jobs not processing

1. Check queue worker is running:
```bash
php artisan queue:work
# or
php artisan horizon
```

2. Check Redis connection (if using Redis):
```bash
php artisan tinker
>>> Redis::connection()->ping()
```

### Progress not updating

1. Check cache is configured correctly
2. Verify progress_key is passed in session
3. Check browser console for JavaScript errors

### Timeout issues

Increase timeout in Horizon config:
```php
'timeout' => 7200, // 2 hours
```

Or in Job class:
```php
public $timeout = 7200;
```

## Performance Tips

1. **Use Redis** for queue connection (faster than database)
2. **Dedicate queue** for backups: `queue => 'backups'`
3. **Single process** for backup queue (prevents conflicts)
4. **Increase timeout** for large backups
5. **Monitor with Horizon** to optimize performance

## Security Considerations

- Progress keys are unique and unpredictable
- Keys expire after 1 hour in cache
- All backup operations use existing auth middleware
- No sensitive data exposed in progress updates

## Credits

Developed by [fomvasss](https://github.com/fomvasss) for the Laravel Backup UI package.
