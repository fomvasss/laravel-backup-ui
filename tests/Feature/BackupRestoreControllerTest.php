<?php

namespace Fomvasss\LaravelBackupUi\Tests\Feature;

use Fomvasss\LaravelBackupUi\Jobs\RestoreBackupJob;
use Fomvasss\LaravelBackupUi\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class BackupRestoreControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->actingAs($this->createUser());

        // Setting app.env to 'local' below (to exercise the restore guard) disables
        // VerifyCsrfToken's test-environment bypass, which only checks for 'testing'.
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    protected function tearDown(): void
    {
        $this->app['env'] = 'testing';

        parent::tearDown();
    }

    /** @test */
    public function it_refuses_restore_outside_local_environment()
    {
        $this->app['env'] = 'production';

        Storage::disk('local')->put('backup.zip', $this->makeZipContents(['db-dumps/mysql.sql' => 'SELECT 1;']));

        $response = $this->post('/backup/restore/local/backup.zip');

        $response->assertStatus(403);
    }

    /** @test */
    public function it_fails_when_backup_file_does_not_exist()
    {
        $this->app['env'] = 'local';

        $response = $this->post('/backup/restore/local/non-existent.zip');

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Backup file not found');
    }

    /** @test */
    public function it_fails_gracefully_for_unsupported_driver()
    {
        $this->app['env'] = 'local';

        // Test suite's default connection is sqlite — restore only supports mysql/mariadb.
        Storage::disk('local')->put('backup.zip', $this->makeZipContents(['db-dumps/sqlite.sql' => 'SELECT 1;']));

        $response = $this->post('/backup/restore/local/backup.zip');

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertStringContainsString('mysql/mariadb', session('error'));
    }

    /** @test */
    public function it_restores_synchronously_when_queue_is_disabled()
    {
        $this->app['env'] = 'local';
        config(['backup-ui.queue.enabled' => false]);

        Storage::disk('local')->put('backup.zip', $this->makeZipContents(['db-dumps/sqlite.sql' => 'SELECT 1;']));

        $response = $this->post('/backup/restore/local/backup.zip');

        $response->assertRedirect();
        $this->assertNull(session('active_progress_key'));
    }

    /** @test */
    public function it_dispatches_job_when_queue_is_enabled()
    {
        $this->app['env'] = 'local';
        Queue::fake();
        config(['backup-ui.queue.enabled' => true]);

        Storage::disk('local')->put('backup.zip', $this->makeZipContents(['db-dumps/sqlite.sql' => 'SELECT 1;']));

        $response = $this->post('/backup/restore/local/backup.zip');

        Queue::assertPushed(RestoreBackupJob::class, function ($job) {
            return $job->disk === 'local' && $job->path === 'backup.zip';
        });
        $response->assertRedirect();
        $response->assertSessionHas('info');
        $response->assertSessionHas('active_progress_key');
    }

    protected function makeZipContents(array $files): string
    {
        $zipPath = sys_get_temp_dir() . '/backup-ui-restore-fixture-' . uniqid() . '.zip';

        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        $contents = file_get_contents($zipPath);
        unlink($zipPath);

        return $contents;
    }
}
