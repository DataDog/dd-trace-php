<?php

// Only the worker submits an observation. The main thread must not rely on
// its own evaluation history to flush the sidecar's shared coalescer.
$runtime = new \parallel\Runtime();
$channel = \parallel\Channel::make('ffe-shutdown-barrier');
$future = $runtime->run(function ($channel) {
    if (!\DDTrace\Internal\record_ffe_flag_evaluation(
        'flush-flag', 'blue', null, 'worker-subject', array(), null, false, false, 1700000000123
    )) throw new RuntimeException('worker submission rejected');
    if ($channel->recv() === 'flush') dd_trace_synchronous_flush();
    return true;
}, array($channel));

// The capture server releases this only after it is holding an HTTP send.
if (trim(fgets(STDIN)) !== 'flush') throw new RuntimeException('missing flush barrier');
$mode = getenv('PHP_FFE_FLUSH_MODE');
$channel->send($mode === 'worker' ? 'flush' : 'finish');
if (!$future->value()) throw new RuntimeException('worker did not finish');
$runtime->close();
switch ($mode) {
    case 'explicit':
        dd_trace_synchronous_flush();
        break;
    case 'internal':
        dd_trace_internal_fn('synchronous_flush');
        break;
    case 'shutdown':
        exit(0);
}
echo "returned\n";
