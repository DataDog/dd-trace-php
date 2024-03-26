<?php

require __DIR__ . '/../../vendor/autoload.php';

// If 0.0.0.0:9999 is already used, kill the process


$http = new Swoole\Http\Server("0.0.0.0", 9999);
$http->set(['hook_flags' => SWOOLE_HOOK_ALL]);

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
