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
