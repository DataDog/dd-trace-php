<?php

/** @var \Laravel\Lumen\Routing\Router $router */

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It is a breeze. Simply tell Lumen the URIs it should respond to
| and give it the Closure to call when that URI is requested.
|
*/

$router->get('/', function () use ($router) {
    return $router->app->version();
});

$router->get('simple', [
    'as' => 'simple_route',
    'uses' => 'ExampleController@simple',
]);

$router->get('simple_view', [
    'uses' => 'ExampleController@simpleView',
]);

$router->get('error', [
    'uses' => 'ExampleController@error',
]);
