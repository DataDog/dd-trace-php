--TEST--
GH#3254: Guzzle tracing causes response body stream to stay open
--ENV--
DD_TRACE_DEBUG=0
--FILE--
<?php

require __DIR__ . '/vendor/autoload.php';

\DDTrace\hook_method(
    'GuzzleHttp\Psr7\Stream',
    '__destruct',
    function () {
        echo 'stream destructed' . PHP_EOL;
    }
);

$client  = new \GuzzleHttp\Client();
$request = new \GuzzleHttp\Psr7\Request('GET', 'https://httpbin.org/json');
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
stream destructed
