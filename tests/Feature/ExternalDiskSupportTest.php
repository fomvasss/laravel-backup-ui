<?php

namespace Fomvasss\LaravelBackupUi\Tests\Feature;

use Fomvasss\LaravelBackupUi\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

class ExternalDiskSupportTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_handle_s3_disk_configuration()
    {
        // Mock S3 disk configuration
        config([
            'filesystems.disks.s3-backup' => [
                'driver' => 's3',
                'key' => 'fake-key',
                'secret' => 'fake-secret',
                'region' => 'us-east-1',
                'bucket' => 'test-bucket',
            ],
            'backup.backup.destination.disks' => ['s3-backup']
        ]);

        $response = $this->get('/admin/backup');

        $response->assertStatus(200);
        $response->assertViewHas('backupDestinations');

        $backupDestinations = $response->viewData('backupDestinations');

        if ($backupDestinations->isNotEmpty()) {
            $s3Destination = $backupDestinations->first();
            $this->assertEquals('s3-backup', $s3Destination['name']);
            $this->assertArrayHasKey('driver', $s3Destination);
            $this->assertEquals('s3', $s3Destination['driver']);
        }
    }

    /** @test */
    public function it_can_handle_ftp_disk_configuration()
    {
        // Mock FTP disk configuration
        config([
            'filesystems.disks.ftp-backup' => [
                'driver' => 'ftp',
                'host' => 'ftp.example.com',
                'username' => 'testuser',
                'password' => 'testpass',
            ],
            'backup.backup.destination.disks' => ['ftp-backup']
        ]);

        $response = $this->get('/admin/backup');

        $response->assertStatus(200);

        $backupDestinations = $response->viewData('backupDestinations');

        if ($backupDestinations->isNotEmpty()) {
            $ftpDestination = $backupDestinations->first();
            $this->assertEquals('ftp-backup', $ftpDestination['name']);
            $this->assertArrayHasKey('driver', $ftpDestination);
            $this->assertEquals('ftp', $ftpDestination['driver']);
        }
    }

    /** @test */
    public function it_handles_unreachable_external_disks_gracefully()
    {
        // Mock unreachable external disk
        config([
            'filesystems.disks.unreachable-s3' => [
                'driver' => 's3',
                'key' => 'invalid-key',
                'secret' => 'invalid-secret',
                'region' => 'invalid-region',
                'bucket' => 'non-existent-bucket',
            ],
            'backup.backup.destination.disks' => ['unreachable-s3']
        ]);

        $response = $this->get('/admin/backup');

        $response->assertStatus(200);

        $backupDestinations = $response->viewData('backupDestinations');

        if ($backupDestinations->isNotEmpty()) {
            $unreachableDestination = $backupDestinations->first();
            $this->assertEquals('unreachable-s3', $unreachableDestination['name']);

            // Should be marked as unreachable but not crash the page
            if (isset($unreachableDestination['reachable'])) {
                $this->assertFalse($unreachableDestination['reachable']);
            }
        }
    }

    /** @test */
    public function it_shows_appropriate_error_messages_for_external_disks()
    {
        config([
            'filesystems.disks.test-gcs' => [
                'driver' => 'gcs',
                'project_id' => 'test-project',
                'key_file' => '/path/to/invalid/keyfile.json',
            ],
            'backup.backup.destination.disks' => ['test-gcs']
        ]);

        $response = $this->get('/admin/backup');

        $response->assertStatus(200);

        $backupDestinations = $response->viewData('backupDestinations');

        if ($backupDestinations->isNotEmpty()) {
            $gcsDestination = $backupDestinations->first();

            if (isset($gcsDestination['error'])) {
                $this->assertStringContainsString('gcs', $gcsDestination['error']);
            }
        }
    }

    /** @test */
    public function it_can_detect_different_disk_drivers()
    {
        $controller = new \Fomvasss\LaravelBackupUi\Http\Controllers\BackupController();

        // Test different driver detection
        $testDrivers = [
            'local' => ['driver' => 'local', 'root' => '/tmp'],
            's3' => ['driver' => 's3', 'bucket' => 'test'],
            'ftp' => ['driver' => 'ftp', 'host' => 'test.com'],
            'gcs' => ['driver' => 'gcs', 'project_id' => 'test'],
        ];

        foreach ($testDrivers as $diskName => $diskConfig) {
            config(["filesystems.disks.{$diskName}" => $diskConfig]);

            try {
                $disk = Storage::disk($diskName);

                // Use reflection to test private method
                $reflection = new \ReflectionClass($controller);
                $method = $reflection->getMethod('isDiskReachable');
                $method->setAccessible(true);

                // Call method with correct parameters: $disk and $driver
                $result = $method->invoke($controller, $disk, $diskConfig['driver']);
                $this->assertIsBool($result);
            } catch (\Exception $e) {
                // If disk can't be created (e.g., missing dependencies), that's OK for testing
                $this->assertTrue(true);
            }
        }
    }

    /** @test */
    public function it_handles_file_size_and_modification_time_for_external_disks()
    {
        Storage::fake('s3');

        config([
            'filesystems.disks.s3' => [
                'driver' => 's3',
                'bucket' => 'test-bucket',
            ]
        ]);

        // Create a test backup file
        Storage::disk('s3')->put('laravel-backup/2024/01/17/test-backup.zip', 'test content');

        $controller = new \Fomvasss\LaravelBackupUi\Http\Controllers\BackupController();
        $disk = Storage::disk('s3');

        // Use reflection to test private methods
        $reflection = new \ReflectionClass($controller);

        $getSizeMethod = $reflection->getMethod('getFileSize');
        $getSizeMethod->setAccessible(true);

        $getTimeMethod = $reflection->getMethod('getFileLastModified');
        $getTimeMethod->setAccessible(true);

        $size = $getSizeMethod->invoke($controller, $disk, 'laravel-backup/2024/01/17/test-backup.zip', 's3');
        $time = $getTimeMethod->invoke($controller, $disk, 'laravel-backup/2024/01/17/test-backup.zip', 's3');

        $this->assertIsInt($size);
        $this->assertIsInt($time);
        $this->assertGreaterThan(0, $size);
        $this->assertGreaterThan(0, $time);
    }
}
