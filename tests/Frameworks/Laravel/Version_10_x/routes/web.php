<?php

use App\Http\Controllers\CommonSpecsController;
use App\Http\Controllers\LoginTestController;
use App\Http\Controllers\RaspTestController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('simple', [CommonSpecsController::class, 'simple'])->name('simple_route');
Route::get('simple_view', [CommonSpecsController::class, 'simple_view']);
Route::get('error', [CommonSpecsController::class, 'error']);
Route::get('ignored_exception', [CommonSpecsController::class, 'ignored_exception'])->name('ignored_exception');
Route::get('login/auth', [LoginTestController::class, 'auth'])->name('login');
Route::get('login/signup', [LoginTestController::class, 'register']);
Route::get('/behind_auth', [LoginTestController::class, 'behind_auth'])->name('behind_auth')->middleware('auth');
Route::get('rasp', [RaspTestController::class, 'rasp']);
Route::get('/telemetry', function () {
    dd_trace_internal_fn("finalize_telemetry");
    return response('Done');
});