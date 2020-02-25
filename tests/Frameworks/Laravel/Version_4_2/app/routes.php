<?php

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It's a breeze. Simply tell Laravel the URIs it should respond to
| and give it the Closure to execute when that URI is requested.
|
*/

Route::get('/simple', ['uses' => 'HomeController@simple', 'as' => 'simple_route']);
Route::get('/simple_view', 'HomeController@simple_view');
Route::get('/error', ['uses' => 'HomeController@error', 'as' => 'error']);
Route::get('/http_response_code/error', ['uses' => 'HomeController@http_response_error', 'as' => 'http_response_error']);
Route::get('/http_response_code/success', ['uses' => 'HomeController@http_response_success', 'as' => 'http_response_success']);
Route::get('/eloquent/get', 'EloquentTestController@get');
Route::get('/eloquent/insert', 'EloquentTestController@insert');
Route::get('/eloquent/update', 'EloquentTestController@update');
Route::get('/eloquent/delete', 'EloquentTestController@delete');
Route::get('/eloquent/destroy', 'EloquentTestController@destroy');
Route::get('/eloquent/refresh', 'EloquentTestController@refresh');
