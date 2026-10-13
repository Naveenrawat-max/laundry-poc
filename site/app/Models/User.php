<?php
/** A person who logs in. One role per user. The password is stored only as a hash and never leaves the server. */
declare(strict_types=1);

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

final class User extends Authenticatable
{
    // Not used yet: it is what lets the handheld log in with a token later, without a new build.
    use HasApiTokens;

    public const ROLES = ['office', 'inventory', 'driver', 'washing', 'outbound'];

    /** The schema names the column password_hash. Laravel's login finds the hash through this name. */
    protected $authPasswordName = 'password_hash';

    /** Exactly what the API shows of a user. */
    protected $visible = ['id', 'name', 'username', 'role', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
