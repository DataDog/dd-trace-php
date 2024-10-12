<?php

require __DIR__ . '/../../vendor/autoload.php';

$http = new Swoole\Http\Server("0.0.0.0", $argv[1]);
$http->set([
    'worker_num' => 2
]);
$http->on('request', function ($request, $response) {
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
