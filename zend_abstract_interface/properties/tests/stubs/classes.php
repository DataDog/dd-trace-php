<?php

namespace zai\properties\test;

class Super {
    private $superPrivate = "superPrivate";
    private $private = "private";
    private $dynamicPrivate = "dynamicPrivate";
    protected $superProtected = "superProtected";
    protected $protected = "protected";
    public $public = "public";
}

class Child extends Super {
    private $private = "private from child";
    public $protected = "protected from child";
}

// easy access functions
function super() {
    return new Super;
}

function child() {
    $dyn = new Child;
    $dyn->dynamicPrivate = "dynamicPrivate from child";
    return $dyn;
}
