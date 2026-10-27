<?php

function foobar () {
    gc_collect_cycles();
}

include(__DIR__.'/timeline_call.php');

// Keep eval compilation above the analyzer's 1% floor despite sleep jitter.
eval(str_repeat('$unused = 1;', 100) . 'usleep(1);');
