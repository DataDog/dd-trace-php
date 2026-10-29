--TEST--
Parser allocator fallback reserves chunk headroom and reports a fatal after cleanup
--INI--
extension=ddtrace.so
memory_limit=-1
display_errors=0
--SKIPIF--
<?php
if (datadog\appsec\testing\body_parser_has_femalloc()) {
    die('skip requires a PHP runtime without femalloc');
}
?>
--FILE--
<?php

// Warm up the parser runtimes before measuring request memory.
datadog\appsec\convert_json('[1]');
datadog\appsec\convert_xml('<r/>', 'application/xml');

// Equality is allowed: one full chunk of headroom permits a small parse.
ini_set('memory_limit', (string) (memory_get_usage(true) + 2 * 1024 * 1024));
var_dump(datadog\appsec\convert_json('[1]'));

$returned = false;
$parseLine = null;
register_shutdown_function(function () use (&$returned, &$parseLine) {
    ini_set('memory_limit', '-1');
    $error = error_get_last();
    var_dump(!$returned && $error['type'] === E_ERROR && $error['line'] === $parseLine &&
        $error['message'] === 'AppSec body parser exceeded the memory limit');
    // Both parsers can run again after the fatal, on the same thread.
    var_dump(datadog\appsec\convert_json('[1]'));
    var_dump(datadog\appsec\convert_xml('<r/>', 'application/xml'));
});

// A small allocation can need an entire new 2 MiB Zend chunk. One MiB of
// headroom is insufficient even though the parser's first request is smaller.
ini_set('memory_limit', (string) (memory_get_usage(true) + 1024 * 1024));
$parseLine = __LINE__ + 1;
datadog\appsec\convert_json('[1]');
$returned = true;
?>
--EXPECT--
array(1) {
  [0]=>
  int(1)
}
bool(true)
array(1) {
  [0]=>
  int(1)
}
array(1) {
  ["r"]=>
  array(0) {
  }
}
