--TEST--
#[DDTrace\Trace] numeric $tags and $attributes keys are sent as decimal strings, like any other attribute key
--SKIPIF--
<?php if (PHP_VERSION_ID < 80000) die('skip: attributes require PHP 8.0'); ?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--INI--
error_reporting=E_ALL
--FILE--
<?php
include __DIR__ . '/sandbox/dd_dumper.inc';
#[DDTrace\Trace(name: "numeric", tags: [5 => 'five', 'tag' => 't'], attributes: [7 => 'seven', 8 => 8])]
function numeric() {}

numeric();

$span = dd_clean_spans()[0];
var_dump(array_intersect_key($span['attributes'], [5 => 1, 'tag' => 1, 7 => 1, 8 => 1]));
?>
--EXPECTF--
Deprecated: DDTrace\Trace: $tags is deprecated, use $attributes instead in %s on line %d
array(4) {
  [5]=>
  string(4) "five"
  ["tag"]=>
  string(1) "t"
  [7]=>
  string(5) "seven"
  [8]=>
  int(8)
}
