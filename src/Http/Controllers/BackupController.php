<?php

namespace Fomvasss\LaravelBackupUi\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Spatie\Backup\BackupDestination\BackupDestinationFactory;
use Spatie\Backup\Tasks\Monitor\BackupDestinationStatusFactory;
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
        ]);
    }

    public function create(Request $request)
    {
        $request->validate([
            'option' => 'nullable|in:only-db,only-files',
        ]);

        try {
            $option = $request->get('option');
            $commandOptions = [];

            // Handle different command signatures between v8 and v9
            if ($option === 'only-db') {
                $commandOptions['--only-db'] = true;
            } elseif ($option === 'only-files') {
                $commandOptions['--only-files'] = true;
            }

            // Support for both v8 and v9 command syntax
            if (!empty($commandOptions)) {
                Artisan::call('backup:run', $commandOptions);
            } else {
                Artisan::call('backup:run');
            }

            $output = Artisan::output();

            // Check for common error patterns in output
            if (str_contains($output, 'failed') ||
                str_contains($output, 'error') ||
                str_contains($output, 'not found') ||
                str_contains($output, 'mysqldump') ||
                str_contains($output, 'pg_dump')) {

                // Show detailed error if configured
                if (config('backup-ui.show_detailed_errors', true)) {
                    $errorMsg = 'Backup failed. Details: ' . strip_tags($output);

                    return back()->with('error', $errorMsg);
                } else {
                    return back()->with('error', 'Backup failed. Check logs for details.');
                }
            }

            return back()->with('success', 'Backup created successfully!');
        } catch (\Exception $e) {
            return back()->with('error', 'Backup failed: ' . $e->getMessage());
        }
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
        } catch (\Exception $e) {
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

        } catch (\Exception $e) {
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
        } catch (\Exception $e) {
            return back()->with('error', 'Delete failed: ' . $e->getMessage());
        }
    }

    public function clean()
    {
        try {
            // Support for both v8 and v9 clean command
            $exitCode = Artisan::call('backup:clean');

            if ($exitCode === 0) {
                return back()->with('success', 'Old backups cleaned successfully!');
            } else {
                return back()->with('error', 'Clean command completed with warnings. Check logs for details.');
            }
        } catch (\Exception $e) {
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
            } catch (\Exception $e) {
                $backupDestinations->push([
                    'name' => $diskName,
                    'reachable' => false,
                    'healthy' => false,
                    'error' => $e->getMessage(),
                    'amount' => 0,
                    'newest' => null,
                    'usedStorage' => '0 KB',
                    'backups' => [],
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

            } catch (\Exception $e) {
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

        } catch (\Exception $e) {
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
                    } catch (\Exception $e) {
                        return false;
                    }

                case 'ftp':
                case 'sftp':
                    // For FTP/SFTP, try to get directory listing
                    try {
                        $disk->allFiles();
                        return true;
                    } catch (\Exception $e) {
                        return false;
                    }

                case 'gcs':
                case 'google':
                    // For Google Cloud Storage
                    try {
                        $disk->allFiles();
                        return true;
                    } catch (\Exception $e) {
                        return false;
                    }

                case 'local':
                case 'public':
                    // For local disks, try basic operations
                    try {
                        // Try to perform basic disk operations
                        $disk->allFiles();
                        return true;
                    } catch (\Exception $e) {
                        return false;
                    }

                default:
                    // For other drivers, try basic existence check
                    try {
                        $disk->allFiles();
                        return true;
                    } catch (\Exception $e) {
                        return false;
                    }
            }
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function getFileSize($disk, $file, $driver)
    {
        try {
            return $disk->size($file);
        } catch (\Exception $e) {
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
        } catch (\Exception $e) {
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

    protected function createBackupDestination($diskName)
    {
        // Try different approaches based on available API versions

        // Method 1: Try with Config object (newer versions)
        if (class_exists('Spatie\Backup\Config\Config')) {
            try {
                $configClass = 'Spatie\Backup\Config\Config';

                // Use the full config structure that Config expects
                $configData = config('backup');

                // Create Config object using static method
                if (method_exists($configClass, 'createFromArray')) {
                    $config = $configClass::createFromArray($configData);
                } elseif (method_exists($configClass, 'createFromConfig')) {
                    $config = $configClass::createFromConfig($configData);
                } else {
                    // Try using Laravel's config() helper with Config class
                    $reflection = new \ReflectionClass($configClass);
                    if ($reflection->hasMethod('createFromConfigKey')) {
                        $config = $configClass::createFromConfigKey('backup');
                    } else {
                        throw new \Exception('Unable to create Config object - no suitable factory method found');
                    }
                }

                // Now create BackupDestination using the config
                return BackupDestinationFactory::createFromArray($config);
            } catch (\Exception $e) {
                // Fall through to next method
            }
        }

        // Method 2: Try direct creation with BackupDestination constructor
        if (class_exists('Spatie\Backup\BackupDestination\BackupDestination')) {
            try {
                $backupDestinationClass = 'Spatie\Backup\BackupDestination\BackupDestination';
                $disk = Storage::disk($diskName);
                $backupName = config('backup.backup.name');

                return new $backupDestinationClass($disk, $backupName);
            } catch (\Exception $e) {
                // Fall through to next method
            }
        }

        // Method 3: Try using BackupDestinationFactory with different approaches
        try {
            // First try: see if we can call it with just the disk name
            if (method_exists(BackupDestinationFactory::class, 'create')) {
                return BackupDestinationFactory::create($diskName, config('backup.backup.name'));
            }

            // Second try: legacy array method (older versions)
            return BackupDestinationFactory::createFromArray([
                'disk' => $diskName,
                'backup_name' => config('backup.backup.name'),
            ]);
        } catch (\Exception $e) {
            // Method 4: Fallback to manual BackupDestination creation
            try {
                // Try to find any BackupDestination class and create it manually
                $possibleClasses = [
                    'Spatie\Backup\BackupDestination\BackupDestination',
                    'Spatie\Backup\Backup\BackupDestination',
                ];

                foreach ($possibleClasses as $className) {
                    if (class_exists($className)) {
                        $disk = Storage::disk($diskName);
                        $backupName = config('backup.backup.name');

                        // Try different constructor signatures
                        try {
                            return new $className($disk, $backupName);
                        } catch (\Exception $ex) {
                            try {
                                return new $className($disk, $backupName, []);
                            } catch (\Exception $ex2) {
                                continue;
                            }
                        }
                    }
                }

                throw new \Exception("Unable to create backup destination for disk '{$diskName}': " . $e->getMessage());
            } catch (\Exception $fallbackException) {
                throw new \Exception("All methods failed to create backup destination for disk '{$diskName}'. Original error: " . $e->getMessage() . ". Fallback error: " . $fallbackException->getMessage());
            }
        }
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

    protected function getSpatieBackupVersion()
    {
        $composer = json_decode(file_get_contents(base_path('composer.lock')), true);

        foreach ($composer['packages'] as $package) {
            if ($package['name'] === 'spatie/laravel-backup') {
                return $package['version'];
            }
        }

        return null;
    }

    protected function isVersion9OrHigher()
    {
        $version = $this->getSpatieBackupVersion();
        return $version && version_compare($version, '9.0.0', '>=');
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

    protected function getSpatieBackupApiVersion()
    {
        // Check for v9+ API by looking for Config class
        if (class_exists('Spatie\Backup\Config\Config')) {
            return 9;
        }

        // Check for v8 API structure
        if (method_exists(BackupDestinationFactory::class, 'createFromArray')) {
            return 8;
        }

        return null;
    }
}
