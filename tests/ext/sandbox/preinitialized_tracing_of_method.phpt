--TEST--
[Prehook regression] Trace public static method
--ENV--
_DD_LOAD_TEST_INTEGRATIONS=1
--FILE--
<?php
use DDTrace\SpanData;

class Test
{
    public function automaticaly_traced_method()
    {
        echo "AUTOMATICALY_TRACED_METHOD" . PHP_EOL;
        return true;
    }
}

function tracing_function(SpanData $span, array $args) {
    echo "TRACING_FUNCTION" . PHP_EOL;
    return true;
}

(new Test())->automaticaly_traced_method();
?>
--EXPECT--
AUTOMATICALY_TRACED_METHOD
TRACING_FUNCTION

