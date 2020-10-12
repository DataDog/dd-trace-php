--TEST--
[Sandbox regression] Trace private method
--FILE--
<?php
class Test
{
    public function m(){
        echo "METHOD" . PHP_EOL;
        $this->private_method();
    }

    private function private_method()
    {
        echo "PRIVATE METHOD" . PHP_EOL;
    }
}

DDTrace\trace_method('Test', "private_method", function() {
    echo "PRIVATE HOOK" . PHP_EOL;
});

(new Test())->m();
?>
--EXPECT--
METHOD
PRIVATE METHOD
PRIVATE HOOK
