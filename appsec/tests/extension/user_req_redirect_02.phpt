--TEST--
User requests: redirect with block_id
--INI--
extension=ddtrace.so
datadog.appsec.enabled=true
datadog.appsec.cli_start_on_rinit=false
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

use function DDTrace\UserRequest\notify_start;
use function DDTrace\start_span;
use function DDTrace\close_span;

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createinitedRun([
    response_list(response_request_init([[['redirect', ['status_code' => '302', 'location' => 'https://www.example.com?block_id={block_id}', 'block_id' => 'some-block-id']]], ['{"yet another":"attack"}'], true])),
    response_list(response_request_shutdown([[['ok', []]], [], []]))
]);

$span = start_span();

$res = notify_start($span, array());
echo "Result of notify_start:\n";
print_r($res);

close_span(100.0);
--EXPECTF--
Result of notify_start:
Array
(
    [status] => 302
    [headers] => Array
        (
            [Location] => https://www.example.com?block_id=some-block-id
        )

)
