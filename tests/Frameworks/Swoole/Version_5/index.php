<?php
require __DIR__ . '/../../../vendor/autoload.php';
file_put_contents(__DIR__ . '/server.log', 'Class exists Swoole\Http\Server: ' . (class_exists('Swoole\Http\Server') ? 'yes' : 'no') . PHP_EOL, FILE_APPEND);
file_put_contents(__DIR__ . '/server.log', 'SAPI: ' . PHP_SAPI . PHP_EOL, FILE_APPEND);
file_put_contents(__DIR__ . '/server.log', 'Initializing server' . PHP_EOL, FILE_APPEND);
$http = new Swoole\Http\Server("0.0.0.0", 9999);
file_put_contents(__DIR__ . '/server.log', 'Server initialized' . PHP_EOL, FILE_APPEND);
$http->set(['hook_flags' => SWOOLE_HOOK_ALL]);
file_put_contents(__DIR__ . '/server.log', 'Hook flags set' . PHP_EOL, FILE_APPEND);

$http->on('request', function ($request, $response) {
    file_put_contents(__DIR__ . '/server.log', 'Request received' . PHP_EOL, FILE_APPEND);
    $response->end(json_encode(['hello' => 'world']));
    file_put_contents(__DIR__ . '/server.log', 'Request processed' . PHP_EOL, FILE_APPEND);
});
file_put_contents(__DIR__ . '/server.log', 'Request handler set' . PHP_EOL, FILE_APPEND);

file_put_contents(__DIR__ . '/server.log', 'Starting server' . PHP_EOL, FILE_APPEND);
$http->start();
file_put_contents(__DIR__ . '/server.log', 'Server started' . PHP_EOL, FILE_APPEND);
