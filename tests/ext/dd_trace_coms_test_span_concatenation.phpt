--TEST--
Coms test messagepack payloads are concatendated and serialized correctly
--FILE--
<?php

dd_trace_internal_fn('ddtrace_coms_buffer_data', 0, "a");
dd_trace_internal_fn('ddtrace_coms_buffer_data', 1, "b");
dd_trace_internal_fn('ddtrace_coms_buffer_data', 3, "c");
dd_trace_internal_fn('ddtrace_coms_buffer_data', 0, "b");
dd_trace_internal_fn('ddtrace_coms_buffer_data', 2, "b");
dd_trace_internal_fn('ddtrace_coms_buffer_data', 0, "c");
dd_trace_internal_fn('ddtrace_coms_buffer_data', 0, "d");
dd_trace_internal_fn('ddtrace_coms_buffer_data', 0, "e");
dd_trace_internal_fn('ddtrace_coms_buffer_data', 0, "f");
dd_trace_internal_fn('ddtrace_coms_buffer_data', 0, "0");
dd_trace_internal_fn('ddtrace_coms_buffer_data', 0, "1");
dd_trace_internal_fn('ddtrace_coms_buffer_data', 0, "2");
dd_trace_internal_fn('ddtrace_coms_buffer_data', 0, "3");
dd_trace_internal_fn('ddtrace_coms_buffer_data', 0, "4");
dd_trace_internal_fn('ddtrace_coms_buffer_data', 0, "5");
dd_trace_internal_fn('ddtrace_coms_buffer_data', 0, "6");
dd_trace_internal_fn('ddtrace_coms_buffer_data', 0, "7");
dd_trace_internal_fn('ddtrace_coms_buffer_data', 0, "8");
dd_trace_internal_fn('ddtrace_coms_buffer_data', 0, "9");
dd_trace_internal_fn('ddtrace_coms_buffer_data', 4, ".");
dd_trace_internal_fn('ddtrace_coms_buffer_data', 5, ".");
dd_trace_internal_fn('ddtrace_coms_buffer_data', 6, ".");
dd_trace_internal_fn('ddtrace_coms_buffer_data', 7, ".");
dd_trace_internal_fn('ddtrace_coms_buffer_data', 8, ".");
dd_trace_internal_fn('ddtrace_coms_buffer_data', 9, ".");
dd_trace_internal_fn('ddtrace_coms_buffer_data', 10, ".");
dd_trace_internal_fn('ddtrace_coms_buffer_data', 11, ".");
dd_trace_internal_fn('ddtrace_coms_buffer_data', 12, ".");
dd_trace_internal_fn('ddtrace_coms_buffer_data', 13, ".");
dd_trace_internal_fn('ddtrace_coms_buffer_data', 14, ".");
dd_trace_internal_fn('ddtrace_coms_buffer_data', 15, ""); // ignore empty

dd_trace_internal_fn('test_msgpack_consumer');
?>
--EXPECT--
9F DC 00 10 abcdef0123456789 91 b 91 c 91 b 91 . 91 . 91 . 91 . 91 . 91 . 91 . 91 . 91 . 91 . 91 .
