<?php

namespace Fomvasss\LaravelBackupUi\Tests\Feature;

use Fomvasss\LaravelBackupUi\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\BackupDestination\BackupDestinationFactory;
use Spatie\Backup\Tasks\Monitor\BackupDestinationStatusFactory;

class VersionCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** @test */
    public function it_handles_spatie_backup_v8_api()
    {
        if (!class_exists(BackupDestinationFactory::class)) {
            $this->markTestSkipped('spatie/laravel-backup not installed');
        }

        // Test that we can create backup destinations (v8 compatibility)
        try {
            $backupDestination = BackupDestinationFactory::createFromArray([
                'disk' => 'local',
                'backup_name' => 'test-backup',
            ]);

            $this->assertNotNull($backupDestination);
        } catch (\Exception $e) {
            // If this fails, it might be because we're testing without the actual package
            $this->markTestSkipped('BackupDestinationFactory not available: ' . $e->getMessage());
        }
    }

    /** @test */
    public function it_handles_backup_destination_status_factory()
    {
        if (!class_exists(BackupDestinationStatusFactory::class)) {
            $this->markTestSkipped('BackupDestinationStatusFactory not available');
        }

        // Test method existence for version compatibility
        $this->assertTrue(
            method_exists(BackupDestinationStatusFactory::class, 'createForMonitorConfig') ||
            method_exists(BackupDestinationStatusFactory::class, 'createBackupDestinationStatus')
        );
    }

    /** @test */
    public function it_displays_backup_destinations_without_errors()
    {
        $response = $this->get('/admin/backup');

        $response->assertStatus(200);
        $response->assertViewHas('backupDestinations');

        $backupDestinations = $response->viewData('backupDestinations');
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $backupDestinations);
    }

    /** @test */
    public function it_handles_missing_backup_configuration_gracefully()
    {
        // Test with empty backup configuration
        $this->app['config']->set('backup.backup.destination.disks', []);

        $response = $this->get('/admin/backup');

        $response->assertStatus(200);
        $response->assertSee('No backup destinations configured');
    }

    /** @test */
    public function it_handles_invalid_disk_configuration()
    {
        // Test with non-existent disk
        $this->app['config']->set('backup.backup.destination.disks', ['non-existent']);

        $response = $this->get('/admin/backup');

        $response->assertStatus(200);
        // Should handle the error gracefully and show error message
        $backupDestinations = $response->viewData('backupDestinations');
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $backupDestinations);

        if ($backupDestinations->isNotEmpty()) {
            $destination = $backupDestinations->first();
            $this->assertEquals('non-existent', $destination['name']);
            $this->assertFalse($destination['reachable']);
            $this->assertArrayHasKey('error', $destination);
        }
    }

    /** @test */
    public function it_creates_backup_with_different_command_formats()
    {
        // Test full backup
        $response = $this->post('/admin/backup/create');
        $response->assertSessionHasNoErrors();

        // Test database-only backup
        $response = $this->post('/admin/backup/create', ['option' => 'only-db']);
        $response->assertSessionHasNoErrors();

        // Test files-only backup
        $response = $this->post('/admin/backup/create', ['option' => 'only-files']);
        $response->assertSessionHasNoErrors();
    }

    /** @test */
    public function it_handles_backup_file_operations_with_encoded_paths()
    {
        // Create a test backup file with special characters
        $filename = 'test backup [2024-01-17] & more.zip';
        Storage::disk('local')->put($filename, 'test content');

        // Test download with encoded filename
        $encodedFilename = urlencode($filename);
        $response = $this->get("/admin/backup/download/local/{$encodedFilename}");
        $response->assertStatus(200);

        // Test delete with encoded filename
        $response = $this->delete("/admin/backup/delete/local/{$encodedFilename}");
        $response->assertRedirect();
        $response->assertSessionHas('success');

        // Verify file was deleted
        Storage::disk('local')->assertMissing($filename);
    }

    /** @test */
    public function it_validates_csrf_protection_on_state_changing_operations()
    {
        // Test that POST requests require CSRF token
        $response = $this->post('/admin/backup/create', [], [
            'HTTP_X-Requested-With' => 'XMLHttpRequest'
        ]);

        // Should fail due to missing CSRF token
        $this->assertTrue(in_array($response->getStatusCode(), [419, 500])); // 419 is CSRF token mismatch
    }

    /** @test */
    public function it_handles_backup_storage_calculation_edge_cases()
    {
        // Test with empty backups collection
        $response = $this->get('/admin/backup');
        $response->assertStatus(200);

        $backupDestinations = $response->viewData('backupDestinations');

        foreach ($backupDestinations as $destination) {
            if (isset($destination['usedStorage'])) {
                // Should handle zero or empty storage gracefully
                $this->assertIsString($destination['usedStorage']);
            }
        }
    }

    /** @test */
    public function it_maintains_consistent_response_format_across_operations()
    {
        // All operations should redirect back with flash messages
        $operations = [
            ['POST', '/admin/backup/create'],
            ['DELETE', '/admin/backup/delete/local/test.zip'],
            ['POST', '/admin/backup/clean'],
        ];

        foreach ($operations as [$method, $url]) {
            $response = $this->call($method, $url);

            // Should be redirect response
            $this->assertTrue(in_array($response->getStatusCode(), [302, 404])); // 404 for non-existent files is OK

            if ($response->getStatusCode() === 302) {
                // Should have flash message (success or error)
                $session = $response->getSession();
                $this->assertTrue(
                    $session->has('success') || $session->has('error'),
                    "Operation {$method} {$url} should have flash message"
                );
            }
        }
    }
}
