<?php
/** Commands for the server's command line. */
declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;

// Creates the first office user. Run once on the server: php artisan laundry:office-user
// No user is seeded, because a password must never sit in a file.
Artisan::command('laundry:office-user', function () {
    try {
        $user = User::register([
            'name' => $this->ask('Full name'),
            'username' => $this->ask('Username'),
            'password' => $this->secret('Password (10 to 72 characters)'),
            'role' => 'office',
        ]);
    } catch (ValidationException $e) {
        $this->error(implode(' ', $e->validator->errors()->all()));
        return 1;
    } catch (UniqueConstraintViolationException) {
        $this->error('That username already exists.');
        return 1;
    }
    $this->info("Created office user {$user->username}.");
    return 0;
})->purpose('Create the first office user');
