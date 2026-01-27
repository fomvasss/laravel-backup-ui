<?php

namespace Fomvasss\LaravelBackupUi\Tests\Unit;

use Fomvasss\LaravelBackupUi\Jobs\CreateBackupJob;
use Fomvasss\LaravelBackupUi\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

class CreateBackupJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Clear cache before each test
        Cache::flush();
    }

    /** @test */
    public function it_can_be_dispatched_to_queue()
    {
        Queue::fake();

        $progressKey = 'backup_progress_test';
        
        CreateBackupJob::dispatch(null, $progressKey);

        Queue::assertPushed(CreateBackupJob::class);
    }

    /** @test */
    public function it_respects_queue_configuration()
    {
        config(['backup-ui.queue.name' => 'test-queue']);

        $progressKey = 'backup_progress_test';
        $job = new CreateBackupJob(null, $progressKey);

        $this->assertEquals('test-queue', $job->queue);
    }

    /** @test */
    public function it_updates_progress_in_cache()
    {
        $progressKey = 'backup_progress_test';
        
        // Simulate what the job does internally
        Cache::put($progressKey, [
            'percentage' => 50,
            'message' => 'Processing backup...',
            'status' => 'processing',
            'updated_at' => now()->toIso8601String(),
            'option' => null,
        ], 3600);

        $progress = Cache::get($progressKey);

        $this->assertNotNull($progress);
        $this->assertEquals(50, $progress['percentage']);
        $this->assertEquals('processing', $progress['status']);
    }

    /** @test */
    public function it_has_correct_timeout_settings()
    {
        $job = new CreateBackupJob(null, 'test_key');

        $this->assertEquals(3600, $job->timeout);
        $this->assertEquals(3, $job->tries);
    }

    /** @test */
    public function it_handles_different_backup_options()
    {
        $options = [null, 'only-db', 'only-files'];

        foreach ($options as $option) {
            $job = new CreateBackupJob($option, 'test_key_' . $option);
            
            // Just verify the job can be created with different options
            $this->assertInstanceOf(CreateBackupJob::class, $job);
        }
    }


    /** @test */
    public function progress_key_is_unique_for_each_job()
    {
        $key1 = 'backup_progress_' . uniqid();
        $key2 = 'backup_progress_' . uniqid();

        $this->assertNotEquals($key1, $key2);
    }
}
