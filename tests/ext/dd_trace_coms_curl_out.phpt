--TEST--
Coms test parallel writer consistency
--FILE--
<?php
// store two packets separately
    echo (dd_trace_internal_fn('flush_data', 'foo') ? 'true' : 'false') . PHP_EOL; // true if success writing 3 + 8 bytes on 64 bit platform

    dd_trace_internal_fn('curl_ze_data_out');
?>
--EXPECT--
true
