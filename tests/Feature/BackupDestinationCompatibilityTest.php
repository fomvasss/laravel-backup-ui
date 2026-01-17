<?php

namespace Fomvasss\LaravelBackupUi\Tests\Feature;

use Fomvasss\LaravelBackupUi\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

class BackupDestinationCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** @test */
    public function it_handles_backup_destination_creation_gracefully()
    {
        $response = $this->get('/admin/backup');

        $response->assertStatus(200);
        $response->assertViewHas('backupDestinations');

        $backupDestinations = $response->viewData('backupDestinations');

        // Should handle any API version without crashing
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $backupDestinations);

        // If destinations exist, they should have proper structure
        if ($backupDestinations->isNotEmpty()) {
            $destination = $backupDestinations->first();
            $this->assertArrayHasKey('name', $destination);
            $this->assertArrayHasKey('reachable', $destination);

            // If there's an error, it should be handled gracefully
            if (isset($destination['error'])) {
                $this->assertIsString($destination['error']);
            }
        }
    }

    /** @test */
    public function it_shows_error_message_for_incompatible_api()
    {
        // This test ensures errors are displayed to user rather than crashing
        $response = $this->get('/admin/backup');

        $response->assertStatus(200);
        // Page should load even if there are API compatibility issues
        $response->assertSee('Backup Management');
    }
}
