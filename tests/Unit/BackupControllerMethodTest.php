<?php

namespace Fomvasss\LaravelBackupUi\Tests\Unit;

use Fomvasss\LaravelBackupUi\Tests\TestCase;
use Fomvasss\LaravelBackupUi\Http\Controllers\BackupController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

class BackupControllerMethodTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_check_disk_reachability_without_errors()
    {
        Storage::fake('local');

        $controller = new BackupController();
        $disk = Storage::disk('local');

        // Use reflection to test private method
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('isDiskReachable');
        $method->setAccessible(true);

        // Test with local disk
        $result = $method->invoke($controller, $disk, 'local');
        $this->assertIsBool($result);

        // Test with different drivers
        $drivers = ['s3', 'ftp', 'gcs', 'unknown'];
        foreach ($drivers as $driver) {
            $result = $method->invoke($controller, $disk, $driver);
            $this->assertIsBool($result);
        }
    }

    /** @test */
    public function it_can_handle_file_size_operations_safely()
    {
        Storage::fake('local');
        Storage::disk('local')->put('test-file.txt', 'test content');

        $controller = new BackupController();
        $disk = Storage::disk('local');

        // Use reflection to test private method
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('getFileSize');
        $method->setAccessible(true);

        $size = $method->invoke($controller, $disk, 'test-file.txt', 'local');
        $this->assertIsInt($size);
        $this->assertGreaterThan(0, $size);

        // Test with non-existent file
        $size = $method->invoke($controller, $disk, 'non-existent.txt', 'local');
        $this->assertEquals(0, $size); // Should return 0 for non-existent files
    }

    /** @test */
    public function it_can_handle_last_modified_operations_safely()
    {
        Storage::fake('local');
        Storage::disk('local')->put('test-file.txt', 'test content');

        $controller = new BackupController();
        $disk = Storage::disk('local');

        // Use reflection to test private method
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('getFileLastModified');
        $method->setAccessible(true);

        $time = $method->invoke($controller, $disk, 'test-file.txt', 'local');
        $this->assertIsInt($time);
        $this->assertGreaterThan(0, $time);

        // Test with non-existent file
        $time = $method->invoke($controller, $disk, 'non-existent.txt', 'local');
        $this->assertIsInt($time); // Should return current time as fallback
    }
}
