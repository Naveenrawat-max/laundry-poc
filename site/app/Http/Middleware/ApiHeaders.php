<?php
/** Headers on every API answer: never guess a content type, never cache, and HTTPS only once the site is live. */
declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ApiHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (!$request->is('api/*')) {
            return $response;   // Laravel's own pages keep their own headers
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Business-Date', today_nl());
        if (app()->isProduction()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }
        return $response;
    }
}
