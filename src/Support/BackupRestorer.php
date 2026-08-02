<?php

namespace Fomvasss\LaravelBackupUi\Support;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use ZipArchive;

class BackupRestorer
{
    /** Drivers `runImport()` knows how to restore into. */
    public const SUPPORTED_DRIVERS = ['mysql', 'mariadb', 'pgsql', 'postgres'];

    /**
     * Locate a backup file on disk. The UI only knows the file's basename
     * (same as download/delete), while spatie/laravel-backup actually stores
     * files under a date-organized subdirectory — so fall back to a basename
     * search across the whole disk when the direct path doesn't exist.
     */
    public function locateFile($disk, string $path): ?string
    {
        if ($disk->exists($path)) {
            return $path;
        }

        $baseFilename = basename($path);

        foreach ($disk->allFiles() as $file) {
            if (basename($file) === $baseFilename) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Resolve and validate the database connection config for restoring into.
     *
     * @throws \RuntimeException
     */
    public function resolveConnectionConfig(?string $connectionName): array
    {
        $connectionName = $connectionName ?: config('database.default');
        $connectionConfig = config("database.connections.{$connectionName}");

        if (!$connectionConfig) {
            throw new \RuntimeException("Unknown database connection: {$connectionName}");
        }

        if (!in_array($connectionConfig['driver'] ?? null, self::SUPPORTED_DRIVERS, true)) {
            throw new \RuntimeException('Restore currently supports only mysql/mariadb/pgsql connections, got: ' . ($connectionConfig['driver'] ?? 'unknown'));
        }

        return [$connectionName, $connectionConfig];
    }

    /**
     * Extract the zip and return the list of db-dumps/*.sql files found inside.
     */
    public function extractCandidates(string $zipPath, string $tempDir): array
    {
        $zip = new ZipArchive();

        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException("Unable to open zip file: {$zipPath}");
        }

        $zip->extractTo($tempDir);
        $zip->close();

        return glob($tempDir . '/db-dumps/*.sql') ?: [];
    }

    /**
     * Pick the dump file matching the connection name. Returns null if there is
     * more than one candidate and none of them matches the connection name.
     */
    public function resolveDumpFile(array $candidates, string $connectionName): ?string
    {
        if (count($candidates) === 1) {
            return $candidates[0];
        }

        foreach ($candidates as $file) {
            if (str_contains(basename($file), $connectionName)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Import a SQL dump into the connection, dispatching to the right client
     * binary for the connection's driver.
     */
    public function runImport(string $sqlFile, array $connectionConfig): void
    {
        match ($connectionConfig['driver'] ?? null) {
            'mysql', 'mariadb' => $this->runMysqlImport($sqlFile, $connectionConfig),
            'pgsql', 'postgres' => $this->runPgsqlImport($sqlFile, $connectionConfig),
            default => throw new \RuntimeException(
                'Restore currently supports only mysql/mariadb/pgsql connections, got: '
                . ($connectionConfig['driver'] ?? 'unknown')
            ),
        };
    }

    public function runMysqlImport(string $sqlFile, array $connectionConfig): void
    {
        $credentialsFile = tempnam(sys_get_temp_dir(), 'backup-ui-restore-');

        $lines = [
            '[client]',
            'user=' . $connectionConfig['username'],
            'password=' . $connectionConfig['password'],
            'host=' . $connectionConfig['host'],
            'port=' . ($connectionConfig['port'] ?? 3306),
        ];

        if ($connectionConfig['dump']['skip_ssl'] ?? false) {
            $sslFlag = $this->detectMysqlSslFlag();

            if ($sslFlag) {
                $lines[] = $sslFlag;
            }
        }

        file_put_contents($credentialsFile, implode(PHP_EOL, $lines));
        chmod($credentialsFile, 0600);

        try {
            $process = new Process([
                'mysql',
                '--defaults-extra-file=' . $credentialsFile,
                $connectionConfig['database'],
            ]);
            $process->setInput(fopen($sqlFile, 'r'));
            $process->setTimeout(3600);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }
        } finally {
            @unlink($credentialsFile);
        }
    }

    public function runPgsqlImport(string $sqlFile, array $connectionConfig): void
    {
        $passfile = tempnam(sys_get_temp_dir(), 'backup-ui-restore-');

        // .pgpass format: hostname:port:database:username:password
        // (':' and '\' inside a field must be backslash-escaped per the Postgres docs)
        $escape = fn (string $value): string => str_replace([':', '\\'], ['\\:', '\\\\'], $value);

        $line = implode(':', [
            $escape((string) $connectionConfig['host']),
            (string) ($connectionConfig['port'] ?? 5432),
            $escape($connectionConfig['database']),
            $escape($connectionConfig['username']),
            $escape((string) $connectionConfig['password']),
        ]);

        file_put_contents($passfile, $line . PHP_EOL);
        chmod($passfile, 0600);

        try {
            $process = new Process([
                'psql',
                '--host=' . $connectionConfig['host'],
                '--port=' . ($connectionConfig['port'] ?? 5432),
                '--username=' . $connectionConfig['username'],
                '--dbname=' . $connectionConfig['database'],
                '--set=ON_ERROR_STOP=1',
                '--file=' . $sqlFile,
            ], null, ['PGPASSFILE' => $passfile]);
            $process->setTimeout(3600);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }
        } finally {
            @unlink($passfile);
        }
    }

    /**
     * The flag needed to disable SSL differs by client vendor/version
     * (MariaDB only knows --skip-ssl, MySQL 8.0.26+ only knows --ssl-mode=DISABLED,
     * and both may be present across different containers/hosts) — so detect it
     * from the actual `mysql` binary available right now instead of assuming one.
     */
    protected function detectMysqlSslFlag(): ?string
    {
        $process = new Process(['mysql', '--help']);
        $process->run();

        $help = $process->getOutput() . $process->getErrorOutput();

        if (str_contains($help, 'ssl-mode')) {
            return 'ssl-mode=DISABLED';
        }

        if (str_contains($help, 'skip-ssl')) {
            return 'skip-ssl';
        }

        return null;
    }

    public function cleanupDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($dir);
    }
}
