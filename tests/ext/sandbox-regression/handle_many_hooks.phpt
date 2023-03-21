--TEST--
The tracer should not crash when many hooks are installed
--ENV--
DD_TRACE_HOOK_LIMIT=0
--FILE--
<?php

$spanId = [];
for ($i = 1; $i <= 256; ++$i) {
    DDTrace\trace_function("test", function ($span) use ($i, &$spanId, &$run) {
        $run += $i;
        $spanId[] = $span->id;
    });
}

function test() {
    echo "test\n";
}

test();
var_dump($run);
echo "Unique Spans: "; var_dump(count(array_unique($spanId)));

?>
--EXPECT--
test
int(32896)
Unique Spans: int(1)
