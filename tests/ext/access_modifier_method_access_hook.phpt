--TEST--
Check object's private and protected methods can be invoked from a callback.
--FILE--
<?php
class Test
{
    public function m()
    {
        echo "METHOD" . PHP_EOL;
    }

    protected function protected_method()
    {
        echo "PROTECTED METHOD" . PHP_EOL;
    }

    private function private_method()
    {
        echo "PRIVATE METHOD" . PHP_EOL;
    }
}

dd_trace("Test", "m", function() {
    $this->m();
    $this->protected_method();
    $this->private_method();
});

(new Test())->m();

?>
--EXPECT--
METHOD
PROTECTED METHOD
PRIVATE METHOD
