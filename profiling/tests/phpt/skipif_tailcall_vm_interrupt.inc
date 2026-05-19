<?php
if (
    PHP_VERSION_ID >= 80500 &&
    PHP_VERSION_ID < 80507 &&
    defined('ZEND_VM_KIND') &&
    ZEND_VM_KIND === 'ZEND_VM_KIND_TAILCALL'
) {
    die("skip: profiler is disabled on PHP 8.5.0-8.5.6 with the tailcall VM kind\n");
}
