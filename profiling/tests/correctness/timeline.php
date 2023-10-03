<?php

function foobar () {
    gc_collect_cycles();
}

include(__DIR__.'/timeline_call.php');

eval('usleep(1);');
