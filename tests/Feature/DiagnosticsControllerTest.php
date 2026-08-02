<?php

namespace Fomvasss\LaravelBackupUi\Tests\Feature;

use Fomvasss\LaravelBackupUi\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DiagnosticsControllerTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_returns_diagnostics_json()
    {
        $response = $this->get('/backup/diagnostics');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'php_version',
            'laravel_version',
            'current_path',
            'spatie_backup_version',
            'writable_directories',
            'external_disks',
            'database_connection' => ['name', 'driver', 'database', 'host', 'restore_supported'],
        ]);
    }

    /** @test */
    public function it_reports_a_spatie_backup_version_string()
    {
        $response = $this->get('/backup/diagnostics');

        $response->assertStatus(200);
        $this->assertIsString($response->json('spatie_backup_version'));
    }

    /** @test */
    public function it_flags_the_test_suites_sqlite_connection_as_restore_unsupported()
    {
        // Test suite's default connection is sqlite — not in BackupRestorer::SUPPORTED_DRIVERS.
        $response = $this->get('/backup/diagnostics');

        $response->assertStatus(200);
        $this->assertEquals('sqlite', $response->json('database_connection.driver'));
        $this->assertFalse($response->json('database_connection.restore_supported'));
    }
}
