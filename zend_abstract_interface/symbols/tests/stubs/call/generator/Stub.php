<?php

function generator() {
    yield;
}

class GeneratorRebindTarget {
    private $foo = true;
}

class GeneratorGetter {
    private $foo = false;

    function closure() {
        return function() {
            yield $this->foo;
        };
    }
}