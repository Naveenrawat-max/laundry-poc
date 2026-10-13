<?php
/** User management by the office, and the one-time command that creates the first office user. */
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

final class UsersTest extends ApiTestCase
{
    private function newUser(array $over = []): array
    {
        return $over + ['name' => 'Dana Driver', 'username' => 'Dana.D', 'password' => 'long-password-1', 'role' => 'driver'];
    }

    public function test_office_adds_a_user_who_can_then_log_in(): void
    {
        $this->loginAs('office');
        $user = $this->ok($this->api('POST', '/users', $this->newUser()))['user'];
        $this->assertSame(['id', 'name', 'username', 'role', 'is_active'], array_keys($user));
        $this->assertSame('dana.d', $user['username']);

        $this->logout();
        $out = $this->ok($this->api('POST', '/auth/login', ['username' => 'dana.d', 'password' => 'long-password-1']));
        $this->assertSame('driver', $out['user']['role']);
    }

    public function test_the_list_never_shows_a_password_hash(): void
    {
        $this->loginAs('office');
        $rows = $this->ok($this->api('GET', '/users'))['rows'];
        $this->assertCount(6, $rows);
        $this->assertStringNotContainsString('password', json_encode($rows));
    }

    public function test_bad_input_is_refused(): void
    {
        $this->loginAs('office');
        $this->assertError(422, 'invalid_input', $this->api('POST', '/users', $this->newUser(['password' => 'short'])));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/users', $this->newUser(['password' => str_repeat('a', 73)])));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/users', $this->newUser(['username' => 'has space'])));
        $this->assertError(422, 'invalid_input', $this->api('POST', '/users', $this->newUser(['role' => 'admin'])));
        $this->assertError(409, 'duplicate', $this->api('POST', '/users', $this->newUser(['username' => 'driver'])));
    }

    public function test_a_switched_off_user_cannot_log_in(): void
    {
        $this->loginAs('office');
        $user = $this->ok($this->api('PATCH', "/users/{$this->users['driver']}", ['is_active' => false]))['user'];
        $this->assertFalse($user['is_active']);

        $this->logout();
        $this->assertError(401, 'bad_login',
            $this->api('POST', '/auth/login', ['username' => 'driver', 'password' => self::PASSWORD]));
    }

    public function test_office_changes_role_name_and_password(): void
    {
        $this->loginAs('office');
        $user = $this->ok($this->api('PATCH', "/users/{$this->users['outbound']}",
            ['role' => 'inventory', 'name' => 'Olga', 'password' => 'another-password-2']))['user'];
        $this->assertSame(['inventory', 'Olga'], [$user['role'], $user['name']]);

        $this->logout();
        $this->ok($this->api('POST', '/auth/login', ['username' => 'outbound', 'password' => 'another-password-2']));
    }

    public function test_an_office_user_cannot_lock_himself_out(): void
    {
        $this->loginAs('office');
        $me = $this->users['office'];
        $this->assertError(422, 'self_lockout', $this->api('PATCH', "/users/$me", ['is_active' => false]));
        $this->assertError(422, 'self_lockout', $this->api('PATCH', "/users/$me", ['role' => 'driver']));
        $this->ok($this->api('PATCH', "/users/$me", ['name' => 'Olivia']));
    }

    public function test_unknown_user_is_404_and_other_roles_are_refused(): void
    {
        $this->loginAs('office');
        $this->assertError(404, 'not_found', $this->api('PATCH', '/users/999', ['name' => 'X']));
        $this->loginAs('inventory');
        $this->assertError(403, 'forbidden', $this->api('GET', '/users'));
    }

    public function test_the_command_creates_the_first_office_user(): void
    {
        $this->artisan('laundry:office-user')
            ->expectsQuestion('Full name', 'First Office')
            ->expectsQuestion('Username', 'First.Office')
            ->expectsQuestion('Password (10 to 72 characters)', 'long-password-1')
            ->expectsOutputToContain('Created office user first.office.')
            ->assertExitCode(0);

        $out = $this->ok($this->api('POST', '/auth/login', ['username' => 'first.office', 'password' => 'long-password-1']));
        $this->assertSame('office', $out['user']['role']);
    }

    public function test_the_command_refuses_a_weak_password_and_a_name_that_exists(): void
    {
        $this->artisan('laundry:office-user')
            ->expectsQuestion('Full name', 'First Office')
            ->expectsQuestion('Username', 'first.office')
            ->expectsQuestion('Password (10 to 72 characters)', 'short')
            ->assertExitCode(1);
        $this->artisan('laundry:office-user')
            ->expectsQuestion('Full name', 'Second Office')
            ->expectsQuestion('Username', 'office')
            ->expectsQuestion('Password (10 to 72 characters)', 'long-password-1')
            ->assertExitCode(1);
        $this->assertSame(6, (int) DB::scalar('SELECT COUNT(*) FROM users'));
    }
}
