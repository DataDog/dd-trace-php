<?php

if (!\DDTrace\Internal\record_ffe_flag_evaluation(
    'flush-flag', 'blue', null, 'subject', array(), null, false, false, 1700000000123
)) throw new RuntimeException('submission rejected');

if (getenv('PHP_FFE_FLUSH_MODE') === 'fork') {
    $pid = pcntl_fork();
    if ($pid === -1) throw new RuntimeException('fork failed');
    if ($pid === 0) {
        if (!\DDTrace\Internal\record_ffe_flag_evaluation(
            'flush-child', 'blue', null, 'child-subject', array(), null, false, false, 1700000000123
        )) throw new RuntimeException('forked submission rejected');
        dd_trace_synchronous_flush();
        exit(0);
    }
}

// The parent waits for the actual HTTP request before releasing this barrier.
// Thus the test exercises a flush with an in-flight send, not a timer race.
if (trim(fgets(STDIN)) !== 'flush') throw new RuntimeException('missing flush barrier');
switch (getenv('PHP_FFE_FLUSH_MODE')) {
    case 'explicit':
    case 'fork':
        dd_trace_synchronous_flush();
        break;
    case 'internal':
        dd_trace_internal_fn('synchronous_flush');
        break;
    case 'shutdown':
        exit(0);
}
if (isset($pid)) {
    pcntl_waitpid($pid, $status);
    if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) throw new RuntimeException('forked child failed');
}
echo "returned\n";
