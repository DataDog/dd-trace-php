--TEST--
DDTrace\SpanData is used as an inline API
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--FILE--
<?php
use DDTrace\SpanData;

function bar($message) {
    echo "bar($message)\n";
}

DDTrace\trace_function('bar', function (SpanData $span) {
    $span->service = 'closure';
});

$span = new SpanData();
$span->name = 'userland';
$span->service = 'inline';
bar('hello');
$span->close();

include 'dd_dumper.inc';
dd_dump_spans();
?>
--EXPECTF--
bar(hello)
spans(\DDTrace\SpanData) (2) {
  userland (inline, userland)
    system.pid => %d
  bar (closure, bar)
}
