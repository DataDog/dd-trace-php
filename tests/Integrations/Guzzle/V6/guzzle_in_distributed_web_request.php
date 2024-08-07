<?php

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . "/../{$_GET["version"]}/vendor/autoload.php";

use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Is;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\Response;

$curl = new CurlMultiHandler();
$client = new Client([
    'handler' => HandlerStack::create($curl)
]);

$resolver = function (Response $response) use (&$found) {
    $found[] = $response;
};

$promise1 = $client->getAsync('http://httpbin_integration/headers', [
    'headers' => [
        'honored' => 'preserved_value',
    ],
])->then($resolver);

$promise2 = $client->getAsync('http://httpbin_integration/headers', [
    'headers' => [
        'honored' => 'preserved_value',
    ],
])->then($resolver);

$aggregate = Utils::all([$promise1, $promise2]);
while (!Is::settled($aggregate)) {
    $curl->tick();
}

echo json_encode(array_map(function ($response) {
    return json_decode($response->getBody(), 1);
}, $found));
