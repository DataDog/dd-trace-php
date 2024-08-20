<?php

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Message\Request;

$client = new Client();
$request = new Request('get', 'http://httpbin_integration/status/200');
$client->send($request);
