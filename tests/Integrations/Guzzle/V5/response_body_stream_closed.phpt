--TEST--
GH#3254: Guzzle tracing causes response body stream to stay open
--SKIPIF--
<?php if (getenv('PHPUNIT_COVERAGE')) die('skip test not compatible with coverage mode'); ?>
--ENV--
DD_TRACE_DEBUG=0
--FILE--
<?php

require __DIR__ . '/vendor/autoload.php';

\DDTrace\hook_method(
    'GuzzleHttp\Stream\Stream',
    '__destruct',
    function () {
        echo 'stream destructed' . PHP_EOL;
    }
);

$port = getenv('HTTPBIN_PORT') ?: '80';
$url = 'http://' . getenv('HTTPBIN_HOSTNAME') . ':' . $port .'/json';

$client  = new \GuzzleHttp\Client();
$request = new \GuzzleHttp\Message\Request('GET', $url);
for ($i = 1; $i <= 3; $i++) {
    echo 'Request ' . $i . PHP_EOL;
    $response = $client->send($request);
    unset($response);
    sleep(1);
}

?>
--EXPECT--
Request 1
stream destructed
Request 2
stream destructed
Request 3
stream destructed
