<?php

use Illuminate\Routing\Controller;
use Illuminate\Http\Response;

class HomeController extends Controller {

	/*
	|--------------------------------------------------------------------------
	| Default Home Controller
	|--------------------------------------------------------------------------
	|
	| You may wish to use controllers instead of, or in addition to, Closure
	| based routes. That's great! Here is an example controller method to
	| get you started. To route to this controller, just add the route:
	|
	|	Route::get('/', 'HomeController@showWelcome');
	|
	*/

	public function simple()
	{
		return 'simple';
	}

	public function simple_view()
	{
		return View::make('simple_view');
	}

	public function error()
	{
		throw new Exception('Controller error');
	}

	public function http_response_success()
	{
		$response = new Response();
		$response->setStatusCode(Response::HTTP_OK);
		http_response_code(Response::HTTP_OK);

		return $response;
	}

	public function http_response_error()
	{
		$response = new Response();
		$response->setStatusCode(Response::HTTP_INTERNAL_SERVER_ERROR);
		http_response_code(Response::HTTP_INTERNAL_SERVER_ERROR);

		return $response;
	}
}
