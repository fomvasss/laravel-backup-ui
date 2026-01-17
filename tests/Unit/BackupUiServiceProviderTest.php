<?php

namespace Fomvasss\LaravelBackupUi\Tests\Unit;

use Fomvasss\LaravelBackupUi\Tests\TestCase;
use Fomvasss\LaravelBackupUi\BackupUiServiceProvider;
use Illuminate\Support\Facades\Route;

class BackupUiServiceProviderTest extends TestCase
{
    /** @test */
    public function it_registers_the_service_provider()
    {
        $this->assertInstanceOf(BackupUiServiceProvider::class, $this->app->getProvider(BackupUiServiceProvider::class));
    }

    /** @test */
    public function it_merges_configuration()
    {
        $this->assertNotNull(config('backup-ui'));
        $this->assertEquals('admin/backup', config('backup-ui.route_prefix'));
        $this->assertEquals('Test Backup Management', config('backup-ui.page_title'));
    }

    /** @test */
    public function it_loads_views()
    {
        $this->assertTrue($this->app['view']->exists('backup-ui::index'));
        $this->assertTrue($this->app['view']->exists('backup-ui::layout'));
    }

    /** @test */
    public function it_registers_routes()
    {
        $routes = collect(Route::getRoutes())->map(function ($route) {
            return [
                'uri' => $route->uri(),
                'name' => $route->getName(),
                'methods' => $route->methods(),
            ];
        });

        // Check that backup UI routes are registered
        $this->assertTrue($routes->contains(function ($route) {
            return $route['name'] === 'backup-ui.index' && $route['uri'] === 'admin/backup';
        }));

        $this->assertTrue($routes->contains(function ($route) {
            return $route['name'] === 'backup-ui.create' && $route['uri'] === 'admin/backup/create';
        }));

        $this->assertTrue($routes->contains(function ($route) {
            return $route['name'] === 'backup-ui.download';
        }));

        $this->assertTrue($routes->contains(function ($route) {
            return $route['name'] === 'backup-ui.delete';
        }));

        $this->assertTrue($routes->contains(function ($route) {
            return $route['name'] === 'backup-ui.clean' && $route['uri'] === 'admin/backup/clean';
        }));
    }

    /** @test */
    public function it_registers_routes_with_custom_prefix()
    {
        // Create a new app instance with custom configuration
        $app = $this->createApplication();
        $app['config']->set('backup-ui.route_prefix', 'custom/backup-manager');

        $provider = new BackupUiServiceProvider($app);
        $provider->register();
        $provider->boot();

        $routes = collect(Route::getRoutes())->map(function ($route) {
            return $route->uri();
        });

        $this->assertTrue($routes->contains('custom/backup-manager'));
    }

    /** @test */
    public function it_applies_configured_middleware()
    {
        $routes = collect(Route::getRoutes())->filter(function ($route) {
            return str_starts_with($route->getName() ?? '', 'backup-ui.');
        });

        foreach ($routes as $route) {
            $middleware = $route->middleware();
            $this->assertContains('web', $middleware);
        }
    }

    /** @test */
    public function it_can_publish_configuration()
    {
        $this->artisan('vendor:publish', [
            '--tag' => 'backup-ui-config',
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertFileExists(config_path('backup-ui.php'));
    }

    /** @test */
    public function it_can_publish_views()
    {
        $this->artisan('vendor:publish', [
            '--tag' => 'backup-ui-views',
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertFileExists(resource_path('views/vendor/backup-ui/index.blade.php'));
        $this->assertFileExists(resource_path('views/vendor/backup-ui/layout.blade.php'));
    }
}
