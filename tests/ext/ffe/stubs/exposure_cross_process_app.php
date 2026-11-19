<?php

// The parent and a forked child act as two PHP-FPM workers of one sidecar.
// Each exposure waits until the test received the previous one, so the
// sidecar sees them in order.
function expose($step, $allocation) {
    DDTrace\Testing\ffe_load_config(json_encode(array(
        'createdAt' => '2024-01-01T00:00:00Z',
        'environment' => array('name' => 'test'),
        'flags' => array('flag' => array(
            'key' => 'flag', 'enabled' => true, 'variationType' => 'STRING',
            'variations' => array('on' => array('key' => 'on', 'value' => 'on')),
            'allocations' => array(array(
                'key' => $allocation, 'rules' => array(), 'doLog' => true,
                'splits' => array(array('variationKey' => 'on', 'serialId' => 1, 'shards' => array())),
            )),
        )),
    )));
    $result = DDTrace\ffe_evaluate('flag', DDTrace\FFE_STRING, 'user', array());
    $handedOff = DDTrace\Testing\flush_ffe_exposures();
    echo $step, ': cache_hit=', json_encode($result->exposureCacheHit), ' handed_off=', json_encode($handedOff), "\n";
}

function await_delivery() {
    if (trim((string) fgets(STDIN)) !== 'next') throw new RuntimeException('missing delivery barrier');
}

expose('first', 'allocation-a');
await_delivery();
$pid = pcntl_fork();
if ($pid === -1) throw new RuntimeException('fork failed');
if ($pid === 0) {
    expose('other_worker', 'allocation-b');
    exit(0);
}
pcntl_waitpid($pid, $status);
if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) throw new RuntimeException('forked child failed');
await_delivery();
// This process never saw allocation-b, so its own cache reports a repeat.
expose('back', 'allocation-a');
expose('repeat', 'allocation-a');
