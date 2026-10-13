<?php
/** Gives the web app its session and forgery check, and leaves a handheld, which logs in for a token, without both. */
declare(strict_types=1);

namespace App\Http\Middleware;

use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

final class WebAppSession extends EnsureFrontendRequestsAreStateful
{
    /**
     * Sanctum goes by the address a call comes from. That is not enough here: the simulated handhelds run inside
     * the web app's page, and with a session they would all be the one logged-in office user.
     * A call that asks for a token or carries one is a handheld's. It gets no cookie, so it needs no forgery check:
     * a browser never adds a token to a call by itself, as it does with a cookie.
     */
    public static function fromFrontend($request)
    {
        return !$request->is('api/v1/auth/token') && $request->bearerToken() === null && parent::fromFrontend($request);
    }
}
