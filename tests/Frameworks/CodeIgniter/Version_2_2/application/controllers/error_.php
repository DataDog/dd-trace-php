<?php

class Error_ extends CI_Controller {
    function index() {
        throw new \Exception('datadog');
    }
}
