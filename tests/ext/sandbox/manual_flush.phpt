--TEST--
Spans are automatically flushed when auto-flushing enabled
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip: Test requires internal spans'); ?>
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_DEBUG=1
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

use DDTrace\SpanData;

DDTrace\trace_function('main', function (SpanData $span) {
    $span->name = 'main';
});

function main() {}

main();
DDTrace\flush();
main();

?>
--EXPECT--
Successfully triggered flush with trace of size 1
Successfully triggered flush with trace of size 1
