<?php

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

Route::get('/simple', ['as' => 'simple_route', 'uses' => 'CommonSpecsController@simple']);
Route::get('/simple_view', 'CommonSpecsController@simple_view');
Route::get('dynamic_route/{param01}/static/{param02?}', 'CommonSpecsController@dynamicRoute');
Route::get('/error', 'CommonSpecsController@error');
Route::get('/pipeline_once', 'TestsController@pipelineCalledOnce');
Route::get('/pipeline_twice', 'TestsController@pipelineCalledTwice');
Route::get('/eloquent/get', 'EloquentTestController@get');
Route::get('/eloquent/insert', 'EloquentTestController@insert');
Route::get('/eloquent/update', 'EloquentTestController@update');
Route::get('/eloquent/delete', 'EloquentTestController@delete');
Route::get('/eloquent/destroy', 'EloquentTestController@destroy');
Route::get('/eloquent/refresh', 'EloquentTestController@refresh');
Route::get('queue/batch', 'QueueTestController@batch');
Route::get('queue/batchDefault', 'QueueTestController@batchDefault');
Route::get('queue/create', 'QueueTestController@create');
Route::get('queue/jobFailure', 'QueueTestController@jobFailure');
Route::get('queue/workOn', 'QueueTestController@workOn');
Route::get('login/auth', 'LoginTestController@auth');
Route::get('login/signup', 'LoginTestController@register');
Route::get('/behind_auth', 'LoginTestController@behind_auth')->middleware('auth');
Route::get('rasp', 'RaspTestController@rasp');
Route::get('/telemetry', function () {
    dd_trace_internal_fn("finalize_telemetry");
    return response('Done');
});
