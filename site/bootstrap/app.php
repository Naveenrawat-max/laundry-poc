<?php
/** Wires the application: the API under /api/v1, the session for the web app, the role check, and the one error shape. */

use App\Http\Middleware\ApiHeaders;
use App\Http\Middleware\Role;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The web app logs in with a session cookie. Sanctum adds the session and the forgery check to its API calls.
        $middleware->statefulApi();
        $middleware->api(append: [ApiHeaders::class]);
        $middleware->alias(['role' => Role::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every failure under /api answers in the one error shape. Other pages keep Laravel's own error pages.
        $exceptions->render(fn (Throwable $e, Request $request) => $request->is('api/*') ? api_error($e) : null);
    })->create();
