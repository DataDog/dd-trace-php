<?php

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . "/../{$_GET["version"]}/vendor/autoload.php";

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;

$client = new Client();
$request = new Request('get', 'http://httpbin_integration/status/200');
$client->send($request);
