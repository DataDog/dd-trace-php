<?php

App::uses('AppController', 'Controller');


class HttpResponseCodeController extends AppController {
	public function error() {
		$this->autoRender = false;
		http_response_code(500);
	}

	public function success() {
		$this->autoRender = false;
		http_response_code(200);
	}
}
