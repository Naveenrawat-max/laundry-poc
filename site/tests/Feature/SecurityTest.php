<?php
/** The security gate: every route needs a login and the right role, and hostile input stays data. */
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\ApiTestCase;

final class SecurityTest extends ApiTestCase
{
    /**
     * Every API route as "METHOD /path" => the roles that may call it, or null when it needs no login.
     * Read from the router itself, so a new route is covered the day it is added.
     */
    private function apiRoutes(): array
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            if (!str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            $middleware = $route->gatherMiddleware();
            $roles = null;
            if (in_array('auth:sanctum', $middleware, true)) {
                $roles = User::ROLES;
                foreach ($middleware as $name) {
                    if (str_starts_with($name, 'role:')) {
                        $roles = explode(',', substr($name, 5));
                    }
                }
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $routes["$method " . substr($route->uri(), strlen('api/v1'))] = $roles;
            }
        }
        return $routes;
    }

    public function test_every_route_needs_a_login_and_the_right_role(): void
    {
        $guarded = array_filter($this->apiRoutes(), fn (?array $roles) => $roles !== null);
        $this->assertCount(47, $guarded);

        foreach ($guarded as $name => $roles) {
            [$method, $path] = explode(' ', preg_replace('/\{\w+\}/', '1', $name));
            $this->logout();
            [$status, $body] = $this->api($method, $path);
            $this->assertSame([401, 'not_logged_in'], [$status, $body['error']['code'] ?? null], "$name without a login");
            foreach (array_diff(User::ROLES, $roles) as $role) {
                $this->loginAs($role);
                [$status, $body] = $this->api($method, $path);
                $this->assertSame([403, 'forbidden'], [$status, $body['error']['code'] ?? null], "$name as $role");
            }
        }
    }

    public function test_only_the_two_logins_are_open_without_a_login(): void
    {
        $open = array_keys(array_filter($this->apiRoutes(), fn (?array $roles) => $roles === null));
        $this->assertSame(['POST /auth/login', 'POST /auth/token'], $open);
    }

    public function test_every_answer_carries_the_security_headers(): void
    {
        $headers = fn () => $this->json('GET', '/api/v1/auth/me')->headers;   // a 401: errors carry the headers too
        $this->assertSame('nosniff', $headers()->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', $headers()->get('Cache-Control'));
        $this->assertNull($headers()->get('Strict-Transport-Security'));
        // An unknown path is answered before any route is chosen. It carries the headers all the same.
        $this->assertSame('nosniff', $this->json('GET', '/api/v1/no-such-path')->headers->get('X-Content-Type-Options'));

        $this->app['env'] = 'production';   // what APP_ENV=production in .env does
        $this->assertSame('max-age=31536000', $headers()->get('Strict-Transport-Security'));
    }

    public function test_sql_in_a_login_or_a_search_stays_text(): void
    {
        $this->assertError(401, 'bad_login',
            $this->api('POST', '/auth/login', ['username' => "' OR '1'='1", 'password' => "' OR '1'='1"]));
        $this->loginAs('office');
        $this->assertSame(0, $this->ok($this->api('GET', '/items?q=' . urlencode("' OR 1=1 -- ")))['total']);
        $this->assertSame(40, (int) DB::scalar('SELECT COUNT(*) FROM items'));
    }

    public function test_a_tampered_proof_path_cannot_leave_the_proofs_folder(): void
    {
        [$task] = $this->activateContract(1);
        DB::update("UPDATE tasks SET status = 'done', completed_at = UTC_TIMESTAMP(), photo_path = '../../../.env', signature_path = 'x.png' WHERE id = ?", [$task]);
        $this->loginAs('office');
        $this->assertError(404, 'no_proof', $this->api('GET', "/tasks/$task/photo"));
    }

    public function test_a_password_is_stored_only_as_a_hash(): void
    {
        $this->loginAs('office');
        $out = $this->ok($this->api('POST', '/users',
            ['name' => 'Dana', 'username' => 'dana', 'password' => 'long-password-1', 'role' => 'driver']));
        $this->assertStringNotContainsString('long-password-1', json_encode($out));
        $hash = DB::scalar("SELECT password_hash FROM users WHERE username = 'dana'");
        $this->assertStringStartsWith('$2y$', $hash);
        $this->assertTrue(Hash::check('long-password-1', $hash));
    }
}
