<?php

use App\Http\Controllers\CommonSpecsController;
use App\Http\Controllers\RaspTestController;
use Illuminate\Support\Facades\Route;

Route::get('simple', [CommonSpecsController::class, 'simple'])->name('simple_route');
Route::get('simple_view', [CommonSpecsController::class, 'simple_view']);
Route::get('error', [CommonSpecsController::class, 'error']);
Route::get('rasp', [RaspTestController::class, 'rasp']);
Route::get('/telemetry', function () {
    dd_trace_internal_fn("finalize_telemetry");
    return response('Done');
});