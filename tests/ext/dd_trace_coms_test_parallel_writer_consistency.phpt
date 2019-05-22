--TEST--
Coms test parallel writer consistency
--FILE--
<?php
// store two packets separately
    echo (dd_trace_internal_fn('flush_data', 'foo') ? 'true' : 'false') . PHP_EOL; // true if success writing 3 + 8 bytes on 64 bit platform
    echo (dd_trace_internal_fn('flush_data', 'bar') ? 'true' : 'false') . PHP_EOL; // ...
    echo (dd_trace_internal_fn('flush_data', '') ? 'true' : 'false') . PHP_EOL; // 0 length write is not written

// store multiple data concurrently
    dd_trace_internal_fn('test_writers');

// read and parse stored data for correctness - prints out all data packets that do not match written pattern "0123456789"
    dd_trace_internal_fn('test_consumer');
    dd_trace_internal_fn('flush_data', 'a'); // write to a new stack - 1 byte of data + 8 byte size
// prints out previous "foobar" data and an new 1 byte stack
    dd_trace_internal_fn('test_consumer');
// prints out all previous data and newly allocated empty stack
    dd_trace_internal_fn('test_consumer');
// prints out all previously allocated data but newly allocated stack gets recycles since its empty
    dd_trace_internal_fn('test_consumer');
?>
--EXPECT--
true
true
false
written 3600000
foo
bar
bytes_written 3600022
foo
bar
bytes_written 3600022
a
bytes_written 9
foo
bar
bytes_written 3600022
a
bytes_written 9
bytes_written 0
foo
bar
bytes_written 3600022
a
bytes_written 9
