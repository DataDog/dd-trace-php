<?php

use App\Http\Controllers\CommonSpecsController;
use Illuminate\Support\Facades\Route;

Route::get('simple', [CommonSpecsController::class, 'simple'])->name('simple_route');
Route::get('simple_view', [CommonSpecsController::class, 'simple_view']);
Route::get('error', [CommonSpecsController::class, 'error']);
Route::get('ignored_exception', [CommonSpecsController::class, 'ignored_exception'])->name('ignored_exception');
