<?php

App::uses('AppController', 'Controller');

class SimpleController extends AppController
{
	public function index()
	{
		$this->autoRender = false;
		error_log('This is log');
		echo 'Hello, CakePHP 2.3.9 on PHP ' . phpversion();
	}
}
