<?php

namespace Fomvasss\LaravelBackupUi\Support;

class BackupOutputAnalyzer
{
    protected const FAILURE_MARKERS = ['failed', 'error', 'not found', 'mysqldump', 'pg_dump'];

    public static function indicatesFailure(string $output): bool
    {
        foreach (self::FAILURE_MARKERS as $marker) {
            if (str_contains($output, $marker)) {
                return true;
            }
        }

        return false;
    }
}
