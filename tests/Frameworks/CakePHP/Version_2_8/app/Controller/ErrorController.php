<?php

App::uses('AppController', 'Controller');

class ErrorController extends AppController
{
	public function index()
	{
		throw new \Exception('Foo error');
	}
}
