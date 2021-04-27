--TEST--
hook_function posthook should ignore limited mode
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_SPANS_LIMIT=1
--INI--
zend.assertions=1
assert.exception=1
--FILE--
<?php

DDTrace\trace_function('main',
    function ($span, $args) {
        $span->name = $span->resource = 'main';
        $span->service = 'phpt';
        echo "main traced.\n";
    }
);

foreach (['control', 'route', 'view'] as $target) {
    DDTrace\hook_function($target,
        null,
        function ($args) use ($target) {
            echo "{$target} hooked.\n";
        }
    );
}

function view() {
    echo __FUNCTION__, ".\n";
}

function control() {
    echo __FUNCTION__, ".\n";
    view();
}

function route() {
    echo __FUNCTION__, ".\n";
    return 'control';
}

function main()
{
    echo __FUNCTION__, ".\n";
    $control = route();
    $control();
}

main();
?>
--EXPECT--
main.
route.
route hooked.
control.
view.
view hooked.
control hooked.
main traced.
