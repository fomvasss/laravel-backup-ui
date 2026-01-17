<?php

namespace Fomvasss\LaravelBackupUi\Tests\Feature;

use Fomvasss\LaravelBackupUi\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

class ViewRenderingTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_renders_layout_view_correctly()
    {
        $view = $this->app['view']->make('backup-ui::layout', [
            'pageTitle' => 'Test Title'
        ]);

        $content = $view->render();

        $this->assertStringContainsString('Test Title', $content);
        $this->assertStringContainsString('bootstrap', $content);
        $this->assertStringContainsString('font-awesome', $content);
        $this->assertStringContainsString('csrf-token', $content);
    }

    /** @test */
    public function it_renders_index_view_with_empty_destinations()
    {
        $view = $this->app['view']->make('backup-ui::index', [
            'backupDestinations' => collect(),
            'pageTitle' => 'Backup Management'
        ]);

        $content = $view->render();

        $this->assertStringContainsString('Backup Management', $content);
        $this->assertStringContainsString('No backup destinations configured', $content);
        $this->assertStringContainsString('Create Backup', $content);
        $this->assertStringContainsString('Clean Old', $content);
    }

    /** @test */
    public function it_renders_index_view_with_backup_destinations()
    {
        $backupDestinations = collect([
            [
                'name' => 'local',
                'reachable' => true,
                'healthy' => true,
                'amount' => 5,
                'newest' => null,
                'usedStorage' => '100 MB',
                'backups' => [
                    [
                        'path' => 'backup1.zip',
                        'last_modified' => now()->timestamp,
                        'human_readable_size' => '50 MB'
                    ],
                    [
                        'path' => 'backup2.zip',
                        'last_modified' => now()->subDay()->timestamp,
                        'human_readable_size' => '50 MB'
                    ]
                ]
            ]
        ]);

        $view = $this->app['view']->make('backup-ui::index', [
            'backupDestinations' => $backupDestinations,
            'pageTitle' => 'Backup Management'
        ]);

        $content = $view->render();

        $this->assertStringContainsString('local', $content);
        $this->assertStringContainsString('Reachable', $content);
        $this->assertStringContainsString('Healthy', $content);
        $this->assertStringContainsString('100 MB', $content);
        $this->assertStringContainsString('backup1.zip', $content);
        $this->assertStringContainsString('backup2.zip', $content);
        $this->assertStringContainsString('50 MB', $content);
    }

    /** @test */
    public function it_renders_error_destinations_correctly()
    {
        $backupDestinations = collect([
            [
                'name' => 'broken-disk',
                'reachable' => false,
                'healthy' => false,
                'error' => 'Disk not accessible'
            ]
        ]);

        $view = $this->app['view']->make('backup-ui::index', [
            'backupDestinations' => $backupDestinations,
            'pageTitle' => 'Backup Management'
        ]);

        $content = $view->render();

        $this->assertStringContainsString('broken-disk', $content);
        $this->assertStringContainsString('Unreachable', $content);
        $this->assertStringContainsString('Unhealthy', $content);
        $this->assertStringContainsString('Disk not accessible', $content);
        $this->assertStringContainsString('alert-danger', $content);
    }

    /** @test */
    public function it_includes_csrf_tokens_in_forms()
    {
        $view = $this->app['view']->make('backup-ui::index', [
            'backupDestinations' => collect(),
            'pageTitle' => 'Test'
        ]);

        $content = $view->render();

        // Check that CSRF tokens are present in forms
        $this->assertStringContainsString('@csrf', $content);
        $this->assertStringContainsString('csrf_token', $content);
    }

    /** @test */
    public function it_includes_download_and_delete_buttons_for_backups()
    {
        $backupDestinations = collect([
            [
                'name' => 'local',
                'reachable' => true,
                'healthy' => true,
                'amount' => 1,
                'newest' => null,
                'usedStorage' => '50 MB',
                'backups' => [
                    [
                        'path' => 'test-backup.zip',
                        'last_modified' => now()->timestamp,
                        'human_readable_size' => '50 MB'
                    ]
                ]
            ]
        ]);

        $view = $this->app['view']->make('backup-ui::index', [
            'backupDestinations' => $backupDestinations,
            'pageTitle' => 'Test'
        ]);

        $content = $view->render();

        $this->assertStringContainsString('fa-download', $content);
        $this->assertStringContainsString('fa-trash', $content);
        $this->assertStringContainsString('backup-ui.download', $content);
        $this->assertStringContainsString('backup-ui.delete', $content);
        $this->assertStringContainsString('test-backup.zip', $content);
    }

    /** @test */
    public function it_shows_javascript_functionality()
    {
        $view = $this->app['view']->make('backup-ui::index', [
            'backupDestinations' => collect(),
            'pageTitle' => 'Test'
        ]);

        $content = $view->render();

        $this->assertStringContainsString('createBackup', $content);
        $this->assertStringContainsString('delete-backup', $content);
        $this->assertStringContainsString('loadingModal', $content);
        $this->assertStringContainsString('bootstrap.Modal', $content);
    }

    /** @test */
    public function it_handles_special_characters_in_filenames()
    {
        $backupDestinations = collect([
            [
                'name' => 'local',
                'reachable' => true,
                'healthy' => true,
                'amount' => 1,
                'newest' => null,
                'usedStorage' => '50 MB',
                'backups' => [
                    [
                        'path' => 'backup with spaces & symbols [2024].zip',
                        'last_modified' => now()->timestamp,
                        'human_readable_size' => '50 MB'
                    ]
                ]
            ]
        ]);

        $view = $this->app['view']->make('backup-ui::index', [
            'backupDestinations' => $backupDestinations,
            'pageTitle' => 'Test'
        ]);

        $content = $view->render();

        // Should contain URL-encoded version for links
        $this->assertStringContainsString(urlencode('backup with spaces & symbols [2024].zip'), $content);
        // Should display readable filename in the table
        $this->assertStringContainsString('backup with spaces &amp; symbols [2024].zip', $content);
    }

    /** @test */
    public function it_displays_responsive_design_elements()
    {
        $view = $this->app['view']->make('backup-ui::layout', [
            'pageTitle' => 'Test'
        ]);

        $content = $view->render();

        $this->assertStringContainsString('viewport', $content);
        $this->assertStringContainsString('container-fluid', $content);
        $this->assertStringContainsString('responsive', $content);
    }

    /** @test */
    public function it_includes_loading_states_and_feedback()
    {
        $view = $this->app['view']->make('backup-ui::index', [
            'backupDestinations' => collect(),
            'pageTitle' => 'Test'
        ]);

        $content = $view->render();

        $this->assertStringContainsString('spinner-border', $content);
        $this->assertStringContainsString('Creating backup', $content);
        $this->assertStringContainsString('may take several minutes', $content);
    }
}
