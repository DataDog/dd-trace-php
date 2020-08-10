--TEST--
DDTrace\hook_function posthook is called at exit (shutdown handler)
--INI--
zend.assertions=1
assert.exception=1
ddtrace.request_init_hook=
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

function ddtrace_shutdown_handler()
{
    echo "Shutting down.\n";
    exit();
}

register_shutdown_function('ddtrace_shutdown_handler');

DDTrace\hook_function('main',
    null,
    function ($args, $retval) {
        echo "main hooked.\n";
        assert($retval === null);
    });

function view($name)
{
    echo "Hello, {$name}.\n";
    exit(0);
}

function model($name)
{
    return $name;
}

function main()
{
    $model = model('Datadog');
    view($model);
}

main();

?>
--EXPECT--
Hello, Datadog.
main hooked.
Shutting down.
