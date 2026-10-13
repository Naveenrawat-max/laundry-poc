<?php
/** What every test starts with: the mock data plus six users, one per role and a second driver. */
declare(strict_types=1);

namespace Tests;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class TestSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DatabaseSeeder::class);
        $hash = Hash::make(ApiTestCase::PASSWORD);
        $roles = ['office' => 'office', 'inventory' => 'inventory', 'driver' => 'driver',
            'driver2' => 'driver', 'washing' => 'washing', 'outbound' => 'outbound'];
        foreach ($roles as $username => $role) {
            DB::table('users')->insert(['name' => ucfirst($username), 'username' => $username,
                'password_hash' => $hash, 'role' => $role]);
        }
    }
}
