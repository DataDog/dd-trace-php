--TEST--
Check protected method can be overwritten and we are able to call original.
--FILE--
<?php

class Test
{

    // public $blah = "Fsfafasfas" ;
    function __construct()
    {
        // $this->blah = 1;
    }

    function __isset($isset){
        dd_trace_noop();
        return true;
    }

    function __get($name) {
        dd_trace_noop();
        echo "hello";

        return "!";
    }

    public function m()
    {
        echo "METHOD" . PHP_EOL;
        $this->protected_method();
    }

    protected function protected_method()
    {
        echo $this->blah . PHP_EOL;
        echo "PROTECTED METHOD" . PHP_EOL;
    }
}

dd_trace("Test", "protected_method", function(){
    $this->protected_method();
    echo "PROTECTED HOOK" . PHP_EOL;
});

(new Test())->m();

?>
--EXPECT--
METHOD
PROTECTED METHOD
PROTECTED HOOK
