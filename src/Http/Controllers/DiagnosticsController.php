<?php

namespace Fomvasss\LaravelBackupUi\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DiagnosticsController extends Controller
{
    public function index()
    {
        $diagnostics = [
            'php_version' => phpversion(),
            'laravel_version' => app()->version(),
            'current_path' => getenv('PATH'),
            'spatie_backup_version' => $this->getSpatieBackupVersion(),
            'writable_directories' => $this->checkWritableDirectories(),
            'external_disks' => $this->checkExternalDisks(),
        ];

        return response()->json($diagnostics);
    }


    protected function getSpatieBackupVersion()
    {
        try {
            $composerLock = json_decode(file_get_contents(base_path('composer.lock')), true);
            foreach ($composerLock['packages'] as $package) {
                if ($package['name'] === 'spatie/laravel-backup') {
                    return $package['version'];
                }
            }
        } catch (\Exception $e) {
            return 'Unknown';
        }

        return 'Not found';
    }

    protected function checkWritableDirectories()
    {
        $directories = [];

        foreach (config('backup.backup.destination.disks', []) as $disk) {
            try {
                $diskConfig = config("filesystems.disks.$disk");
                if ($diskConfig && isset($diskConfig['root'])) {
                    $path = $diskConfig['root'];
                    $directories[$disk] = [
                        'path' => $path,
                        'exists' => is_dir($path),
                        'writable' => is_writable($path),
                    ];
                }
            } catch (\Exception $e) {
                $directories[$disk] = ['error' => $e->getMessage()];
            }
        }

        return $directories;
    }

    protected function checkExternalDisks()
    {
        $disks = [];

        foreach (config('backup.backup.destination.disks', []) as $diskName) {
            try {
                $diskConfig = config("filesystems.disks.$diskName", []);
                $driver = $diskConfig['driver'] ?? 'unknown';

                $disk = \Storage::disk($diskName);

                $isReachable = false;
                $error = null;

                try {
                    $disk->allFiles();
                    $isReachable = true;
                } catch (\Exception $e) {
                    $error = $e->getMessage();
                }

                $disks[$diskName] = [
                    'driver' => $driver,
                    'reachable' => $isReachable,
                    'is_external' => !in_array($driver, ['local', 'public']),
                    'config_keys' => array_keys($diskConfig),
                    'error' => $error,
                ];

            } catch (\Exception $e) {
                $disks[$diskName] = [
                    'driver' => 'unknown',
                    'reachable' => false,
                    'is_external' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $disks;
    }
}
