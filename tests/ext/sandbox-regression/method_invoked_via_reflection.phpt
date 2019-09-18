--TEST--
[Sandbox regression] Method invoked via reflection correctly returns created object
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--FILE--
<?php
class Test {
    public function __construct($append = "") {
        $this->append = $append;
        echo "CONSTRUCT" . $this->append . PHP_EOL;
    }

    public function method($append = "") {
        return "METHOD" . $append . $this->append . PHP_EOL;
    }

    public function create($append = ""){
        return new Test($append);
    }
}

dd_trace_method("Test", "__construct", function () {
    echo "HOOK CONSTRUCT" . $this->append . PHP_EOL;
});

$reflectionMethod = new ReflectionMethod('Test', 'method');
$reflectionCreate = new ReflectionMethod('Test', 'create');

$a = new Test();
echo $a->method();
echo $reflectionMethod->invoke($a, " called via reflection");

$obj = $reflectionCreate->invoke($a, " created via reflection");
echo $obj->method();

?>
--EXPECT--
CONSTRUCT
HOOK CONSTRUCT
METHOD
METHOD called via reflection
CONSTRUCT created via reflection
HOOK CONSTRUCT created via reflection
METHOD created via reflection
