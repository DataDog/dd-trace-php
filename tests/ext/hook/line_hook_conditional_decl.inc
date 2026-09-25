<?php

if (!function_exists('conditional_target')) {
    function conditional_target($n) {
        $inner = $n + 10;
        return $inner;
    }
}
