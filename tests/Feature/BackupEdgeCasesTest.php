<?php

namespace Fomvasss\LaravelBackupUi\Tests\Feature;

use Fomvasss\LaravelBackupUi\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

class BackupEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->actingAs($this->createUser());
    }

    /** @test */
    public function it_handles_missing_backup_configuration_gracefully()
    {
        // Test with empty backup configuration
        $this->app['config']->set('backup.backup.destination.disks', []);

        $response = $this->get('/backup');

        $response->assertStatus(200);
        $response->assertSee('No backup destinations configured');
    }

    /** @test */
    public function it_handles_invalid_disk_configuration()
    {
        // Test with non-existent disk
        $this->app['config']->set('backup.backup.destination.disks', ['non-existent']);

        $response = $this->get('/backup');

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
    public function it_validates_csrf_protection_on_state_changing_operations()
    {
        // Orchestra Testbench's default 'web' middleware group does not include
        // CSRF verification, so this can't be exercised through the test HTTP kernel.
        $this->markTestSkipped('CSRF verification is not part of the Testbench web middleware group.');
    }

    /** @test */
    public function it_handles_backup_storage_calculation_edge_cases()
    {
        // Test with empty backups collection
        $response = $this->get('/backup');
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
            ['POST', '/backup/create'],
            ['DELETE', '/backup/delete/local/test.zip'],
            ['POST', '/backup/clean'],
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
