--TEST--
Methods invoked by the exception handler will get traced
--FILE--
<?php
class FooErrorHandler
{
    public static function handle(Exception $e)
    {
        echo "There was an error: \n";
        printf("    %s\n\n", $e->getMessage());
    }
}

dd_trace('FooErrorHandler', 'handle', function() {
    echo "**TRACED**\n";
    return dd_trace_forward_call();
});

set_exception_handler('FooErrorHandler::handle');

$e = new Exception('Oops! Foo error');

echo "Manual call:\n";
FooErrorHandler::handle($e);

echo "Handler call:\n";
throw $e;
?>
--EXPECT--
Manual call:
**TRACED**
There was an error:
    Oops! Foo error

Handler call:
**TRACED**
There was an error:
    Oops! Foo error
