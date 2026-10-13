<?php
/** Runs on every route behind the login: a switched-off user is out at once, and "role:a,b" lets only those roles through. */
declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class Role
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        if (!$user->is_active) {
            // The user is read from the database on every call, so switching someone off works at his next call.
            Auth::guard('web')->logout();
            fail(401, 'not_logged_in', 'Log in first.');
        }
        if ($roles !== [] && !in_array($user->role, $roles, true)) {
            fail(403, 'forbidden', 'Your role may not do this.');
        }
        return $next($request);
    }
}
