<?php

namespace Fomvasss\LaravelBackupUi\Tests\Unit;

use Fomvasss\LaravelBackupUi\Support\BackupRestorer;
use Fomvasss\LaravelBackupUi\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class BackupRestorerTest extends TestCase
{
    protected BackupRestorer $restorer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restorer = new BackupRestorer();
    }

    /** @test */
    public function it_locates_a_file_directly_when_the_exact_path_exists()
    {
        Storage::fake('local');
        Storage::disk('local')->put('backup.zip', 'fake content');

        $found = $this->restorer->locateFile(Storage::disk('local'), 'backup.zip');

        $this->assertEquals('backup.zip', $found);
    }

    /** @test */
    public function it_locates_a_file_by_basename_when_it_lives_in_a_subdirectory()
    {
        // spatie/laravel-backup stores files under a date-organized subdirectory
        // (e.g. test-backup/2026-07-31-20-17-41.zip), but the UI only knows the basename.
        Storage::fake('local');
        Storage::disk('local')->put('test-backup/2026-07-31-20-17-41.zip', 'fake content');

        $found = $this->restorer->locateFile(Storage::disk('local'), '2026-07-31-20-17-41.zip');

        $this->assertEquals('test-backup/2026-07-31-20-17-41.zip', $found);
    }

    /** @test */
    public function it_returns_null_when_no_file_matches()
    {
        Storage::fake('local');

        $found = $this->restorer->locateFile(Storage::disk('local'), 'does-not-exist.zip');

        $this->assertNull($found);
    }

    /** @test */
    public function it_rejects_unknown_connection()
    {
        $this->expectException(\RuntimeException::class);

        $this->restorer->resolveConnectionConfig('does-not-exist');
    }

    /** @test */
    public function it_rejects_unsupported_driver()
    {
        // Test suite's default connection is sqlite.
        $this->expectException(\RuntimeException::class);

        $this->restorer->resolveConnectionConfig(null);
    }

    /** @test */
    public function it_accepts_a_configured_mysql_connection()
    {
        config(['database.connections.mysql_test' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'test',
            'username' => 'root',
            'password' => '',
        ]]);

        [$name, $config] = $this->restorer->resolveConnectionConfig('mysql_test');

        $this->assertEquals('mysql_test', $name);
        $this->assertEquals('mysql', $config['driver']);
    }

    /** @test */
    public function it_accepts_a_configured_pgsql_connection()
    {
        config(['database.connections.pgsql_test' => [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 5432,
            'database' => 'test',
            'username' => 'postgres',
            'password' => '',
        ]]);

        [$name, $config] = $this->restorer->resolveConnectionConfig('pgsql_test');

        $this->assertEquals('pgsql_test', $name);
        $this->assertEquals('pgsql', $config['driver']);
    }

    /** @test */
    public function it_rejects_runimport_for_an_unsupported_driver()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sqlsrv');

        $this->restorer->runImport('/does/not/matter.sql', ['driver' => 'sqlsrv']);
    }

    /** @test */
    public function it_extracts_the_single_dump_file_from_a_zip()
    {
        $zip = $this->makeZip(['db-dumps/mysql.sql' => 'CREATE TABLE foo (id INT);']);
        $tempDir = $this->makeTempDir();

        $candidates = $this->restorer->extractCandidates($zip, $tempDir);

        $this->assertCount(1, $candidates);
        $this->assertStringEndsWith('mysql.sql', $candidates[0]);
        $this->assertEquals($candidates[0], $this->restorer->resolveDumpFile($candidates, 'mysql'));
    }

    /** @test */
    public function it_matches_dump_by_connection_name_when_multiple_exist()
    {
        $zip = $this->makeZip([
            'db-dumps/mysql.sql' => 'CREATE TABLE foo (id INT);',
            'db-dumps/pgsql.sql' => 'CREATE TABLE bar (id INT);',
        ]);
        $tempDir = $this->makeTempDir();

        $candidates = $this->restorer->extractCandidates($zip, $tempDir);
        $resolved = $this->restorer->resolveDumpFile($candidates, 'pgsql');

        $this->assertNotNull($resolved);
        $this->assertStringEndsWith('pgsql.sql', $resolved);
    }

    /** @test */
    public function it_returns_null_when_multiple_dumps_dont_match_the_connection()
    {
        $zip = $this->makeZip([
            'db-dumps/mysql.sql' => 'CREATE TABLE foo (id INT);',
            'db-dumps/pgsql.sql' => 'CREATE TABLE bar (id INT);',
        ]);
        $tempDir = $this->makeTempDir();

        $candidates = $this->restorer->extractCandidates($zip, $tempDir);

        $this->assertNull($this->restorer->resolveDumpFile($candidates, 'sqlsrv'));
    }

    /** @test */
    public function it_returns_empty_candidates_when_no_dumps_in_zip()
    {
        $zip = $this->makeZip(['storage/app/public/.gitignore' => '']);
        $tempDir = $this->makeTempDir();

        $this->assertEmpty($this->restorer->extractCandidates($zip, $tempDir));
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

    protected function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/backup-ui-restore-test-' . uniqid();
        mkdir($dir, 0755, true);

        return $dir;
    }
}
