--TEST--
Check private method can be overwritten and we are able to call original.
--ENV--
DD_TRACE_WARN_LEGACY_DD_TRACE=0
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

dd_trace('Test', "private_method", function() {
    $this->private_method();
    echo "PRIVATE HOOK" . PHP_EOL;
});

(new Test())->m();
?>
--EXPECT--
METHOD
PRIVATE METHOD
PRIVATE HOOK
