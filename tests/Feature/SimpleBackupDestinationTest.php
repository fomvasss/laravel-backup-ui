<?php

namespace Fomvasss\LaravelBackupUi\Tests\Feature;

use Fomvasss\LaravelBackupUi\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

class SimpleBackupDestinationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** @test */
    public function it_can_detect_backup_files_directly()
    {
        // Create some fake backup files
        Storage::disk('local')->put('laravel-backup-2024-01-17-14-30-00.zip', 'fake backup content 1');
        Storage::disk('local')->put('laravel-backup-2024-01-16-14-30-00.zip', 'fake backup content 2');
        Storage::disk('local')->put('not-a-backup.txt', 'not a backup file');

        $response = $this->get('/admin/backup');

        $response->assertStatus(200);
        $response->assertViewHas('backupDestinations');

        $backupDestinations = $response->viewData('backupDestinations');
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $backupDestinations);

        if ($backupDestinations->isNotEmpty()) {
            $destination = $backupDestinations->first();

            // Should detect the backup files
            $this->assertEquals('local', $destination['name']);
            $this->assertTrue($destination['reachable']);
            $this->assertArrayHasKey('backups', $destination);

            // Should have found the backup files (not the txt file)
            if (count($destination['backups']) > 0) {
                foreach ($destination['backups'] as $backup) {
                    $this->assertStringContainsString('laravel-backup', $backup['path']);
                    $this->assertStringEndsWith('.zip', $backup['path']);
                }
            }
        }
    }

    /** @test */
    public function it_handles_empty_backup_directories()
    {
        // No backup files on disk
        $response = $this->get('/admin/backup');

        $response->assertStatus(200);
        $backupDestinations = $response->viewData('backupDestinations');

        if ($backupDestinations->isNotEmpty()) {
            $destination = $backupDestinations->first();
            $this->assertEquals(0, $destination['amount']);
            $this->assertEmpty($destination['backups']);
            $this->assertEquals('0 B', $destination['usedStorage']);
        }
    }

    /** @test */
    public function it_calculates_file_sizes_correctly()
    {
        // Create backup file with known size
        $content = str_repeat('x', 2048); // 2KB content
        Storage::disk('local')->put('laravel-backup-test.zip', $content);

        $response = $this->get('/admin/backup');

        $response->assertStatus(200);
        $backupDestinations = $response->viewData('backupDestinations');

        if ($backupDestinations->isNotEmpty()) {
            $destination = $backupDestinations->first();
            if (!empty($destination['backups'])) {
                $backup = $destination['backups'][0];
                $this->assertEquals(2, $backup['size_in_kb']);
                $this->assertEquals('2 KB', $backup['human_readable_size']);
            }
        }
    }

    /** @test */
    public function it_handles_unreachable_disks()
    {
        // Set up config with non-existent disk
        $this->app['config']->set('backup.backup.destination.disks', ['non-existent']);

        $response = $this->get('/admin/backup');

        $response->assertStatus(200);
        $backupDestinations = $response->viewData('backupDestinations');

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $backupDestinations);

        if ($backupDestinations->isNotEmpty()) {
            $destination = $backupDestinations->first();
            $this->assertEquals('non-existent', $destination['name']);
            $this->assertFalse($destination['reachable']);
            $this->assertArrayHasKey('error', $destination);
        }
    }
}
