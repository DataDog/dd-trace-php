--TEST--
Short running multiple forks
--FILE--
<?php

require 'functions.inc';

const NUMBER_OF_CHILDREN = 5;

call_httpbin();

$currentIteration = 0;
while ($currentIteration !== NUMBER_OF_CHILDREN) {
    $forkPid = pcntl_fork();
    if ($forkPid > 0) {
        // Main
        call_httpbin();
    } else if ($forkPid === 0) {
        // Child
        call_httpbin();
        exit(0);
    } else {
        exit(-1);
    }

    $currentIteration++;
}

pcntl_wait($childStatus);

call_httpbin();
?>
--EXPECTF--
