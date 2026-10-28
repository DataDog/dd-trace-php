--TEST--
JSON parser memory limit failure cleans up with native femalloc or its fallback
--INI--
extension=ddtrace.so
memory_limit=8M
display_errors=0
--FILE--
<?php
$parseLine = null;
$returned = false;
$hasFemalloc = datadog\appsec\testing\body_parser_has_femalloc();
register_shutdown_function(function () use (&$parseLine, &$returned, $hasFemalloc) {
    ini_set('memory_limit', '64M');
    $error = error_get_last();
    $message = $hasFemalloc ? 'Allowed memory size' : 'AppSec body parser exceeded the memory limit';
    var_dump(!$returned && $error['type'] === E_ERROR && $error['line'] === $parseLine &&
        strpos($error['message'], $message) !== false);
    var_dump(datadog\appsec\convert_json('[1]'));
});
// Input fits the limit; the parser's internal/output allocations exceed it.
$input = '[' . str_repeat('0,', 1000000) . '0]';
$parseLine = __LINE__ + 1;
datadog\appsec\convert_json($input);
$returned = true;
?>
--EXPECT--
bool(true)
array(1) {
  [0]=>
  int(1)
}
