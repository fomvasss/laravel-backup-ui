<?php

namespace Fomvasss\LaravelBackupUi\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Fomvasss\LaravelBackupUi\Jobs\Concerns\TracksProgress;
use Fomvasss\LaravelBackupUi\Support\BackupRestorer;

class RestoreBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksProgress;

    public $timeout = 3600;

    // A failed restore may have left the database in a partial state — don't blindly retry.
    public $tries = 1;

    public function __construct(
        public string $progressKey,
        public string $disk,
        public string $path
    ) {
        $queue = config('backup-ui.queue.name');
        if ($queue) {
            $this->onQueue($queue);
        }
    }

    public function handle(BackupRestorer $restorer): void
    {
        $tempZip = null;
        $tempDir = null;

        try {
            $this->updateProgress($this->progressKey, 0, 'Starting restore...');

            $backupDisk = Storage::disk($this->disk);
            $decodedPath = urldecode($this->path);
            $foundPath = $restorer->locateFile($backupDisk, $decodedPath);

            if (!$foundPath) {
                throw new \RuntimeException('Backup file not found');
            }

            $this->updateProgress($this->progressKey, 15, 'Downloading backup file...');

            $tempZip = tempnam(sys_get_temp_dir(), 'backup-ui-restore-') . '.zip';
            $stream = $backupDisk->readStream($foundPath);
            $out = fopen($tempZip, 'w');
            stream_copy_to_stream($stream, $out);
            fclose($out);
            fclose($stream);

            [$connectionName, $connectionConfig] = $restorer->resolveConnectionConfig(null);

            $this->updateProgress($this->progressKey, 35, 'Extracting archive...');

            $tempDir = storage_path('app/backup-ui-restore-' . uniqid());
            mkdir($tempDir, 0755, true);

            $candidates = $restorer->extractCandidates($tempZip, $tempDir);

            if (empty($candidates)) {
                throw new \RuntimeException('No database dump found inside the archive (expected db-dumps/*.sql)');
            }

            $sqlFile = $restorer->resolveDumpFile($candidates, $connectionName);

            if (!$sqlFile) {
                throw new \RuntimeException('Multiple dumps found in the archive and none matches the current connection');
            }

            $this->updateProgress($this->progressKey, 60, 'Restoring database...');

            $restorer->runMysqlImport($sqlFile, $connectionConfig);

            $this->updateProgress($this->progressKey, 100, 'Database restored successfully!', 'success');
        } catch (\Throwable $e) {
            Log::error('Backup UI: Restore job failed', [
                'disk' => $this->disk,
                'path' => $this->path,
                'error' => $e->getMessage(),
            ]);

            $this->updateProgress($this->progressKey, 100, 'Restore failed: ' . $e->getMessage(), 'error');

            throw $e;
        } finally {
            if ($tempDir) {
                (new BackupRestorer())->cleanupDir($tempDir);
            }
            if ($tempZip) {
                @unlink($tempZip);
            }
        }
    }
}
