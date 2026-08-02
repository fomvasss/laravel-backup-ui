<?php

namespace Fomvasss\LaravelBackupUi\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Fomvasss\LaravelBackupUi\Support\BackupOutputAnalyzer;
use Fomvasss\LaravelBackupUi\Support\BackupRestorer;
use Carbon\Carbon;

class BackupController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            if (!$this->isAuthorized()) {
                abort(403, 'Unauthorized');
            }
            return $next($request);
        });
    }

    public function index()
    {
        $backupDestinations = $this->getBackupDestinations();

        return view('backup-ui::index', [
            'backupDestinations' => $backupDestinations,
            'pageTitle' => config('backup-ui.page_title'),
            'activeProgressKey' => $this->activeProgressKey(),
            'currentConnection' => $this->currentConnectionInfo(),
        ]);
    }

    /**
     * Info about the connection `database.default` points at — shown in the UI so it's
     * obvious up front which database "Restore" would overwrite, and whether its driver
     * is one BackupRestorer actually supports.
     */
    protected function currentConnectionInfo(): array
    {
        $name = config('database.default');
        $config = config("database.connections.{$name}", []);
        $driver = $config['driver'] ?? 'unknown';

        return [
            'name' => $name,
            'driver' => $driver,
            'database' => $config['database'] ?? null,
            'host' => $config['host'] ?? null,
            'restore_supported' => in_array($driver, BackupRestorer::SUPPORTED_DRIVERS, true),
        ];
    }

    /**
     * The key of the currently running create/restore job, if any — persisted
     * in the session (not flashed) so progress stays visible across page reloads.
     */
    protected function activeProgressKey(): ?string
    {
        $key = session('active_progress_key');

        if (!$key) {
            return null;
        }

        $progress = Cache::get($key);

        if (!$progress || $progress['status'] !== 'processing') {
            session()->forget('active_progress_key');

            return null;
        }

        return $key;
    }

    public function create(Request $request)
    {
        $request->validate([
            'option' => 'nullable|in:only-db,only-files',
        ]);

        $option = $request->get('option');

        // Check if queue mode is enabled
        if (config('backup-ui.queue.enabled', false)) {
            return $this->createBackupAsync($option);
        }

        // Synchronous mode (original behavior)
        return $this->createBackupSync($option);
    }

    /**
     * Create backup asynchronously using queue
     */
    protected function createBackupAsync($option)
    {
        try {
            // Generate unique progress key
            $progressKey = 'backup_progress_' . uniqid();

            // Dispatch job to queue
            \Fomvasss\LaravelBackupUi\Jobs\CreateBackupJob::dispatch($progressKey, $option);

            session(['active_progress_key' => $progressKey]);

            return redirect()->route('backup-ui.index')
                ->with('info', 'Backup job has been queued. This page will update automatically when complete.');

        } catch (\Throwable $e) {
            Log::error('Failed to queue backup job', [
                'option' => $option,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to queue backup: ' . $e->getMessage());
        }
    }

    /**
     * Create backup synchronously (original behavior)
     */
    protected function createBackupSync($option)
    {
        try {
            $commandOptions = [];

            if ($option === 'only-db') {
                $commandOptions['--only-db'] = true;
            } elseif ($option === 'only-files') {
                $commandOptions['--only-files'] = true;
            }

            if (!empty($commandOptions)) {
                Artisan::call('backup:run', $commandOptions);
            } else {
                Artisan::call('backup:run');
            }

            $output = Artisan::output();

            if (BackupOutputAnalyzer::indicatesFailure($output)) {
                // Show detailed error if configured
                if (config('backup-ui.show_detailed_errors', true)) {
                    $errorMsg = 'Backup failed. Details: ' . strip_tags($output);

                    return back()->with('error', $errorMsg);
                } else {
                    return back()->with('error', 'Backup failed. Check logs for details.');
                }
            }

            return back()->with('success', 'Backup created successfully!');
        } catch (\Throwable $e) {
            return back()->with('error', 'Backup failed: ' . $e->getMessage());
        }
    }

    /**
     * Get backup progress status (for Ajax polling)
     */
    public function status(Request $request)
    {
        $progressKey = $request->get('progress_key');

        if (!$progressKey) {
            return response()->json([
                'success' => false,
                'message' => 'Progress key is required',
            ], 400);
        }

        $progress = Cache::get($progressKey);

        if (!$progress) {
            return response()->json([
                'success' => false,
                'percentage' => 0,
                'message' => 'Waiting for job to start...',
                'status' => 'queued',
            ]);
        }

        return response()->json([
            'success' => true,
            'percentage' => $progress['percentage'],
            'message' => $progress['message'],
            'status' => $progress['status'],
            'updated_at' => $progress['updated_at'],
        ]);
    }


    public function download($disk, $path)
    {
        try {
            $backupDisk = Storage::disk($disk);
            $diskConfig = config("filesystems.disks.$disk", []);
            $diskDriver = $diskConfig['driver'] ?? 'unknown';

            // Decode path to handle special characters
            $decodedPath = urldecode($path);

            // Log what we're looking for (for debugging)
            Log::info("Backup UI: Looking for file", [
                'disk' => $disk,
                'driver' => $diskDriver,
                'original_path' => $path,
                'decoded_path' => $decodedPath
            ]);

            // First try: direct path (if full path is provided)
            if ($backupDisk->exists($decodedPath)) {
                Log::info("Backup UI: Found file via direct path", ['path' => $decodedPath]);
                return $this->downloadFile($backupDisk, $decodedPath, $diskDriver);
            }

            // Second try: search for file in backup directories (spatie structure)
            $foundPath = $this->findBackupFile($backupDisk, $decodedPath);
            if ($foundPath) {
                Log::info("Backup UI: Found file via backup search", ['found_path' => $foundPath]);
                return $this->downloadFile($backupDisk, $foundPath, $diskDriver);
            }

            // Third try: look for just filename in all subdirectories
            $allFiles = $backupDisk->allFiles();
            foreach ($allFiles as $file) {
                if (basename($file) === basename($decodedPath)) {
                    Log::info("Backup UI: Found file via basename match", ['found_path' => $file]);
                    return $this->downloadFile($backupDisk, $file, $diskDriver);
                }
            }

            // Log available files for debugging
            Log::warning("Backup UI: File not found", [
                'requested_file' => $decodedPath,
                'disk_driver' => $diskDriver,
                'available_files' => array_slice($allFiles, 0, 10) // First 10 files for debugging
            ]);

            abort(404, 'Backup file not found');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error("Backup UI: Download failed", [
                'disk' => $disk,
                'path' => $path,
                'error' => $e->getMessage()
            ]);
            return back()->with('error', 'Download failed: ' . $e->getMessage());
        }
    }

    protected function downloadFile($disk, $path, $driver)
    {
        switch ($driver) {
            case 's3':
            case 'gcs':
            case 'google':
            case 'ftp':
            case 'sftp':
                // For remote disks, Laravel's download() method should handle streaming
                return $disk->download($path);

            case 'local':
            case 'public':
                // For local disks, use standard download
                return $disk->download($path);

            default:
                // For unknown drivers, try standard download
                return $disk->download($path);
        }
    }

    protected function findBackupFile($disk, $filename)
    {
        // Get just the filename without any path
        $baseFilename = basename($filename);

        // spatie/laravel-backup typically stores files in structure like:
        // backup-name/YYYY/MM/DD/backup-file.zip
        // Let's search systematically

        try {
            $allFiles = $disk->allFiles();

            // Look for exact filename matches first (most efficient)
            foreach ($allFiles as $file) {
                if (basename($file) === $baseFilename) {
                    return $file;
                }
            }

            // Look for files that match the backup pattern
            $backupName = config('backup.backup.name', 'laravel-backup');

            // First, look in the backup name directory structure
            foreach ($allFiles as $file) {
                if (str_contains($file, $backupName) &&
                    basename($file) === $baseFilename &&
                    (str_ends_with($file, '.zip') || str_ends_with($file, '.tar') || str_ends_with($file, '.tar.gz'))) {
                    return $file;
                }
            }

            // Last resort: look for any file with matching basename that looks like a backup
            foreach ($allFiles as $file) {
                if (basename($file) === $baseFilename &&
                    (str_ends_with($file, '.zip') || str_ends_with($file, '.tar') || str_ends_with($file, '.tar.gz'))) {
                    return $file;
                }
            }

        } catch (\Throwable $e) {
            // If we can't search, return null
            return null;
        }

        return null;
    }

    public function delete($disk, $path)
    {
        try {
            $backupDisk = Storage::disk($disk);

            // Decode path to handle special characters
            $decodedPath = urldecode($path);

            // First try: direct path
            if ($backupDisk->exists($decodedPath)) {
                $backupDisk->delete($decodedPath);
                return back()->with('success', 'Backup deleted successfully!');
            }

            // Second try: search for file in backup directories
            $foundPath = $this->findBackupFile($backupDisk, $decodedPath);
            if ($foundPath && $backupDisk->exists($foundPath)) {
                $backupDisk->delete($foundPath);
                return back()->with('success', 'Backup deleted successfully!');
            }

            // Third try: search by filename in all directories
            $allFiles = $backupDisk->allFiles();
            foreach ($allFiles as $file) {
                if (basename($file) === basename($decodedPath)) {
                    $backupDisk->delete($file);
                    return back()->with('success', 'Backup deleted successfully!');
                }
            }

            return back()->with('error', 'Backup file not found');
        } catch (\Throwable $e) {
            return back()->with('error', 'Delete failed: ' . $e->getMessage());
        }
    }

    public function restore($disk, $path)
    {
        if (!app()->environment('local')) {
            abort(403, 'Restore is only available in the local environment.');
        }

        if (config('backup-ui.queue.enabled', false)) {
            return $this->restoreAsync($disk, $path);
        }

        return $this->restoreSync($disk, $path);
    }

    protected function restoreAsync($disk, $path)
    {
        try {
            $progressKey = 'restore_progress_' . uniqid();

            \Fomvasss\LaravelBackupUi\Jobs\RestoreBackupJob::dispatch($progressKey, $disk, $path);

            session(['active_progress_key' => $progressKey]);

            return redirect()->route('backup-ui.index')
                ->with('info', 'Restore job has been queued. This page will update automatically when complete.');
        } catch (\Throwable $e) {
            Log::error('Failed to queue restore job', [
                'disk' => $disk,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to queue restore: ' . $e->getMessage());
        }
    }

    protected function restoreSync($disk, $path)
    {
        $backupDisk = Storage::disk($disk);
        $decodedPath = urldecode($path);
        $restorer = new BackupRestorer();

        $foundPath = $restorer->locateFile($backupDisk, $decodedPath);

        if (!$foundPath) {
            return back()->with('error', 'Backup file not found');
        }

        $tempZip = tempnam(sys_get_temp_dir(), 'backup-ui-restore-') . '.zip';

        try {
            $stream = $backupDisk->readStream($foundPath);
            $out = fopen($tempZip, 'w');
            stream_copy_to_stream($stream, $out);
            fclose($out);
            fclose($stream);

            [$connectionName, $connectionConfig] = $restorer->resolveConnectionConfig(null);

            $tempDir = storage_path('app/backup-ui-restore-' . uniqid());
            mkdir($tempDir, 0755, true);

            try {
                $candidates = $restorer->extractCandidates($tempZip, $tempDir);

                if (empty($candidates)) {
                    return back()->with('error', 'No database dump found inside the archive (expected db-dumps/*.sql).');
                }

                $sqlFile = $restorer->resolveDumpFile($candidates, $connectionName);

                if (!$sqlFile) {
                    return back()->with('error', 'Multiple dumps found in the archive and none matches the current connection — use `php artisan backup-ui:restore` from the console instead.');
                }

                $restorer->runImport($sqlFile, $connectionConfig);

                return back()->with('success', 'Database restored successfully!');
            } finally {
                $restorer->cleanupDir($tempDir);
            }
        } catch (\Throwable $e) {
            Log::error('Backup UI: Restore failed', [
                'disk' => $disk,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Restore failed: ' . $e->getMessage());
        } finally {
            @unlink($tempZip);
        }
    }

    public function clean()
    {
        try {
            $exitCode = Artisan::call('backup:clean');

            if ($exitCode === 0) {
                return back()->with('success', 'Old backups cleaned successfully!');
            } else {
                return back()->with('error', 'Clean command completed with warnings. Check logs for details.');
            }
        } catch (\Throwable $e) {
            return back()->with('error', 'Clean failed: ' . $e->getMessage());
        }
    }

    protected function getBackupDestinations()
    {
        $backupDestinations = collect();

        foreach (config('backup.backup.destination.disks') as $diskName) {
            try {
                // Simplified approach: just get backup information without complex API calls
                $backupDestination = $this->createSimpleBackupDestination($diskName);

                $backupDestinations->push($backupDestination);
            } catch (\Throwable $e) {
                $backupDestinations->push([
                    'name' => $diskName,
                    'reachable' => false,
                    'healthy' => false,
                    'error' => $e->getMessage(),
                    'amount' => 0,
                    'newest' => null,
                    'usedStorage' => '0 KB',
                    'backups' => [],
                    'driver' => config("filesystems.disks.$diskName.driver", 'unknown'),
                ]);
            }
        }

        return $backupDestinations;
    }

    protected function createSimpleBackupDestination($diskName)
    {
        try {
            // Get the disk
            $disk = Storage::disk($diskName);
            $diskConfig = config("filesystems.disks.$diskName", []);
            $diskDriver = $diskConfig['driver'] ?? 'unknown';

            // Check if disk is reachable with driver-specific logic
            $reachable = $this->isDiskReachable($disk, $diskDriver);

            if (!$reachable) {
                return [
                    'name' => $diskName,
                    'reachable' => false,
                    'healthy' => false,
                    'error' => "Disk '{$diskName}' (driver: {$diskDriver}) is not reachable",
                    'amount' => 0,
                    'newest' => null,
                    'usedStorage' => '0 KB',
                    'backups' => [],
                    'driver' => $diskDriver,
                ];
            }

            // Get backup files directly from disk
            $backupName = config('backup.backup.name', 'laravel-backup');
            $backupFiles = [];
            $totalSize = 0;

            try {
                // Look for backup files (common patterns)
                $allFiles = $disk->allFiles();
                $backupPattern = $backupName;

                foreach ($allFiles as $file) {
                    if (str_contains($file, $backupPattern) &&
                        (str_ends_with($file, '.zip') || str_ends_with($file, '.tar') || str_ends_with($file, '.tar.gz'))) {

                        $size = $this->getFileSize($disk, $file, $diskDriver);
                        $lastModified = $this->getFileLastModified($disk, $file, $diskDriver);

                        $backupFiles[] = [
                            'path' => $file,
                            'size_in_kb' => round($size / 1024, 2),
                            'human_readable_size' => $this->formatBytes($size),
                            'last_modified' => $lastModified,
                        ];

                        $totalSize += $size;
                    }
                }

                // Sort by last modified (newest first)
                usort($backupFiles, function($a, $b) {
                    return $b['last_modified'] - $a['last_modified'];
                });

            } catch (\Throwable $e) {
                // If we can't read files, return basic info
                Log::warning("Backup UI: Could not read files from disk '{$diskName}'", [
                    'driver' => $diskDriver,
                    'error' => $e->getMessage()
                ]);
                $backupFiles = [];
            }

            // Create newestBackup object that mimics the spatie backup structure
            $newestBackup = null;
            if (!empty($backupFiles)) {
                $newestBackup = new class($backupFiles[0]['last_modified']) {
                    private $timestamp;

                    public function __construct($timestamp) {
                        $this->timestamp = $timestamp;
                    }

                    public function date() {
                        return Carbon::createFromTimestamp($this->timestamp);
                    }
                };
            }

            return [
                'name' => $diskName,
                'reachable' => true,
                'healthy' => true,
                'amount' => count($backupFiles),
                'newest' => $newestBackup,
                'usedStorage' => $this->formatBytes($totalSize),
                'backups' => $backupFiles,
                'driver' => $diskDriver,
            ];

        } catch (\Throwable $e) {
            Log::error("Backup UI: Failed to process backup destination '{$diskName}'", [
                'error' => $e->getMessage()
            ]);

            throw new \Exception("Failed to process backup destination '{$diskName}': " . $e->getMessage());
        }
    }

    protected function isDiskReachable($disk, $driver)
    {
        try {
            switch ($driver) {
                case 's3':
                    // For S3, try to list objects with a limit
                    try {
                        $disk->allFiles();
                        return true;
                    } catch (\Throwable $e) {
                        return false;
                    }

                case 'ftp':
                case 'sftp':
                    // For FTP/SFTP, try to get directory listing
                    try {
                        $disk->allFiles();
                        return true;
                    } catch (\Throwable $e) {
                        return false;
                    }

                case 'gcs':
                case 'google':
                    // For Google Cloud Storage
                    try {
                        $disk->allFiles();
                        return true;
                    } catch (\Throwable $e) {
                        return false;
                    }

                case 'local':
                case 'public':
                    // For local disks, try basic operations
                    try {
                        // Try to perform basic disk operations
                        $disk->allFiles();
                        return true;
                    } catch (\Throwable $e) {
                        return false;
                    }

                default:
                    // For other drivers, try basic existence check
                    try {
                        $disk->allFiles();
                        return true;
                    } catch (\Throwable $e) {
                        return false;
                    }
            }
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function getFileSize($disk, $file, $driver)
    {
        try {
            return $disk->size($file);
        } catch (\Throwable $e) {
            // Some drivers might not support size() method
            Log::warning("Backup UI: Could not get file size for '{$file}' on driver '{$driver}'", [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    protected function getFileLastModified($disk, $file, $driver)
    {
        try {
            return $disk->lastModified($file);
        } catch (\Throwable $e) {
            // Some drivers might not support lastModified() method
            Log::warning("Backup UI: Could not get last modified time for '{$file}' on driver '{$driver}'", [
                'error' => $e->getMessage()
            ]);
            return time(); // Current timestamp as fallback
        }
    }

    protected function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }

    protected function calculateUsedStorage($backups)
    {
        $totalSize = $backups->sum('size_in_kb');

        if ($totalSize < 1024) {
            return $totalSize . ' KB';
        } elseif ($totalSize < 1048576) {
            return round($totalSize / 1024, 2) . ' MB';
        } else {
            return round($totalSize / 1048576, 2) . ' GB';
        }
    }

    protected function isAuthorized()
    {
        $authCallback = config('backup-ui.auth_callback');

        if ($authCallback && is_callable($authCallback)) {
            return call_user_func($authCallback);
        }

        $allowedUsers = config('backup-ui.allowed_users', []);

        if (empty($allowedUsers)) {
            return auth()->check();
        }

        return auth()->check() && in_array(auth()->user()->email, $allowedUsers);
    }
}
