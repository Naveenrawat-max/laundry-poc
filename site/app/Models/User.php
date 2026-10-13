<?php
/** A person who logs in. One role per user. The password is stored only as a hash and never leaves the server. */
declare(strict_types=1);

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\HasApiTokens;

final class User extends Authenticatable
{
    // Not used yet: it is what lets the handheld log in with a token later, without a new build.
    use HasApiTokens;

    public const ROLES = ['office', 'inventory', 'driver', 'washing', 'outbound'];

    // bcrypt reads only the first 72 bytes, so a longer password would be silently cut.
    public const PASSWORD_RULE = 'string|min:10|max:72';

    /** The schema names the column password_hash. Laravel's login finds the hash through this name. */
    protected $authPasswordName = 'password_hash';

    /** Exactly what the API shows of a user. */
    protected $visible = ['id', 'name', 'username', 'role', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    /** Validates and stores a new user. Used by POST /users and by the command laundry:office-user. */
    public static function register(array $in): self
    {
        if (is_string($in['username'] ?? null)) {
            $in['username'] = strtolower(trim($in['username']));
        }
        $data = Validator::make($in, [
            'name' => 'required|string|max:120',
            'username' => ['required', 'string', 'regex:/^[a-z0-9._-]{3,60}$/'],
            'password' => 'required|' . self::PASSWORD_RULE,
            'role' => ['required', Rule::in(self::ROLES)],
        ], ['username.regex' => 'The username must be 3 to 60 letters, digits, dots, dashes or underscores.'])->validate();

        $user = new self;
        $user->forceFill([
            'name' => trim($data['name']),
            'username' => $data['username'],
            'password_hash' => Hash::make($data['password']),
            'role' => $data['role'],
        ])->save();
        return $user->fresh();
    }
}
