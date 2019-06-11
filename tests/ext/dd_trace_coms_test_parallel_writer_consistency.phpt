--TEST--
Coms test parallel writer consistency
--FILE--
<?php
// store two packets separately
    echo (dd_trace_internal_fn('ddtrace_coms_buffer_data', 0, 'foo') ? 'true' : 'false') . PHP_EOL; // true if success writing 3 + 12 bytes on 64 bit platform
    echo (dd_trace_internal_fn('ddtrace_coms_buffer_data', 0, 'bar') ? 'true' : 'false') . PHP_EOL; // ...
    echo (dd_trace_internal_fn('ddtrace_coms_buffer_data', 0, '') ? 'true' : 'false') . PHP_EOL; // 0 length write is not written

// store multiple data concurrently
    dd_trace_internal_fn('test_writers'); // will write 4400000 bytes

// total bytes written at this point 4400030

// read and parse stored data for correctness - prints out all data packets that do not match written pattern "0123456789"
    dd_trace_internal_fn('test_consumer');
    dd_trace_internal_fn('ddtrace_coms_buffer_data', 0, 'a'); // write to a new stack - 1 byte of data + 12 byte size of metadata
// prints out previous "foobar" data and an new 1 byte stack
    echo "----" . PHP_EOL;
    dd_trace_internal_fn('test_consumer');
// prints out all previous data and newly allocated empty stack
    echo "----" . PHP_EOL;
    dd_trace_internal_fn('test_consumer');
    echo "----" . PHP_EOL;
// prints out all previously allocated data but newly allocated stack gets recycles since its empty
    dd_trace_internal_fn('test_consumer');
?>
--EXPECT--
true
true
false
written 3600000
----
foo
bar
bytes_written 4400030
----
foo
bar
bytes_written 4400030
a
bytes_written 13
----
foo
bar
bytes_written 4400030
a
bytes_written 13
foo
bar
bytes_written 4400030
a
bytes_written 13
