--TEST--
E_ERROR fatal errors are tracked from hitting the max execution time
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--INI--
max_execution_time=1
--FILE--
<?php
register_shutdown_function(function () {
    echo 'Shutdown' . PHP_EOL;
    array_map(function($span) {
        echo $span['name'] . PHP_EOL;
        if (isset($span['error']) && $span['error'] === 1) {
            echo $span['meta']['error.type'] . PHP_EOL;
            echo $span['meta']['error.msg'] . PHP_EOL;
            echo $span['meta']['error.stack'] . PHP_EOL;
        }
    }, dd_trace_serialize_closed_spans());
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

dd_trace_function('main', function (DDTrace\SpanData $span) {
    $span->name = 'main()';
});

dd_trace_function('array_sum', function (DDTrace\SpanData $span) {
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
