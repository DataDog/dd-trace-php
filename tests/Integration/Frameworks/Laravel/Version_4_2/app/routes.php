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
