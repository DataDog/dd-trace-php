<?php

class Simple extends CI_Controller {
    function index() {
        echo 'simple';
    }

    function http_response_code_success() {
        http_response_code(200);
    }
}
