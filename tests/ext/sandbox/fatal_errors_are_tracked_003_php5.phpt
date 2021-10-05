--TEST--
E_ERROR fatal errors are tracked from hitting the max execution time
--ENV--
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=array_sum
--INI--
max_execution_time=1
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die("skip: PHP 5.4 does not run posthooks on autoclose"); ?>
<?php if (PHP_MAJOR_VERSION !== 5) die("skip: This test is only for PHP 5"); ?>
--FILE--
<?php
register_shutdown_function(function () {
    echo 'Shutdown' . PHP_EOL;
    foreach (dd_trace_serialize_closed_spans() as $span) {
        echo $span['name'] . PHP_EOL;
        if (isset($span['error']) && $span['error'] === 1) {
            echo $span['meta']['error.type'] . PHP_EOL;
            echo $span['meta']['error.msg'] . PHP_EOL;
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
#1 %s(%d): makeFatalError()
#2 %s(%d): main()
#3 {main}
array_sum()
