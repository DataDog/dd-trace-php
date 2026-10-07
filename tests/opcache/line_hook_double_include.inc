<?php

if (!function_exists('di_target')) {
    function di_target($n) {
        $inner = $n + 10;
        return $inner;
    }
}
