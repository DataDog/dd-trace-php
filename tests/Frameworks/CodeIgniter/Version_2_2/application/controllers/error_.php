<?php

class Error_ extends CI_Controller {
    function index() {
        throw new \Exception('datadog');
    }

    function http_response_code_failure() {
        http_response_code(500);
    }
}
