<?php
/** The API routes, all under /api/v1. Only the login is open; "role:" says which roles may call a route. */
declare(strict_types=1);

use App\Http\Controllers as C;
use Illuminate\Support\Facades\Route;

// An id in a path is a whole number. Anything else is an unknown path.
Route::pattern('id', '\d{1,18}');
Route::pattern('itemId', '\d{1,18}');

Route::post('/auth/login', [C\AuthController::class, 'login']);

// Everything below needs a logged-in user who is still switched on. A route without its own "role:" is open to all roles.
Route::middleware(['auth:sanctum', 'role'])->group(function () {
    $scanners = 'role:inventory,driver,washing,outbound';

    Route::post('/auth/logout', [C\AuthController::class, 'logout']);
    Route::get('/auth/me', [C\AuthController::class, 'me']);
});
