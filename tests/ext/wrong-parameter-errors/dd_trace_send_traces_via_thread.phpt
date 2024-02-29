--TEST--
dd_trace_send_traces_via_thread is passed wrong parameters
--FILE--
<?php

try {
    \dd_trace_send_traces_via_thread(0);
} catch (ArgumentCountError $e) {
    echo "OK1\n";
}

try {
    \dd_trace_send_traces_via_thread(0, ["foo"]);
} catch (ArgumentCountError $e) {
    echo "OK2\n";
}

try {
    \dd_trace_send_traces_via_thread(0, ["foo"], new StdClass());
} catch (TypeError $e) {
    echo "OK3\n";
}

try {
    \dd_trace_send_traces_via_thread(0, "foo", "bar");
} catch (TypeError $e) {
    echo "OK4\n";
}

try {
    \dd_trace_send_traces_via_thread("foo", ["foo"], "bar");
} catch (TypeError $e) {
    echo "OK5\n";
}

?>
--EXPECT--
OK1
OK2
OK3
OK4
OK5
