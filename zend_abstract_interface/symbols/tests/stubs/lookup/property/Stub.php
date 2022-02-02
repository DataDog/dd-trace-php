<?php
namespace DDTraceTesting;

class Base {
    public static $publicStatic = 24;
    protected static $protectedStatic = 24;
    private static $privateStatic = 24;

    public $publicProperty = 24;
    protected $protectedProperty = 24;
    private $privateProperty = 24;
}

class Stub extends Base {

    public function __construct() {
        $this->dynamicProperty = 42;
    }

    public static $publicStatic = 42;
    protected static $protectedStatic = 42;
    private static $privateStatic = 42;

    public $publicProperty = 42;
    protected $protectedProperty = 42;
    private $privateProperty = 42;
}
?>
