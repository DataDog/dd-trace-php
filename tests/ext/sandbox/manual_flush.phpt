--TEST--
Spans are not automatically flushed when auto-flushing disabled
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_LOG_LEVEL=info,startup=off
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
--EXPECTF--
[ddtrace] [info] Flushing trace of size 1 to send-queue for %s
[ddtrace] [info] Flushing trace of size 1 to send-queue for %s
