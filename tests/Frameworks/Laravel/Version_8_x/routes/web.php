<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CommonSpecsController;
use App\Http\Controllers\EloquentTestController;

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
