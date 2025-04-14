--TEST--
Test try_drop_span() on root span
--INI--
datadog.trace.generate_root_span=0
datadog.trace.auto_flush_enabled=1
--FILE--
<?php

$extraRef = $span = DDTrace\start_span();
$span->onClose[] = function($span) {
    var_dump(DDTrace\try_drop_span($span));
};
DDTrace\close_span();

foreach (dd_trace_serialize_closed_spans() as $span) {
    echo $span["service"], "\n";
}

?>
--EXPECT--
bool(true)
