<?php

use App\Http\Controllers\CommonSpecsController;
use App\Http\Controllers\EloquentTestController;
use App\Http\Controllers\InternalErrorController;
use App\Http\Controllers\QueueTestController;
use App\Http\Controllers\RouteCachingController;
use App\Http\Controllers\LoginTestController;
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
Route::get('dynamic_route/{param01}/static/{param02?}', [CommonSpecsController::class, 'dynamicRoute']);
Route::get('error', [CommonSpecsController::class, 'error']);
Route::get('eloquent/get', [EloquentTestController::class, 'get']);
Route::get('eloquent/insert', [EloquentTestController::class, 'insert']);
Route::get('eloquent/update', [EloquentTestController::class, 'update']);
Route::get('eloquent/delete', [EloquentTestController::class, 'delete']);
Route::get('eloquent/destroy', [EloquentTestController::class, 'destroy']);
Route::get('eloquent/refresh', [EloquentTestController::class, 'refresh']);
Route::get('not-implemented', [InternalErrorController::class, 'notImplemented'])->name('not-implemented');
Route::get('unauthorized', [InternalErrorController::class, 'unauthorized'])->name('unauthorized');
Route::get('queue/batch', [QueueTestController::class, 'batch']);
Route::get('queue/batchDefault', [QueueTestController::class, 'batchDefault']);
Route::get('queue/broadcast', [QueueTestController::class, 'broadcast'])->name('broadcast');
Route::get('queue/create', [QueueTestController::class, 'create']);
Route::get('queue/jobFailure', [QueueTestController::class, 'jobFailure']);
Route::get('queue/workOn', [QueueTestController::class, 'workOn']);
Route::get('login/auth', [LoginTestController::class, 'auth']);
Route::get('login/signup', [LoginTestController::class, 'register']);

// This route has to remain unnamed so we test both route cached and not cached.
Route::get('/unnamed-route', [RouteCachingController::class, 'unnamed']);
