--TEST--
User request shutdown restores the memory limit when a parser allocation bails out
--INI--
extension=ddtrace.so
datadog.appsec.enabled=1
datadog.appsec.cli_start_on_rinit=false
memory_limit=8M
display_errors=0
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
use function DDTrace\UserRequest\{notify_start, notify_commit};
use function DDTrace\start_span;
use function datadog\appsec\testing\body_parser_fail_after;

include __DIR__ . '/inc/mock_helper.php';
register_shutdown_function(function () {
    $error = error_get_last();
    var_dump($error['type'] === E_ERROR &&
        strpos($error['message'], 'Injected parser allocation failure') !== false);
    body_parser_fail_after();
    var_dump(datadog\appsec\convert_json('[1]'));
    $outside = str_repeat('x', 16 * 1024 * 1024);
    echo "Limit was not restored\n";
});
$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]]))
], ['no_wait_shutdown' => true]);
$span = start_span();
notify_start($span, ['_SERVER' => ['REQUEST_METHOD' => 'GET']]);
body_parser_fail_after(0, true);
notify_commit($span, 200, ['Content-Type' => ['application/json']], '{"a":1}');
echo "Bailout was swallowed\n";
?>
--EXPECT--
bool(true)
array(1) {
  [0]=>
  int(1)
}
