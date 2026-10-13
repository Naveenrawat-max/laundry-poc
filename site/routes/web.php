<?php
/** Every web path that is not the API serves the one page of the web app. The API lives in routes/api.php. */

use Illuminate\Support\Facades\Route;

// React decides what a path shows. An unknown /api path must stay a JSON 404, so it is left out here.
Route::view('/{path?}', 'app')->where('path', '^(?!api(/|$)|up$).*');
