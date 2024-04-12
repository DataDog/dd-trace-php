<?php

require __DIR__ . '/../../vendor/autoload.php';

$http = new OpenSwoole\Http\Server("0.0.0.0", 9999, \OpenSwoole\Server::SIMPLE_MODE, \OpenSwoole\Constant::SOCK_TCP);

file_put_contents(__DIR__ . '/swoole.log', json_encode($http) . PHP_EOL, FILE_APPEND);

$http->on('request', function ($request, $response) {
    file_put_contents(__DIR__ . '/swoole.log', json_encode($request) . PHP_EOL, FILE_APPEND);
    file_put_contents(__DIR__ . '/swoole.log', json_encode($response) . PHP_EOL, FILE_APPEND);
    $requestUri = $request->server['request_uri'];

    try {
        if ($requestUri == "/error") {
            throw new \Exception("Error page");
        }

        $response->status(200);
        $response->end('Hello Swoole!');
    } catch (\Throwable $e) {
        $response->status(500);
        $response->end('Something Went Wrong!');
    }
});

$http->start();
