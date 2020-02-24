--TEST--
E_ERROR fatal errors are tracked from internal function
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
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
    escapeshellcmd("\0");
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

%s escapeshellcmd(): Input string contains NULL bytes in %s on line %d
Shutdown
main()
E_ERROR
escapeshellcmd(): Input string contains NULL bytes
#0 %s(%d): escapeshellcmd(...)
#1 %s(%d): makeFatalError()
#2 %s(%d): main()
#3 {main}
array_sum()
