<?php

namespace Fomvasss\LaravelBackupUi\Tests\Feature;

use Fomvasss\LaravelBackupUi\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Artisan;

class BackupControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock storage disk
        Storage::fake('local');
    }

    /** @test */
    public function it_can_display_backup_index_page()
    {
        $response = $this->get('/admin/backup');

        $response->assertStatus(200);
        $response->assertViewIs('backup-ui::index');
        $response->assertViewHas('backupDestinations');
        $response->assertViewHas('pageTitle', 'Test Backup Management');
    }

    /** @test */
    public function it_requires_authentication_by_default()
    {
        $this->app['config']->set('backup-ui.middleware', ['web', 'auth']);

        $response = $this->get('/admin/backup');

        $response->assertRedirect('/login');
    }

    /** @test */
    public function authenticated_users_can_access_backup_page()
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $response = $this->get('/admin/backup');

        $response->assertStatus(200);
    }

    /** @test */
    public function it_can_create_full_backup()
    {
        Artisan::shouldReceive('call')
            ->once()
            ->with('backup:run')
            ->andReturn(0);

        Artisan::shouldReceive('output')
            ->once()
            ->andReturn('Backup completed successfully');

        $response = $this->post('/admin/backup/create');

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Backup created successfully!');
    }

    /** @test */
    public function it_can_create_database_only_backup()
    {
        Artisan::shouldReceive('call')
            ->once()
            ->with('backup:run', ['--only-db' => true])
            ->andReturn(0);

        Artisan::shouldReceive('output')
            ->once()
            ->andReturn('Database backup completed successfully');

        $response = $this->post('/admin/backup/create', ['option' => 'only-db']);

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Backup created successfully!');
    }

    /** @test */
    public function it_can_create_files_only_backup()
    {
        Artisan::shouldReceive('call')
            ->once()
            ->with('backup:run', ['--only-files' => true])
            ->andReturn(0);

        Artisan::shouldReceive('output')
            ->once()
            ->andReturn('Files backup completed successfully');

        $response = $this->post('/admin/backup/create', ['option' => 'only-files']);

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Backup created successfully!');
    }

    /** @test */
    public function it_handles_backup_creation_failure()
    {
        Artisan::shouldReceive('call')
            ->once()
            ->with('backup:run')
            ->andThrow(new \Exception('Backup failed'));

        $response = $this->post('/admin/backup/create');

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Backup failed: Backup failed');
    }

    /** @test */
    public function it_validates_backup_creation_options()
    {
        $response = $this->post('/admin/backup/create', ['option' => 'invalid-option']);

        $response->assertSessionHasErrors('option');
    }

    /** @test */
    public function it_can_download_backup_file()
    {
        Storage::disk('local')->put('backup-file.zip', 'fake backup content');

        $response = $this->get('/admin/backup/download/local/backup-file.zip');

        $response->assertStatus(200);
        $response->assertHeader('content-disposition', 'attachment; filename="backup-file.zip"');
    }

    /** @test */
    public function it_returns_404_for_non_existent_backup_file()
    {
        $response = $this->get('/admin/backup/download/local/non-existent-file.zip');

        $response->assertStatus(404);
    }

    /** @test */
    public function it_can_delete_backup_file()
    {
        Storage::disk('local')->put('backup-to-delete.zip', 'content to be deleted');

        $response = $this->delete('/admin/backup/delete/local/backup-to-delete.zip');

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Backup deleted successfully!');
        Storage::disk('local')->assertMissing('backup-to-delete.zip');
    }

    /** @test */
    public function it_handles_deletion_of_non_existent_file()
    {
        $response = $this->delete('/admin/backup/delete/local/non-existent-file.zip');

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Backup file not found');
    }

    /** @test */
    public function it_can_clean_old_backups()
    {
        Artisan::shouldReceive('call')
            ->once()
            ->with('backup:clean')
            ->andReturn(0);

        $response = $this->post('/admin/backup/clean');

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Old backups cleaned successfully!');
    }

    /** @test */
    public function it_handles_clean_command_failure()
    {
        Artisan::shouldReceive('call')
            ->once()
            ->with('backup:clean')
            ->andThrow(new \Exception('Clean failed'));

        $response = $this->post('/admin/backup/clean');

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Clean failed: Clean failed');
    }

    /** @test */
    public function it_restricts_access_to_allowed_users_only()
    {
        $this->app['config']->set('backup-ui.allowed_users', ['allowed@example.com']);

        $unauthorizedUser = $this->createUser(['email' => 'unauthorized@example.com']);
        $authorizedUser = $this->createUser(['email' => 'allowed@example.com']);

        // Test unauthorized user
        $this->actingAs($unauthorizedUser);
        $response = $this->get('/admin/backup');
        $response->assertStatus(403);

        // Test authorized user
        $this->actingAs($authorizedUser);
        $response = $this->get('/admin/backup');
        $response->assertStatus(200);
    }

    /** @test */
    public function it_handles_custom_authentication_callback()
    {
        $this->app['config']->set('backup-ui.auth_callback', function () {
            return auth()->check() && auth()->user()->email === 'admin@example.com';
        });

        $regularUser = $this->createUser(['email' => 'user@example.com']);
        $adminUser = $this->createUser(['email' => 'admin@example.com']);

        // Test regular user
        $this->actingAs($regularUser);
        $response = $this->get('/admin/backup');
        $response->assertStatus(403);

        // Test admin user
        $this->actingAs($adminUser);
        $response = $this->get('/admin/backup');
        $response->assertStatus(200);
    }

    /** @test */
    public function it_handles_special_characters_in_backup_filenames()
    {
        $filename = 'backup with spaces & special chars.zip';
        Storage::disk('local')->put($filename, 'backup content');

        $encodedFilename = urlencode($filename);
        $response = $this->get("/admin/backup/download/local/{$encodedFilename}");

        $response->assertStatus(200);
    }
}
