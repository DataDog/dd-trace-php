--TEST--
[Prehook regression] Private and protected methods are called from a tracing closure
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip: Prehook not supported on PHP 5'); ?>
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
        echo 'Foo PUBLIC' . PHP_EOL;
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
        echo 'Bar PUBLIC' . PHP_EOL;
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

DDTrace\trace_method('Foo', 'aPublic', ['prehook' => function() {
    $this->getBar()->dPublic();
    var_dump($this->bProtected());
    var_dump($this->cPrivate());
}]);

DDTrace\trace_method('Bar', 'dPublic', ['prehook' => function() {
    var_dump($this->eProtected());
    var_dump($this->fPrivate());
}]);

(new Foo())->aPublic();
?>
--EXPECT--
string(13) "Bar PROTECTED"
string(11) "Bar PRIVATE"
Bar PUBLIC
string(13) "Foo PROTECTED"
string(11) "Foo PRIVATE"
Foo PUBLIC
