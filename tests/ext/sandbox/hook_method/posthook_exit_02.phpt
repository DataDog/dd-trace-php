--TEST--
DDTrace\hook_method posthook is called at exit (shutdown handler)
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

DDTrace\hook_method('Controller', 'main',
    null,
    function ($This, $scope, $args, $retval) {
        echo "{$scope}::main hooked.\n";
        assert($retval === null);
    });

final class View
{
    public static function render($name)
    {
        echo "Hello, {$name}.\n";
        exit();
    }
}

final class Model
{
    public static function create($name)
    {
        return $name;
    }
}

final class Controller
{
    public static function main()
    {
        $model = Model::create('Datadog');
        View::render($model);
    }
}

Controller::main();

?>
--EXPECT--
Hello, Datadog.
Controller::main hooked.
Shutting down.
