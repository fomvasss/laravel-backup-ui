<?php

namespace Fomvasss\LaravelBackupUi\Jobs\Concerns;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

trait TracksProgress
{
    protected function updateProgress(string $progressKey, int $percentage, string $message, string $status = 'processing'): void
    {
        Cache::put($progressKey, [
            'percentage' => $percentage,
            'message' => $message,
            'status' => $status, // 'processing', 'success', 'error'
            'updated_at' => now()->toIso8601String(),
        ], 3600);

        Log::debug('Backup UI: progress updated', [
            'key' => $progressKey,
            'percentage' => $percentage,
            'message' => $message,
            'status' => $status,
        ]);
    }
}
