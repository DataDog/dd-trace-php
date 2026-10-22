--TEST--
A fatal parser allocation releases parser memory before the Zend bailout
--INI--
extension=ddtrace.so
display_errors=0
--FILE--
<?php
use function datadog\appsec\testing\body_parser_fail_after;

// Decoding the escapes needs a large scratch buffer. Fail the final output
// allocation while that buffer is still live.
$input = '["' . str_repeat('\\n', 1024 * 1024) . '"]';
body_parser_fail_after();
datadog\appsec\convert_json($input);
$allocations = body_parser_fail_after();
$before = 0;
$returned = false;
register_shutdown_function(function () use (&$returned, &$before, $allocations) {
    // Allow for PHP's fatal-error bookkeeping, but not the 1 MiB scratch buffer.
    var_dump(memory_get_usage() - $before < 64 * 1024);
    $error = error_get_last();
    var_dump(!$returned && $error['type'] === E_ERROR &&
        $error['message'] === 'Injected parser allocation failure');
    // No further allocator callbacks may run after the bailout is caught.
    var_dump(body_parser_fail_after() === $allocations);
    // Both parsers must work again on this thread during shutdown.
    var_dump(datadog\appsec\convert_json('[1]') === [1]);
    var_dump(datadog\appsec\convert_xml('<r/>', 'application/xml') === ['r' => []]);
});
body_parser_fail_after($allocations - 1, true);
$before = memory_get_usage();
datadog\appsec\convert_json($input);
$returned = true;
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
