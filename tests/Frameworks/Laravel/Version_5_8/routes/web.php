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
Route::get('/error', 'CommonSpecsController@error');
Route::get('/eloquent/get', 'EloquentTestController@get');
Route::get('/eloquent/insert', 'EloquentTestController@insert');
Route::get('/eloquent/update', 'EloquentTestController@update');
Route::get('/eloquent/delete', 'EloquentTestController@delete');
Route::get('/eloquent/destroy', 'EloquentTestController@destroy');
Route::get('/eloquent/refresh', 'EloquentTestController@refresh');
