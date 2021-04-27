--TEST--
hook_function posthook should have access only to public members
--INI--
zend.assertions=1
assert.exception=1
--FILE--
<?php

DDTrace\hook_function('main',
    null,
    function ($args) {
        echo "main hooked.\n";
        assert(!is_callable([$args[0], 'emit']));
    }
);


final class App
{
    public function __construct()
    {
    }

    public function run()
    {
        $this->emit(__METHOD__ . ".\n");
    }

    private function emit($message)
    {
        echo $message;
    }
}

function main(App $app)
{
    $app->run();
    echo __FUNCTION__, ".\n";
}

$app = new App();
main($app);
?>
--EXPECT--
App::run.
main.
main hooked.
