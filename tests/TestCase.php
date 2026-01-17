<?php

namespace Fomvasss\LaravelBackupUi\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Fomvasss\LaravelBackupUi\BackupUiServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDatabase();
    }

    protected function getPackageProviders($app)
    {
        return [
            BackupUiServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        // Set up test environment
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Basic backup configuration
        $app['config']->set('backup', [
            'backup' => [
                'name' => 'test-backup',
                'destination' => [
                    'disks' => ['local'],
                ],
                'source' => [
                    'files' => [
                        'include' => [storage_path('app')],
                        'exclude' => [],
                    ],
                    'databases' => ['sqlite'],
                ],
            ],
            'monitor_backups' => [
                [
                    'name' => 'test-backup',
                    'disks' => ['local'],
                    'health_checks' => [],
                ],
            ],
        ]);

        // Backup UI configuration
        $app['config']->set('backup-ui', [
            'route_prefix' => 'admin/backup',
            'middleware' => ['web'],
            'page_title' => 'Test Backup Management',
            'per_page' => 15,
            'allowed_users' => [],
            'auth_callback' => null,
        ]);

        // Filesystem configuration
        $app['config']->set('filesystems.disks.local', [
            'driver' => 'local',
            'root' => storage_path('app'),
        ]);
    }

    protected function setUpDatabase(): void
    {
        // Create users table for authentication tests
        $this->app['db']->connection()->getSchemaBuilder()->create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
    }

    protected function createUser(array $attributes = []): \Illuminate\Foundation\Auth\User
    {
        $user = new class extends \Illuminate\Foundation\Auth\User {
            protected $table = 'users';
            protected $fillable = ['name', 'email', 'password'];
        };

        return $user->create(array_merge([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ], $attributes));
    }
}
