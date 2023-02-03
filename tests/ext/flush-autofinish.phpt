--TEST--
Negative duration for dropped spans
--ENV--
DD_TRACE_DEBUG=1
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
Flushing trace of size 2 to send-queue for %s
bool(true)
bool(true)
Flushing trace of size 1 to send-queue for %s
