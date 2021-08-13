--TEST--
Auto-flushing will attach an exception during exception cleanup
--DESCRIPTION--
@see https://github.com/DataDog/dd-trace-php/issues/879
--SKIPIF--
<?php if (PHP_VERSION_ID >= 70000) die('skip: Test does not work with internal spans'); ?>
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=1
--FILE--
<?php
use DDTrace\SpanData;

require __DIR__ . '/../includes/fake_tracer.inc';
require __DIR__ . '/../includes/fake_global_tracer.inc';

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
Flushing tracer...
Foo.bar (Foo.bar) (error: Oops!)
Tracer reset
Caught exception: Oops!
