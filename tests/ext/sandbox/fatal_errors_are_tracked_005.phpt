--TEST--
All open internal spans are marked with the fatal error
--ENV--
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=array_sum,DDTrace\Testing\trigger_error
--SKIPIF--
<?php
    if (PHP_VERSION_ID < 50500) die("skip: PHP 5.4 does not support close-at-exit functionality");
?>
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

function main()
{
    var_dump(array_sum([1, 99]));
    DDTrace\Testing\trigger_error('My foo error', E_USER_ERROR);
    echo 'You should not see this.' . PHP_EOL;
}

// let's test defaults
DDTrace\trace_function('main', function (DDTrace\SpanData $span) {});
DDTrace\trace_function('array_sum', function (DDTrace\SpanData $span) {});
DDTrace\trace_function('DDTrace\\Testing\\trigger_error', function (DDTrace\SpanData $span) {});

main();
?>
--EXPECTF--
int(100)

%s My foo error in %s on line %d
Shutdown
main
E_USER_ERROR
My foo error
#0 %s(%d): DDTrace\Testing\trigger_error()
#1 %s(%d): main()
#2 {main}
DDTrace\Testing\trigger_error
E_USER_ERROR
My foo error
#0 %s(%d): DDTrace\Testing\trigger_error()
#1 %s(%d): main()
#2 {main}
array_sum
