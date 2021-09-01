--TEST--
Nested closure targeting method call 02 (posthook)
--DESCRIPTION--
In PHP 5 you cannot bind a static closure to an object. It's not always
obvious when a closure is automatically static.

In this one, we're making sure that a tracing closure still works when:
  - It is defined inside another closure that does have a scope.
  - It is targeting a method.
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip: Test requires internal spans'); ?>
--ENV--
DD_TRACE_DEBUG=1
--FILE--
<?php

final class Integration
{
    public function init()
    {
        /* calling hook_method from method scope, not global, is important for
         * this particular test
         */
        DDTrace\hook_method('App', '__construct',
            null,
            function () {
                DDTrace\trace_method('App', 'run',
                    function () {
                        echo "App::run traced.\n";
                    }
                );
                echo "App::__construct hooked.\n";
            }
        );
    }
}

final class App
{
    public function __construct()
    {
    }

    public function run()
    {
        echo __METHOD__, "\n";
    }
}

$integration = new Integration();
$integration->init();

$app = new App();
$app->run();

?>
--EXPECT--
App::__construct hooked.
App::run
App::run traced.
Successfully triggered flush with trace of size 2
