--TEST--
A PHP request timeout does not leak/segfault (run with leak detection)
--ENV--
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=array_sum
--INI--
max_execution_time=1
--FILE--
<?php
register_shutdown_function(function () {
    echo 'Shutdown' . PHP_EOL;
});

function makeFatalError() {
    // Trigger a fatal error (hit the max execution time)
    while(1) {}
    return 42;
}

function main() {
    var_dump(array_sum([1, 99]));
    makeFatalError();
    echo 'You should not see this.' . PHP_EOL;
}

main();
?>
--EXPECTF--
int(100)

%s Maximum execution time of 1 second exceeded in %s on line %d
Shutdown
