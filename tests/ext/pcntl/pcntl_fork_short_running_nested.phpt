--TEST--
Short running nested forks
--SKIPIF--
<?php if (!extension_loaded('pcntl')) die('skip: pcntl extension required'); ?>
--FILE--
<?php

require 'functions.inc';

call_httpbin();

$forkPid = pcntl_fork();

if ($forkPid < 0) {
    // Error
    exit(-1);
} elseif ($forkPid == 0) {
    // Child
    call_httpbin();
    $forkPid = pcntl_fork();
    if ($forkPid < 0) {
        // Error
        exit(-1);
    } else if ($forkPid == 0) {
        // Child
        call_httpbin();
        $forkPid = pcntl_fork();
        if ($forkPid < 0) {
            // Error
            exit(-1);
        } elseif ($forkPid == 0) {
            // Child
            call_httpbin();
        } else {
            pcntl_waitpid($forkPid, $childStatus);
        }
    } else {
        pcntl_waitpid($forkPid, $childStatus);
    }
} else {
    call_httpbin();
    pcntl_waitpid($forkPid, $childStatus);
}

call_httpbin();

echo 'Done.' . PHP_EOL;
?>
--EXPECTF--
Done.
Done.
Done.
Done.
