<?php

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Ring\Client\CurlMultiHandler;

$curl = new CurlMultiHandler();
$client = new Client(['handler' => $curl]);

$port = getenv('HTTPBIN_PORT') ?: '80';
$url = 'http://' . getenv('HTTPBIN_HOSTNAME') . ':' . $port .'/headers';

$future1 = $client->get($url, [
    'future' => true,
    'headers' => [
        'honored' => 'preserved_value',
    ],
]);
$future1->then(function ($response) use (&$headers1) {
    $headers1 = $response->json();
});

$future2 = $client->get($url, [
    'future' => true,
    'headers' => [
        'honored' => 'preserved_value',
    ],
]);
$future2->then(function ($response) use (&$headers2) {
    $headers2 = $response->json();
});

$future1->wait();
$future2->wait();

echo json_encode([$headers1, $headers2]);
