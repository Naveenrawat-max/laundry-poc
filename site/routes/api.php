<?php
/** The API routes, all under /api/v1. Only the two logins are open; "role:" says which roles may call a route. */
declare(strict_types=1);

use App\Http\Controllers as C;
use Illuminate\Support\Facades\Route;

// An id in a path is a whole number. Anything else is an unknown path.
Route::pattern('id', '\d{1,18}');
Route::pattern('itemId', '\d{1,18}');

Route::post('/auth/login', [C\AuthController::class, 'login']);
Route::post('/auth/token', [C\AuthController::class, 'token']);

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

    Route::post('/scan/lookup', [C\ScanController::class, 'lookup'])->middleware($scanners);
    Route::get('/sim/items', [C\ScanController::class, 'simItems'])->middleware($scanners);
    Route::post('/items', [C\ItemController::class, 'store'])->middleware('role:inventory');

    Route::get('/items', [C\ItemController::class, 'index'])->middleware('role:office,inventory,driver,washing');
    Route::get('/items/counts', [C\ItemController::class, 'counts'])->middleware('role:office');
    Route::post('/items/ready', [C\ItemController::class, 'ready'])->middleware('role:inventory');
    Route::post('/items/qc', [C\ItemController::class, 'qc'])->middleware('role:inventory');
    Route::get('/items/{id}', [C\ItemController::class, 'show'])->middleware('role:office,washing');
    Route::post('/items/{id}/correct', [C\ItemController::class, 'correct'])->middleware('role:office');

    Route::post('/handovers', [C\HandoverController::class, 'open'])->middleware($scanners);
    Route::post('/handovers/{id}/scan', [C\HandoverController::class, 'scan'])->middleware($scanners);

    Route::get('/handovers', [C\HandoverController::class, 'index']);
    Route::get('/handovers/{id}', [C\HandoverController::class, 'show']);
    Route::post('/handovers/{id}/close', [C\HandoverController::class, 'close']);
    Route::post('/handovers/{id}/items/{itemId}/resolve', [C\HandoverController::class, 'resolve'])->middleware('role:office');
    Route::get('/handover-receivers', [C\HandoverController::class, 'receivers'])->middleware($scanners);

    Route::get('/contracts', [C\ContractController::class, 'index'])->middleware('role:office');
    Route::post('/contracts', [C\ContractController::class, 'store'])->middleware('role:office');
    Route::patch('/contracts/{id}', [C\ContractController::class, 'update'])->middleware('role:office');
    Route::post('/contracts/{id}/activate', [C\ContractController::class, 'activate'])->middleware('role:office');
    Route::post('/contracts/{id}/end', [C\ContractController::class, 'end'])->middleware('role:office');

    Route::get('/tasks', [C\TaskController::class, 'index'])->middleware('role:office,driver,inventory,outbound');
    Route::post('/tasks', [C\TaskController::class, 'store'])->middleware('role:office');
    Route::patch('/tasks/{id}', [C\TaskController::class, 'update'])->middleware('role:office');

    Route::post('/tasks/{id}/complete', [C\TaskController::class, 'complete'])->middleware('role:driver');
    Route::get('/tasks/{id}/photo', [C\TaskController::class, 'photo'])->middleware('role:office');
    Route::get('/tasks/{id}/signature', [C\TaskController::class, 'signature'])->middleware('role:office');
});
