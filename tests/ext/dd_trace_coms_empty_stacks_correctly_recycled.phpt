--TEST--
Coms test no memory leaks with empty data store
--FILE--
<?php
// valgrind test should catch any memory leaks

for($i = 0; $i < 999; $i++) {
    dd_trace_internal_fn('test_msgpack_consumer');
}
echo 'true' . PHP_EOL;
?>
--EXPECT--
true
