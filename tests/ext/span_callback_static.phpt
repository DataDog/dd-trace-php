--TEST--
End callback using a static method
--ENV--
DD_TRACE_DEBUG=1
--FILE--
<?php

$counter = 0;

class MyCounter
{
    public static function increment()
    {
        global $counter;
        $counter++;
    }
}

$closure = Closure::fromCallable('MyCounter::increment');

$span = \DDTrace\start_span();
$span->endCallback = $closure;
\DDTrace\close_span();

var_dump($counter);

?>
--EXPECT--
