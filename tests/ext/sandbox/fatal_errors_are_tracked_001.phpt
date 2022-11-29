--TEST--
E_ERROR fatal errors are tracked from internal function
--ENV--
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=array_sum
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


function main() {
    var_dump(array_sum([1, 99]));
    DDTrace\Testing\trigger_error("generated for testing", E_ERROR);
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

Fatal error: generated for testing in %s on line %d
Shutdown
main()
E_ERROR
generated for testing
#0 %s(%d): DDTrace\Testing\trigger_error()
#1 %s(%d): main()
#2 {main}
array_sum()
