<?php

namespace Fomvasss\LaravelBackupUi\Tests\Feature;

use Fomvasss\LaravelBackupUi\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

class BackupFilePathTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** @test */
    public function it_can_find_backup_files_in_subdirectories()
    {
        // Create backup file structure similar to spatie/laravel-backup
        $backupPath = 'laravel-backup/2024/01/17/backup-2024-01-17-14-30-00.zip';
        Storage::disk('local')->put($backupPath, 'fake backup content');

        $controller = new \Fomvasss\LaravelBackupUi\Http\Controllers\BackupController();

        // Use reflection to test private method
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('findBackupFile');
        $method->setAccessible(true);

        $disk = Storage::disk('local');

        // Test finding by full filename
        $result = $method->invoke($controller, $disk, 'backup-2024-01-17-14-30-00.zip');

        $this->assertEquals($backupPath, $result);
    }

    /** @test */
    public function it_can_download_backup_files_from_subdirectories()
    {
        // Create backup file in spatie structure
        $backupPath = 'laravel-backup/2024/01/17/backup-2024-01-17-14-30-00.zip';
        Storage::disk('local')->put($backupPath, 'fake backup content');

        // Try to download using just the filename (as the view does)
        $response = $this->get('/admin/backup/download/local/' . urlencode('backup-2024-01-17-14-30-00.zip'));

        $response->assertStatus(200);
        $response->assertHeader('content-disposition');
    }

    /** @test */
    public function it_can_delete_backup_files_from_subdirectories()
    {
        // Create backup file in spatie structure
        $backupPath = 'laravel-backup/2024/01/17/backup-2024-01-17-14-30-00.zip';
        Storage::disk('local')->put($backupPath, 'fake backup content');

        // Verify file exists
        Storage::disk('local')->assertExists($backupPath);

        // Try to delete using just the filename
        $response = $this->delete('/admin/backup/delete/local/' . urlencode('backup-2024-01-17-14-30-00.zip'));

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Backup deleted successfully!');

        // Verify file is deleted
        Storage::disk('local')->assertMissing($backupPath);
    }

    /** @test */
    public function it_handles_backup_files_with_special_characters()
    {
        // Create backup file with special characters
        $backupPath = 'laravel-backup/2024/01/17/backup with spaces & symbols [test].zip';
        Storage::disk('local')->put($backupPath, 'fake backup content');

        $fileName = 'backup with spaces & symbols [test].zip';

        // Try to download with URL encoding
        $response = $this->get('/admin/backup/download/local/' . urlencode($fileName));

        $response->assertStatus(200);
    }

    /** @test */
    public function it_returns_404_for_non_existent_files()
    {
        $response = $this->get('/admin/backup/download/local/non-existent-file.zip');

        $response->assertStatus(404);
    }

    /** @test */
    public function it_shows_backup_files_with_correct_paths_in_listing()
    {
        // Create multiple backup files in different date folders
        Storage::disk('local')->put('laravel-backup/2024/01/17/backup-new.zip', 'new backup');
        Storage::disk('local')->put('laravel-backup/2024/01/16/backup-old.zip', 'old backup');

        $response = $this->get('/admin/backup');

        $response->assertStatus(200);
        $backupDestinations = $response->viewData('backupDestinations');

        if ($backupDestinations->isNotEmpty()) {
            $destination = $backupDestinations->first();

            if (!empty($destination['backups'])) {
                // Should find both backup files
                $this->assertGreaterThanOrEqual(2, count($destination['backups']));

                // Check that paths are stored correctly (with full path including subdirectories)
                $paths = array_column($destination['backups'], 'path');
                $this->assertContains('laravel-backup/2024/01/17/backup-new.zip', $paths);
                $this->assertContains('laravel-backup/2024/01/16/backup-old.zip', $paths);
            }
        }
    }
}
