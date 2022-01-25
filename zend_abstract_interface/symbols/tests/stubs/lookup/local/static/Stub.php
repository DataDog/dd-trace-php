<?php
namespace DDTraceTesting;

class Stub {

    public static function scalar() {
        self::target(42);
    }

    public static function refcounted() {
        self::target(new self());
    }

    public static function reference() {
        static $var;

        $var = new self();

        self::targetWithReference($var);
    }

    public static function target($value) {
        static $var;

        $var = $value;
    }

    public static function targetWithReference(&$value) {
        static $var;

        $var = &$value;
    }
}
?>
