--TEST--
Negative duration for dropped spans
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_LOG_LEVEL=info,startup=off
DD_AUTOFINISH_SPANS=1
--FILE--
<?php

$root = DDTrace\active_span();

DDTrace\start_span();
DDTrace\flush();

var_dump(DDTrace\active_span() != $root);
var_dump(DDTrace\active_span() != null);

?>
--EXPECTF--
[ddtrace] [info] Flushing trace of size 2 to send-queue for %s
bool(true)
bool(true)
[ddtrace] [info] Flushing trace of size 1 to send-queue for %s
