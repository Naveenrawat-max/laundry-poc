<?php
/** Login, logout, the session, the token login of a handheld, the login throttle, and the one error shape for unknown paths and server errors. */
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
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

    /**
     * A call as a handheld makes it: no cookie, and the token in the header when it has one.
     * $origin is set when the handheld runs inside the web app's page, as the simulated ones do.
     */
    private function handheld(string $method, string $path, ?string $token, array $body = [], ?string $origin = null): TestResponse
    {
        Auth::forgetGuards();
        $this->flushSession();
        $this->flushHeaders();   // the token of an earlier call must not ride along
        return $this->json($method, '/api/v1' . $path, $body, array_filter(
            ['Accept' => 'application/json', 'Authorization' => $token === null ? null : "Bearer $token", 'Origin' => $origin]));
    }

    /** Logs a handheld in and returns its token. */
    private function token(string $username): string
    {
        return $this->handheld('POST', '/auth/token', null, ['username' => $username, 'password' => self::PASSWORD])
            ->assertOk()->json('token');
    }

    public function test_a_handheld_logs_in_for_a_token_and_calls_with_it(): void
    {
        $out = $this->handheld('POST', '/auth/token', null, ['username' => 'Driver', 'password' => self::PASSWORD])->assertOk();
        $this->assertSame(['user', 'token'], array_keys($out->json()));
        $this->assertSame(['id', 'name', 'username', 'role', 'is_active'], array_keys($out->json('user')));
        $this->assertSame('driver', $out->json('user.role'));

        $this->assertSame('driver', $this->handheld('GET', '/auth/me', $out->json('token'))->assertOk()->json('user.role'));
        $this->handheld('GET', '/sim/items', $out->json('token'))->assertOk();
        $this->handheld('GET', '/users', $out->json('token'))->assertStatus(403);   // the role still decides
        $this->handheld('GET', '/auth/me', null)->assertStatus(401);
        $this->handheld('GET', '/auth/me', 'not-a-token')->assertStatus(401);
    }

    public function test_a_token_login_is_refused_like_a_web_login(): void
    {
        $try = fn (array $body) => $this->handheld('POST', '/auth/token', null, $body);
        $this->assertSame('invalid_input', $try(['username' => 'driver'])->assertStatus(422)->json('error.code'));
        // The office has no handheld: its rights never travel in a token.
        $this->assertSame('forbidden', $try(['username' => 'office', 'password' => self::PASSWORD])->assertStatus(403)->json('error.code'));
        DB::update('UPDATE users SET is_active = 0 WHERE id = ?', [$this->users['washing']]);
        $this->assertSame('bad_login', $try(['username' => 'washing', 'password' => self::PASSWORD])->assertStatus(401)->json('error.code'));

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame('bad_login', $try(['username' => 'driver', 'password' => 'wrong-password'])->assertStatus(401)->json('error.code'));
        }
        $this->assertSame('too_many_tries', $try(['username' => 'driver', 'password' => self::PASSWORD])->assertStatus(429)->json('error.code'));
        // One count for both doors: the web login of the same user is blocked too.
        $this->assertError(429, 'too_many_tries', $this->login('driver'));
        $this->assertSame(0, (int) DB::scalar('SELECT COUNT(*) FROM personal_access_tokens'));
    }

    public function test_a_new_token_login_ends_the_old_token(): void
    {
        $old = $this->token('driver');
        $new = $this->token('driver');
        $other = $this->token('washing');
        $this->handheld('GET', '/auth/me', $old)->assertStatus(401);
        $this->handheld('GET', '/auth/me', $new)->assertOk();
        $this->handheld('GET', '/auth/me', $other)->assertOk();   // another user keeps his
    }

    public function test_logout_ends_the_token(): void
    {
        $token = $this->token('driver');
        $this->assertSame(['ok' => true], $this->handheld('POST', '/auth/logout', $token)->assertOk()->json());
        $this->handheld('GET', '/auth/me', $token)->assertStatus(401);
        $this->assertSame(0, (int) DB::scalar('SELECT COUNT(*) FROM personal_access_tokens'));
    }

    public function test_a_token_stops_after_twelve_hours_or_when_the_user_is_switched_off(): void
    {
        $token = $this->token('driver');
        $this->travel(11)->hours();
        $this->handheld('GET', '/auth/me', $token)->assertOk();
        $this->travel(61)->minutes();
        $this->handheld('GET', '/auth/me', $token)->assertStatus(401);

        $this->travelBack();
        $token = $this->token('washing');
        DB::update('UPDATE users SET is_active = 0 WHERE id = ?', [$this->users['washing']]);
        $this->assertSame('not_logged_in', $this->handheld('GET', '/auth/me', $token)->assertStatus(401)->json('error.code'));
    }

    public function test_a_handheld_inside_the_web_app_gets_no_session(): void
    {
        // The simulated handhelds run in the web app's page, so their calls carry its address.
        // They still get no cookie: a session there would make them all the one logged-in office user.
        $login = $this->handheld('POST', '/auth/token', null, ['username' => 'driver', 'password' => self::PASSWORD], config('app.url'))->assertOk();
        $this->assertSame([], $login->headers->getCookies());
        $me = $this->handheld('GET', '/auth/me', $login->json('token'), [], config('app.url'))->assertOk();
        $this->assertSame([], $me->headers->getCookies());
        // Without a token the same address is the web app, and that gets its session cookie as before.
        $this->assertNotSame([], $this->handheld('GET', '/auth/me', null, [], config('app.url'))->headers->getCookies());
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
