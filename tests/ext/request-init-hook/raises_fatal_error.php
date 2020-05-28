<?php

/**
 * Since the SKIPIF section runs with all the same INI settings, this will
 * not skip on PHP 5.4 since fatal errors cannot be recovered.
 */
if (PHP_VERSION_ID < 50500) {
    die('skip: Cannot recover from fatal errors in PHP 5.4');
}

ddtrace_init(__DIR__ . '/raises-fatal-error');
