--TEST--
Method invoked via refloction correctly returning created object
--ENV--
DD_TRACE_WARN_LEGACY_DD_TRACE=0
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

dd_trace("Test", "__construct", function ($append = "") {
    $this->__construct($append);
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
