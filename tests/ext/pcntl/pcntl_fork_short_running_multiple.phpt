--TEST--
Short running multiple forks
--SKIPIF--
<?php if (!extension_loaded('pcntl')) die('skip: pcntl extension required'); ?>
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
        echo 'Done.' . PHP_EOL;
        exit(0);
    } else {
        exit(-1);
    }

    $currentIteration++;
}

pcntl_wait($childStatus);

call_httpbin();
echo 'Done.' . PHP_EOL;
?>
--EXPECTF--
Done.
Done.
Done.
Done.
Done.
Done.
