<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| This API is backend-first. Keep web routes minimal and avoid overriding
| framework/package routes such as Sanctum's CSRF cookie endpoint.
|
*/

Route::get('/', static function () {
    return response()->json([
        'success' => true,
        'message' => 'Bylin API is running.',
        'environment' => app()->environment(),
    ]);
});
