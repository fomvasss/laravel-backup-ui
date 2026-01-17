<?php

namespace Fomvasss\LaravelBackupUi\Tests\Unit;

use Fomvasss\LaravelBackupUi\Tests\TestCase;
use Fomvasss\LaravelBackupUi\Http\Middleware\BackupUiAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BackupUiAuthMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new BackupUiAuth();
    }

    /** @test */
    public function it_allows_access_for_authenticated_users_with_no_restrictions()
    {
        $this->app['config']->set('backup-ui.allowed_users', []);
        $this->app['config']->set('backup-ui.auth_callback', null);

        $user = $this->createUser();
        $this->actingAs($user);

        $request = Request::create('/admin/backup');
        $response = $this->middleware->handle($request, function ($req) {
            return new Response('OK');
        });

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', $response->getContent());
    }

    /** @test */
    public function it_redirects_unauthenticated_users_to_login()
    {
        $request = Request::create('/admin/backup');

        $response = $this->middleware->handle($request, function ($req) {
            return new Response('OK');
        });

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertStringContainsString('login', $response->headers->get('Location'));
    }

    /** @test */
    public function it_blocks_unauthorized_users_with_allowed_users_list()
    {
        $this->app['config']->set('backup-ui.allowed_users', ['admin@example.com']);

        $unauthorizedUser = $this->createUser(['email' => 'user@example.com']);
        $this->actingAs($unauthorizedUser);

        $request = Request::create('/admin/backup');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('You are not authorized to access the backup interface');

        $this->middleware->handle($request, function ($req) {
            return new Response('OK');
        });
    }

    /** @test */
    public function it_allows_authorized_users_from_allowed_users_list()
    {
        $this->app['config']->set('backup-ui.allowed_users', ['admin@example.com']);

        $authorizedUser = $this->createUser(['email' => 'admin@example.com']);
        $this->actingAs($authorizedUser);

        $request = Request::create('/admin/backup');
        $response = $this->middleware->handle($request, function ($req) {
            return new Response('OK');
        });

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', $response->getContent());
    }

    /** @test */
    public function it_uses_custom_auth_callback_when_provided()
    {
        $this->app['config']->set('backup-ui.auth_callback', function () {
            return auth()->check() && auth()->user()->email === 'special@example.com';
        });

        // Test unauthorized user
        $unauthorizedUser = $this->createUser(['email' => 'user@example.com']);
        $this->actingAs($unauthorizedUser);

        $request = Request::create('/admin/backup');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Unauthorized access to backup interface');

        $this->middleware->handle($request, function ($req) {
            return new Response('OK');
        });
    }

    /** @test */
    public function it_allows_access_with_successful_custom_auth_callback()
    {
        $this->app['config']->set('backup-ui.auth_callback', function () {
            return auth()->check() && auth()->user()->email === 'special@example.com';
        });

        $specialUser = $this->createUser(['email' => 'special@example.com']);
        $this->actingAs($specialUser);

        $request = Request::create('/admin/backup');
        $response = $this->middleware->handle($request, function ($req) {
            return new Response('OK');
        });

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', $response->getContent());
    }

    /** @test */
    public function it_handles_null_auth_callback_gracefully()
    {
        $this->app['config']->set('backup-ui.auth_callback', null);
        $this->app['config']->set('backup-ui.allowed_users', []);

        $user = $this->createUser();
        $this->actingAs($user);

        $request = Request::create('/admin/backup');
        $response = $this->middleware->handle($request, function ($req) {
            return new Response('OK');
        });

        $this->assertEquals(200, $response->getStatusCode());
    }

    /** @test */
    public function it_handles_invalid_auth_callback_gracefully()
    {
        $this->app['config']->set('backup-ui.auth_callback', 'not-a-callable');

        $user = $this->createUser();
        $this->actingAs($user);

        $request = Request::create('/admin/backup');
        $response = $this->middleware->handle($request, function ($req) {
            return new Response('OK');
        });

        // Should fall back to normal auth check
        $this->assertEquals(200, $response->getStatusCode());
    }

    /** @test */
    public function it_prioritizes_auth_callback_over_allowed_users()
    {
        $this->app['config']->set('backup-ui.auth_callback', function () {
            return true; // Always allow
        });
        $this->app['config']->set('backup-ui.allowed_users', ['admin@example.com']);

        $user = $this->createUser(['email' => 'user@example.com']);
        $this->actingAs($user);

        $request = Request::create('/admin/backup');
        $response = $this->middleware->handle($request, function ($req) {
            return new Response('OK');
        });

        // Should pass because auth_callback returns true, ignoring allowed_users
        $this->assertEquals(200, $response->getStatusCode());
    }
}
