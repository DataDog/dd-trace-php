--TEST--
Coms test messagepack payloads are concatendated and serialized correctly
--FILE--
<?php

dd_trace_internal_fn('flush_span', 0, "a");
dd_trace_internal_fn('flush_span', 1, "b");
dd_trace_internal_fn('flush_span', 3, "c");
dd_trace_internal_fn('flush_span', 0, "b");
dd_trace_internal_fn('flush_span', 2, "b");
dd_trace_internal_fn('flush_span', 0, "c");
dd_trace_internal_fn('flush_span', 0, "d");
dd_trace_internal_fn('flush_span', 0, "e");
dd_trace_internal_fn('flush_span', 0, "f");
dd_trace_internal_fn('flush_span', 0, "0");
dd_trace_internal_fn('flush_span', 0, "1");
dd_trace_internal_fn('flush_span', 0, "2");
dd_trace_internal_fn('flush_span', 0, "3");
dd_trace_internal_fn('flush_span', 0, "4");
dd_trace_internal_fn('flush_span', 0, "5");
dd_trace_internal_fn('flush_span', 0, "6");
dd_trace_internal_fn('flush_span', 0, "7");
dd_trace_internal_fn('flush_span', 0, "8");
dd_trace_internal_fn('flush_span', 0, "9");
dd_trace_internal_fn('flush_span', 4, ".");
dd_trace_internal_fn('flush_span', 5, ".");
dd_trace_internal_fn('flush_span', 6, ".");
dd_trace_internal_fn('flush_span', 7, ".");
dd_trace_internal_fn('flush_span', 8, ".");
dd_trace_internal_fn('flush_span', 9, ".");
dd_trace_internal_fn('flush_span', 10, ".");
dd_trace_internal_fn('flush_span', 11, ".");
dd_trace_internal_fn('flush_span', 12, ".");
dd_trace_internal_fn('flush_span', 13, ".");
dd_trace_internal_fn('flush_span', 14, ".");
dd_trace_internal_fn('flush_span', 15, ""); // ignore empty

dd_trace_internal_fn('test_msgpack_consumer');
?>
--EXPECT--
9F DC 00 10 abcdef0123456789 91 b 91 c 91 b 91 . 91 . 91 . 91 . 91 . 91 . 91 . 91 . 91 . 91 . 91 .
