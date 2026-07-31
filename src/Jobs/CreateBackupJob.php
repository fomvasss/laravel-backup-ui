<?php

namespace Fomvasss\LaravelBackupUi\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Fomvasss\LaravelBackupUi\Jobs\Concerns\TracksProgress;
use Fomvasss\LaravelBackupUi\Support\BackupOutputAnalyzer;

class CreateBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksProgress;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 3600;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Backup option (null for full, 'only-db', 'only-files')
     *
     * @var string|null
     */
    public $option;

    /**
     * Unique progress key for tracking
     *
     * @var string
     */
    public $progressKey;

    /**
     * Create a new job instance.
     *
     * @param string $progressKey
     * @param string|null $option
     */
    public function __construct($progressKey, $option = null)
    {
        $this->progressKey = $progressKey;
        $this->option = $option;
        
        // Set queue from config
        $queue = config('backup-ui.queue.name');
        if ($queue) {
            $this->onQueue($queue);
        }
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            $this->updateProgress($this->progressKey, 0, 'Starting backup process...');

            $commandOptions = [];

            if ($this->option === 'only-db') {
                $commandOptions['--only-db'] = true;
                $this->updateProgress($this->progressKey, 10, 'Preparing database backup...');
            } elseif ($this->option === 'only-files') {
                $commandOptions['--only-files'] = true;
                $this->updateProgress($this->progressKey, 10, 'Preparing files backup...');
            } else {
                $this->updateProgress($this->progressKey, 10, 'Preparing full backup...');
            }

            $this->updateProgress($this->progressKey, 20, 'Running backup command...');

            // Execute backup command
            if (!empty($commandOptions)) {
                $exitCode = Artisan::call('backup:run', $commandOptions);
            } else {
                $exitCode = Artisan::call('backup:run');
            }

            $output = Artisan::output();

            $this->updateProgress($this->progressKey, 90, 'Finalizing backup...');

            // Check for errors
            if ($exitCode !== 0 || BackupOutputAnalyzer::indicatesFailure($output)) {
                $errorMsg = 'Backup failed. Check logs for details.';
                if (config('backup-ui.show_detailed_errors', true)) {
                    $errorMsg = strip_tags($output);
                }

                Log::error('Backup job failed', [
                    'option' => $this->option,
                    'exit_code' => $exitCode,
                    'output' => $output,
                ]);

                $this->updateProgress($this->progressKey, 100, $errorMsg, 'error');

                throw new \Exception($errorMsg);
            }

            $this->updateProgress($this->progressKey, 100, 'Backup completed successfully!', 'success');

            Log::info('Backup job completed successfully', [
                'option' => $this->option,
                'progress_key' => $this->progressKey,
            ]);

        } catch (\Throwable $e) {
            Log::error('Backup job exception', [
                'option' => $this->option,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->updateProgress($this->progressKey, 100, 'Backup failed: ' . $e->getMessage(), 'error');

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception)
    {
        Log::error('Backup job failed permanently', [
            'option' => $this->option,
            'progress_key' => $this->progressKey,
            'error' => $exception->getMessage(),
        ]);

        $this->updateProgress($this->progressKey, 100, 'Backup failed after multiple attempts: ' . $exception->getMessage(), 'error');
    }
}
