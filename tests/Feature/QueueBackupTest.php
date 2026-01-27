<?php

namespace Fomvasss\LaravelBackupUi\Tests\Feature;

use Fomvasss\LaravelBackupUi\Jobs\CreateBackupJob;
use Fomvasss\LaravelBackupUi\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

class QueueBackupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    /** @test */
    public function it_creates_backup_synchronously_when_queue_is_disabled()
    {
        config(['backup-ui.queue.enabled' => false]);

        $response = $this->post(route('backup-ui.create'), [
            'option' => 'only-db',
        ]);

        $response->assertRedirect();
        // In sync mode, we don't expect a progress_key in session
        $this->assertNull(session('progress_key'));
    }

    /** @test */
    public function it_dispatches_job_when_queue_is_enabled()
    {
        Queue::fake();
        config(['backup-ui.queue.enabled' => true]);

        $response = $this->post(route('backup-ui.create'), [
            'option' => 'only-db',
        ]);

        Queue::assertPushed(CreateBackupJob::class);
        $response->assertRedirect();
        $response->assertSessionHas('info');
        $response->assertSessionHas('progress_key');
    }

    /** @test */
    public function it_returns_status_for_queued_backup()
    {
        $progressKey = 'backup_progress_test';
        
        // Simulate progress data
        Cache::put($progressKey, [
            'percentage' => 50,
            'message' => 'Running backup command...',
            'status' => 'processing',
            'updated_at' => now()->toIso8601String(),
        ], 3600);

        $response = $this->get(route('backup-ui.status', ['progress_key' => $progressKey]));

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'percentage' => 50,
            'status' => 'processing',
        ]);
    }

    /** @test */
    public function it_returns_queued_status_when_job_not_started()
    {
        $response = $this->get(route('backup-ui.status', ['progress_key' => 'non_existent_key']));

        $response->assertStatus(200);
        $response->assertJson([
            'success' => false,
            'percentage' => 0,
            'status' => 'queued',
        ]);
    }

    /** @test */
    public function it_returns_error_when_progress_key_is_missing()
    {
        $response = $this->get(route('backup-ui.status'));

        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'message' => 'Progress key is required',
        ]);
    }

    /** @test */
    public function it_uses_configured_queue_name()
    {
        Queue::fake();
        config([
            'backup-ui.queue.enabled' => true,
            'backup-ui.queue.name' => 'custom-queue',
        ]);

        $this->post(route('backup-ui.create'), ['option' => null]);

        Queue::assertPushed(CreateBackupJob::class, function ($job) {
            return $job->queue === 'custom-queue';
        });
    }


    /** @test */
    public function it_validates_backup_option()
    {
        config(['backup-ui.queue.enabled' => true]);

        $response = $this->post(route('backup-ui.create'), [
            'option' => 'invalid-option',
        ]);

        $response->assertSessionHasErrors('option');
    }

    /** @test */
    public function it_accepts_valid_backup_options()
    {
        Queue::fake();
        config(['backup-ui.queue.enabled' => true]);

        $validOptions = [null, 'only-db', 'only-files'];

        foreach ($validOptions as $option) {
            $response = $this->post(route('backup-ui.create'), [
                'option' => $option,
            ]);

            $response->assertSessionHasNoErrors();
        }
    }
}
