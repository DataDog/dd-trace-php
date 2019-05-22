--TEST--
Coms test parallel writer consistency
--FILE--
<?php
// store two packets separately
    echo (dd_trace_internal_fn('flush_data', 'foo') ? 'true' : 'false') . PHP_EOL; // true if success writing 3 + 8 bytes on 64 bit platform
    echo (dd_trace_internal_fn('flush_data', 'bar') ? 'true' : 'false') . PHP_EOL; // ...

// store multiple data concurrently
    dd_trace_internal_fn('test_writers');

// read and parse stored data for correctness - prints out all data packets that do not match written pattern "0123456789"
    dd_trace_internal_fn('test_consumer');
?>
--EXPECT--
true
true
written 3600000
foo
bar
bytes_written 3600022
