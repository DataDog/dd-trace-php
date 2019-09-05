--TEST--
[Sandbox regression] Trace private method
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
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

dd_trace_method('Test', "private_method", function() {
    echo "PRIVATE HOOK" . PHP_EOL;
});

(new Test())->m();
?>
--EXPECT--
METHOD
PRIVATE METHOD
PRIVATE HOOK
