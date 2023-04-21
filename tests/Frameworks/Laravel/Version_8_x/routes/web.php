<?php

use App\Http\Controllers\QueueTestController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CommonSpecsController;
use App\Http\Controllers\EloquentTestController;
use App\Http\Controllers\InternalErrorController;
use App\Http\Controllers\RouteCachingController;

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
Route::get('eloquent/get', [EloquentTestController::class, 'get']);
Route::get('eloquent/insert', [EloquentTestController::class, 'insert']);
Route::get('eloquent/update', [EloquentTestController::class, 'update']);
Route::get('eloquent/delete', [EloquentTestController::class, 'delete']);
Route::get('eloquent/destroy', [EloquentTestController::class, 'destroy']);
Route::get('eloquent/refresh', [EloquentTestController::class, 'refresh']);
Route::get('not-implemented', [InternalErrorController::class, 'notImplemented'])->name('not-implemented');
Route::get('queue/create', [QueueTestController::class, 'create']);
Route::get('queue/work', [QueueTestController::class, 'work']);
Route::get('queue/workOn', [QueueTestController::class, 'workOn']);
Route::get('queue/clear', [QueueTestController::class, 'clear']);
Route::get('unauthorized', [InternalErrorController::class, 'unauthorized'])->name('unauthorized');

// This route has to remain unnamed so we test both route cached and not cached.
Route::get('/unnamed-route', [RouteCachingController::class, 'unnamed']);
