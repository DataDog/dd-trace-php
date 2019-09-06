--TEST--
[Sandbox regression] Private and protected methods are called from a tracing closure
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--FILE--
<?php
class Foo
{
    public function getBar()
    {
        return new Bar();
    }

    public function aPublic()
    {
        return 'Foo PUBLIC';
    }

    protected function bProtected()
    {
        return 'Foo PROTECTED';
    }

    private function cPrivate()
    {
        return 'Foo PRIVATE';
    }
}

class Bar
{
    public function dPublic()
    {
        return 'Bar PUBLIC';
    }

    protected function eProtected()
    {
        return 'Bar PROTECTED';
    }

    private function fPrivate()
    {
        return 'Bar PRIVATE';
    }
}

dd_trace_method('Foo', 'aPublic', function($span, array $args, $retval) {
    $this->getBar()->dPublic();
    var_dump($this->bProtected());
    var_dump($this->cPrivate());
    var_dump($retval);
});

dd_trace_method('Bar', 'dPublic', function($span, array $args, $retval) {
    var_dump($this->eProtected());
    var_dump($this->fPrivate());
    var_dump($retval);
});

(new Foo())->aPublic();

?>
--EXPECT--
string(13) "Bar PROTECTED"
string(11) "Bar PRIVATE"
string(10) "Bar PUBLIC"
string(13) "Foo PROTECTED"
string(11) "Foo PRIVATE"
string(10) "Foo PUBLIC"
