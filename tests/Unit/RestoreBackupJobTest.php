<?php

namespace Fomvasss\LaravelBackupUi\Tests\Unit;

use Fomvasss\LaravelBackupUi\Jobs\RestoreBackupJob;
use Fomvasss\LaravelBackupUi\Support\BackupRestorer;
use Fomvasss\LaravelBackupUi\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class RestoreBackupJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Cache::flush();
    }

    /** @test */
    public function it_records_an_error_progress_entry_when_the_backup_file_is_missing()
    {
        $progressKey = 'restore_progress_test';
        $job = new RestoreBackupJob($progressKey, 'local', 'non-existent.zip');

        $this->expectException(\RuntimeException::class);

        try {
            $job->handle(new BackupRestorer());
        } finally {
            $progress = Cache::get($progressKey);

            $this->assertNotNull($progress);
            $this->assertEquals('error', $progress['status']);
            $this->assertEquals(100, $progress['percentage']);
        }
    }

    /** @test */
    public function it_records_an_error_progress_entry_for_unsupported_driver()
    {
        // Test suite's default connection is sqlite — restore only supports mysql/mariadb.
        Storage::disk('local')->put('backup.zip', 'not a real zip, but exists() is all that matters here');

        $progressKey = 'restore_progress_test';
        $job = new RestoreBackupJob($progressKey, 'local', 'backup.zip');

        $this->expectException(\RuntimeException::class);

        try {
            $job->handle(new BackupRestorer());
        } finally {
            $progress = Cache::get($progressKey);

            $this->assertNotNull($progress);
            $this->assertEquals('error', $progress['status']);
            $this->assertStringContainsString('mysql/mariadb', $progress['message']);
        }
    }
}
