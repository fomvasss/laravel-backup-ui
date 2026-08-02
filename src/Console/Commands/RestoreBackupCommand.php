<?php

namespace Fomvasss\LaravelBackupUi\Console\Commands;

use Fomvasss\LaravelBackupUi\Support\BackupRestorer;
use Illuminate\Console\Command;

class RestoreBackupCommand extends Command
{
    protected $signature = 'backup-ui:restore
        {path : Path to a local spatie/laravel-backup zip file}
        {--connection= : Database connection to restore into (defaults to database.default)}';

    protected $description = 'Restore a database dump from a local backup zip file (local environment only)';

    public function handle(BackupRestorer $restorer): int
    {
        if (!app()->environment('local')) {
            $this->error('backup-ui:restore only runs when APP_ENV=local.');

            return self::FAILURE;
        }

        $zipPath = $this->argument('path');

        if (!is_file($zipPath)) {
            $this->error("File not found: {$zipPath}");

            return self::FAILURE;
        }

        try {
            [$connectionName, $connectionConfig] = $restorer->resolveConnectionConfig($this->option('connection'));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $tempDir = storage_path('app/backup-ui-restore-' . uniqid());
        mkdir($tempDir, 0755, true);

        try {
            $candidates = $restorer->extractCandidates($zipPath, $tempDir);

            if (empty($candidates)) {
                $this->error('No database dump found inside the archive (expected db-dumps/*.sql).');

                return self::FAILURE;
            }

            $sqlFile = $restorer->resolveDumpFile($candidates, $connectionName);

            if (!$sqlFile) {
                $choice = $this->choice('Multiple dumps found, which one to restore?', array_map('basename', $candidates));

                foreach ($candidates as $candidate) {
                    if (basename($candidate) === $choice) {
                        $sqlFile = $candidate;
                        break;
                    }
                }
            }

            $this->line('Found dump: ' . basename($sqlFile));

            if (!$this->confirm(
                "This will OVERWRITE database '{$connectionConfig['database']}' on '{$connectionConfig['host']}' (connection: {$connectionName}). Continue?"
            )) {
                $this->info('Aborted.');

                return self::SUCCESS;
            }

            $restorer->runImport($sqlFile, $connectionConfig);

            $this->info('Database restored successfully.');

            return self::SUCCESS;
        } finally {
            $restorer->cleanupDir($tempDir);
        }
    }
}
