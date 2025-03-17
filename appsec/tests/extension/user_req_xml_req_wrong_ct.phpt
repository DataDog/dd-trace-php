--TEST--
User requests: XML request body is not parsed wotj wrong content-type
--INI--
extension=ddtrace.so
datadog.appsec.enabled=true
datadog.appsec.cli_start_on_rinit=false
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

use function DDTrace\UserRequest\{notify_start,notify_commit};
use function DDtrace\close_span;
use function DDTrace\start_span;

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createinitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()])),
]);

$span = start_span();

$xml = <<<XML
<?xml version="1.0" standalone="yes"?>
<foo attr="bar">
test<br/>baz
</foo>
XML;

$res = notify_start($span, array(
    '_SERVER' => [
        'REMOTE_ADDR' => '1.2.3.4',
        'SERVER_PROTOCOL' => 'HTTP/1.1',
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/foo',
        'SERVER_NAME' => 'example.com',
        'SERVER_PORT' => 80,
        'HTTP_HOST' => 'example2.com',
        'HTTP_CLIENT_IP' => '2.3.4.5',
        'HTTP_CONTENT_TYPE' => 'text/NOT_XML',
    ],
), $xml);
echo "Result of notify_start:\n";
var_dump($res);

close_span(100.0);

$c = $helper->get_commands();
print_r($c[1][1][0]['server.request.body']);
--EXPECT--
Result of notify_start:
NULL
Array
(
)
