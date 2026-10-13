<?php
/** Login for the web app (a session) and for a handheld (a token), logout and the logged-in user. Failed logins are throttled per username and address. */
declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

final class AuthController extends Controller
{
    private const MAX_TRIES = 5;

    private const WINDOW_SECONDS = 60;

    /** A handheld logs in again at the start of a shift. A lost handheld stops working within the day. */
    private const TOKEN_HOURS = 12;

    /**
     * One login try. $check gets the username, the password and "switched on", and says whether they are right.
     * Both logins count their failed tries together, so a second door gives no second set of guesses.
     */
    private function attempt(Request $request, callable $check): void
    {
        $in = $request->validate(['username' => 'required|string|max:60', 'password' => 'required|string']);
        $username = strtolower($in['username']);

        $key = 'login|' . $username . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, self::MAX_TRIES)) {
            fail(429, 'too_many_tries', 'Too many tries. Wait a minute and try again.');
        }
        if (!$check(['username' => $username, 'password' => $in['password'], 'is_active' => 1])) {
            RateLimiter::hit($key, self::WINDOW_SECONDS);
            fail(401, 'bad_login', 'Wrong username or password.');
        }
        RateLimiter::clear($key);
    }

    public function login(Request $request): array
    {
        if (!$request->hasSession()) {
            // Sanctum starts a session only for calls that come from the web app's own address (APP_URL).
            fail(403, 'csrf', 'Log in from the web app.');
        }
        // attempt() takes as long for an unknown user as for a wrong password, so timing does not reveal usernames.
        $this->attempt($request, fn (array $credentials) => Auth::guard('web')->attempt($credentials));
        $request->session()->regenerate();   // a new session id at login
        return ['user' => Auth::guard('web')->user(), 'csrf_token' => csrf_token()];
    }

    /** The login of a handheld. It gets a token to send with every call, and no session. */
    public function token(Request $request): array
    {
        // validate() checks the password as attempt() does, with the same timing, but logs nobody in.
        $this->attempt($request, fn (array $credentials) => Auth::guard('web')->validate($credentials));
        $user = Auth::guard('web')->getLastAttempted();
        if ($user->role === 'office') {
            // The office works in the web app. Its rights never travel in a token.
            fail(403, 'forbidden', 'The office logs in on the web app.');
        }
        $user->tokens()->delete();   // one handheld per user: a new login ends the old one
        $token = $user->createToken('handheld', ['*'], now()->addHours(self::TOKEN_HOURS));
        return ['user' => $user, 'token' => $token->plainTextToken];
    }

    public function logout(Request $request): array
    {
        if (!$request->hasSession()) {
            $request->user()->currentAccessToken()->delete();   // a handheld: its token ends here
            return ['ok' => true];
        }
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
