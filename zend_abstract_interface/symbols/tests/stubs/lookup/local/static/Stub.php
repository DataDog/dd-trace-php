<?php
namespace DDTraceTesting;

class Stub {

    public static function scalar() {
        static $var;

        if (!$var) {
            $var = 42;
        }
    }

    public static function refcounted() {
        static $var;

        if (!$var) {
            $var = new self();
        }
    }

    public static function reference() {
        static $var, $bar;

        if (!$bar) {
            $bar = new self();
        }
 
        if (!$var) {
            /* will not survive call boundary */
            $var = &$bar;
        }
    }
}
?>
