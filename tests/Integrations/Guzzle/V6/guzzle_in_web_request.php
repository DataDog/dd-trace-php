<?php

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . "/../{$_GET["version"]}/vendor/autoload.php";

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;

$port = getenv('HTTPBIN_PORT') ?: '80';
$url = 'http://' . getenv('HTTPBIN_HOSTNAME') . ':' . $port .'/status/200';

$client = new Client();
$request = new Request('get', $url);
$client->send($request);
