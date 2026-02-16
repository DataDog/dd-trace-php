--TEST--
request_shutdown — user_request variant with stream
--INI--
expose_php=0
extension=ddtrace.so
datadog.appsec.enabled=1
datadog.appsec.cli_start_on_rinit=false
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
use function DDTrace\UserRequest\{notify_start,notify_commit};
use function DDTrace\start_span;
use function DDTrace\close_span;

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()]))
]);

$span = start_span();


$res = notify_start($span, array(
    '_SERVER' => [
        'REMOTE_ADDR' => '1.2.3.4',
        'SERVER_PROTOCOL' => 'HTTP/1.1',
        'REQUEST_METHOD' => 'GET',
        'SERVER_NAME' => 'example.com',
        'SERVER_PORT' => 80,
        'HTTP_HOST' => 'example2.com',
        'HTTP_CONTENT_TYPE' => 'application/xml',
    ],
));

echo "Result of notify_start:\n";
var_dump($res);

$xml = <<<XML
<?xml version="1.0" standalone="yes"?>
<foo attr="bar">
test<br/>baz
</foo>
XML;

$stream = fopen('php://memory', 'r+');
fwrite($stream, "junk");
fwrite($stream, $xml);
rewind($stream);
fseek($stream, strlen('junk'), SEEK_SET);

$res = notify_commit($span, 200, array(
    'Content-Type' => ['text/xml'],
), $stream);
echo "Result of notify_commit:\n";
var_dump($res);

echo "Position of stream: ", ftell($stream), "\n";

close_span(100.0);

$c = $helper->get_commands();
print_r($c[2]);
?>
--EXPECTF--
Result of notify_start:
NULL
Result of notify_commit:
NULL
Position of stream: 4
Array
(
    [0] => request_shutdown
    [1] => Array
        (
            [0] => Array
                (
                    [server.response.status] => 200
                    [server.response.headers.no_cookies] => Array
                        (
                            [Content-Type] => Array
                                (
                                    [0] => text/xml
                                )

                        )

                    [server.response.body] => Array
                        (
                            [foo] => Array
                                (
                                    [0] => Array
                                        (
                                            [@attr] => bar
                                        )

                                    [1] => 
test
                                    [2] => Array
                                        (
                                            [br] => Array
                                                (
                                                )

                                        )

                                    [3] => baz

                                )

                        )

                )

            [1] => 0
            [2] => %s
            [3] => 
        )

)
