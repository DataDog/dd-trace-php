--TEST--
Test DDTrace\close_spans_until
--ENV--
DD_TRACE_DEBUG=1
--FILE--
<?php

function traced() {
    DDTrace\start_span();
    DDTrace\start_span();
    var_dump(DDTrace\close_spans_until(DDTrace\root_span()));
    var_dump(DDTrace\close_spans_until(null));

    $start = DDTrace\start_span();
    DDTrace\start_span();
    DDTrace\start_span();
    var_dump(DDTrace\close_spans_until($start));
    DDTrace\close_span();
}

DDTrace\trace_function('traced', function() {});
traced();
var_dump(DDTrace\close_spans_until(null));
var_dump(DDTrace\close_spans_until(null));

?>
--EXPECTF--
bool(false)
int(2)
int(2)
int(1)
int(0)
Flushing trace of size 7 to send-queue for %s