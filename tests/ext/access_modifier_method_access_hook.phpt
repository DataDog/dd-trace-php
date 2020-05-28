--TEST--
Check object's private and protected methods can be invoked from a callback.
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die("skip: requires dd_trace support"); ?>
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

dd_trace('Foo', 'aPublic', function() {
    $this->getBar()->dPublic();
    var_dump($this->bProtected());
    var_dump($this->cPrivate());
    var_dump(dd_trace_forward_call());
});

dd_trace('Bar', 'dPublic', function() {
    var_dump($this->eProtected());
    var_dump($this->fPrivate());
    var_dump(dd_trace_forward_call());
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
