<?php
/** Login, logout and the logged-in user. Laravel keeps the session; failed logins are throttled per username and address. */
declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

final class AuthController extends Controller
{
    private const MAX_TRIES = 5;

    private const WINDOW_SECONDS = 60;

    public function login(Request $request): array
    {
        if (!$request->hasSession()) {
            // Sanctum starts a session only for calls that come from the web app's own address (APP_URL).
            fail(403, 'csrf', 'Log in from the web app.');
        }
        $in = $request->validate(['username' => 'required|string|max:60', 'password' => 'required|string']);
        $username = strtolower($in['username']);

        $key = 'login|' . $username . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, self::MAX_TRIES)) {
            fail(429, 'too_many_tries', 'Too many tries. Wait a minute and try again.');
        }
        // attempt() takes as long for an unknown user as for a wrong password, so timing does not reveal usernames.
        if (!Auth::guard('web')->attempt(['username' => $username, 'password' => $in['password'], 'is_active' => 1])) {
            RateLimiter::hit($key, self::WINDOW_SECONDS);
            fail(401, 'bad_login', 'Wrong username or password.');
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();   // a new session id at login
        return ['user' => Auth::guard('web')->user(), 'csrf_token' => csrf_token()];
    }

    public function logout(Request $request): array
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return ['ok' => true];
    }

    public function me(Request $request): array
    {
        return ['user' => $request->user(), 'csrf_token' => csrf_token()];
    }
}
