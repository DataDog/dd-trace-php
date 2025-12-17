--TEST--
User requests: content negotiation
--INI--
extension=ddtrace.so
datadog.appsec.enabled=true
datadog.appsec.cli_start_on_rinit=false
datadog.appsec.log_level=error
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

use function DDTrace\UserRequest\notify_start;
use function DDTrace\start_span;

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createinitedRun([
    response_list(response_request_init([[['block', new ArrayObject()]], ['{"yet another":"attack"}'], true])),
    response_list(response_request_shutdown([[['ok', []]], [], []]))
]);

$span = start_span();

$res = notify_start($span, array(
    '_SERVER' => [
        'HTTP_ACCEPT' => 'text/html',
    ],
));
echo "Result of notify_start:\n";
print_r($res);

\datadog\appsec\testing\rshutdown();
--EXPECTF--
Result of notify_start:
Array
(
    [status] => 403
    [body] => %s
    [headers] => Array
        (
            [Content-Type] => text/html
            [Content-Length] => 1604
        )

)
