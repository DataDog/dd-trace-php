<?php
namespace DDTraceTesting;

class Stub {

    public static function scalar() {
        $var = 42;

        \ddtrace_testing_frame_intercept();
    }

    public static function refcounted() {
        $var = new self();

        \ddtrace_testing_frame_intercept();
    }

    public static function reference() {
        $bar = new self();
 
        $var =& $bar;

        \ddtrace_testing_frame_intercept();
    }

    public static function param($var) {
        \ddtrace_testing_frame_intercept();
    }
}
?>
