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

Route::get('/', ['as' => 'example_route', 'uses' => 'ExampleController@example']);
Route::get('/exception', ['as' => 'example_route_exception', 'uses' => 'ExampleController@exception']);
Route::get('/fatal', ['as' => 'example_route_fatal', 'uses' => 'ExampleController@fatal']);
Route::get('/trigger_error', ['as' => 'example_route_trigger_error', 'uses' => 'ExampleController@trigger_error']);
Route::get('/caught', ['as' => 'example_route_trigger_error', 'uses' => 'ExampleController@caught']);
