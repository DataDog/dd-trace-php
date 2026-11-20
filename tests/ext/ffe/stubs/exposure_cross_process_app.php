<?php

require_once __DIR__ . '/exposure_sidecar.inc';
function expose($step, $allocation) {
    ffeAwaitExposureSidecar();
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

$parent = getmypid();
expose('first', 'allocation-a');
list($a, $b) = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
$pid = pcntl_fork();
if ($pid === -1) throw new RuntimeException('fork failed');
if ($pid === 0) {
    fclose($a);
    expose('other_worker', 'allocation-b');
    fwrite($b, getmypid() . "\n");
    if (trim(fgets($b)) !== 'back') exit(2);
    expose('repeat', 'allocation-a');
    exit(0);
}
fclose($b);
if ((int) fgets($a) !== $pid || $pid === $parent) throw new RuntimeException('worker PID mismatch');
expose('back', 'allocation-a');
fwrite($a, "back\n");
pcntl_waitpid($pid, $status);
if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) throw new RuntimeException('forked child failed');
