<?php

App::uses('AppController', 'Controller');

class SimpleController extends AppController
{
	public function index()
	{
		$this->autoRender = false;
		echo 'Hello.';
	}
}
