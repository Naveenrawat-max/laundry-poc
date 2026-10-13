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

    // Areas, customers, mat types, buildings and zones. A building hangs under a customer, a zone under a building.
    $refdata = ['areas' => null, 'customers' => null, 'item-types' => null, 'buildings' => 'customers', 'zones' => 'buildings'];
    foreach ($refdata as $resource => $parent) {
        $list = $parent === null ? "/$resource" : "/$parent/{id}/$resource";
        $read = $resource === 'item-types' ? 'role:office,inventory' : 'role:office';
        Route::get($list, [C\RefdataController::class, 'index'])->defaults('resource', $resource)->middleware($read);
        Route::post($list, [C\RefdataController::class, 'store'])->defaults('resource', $resource)->middleware('role:office');
        Route::patch("/$resource/{id}", [C\RefdataController::class, 'update'])->defaults('resource', $resource)->middleware('role:office');
    }


    Route::get('/users', [C\UserController::class, 'index'])->middleware('role:office');
    Route::post('/users', [C\UserController::class, 'store'])->middleware('role:office');
    Route::patch('/users/{id}', [C\UserController::class, 'update'])->middleware('role:office');

});
