<?php

function foobar () {
    gc_collect_cycles();
}

include(__DIR__.'/timeline_call.php');

// Give the eval compilation event enough duration to survive percentage rounding
// in the correctness analyzer on older PHP versions and fast CI runners.
eval(str_repeat('$timelineEvalValue = 1;', 512) . 'usleep(1);');
