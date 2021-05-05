--TEST--
Short running single fork
--SKIPIF--
<?php
include __DIR__ . '/../includes/skipif_no_dev_env.inc';
if (!extension_loaded('pcntl')) die('skip: pcntl extension required');
if (!extension_loaded('curl')) die('skip: curl extension required');
?>
--FILE--
<?php

require 'functions.inc';

$forkPid = pcntl_fork();
echo "Forked PID: $forkPid\n";

call_httpbin();

if ($forkPid > 0) {
    // Main
    call_httpbin();
    pcntl_wait($childStatus);
    call_httpbin();
} else {
    // Child
    call_httpbin();
}

echo "Done PID: $forkPid\n";

?>
--EXPECTF--
Forked PID: %d
Forked PID: %d
Done PID: %d
Done PID: %d
