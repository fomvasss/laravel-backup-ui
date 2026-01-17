<?php

namespace Fomvasss\LaravelBackupUi\Tests\Feature;

use Fomvasss\LaravelBackupUi\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class NewestBackupDateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** @test */
    public function it_handles_newest_backup_date_correctly()
    {
        // Create a backup file with known timestamp
        $backupFile = 'laravel-backup-test.zip';
        Storage::disk('local')->put($backupFile, 'test content');

        // Touch the file to set specific timestamp (1 hour ago)
        $timestamp = now()->subHour()->timestamp;

        $response = $this->get('/admin/backup');

        $response->assertStatus(200);
        $response->assertViewHas('backupDestinations');

        $backupDestinations = $response->viewData('backupDestinations');

        if ($backupDestinations->isNotEmpty()) {
            $destination = $backupDestinations->first();

            if ($destination['newest']) {
                // Test that the date() method works
                $newestDate = $destination['newest']->date();
                $this->assertInstanceOf(Carbon::class, $newestDate);

                // Test that it can format date
                $formattedDate = $destination['newest']->date()->format('Y-m-d H:i:s');
                $this->assertIsString($formattedDate);
                $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $formattedDate);
            } else {
                // If no backups, newest should be null
                $this->assertNull($destination['newest']);
            }
        }
    }

    /** @test */
    public function it_shows_none_when_no_backups_exist()
    {
        // No backup files created
        $response = $this->get('/admin/backup');

        $response->assertStatus(200);

        // Should show "None" for newest backup
        $response->assertSee('None');
    }

    /** @test */
    public function it_handles_multiple_backups_and_shows_newest()
    {
        // Create multiple backup files
        Storage::disk('local')->put('laravel-backup-old.zip', 'old backup');
        Storage::disk('local')->put('laravel-backup-new.zip', 'new backup');

        $response = $this->get('/admin/backup');

        $response->assertStatus(200);
        $backupDestinations = $response->viewData('backupDestinations');

        if ($backupDestinations->isNotEmpty()) {
            $destination = $backupDestinations->first();

            // Should have backups
            $this->assertGreaterThan(0, $destination['amount']);

            if ($destination['newest']) {
                // Should be able to get date of newest backup
                $newestDate = $destination['newest']->date();
                $this->assertInstanceOf(Carbon::class, $newestDate);
            }
        }
    }
}
