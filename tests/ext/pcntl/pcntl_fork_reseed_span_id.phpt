--TEST--
Short running multiple forks
--SKIPIF--
<?php if (!extension_loaded('pcntl')) die('skip: pcntl extension required'); ?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=false
DD_TRACE_DEBUG=false
DD_TRACE_DEBUG_PRNG_SEED=100
--FILE--
<?php

require 'functions.inc';

const NUMBER_OF_CHILDREN = 5;

call_httpbin();
DDTrace\start_span();
echo DDTrace\active_span()->id . ' - parent' . PHP_EOL;

$currentIteration = 1;
while ($currentIteration <= NUMBER_OF_CHILDREN) {
    DDTrace\start_span();
    echo DDTrace\active_span()->id . ' - parent' . PHP_EOL;
    ini_set("datadog.trace.debug_prng_seed", $currentIteration);
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
--EXPECT--
17952737041105130042 - parent
2376730765988821965 - parent
2469588189546311528 - child
Done.
9150857099769749596 - parent
16668552215174154828 - child
Done.
12758643431150887824 - parent
10307413207671831467 - child
Done.
16772453002033882614 - parent
14490808261858112199 - child
Done.
3392054037201815393 - parent
12415856028556828342 - child
Done.
Done.
