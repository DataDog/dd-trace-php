--TEST--
Short running nested forks
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
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

?>
--EXPECTF--
