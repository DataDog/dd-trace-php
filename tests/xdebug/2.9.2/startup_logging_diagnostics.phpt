--TEST--
Startup logging diagnostics show incompatible module
--SKIPIF--
<?php if (PHP_VERSION_ID < 70100) die('skip: PHP 7.1+ required'); ?>
--INI--
error_log=/dev/null
--FILE--
<?php
if (!extension_loaded('Xdebug') || version_compare(phpversion('Xdebug'), '2.9.5') >= 0) die('Xdebug < 2.9.5 required');

$logs = json_decode(\DDTrace\startup_logs(), true);

if (!isset($logs['incompatible module xdebug'])) {
    echo 'Expected incompatible module diagnostic' . PHP_EOL;
    exit(1);
}

echo 'Log: ' . $logs['incompatible module xdebug'] . PHP_EOL;
?>
--EXPECT--
Log: Found incompatible Xdebug version 2.9.2; ddtrace requires Xdebug 2.9.5 or greater
