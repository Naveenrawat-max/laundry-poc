<?php
/** Login, logout, the session, the login throttle, and the one error shape for unknown paths and server errors. */
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\ApiTestCase;

final class AuthTest extends ApiTestCase
{
    private function login(string $username, string $password = self::PASSWORD): array
    {
        return $this->api('POST', '/auth/login', ['username' => $username, 'password' => $password]);
    }

    public function test_login_returns_the_user_and_a_csrf_token(): void
    {
        $out = $this->ok($this->login('Driver'));   // usernames are not case sensitive
        $this->assertSame(['id', 'name', 'username', 'role', 'is_active'], array_keys($out['user']));
        $this->assertSame(['driver', true], [$out['user']['role'], $out['user']['is_active']]);
        $this->assertAuthenticatedAs(User::find($this->users['driver']), 'web');
        $this->assertSame(csrf_token(), $out['csrf_token']);
        $this->assertNotSame('', $out['csrf_token']);
    }

    public function test_wrong_password_and_unknown_user_get_the_same_answer(): void
    {
        $this->assertError(401, 'bad_login', $this->login('driver', 'wrong-password'));
        $this->assertError(401, 'bad_login', $this->login('nobody', 'wrong-password'));
        $this->assertGuest('web');
    }

    public function test_a_switched_off_user_cannot_log_in(): void
    {
        DB::update('UPDATE users SET is_active = 0 WHERE id = ?', [$this->users['driver']]);
        $this->assertError(401, 'bad_login', $this->login('driver'));
        $this->assertGuest('web');
    }

    public function test_a_user_switched_off_while_logged_in_is_logged_out_at_his_next_call(): void
    {
        $this->ok($this->login('driver'));
        $this->ok($this->api('GET', '/auth/me'));
        DB::update('UPDATE users SET is_active = 0 WHERE id = ?', [$this->users['driver']]);
        $this->assertError(401, 'not_logged_in', $this->api('GET', '/auth/me'));
        $this->assertGuest('web');
    }

    public function test_five_failed_tries_block_the_sixth_even_with_the_right_password(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertError(401, 'bad_login', $this->login('driver', 'wrong-password'));
        }
        $this->assertError(429, 'too_many_tries', $this->login('driver'));
        $this->assertError(429, 'too_many_tries', $this->login('DRIVER'));   // the same user in capitals
        $this->ok($this->login('washing'));   // another username is not affected
    }

    public function test_a_good_login_clears_the_failed_tries(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->login('driver', 'wrong-password');
        }
        $this->ok($this->login('driver'));
        $this->logout();
        for ($i = 0; $i < 4; $i++) {
            $this->assertError(401, 'bad_login', $this->login('driver', 'wrong-password'));
        }
    }

    public function test_login_needs_username_and_password(): void
    {
        $this->assertError(422, 'invalid_input', $this->api('POST', '/auth/login', ['username' => 'driver']));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/auth/login', ['password' => 'x']));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/auth/login', ['username' => ['a'], 'password' => 'x']));
    }

    public function test_me_needs_a_login(): void
    {
        $this->assertError(401, 'not_logged_in', $this->api('GET', '/auth/me'));
        $this->loginAs('office');
        $out = $this->ok($this->api('GET', '/auth/me'));
        $this->assertSame('office', $out['user']['role']);
        $this->assertSame(csrf_token(), $out['csrf_token']);
    }

    public function test_logout_ends_the_session(): void
    {
        $this->ok($this->login('office'));
        $this->assertSame(['ok' => true], $this->ok($this->api('POST', '/auth/logout')));
        $this->assertGuest('web');
        $this->assertError(401, 'not_logged_in', $this->api('GET', '/auth/me'));
    }

    public function test_unknown_path_is_404_and_wrong_method_is_405(): void
    {
        $this->assertError(404, 'not_found', $this->api('GET', '/nothing-here'));
        $this->assertError(405, 'method_not_allowed', $this->api('DELETE', '/auth/me'));
    }

    public function test_a_server_error_hides_its_details_unless_debug_is_on(): void
    {
        Route::get('/api/v1/boom', fn () => throw new RuntimeException('secret detail'));

        config(['app.debug' => false]);
        [$status, $body] = $this->api('GET', '/boom');
        $this->assertSame([500, 'server_error', 'Something went wrong.'],
            [$status, $body['error']['code'], $body['error']['message']]);

        config(['app.debug' => true]);
        $this->assertSame('secret detail', $this->api('GET', '/boom')[1]['error']['message']);
    }
}
