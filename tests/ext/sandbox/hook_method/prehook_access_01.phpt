--TEST--
hook_method prehook should have access only to public members
--INI--
zend.assertions=1
assert.exception=1
--FILE--
<?php

DDTrace\hook_method('App', 'run',
    function ($app) {
        echo "App::run hooked.\n";
        assert(!is_callable([$app, 'emit']));
    }
);


final class App
{
    public function __construct()
    {
    }

    public function run()
    {
        $this->emit(__METHOD__ . "\n");
    }

    private function emit($message)
    {
        echo $message;
    }
}

$app = new App();
$app->run();

?>
--EXPECT--
App::run hooked.
App::run
