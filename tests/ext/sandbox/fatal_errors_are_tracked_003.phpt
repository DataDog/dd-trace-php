--TEST--
E_ERROR fatal errors are tracked from hitting the max execution time
--SKIPIF--
<?php if (getenv('USE_ZEND_ALLOC') === '0' && !getenv("SKIP_ASAN")) die('skip timing sensitive test - valgrind is too slow'); ?>
--ENV--
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=array_sum
--INI--
max_execution_time=1
fatal_error_backtraces=0
--FILE--
<?php
register_shutdown_function(function () {
    echo 'Shutdown' . PHP_EOL;
    foreach (dd_trace_serialize_closed_spans() as $span) {
        echo $span['name'] . PHP_EOL;
        if (isset($span['error']) && $span['error'] === 1) {
            echo $span['meta']['error.type'] . PHP_EOL;
            echo $span['meta']['error.message'] . PHP_EOL;
            echo $span['meta']['error.stack'] . PHP_EOL;
        }
    }
});

// make sure args are elided
function makeFatalError($return) {
    // Trigger a fatal error (hit the max execution time)
    while(1) {}
    return $return;
}

function main() {
    var_dump(array_sum([1, 99]));
    makeFatalError(42);
    echo 'You should not see this.' . PHP_EOL;
}

DDTrace\trace_function('main', function (DDTrace\SpanData $span) {
    $span->name = 'main()';
});

DDTrace\trace_function('array_sum', function (DDTrace\SpanData $span) {
    $span->name = 'array_sum()';
});

main();
?>
--EXPECTF--
int(100)

%s Maximum execution time of 1 second exceeded in %s on line %d
Shutdown
main()
E_ERROR
Maximum execution time of 1 second exceeded
#0 %s(%d): makeFatalError()
#1 %s(%d): main()
#2 {main}
array_sum()
