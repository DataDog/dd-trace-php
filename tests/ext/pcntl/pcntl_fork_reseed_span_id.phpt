--TEST--
Short running multiple forks
--SKIPIF--
<?php
include __DIR__ . '/../includes/skipif_no_dev_env.inc';
if (!extension_loaded('pcntl')) die('skip: pcntl extension required');
if (!extension_loaded('curl')) die('skip: curl extension required');
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=false
DD_TRACE_DEBUG=false
DD_TRACE_DEBUG_PRNG_SEED=1
--FILE--
<?php

require 'functions.inc';

const NUMBER_OF_CHILDREN = 5;

call_httpbin();
DDTrace\start_span();
echo DDTrace\active_span()->id . ' - parent' . PHP_EOL;

$currentIteration = 0;
while ($currentIteration !== NUMBER_OF_CHILDREN) {
    DDTrace\start_span();
    echo DDTrace\active_span()->id . ' - parent' . PHP_EOL;
    $forkPid = pcntl_fork();
    if ($forkPid > 0) {
        // Main
        call_httpbin();
    } else if ($forkPid === 0) {
        // Child
        DDTrace\start_span();
        echo DDTrace\active_span()->id . ' - child' . PHP_EOL;
        call_httpbin();
        DDTrace\close_span();
        echo 'Done.' . PHP_EOL;
        exit(0);
    } else {
        exit(-1);
    }
    pcntl_wait($childStatus);
    $currentIteration++;
    DDTrace\close_span();
}

pcntl_wait($childStatus);
DDTrace\close_span();

call_httpbin();
echo 'Done.' . PHP_EOL;
?>
--EXPECTF--
2469588189546311528 - parent
2516265689700432462 - parent
2469588189546311528 - child
Done.
387828560950575246 - parent
2469588189546311528 - child
Done.
6472927700900931384 - parent
2469588189546311528 - child
Done.
16811588669333006409 - parent
2469588189546311528 - child
Done.
8683844110200328628 - parent
2469588189546311528 - child
Done.
Done.
