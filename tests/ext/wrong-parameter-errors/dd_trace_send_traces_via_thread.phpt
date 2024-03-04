--TEST--
dd_trace_send_traces_via_thread is passed wrong parameters
--FILE--
<?php

declare(strict_types = 1);

if (PHP_VERSION_ID < 70100) {
    try {
        \dd_trace_send_traces_via_thread(0);
    } catch (TypeError $e) {
        echo "OK1\n";
    }

    try {
        \dd_trace_send_traces_via_thread(0, ["foo"]);
    } catch (TypeError $e) {
        echo "OK2\n";
    }
} else {
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
