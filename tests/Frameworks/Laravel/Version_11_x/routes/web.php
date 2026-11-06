<?php

use App\Http\Controllers\CommonSpecsController;
use App\Http\Controllers\LoginTestController;
use App\Http\Controllers\RaspTestController;
use Illuminate\Support\Facades\Route;

Route::get('simple', [CommonSpecsController::class, 'simple'])->name('simple_route');
Route::get('simple_view', [CommonSpecsController::class, 'simple_view']);
Route::get('error', [CommonSpecsController::class, 'error']);
Route::get('rasp', [RaspTestController::class, 'rasp']);
Route::get('login/auth', [LoginTestController::class, 'auth'])->name('login');
Route::get('login/signup', [LoginTestController::class, 'register']);
Route::get('/behind_auth', [LoginTestController::class, 'behind_auth'])->name('behind_auth')->middleware('auth');
Route::get('/telemetry', function () {
    dd_trace_internal_fn("finalize_telemetry");
    return response('Done');
});

Route::get('/', function () {
    return 'Hi';
});

Route::get('/dynamic-path/{param01}', function (string $param01) {
    return response("Hi $param01", 200);
});