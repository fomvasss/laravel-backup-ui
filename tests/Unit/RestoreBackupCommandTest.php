<?php

namespace Fomvasss\LaravelBackupUi\Tests\Unit;

use Fomvasss\LaravelBackupUi\Tests\TestCase;
use ZipArchive;

class RestoreBackupCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->app['env'] = 'testing';

        parent::tearDown();
    }

    /** @test */
    public function it_refuses_to_run_outside_local_environment()
    {
        $this->app['env'] = 'production';

        $this->artisan('backup-ui:restore', ['path' => '/does/not/matter.zip'])
            ->assertExitCode(1);
    }

    /** @test */
    public function it_fails_when_file_does_not_exist()
    {
        $this->app['env'] = 'local';

        $this->artisan('backup-ui:restore', ['path' => '/definitely/not/a/real/file.zip'])
            ->assertExitCode(1);
    }

    /** @test */
    public function it_fails_for_unknown_connection()
    {
        $this->app['env'] = 'local';

        $zip = $this->makeZip(['db-dumps/sqlite.sql' => 'SELECT 1;']);

        $this->artisan('backup-ui:restore', ['path' => $zip, '--connection' => 'does-not-exist'])
            ->assertExitCode(1);
    }

    /** @test */
    public function it_fails_for_unsupported_driver()
    {
        $this->app['env'] = 'local';

        // The test suite's default connection is sqlite — restore only supports mysql/mariadb/pgsql.
        $zip = $this->makeZip(['db-dumps/sqlite.sql' => 'SELECT 1;']);

        $this->artisan('backup-ui:restore', ['path' => $zip])
            ->assertExitCode(1);
    }

    protected function makeZip(array $files): string
    {
        $zipPath = sys_get_temp_dir() . '/backup-ui-restore-fixture-' . uniqid() . '.zip';

        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return $zipPath;
    }
}
