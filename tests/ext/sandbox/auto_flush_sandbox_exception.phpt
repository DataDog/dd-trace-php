--TEST--
Auto-flushing will sandbox an exception thrown from the tracer flush
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip: PHP 5.4 not supported'); ?>
<?php if (PHP_VERSION_ID < 70000) die('skip: Auto flushing not supported on PHP 5'); ?>
--ENV--
DD_TRACE_DEBUG=1
DD_TRACE_AUTO_FLUSH_ENABLED=1
--FILE--
<?php
use DDTrace\SpanData;

require 'fake_tracer_exception.inc';
require 'fake_global_tracer.inc';

class Foo
{
    public function bar()
    {
        $this->doException();
    }

    private function doException()
    {
        throw new Exception('Oops!');
    }
}

DDTrace\trace_method('Foo', 'bar', function (SpanData $span) {
    $span->name = 'Foo.bar';
});

$foo = new Foo();
try {
    $foo->bar();
} catch (Exception $e) {
    echo 'Caught exception: ' . $e->getMessage() . PHP_EOL;
}
?>
--EXPECT--
Flushing tracer with exception...
Unable to auto flush the tracer
Caught exception: Oops!
