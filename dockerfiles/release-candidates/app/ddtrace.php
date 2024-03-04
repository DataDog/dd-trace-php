<?php

define('DD_BASE_URL', 'http://' . ('fpm-fcgi' === PHP_SAPI ? 'nginx' : 'localhost'));

function instrumentMethod($class, $method) {
    if (!function_exists('DDTrace\\trace_method')) {
        echo PHP_EOL . 'WARNING! ddtrace does not appear to be installed.' . PHP_EOL . PHP_EOL;
        return;
    }
    DDTrace\trace_method($class, $method, function ($s) {
        $s->service = getenv('DD_SERVICE');
        $s->type = 'web';
    });
}
