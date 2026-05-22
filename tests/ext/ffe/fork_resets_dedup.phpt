--TEST--
FFE: fork handler resets exposure dedup in child
--SKIPIF--
<?php
if (!extension_loaded('pcntl')) die('skip: pcntl required');
if (!function_exists('DDTrace\\ffe_send_exposure')) die('skip: DDTrace\\ffe_send_exposure not available');
if (!function_exists('DDTrace\\Internal\\handle_fork')) die('skip: DDTrace\\Internal\\handle_fork not available');
?>
--ENV--
DD_TRACE_ENABLED=0
--INI--
datadog.trace.generate_root_span=0
--FILE--
<?php

DDTrace\ffe_reset_exposure_state();
DDTrace\ffe_set_service_context('svc-fork', 'test', '1.0.0');

$event = json_encode([
    'timestamp'  => 1,
    'flag'       => ['key' => 'f'],
    'allocation' => ['key' => 'a'],
    'variant'    => ['key' => 'on'],
    'subject'    => ['id' => 'u', 'attributes' => new stdClass()],
]);

$parentFirst = DDTrace\ffe_send_exposure($event, 'f', 'a', 'u', 'on');
echo 'parent_first=' . ($parentFirst ? 'true' : 'false') . "\n";

$pid = pcntl_fork();
if ($pid === -1) {
    die('fork failed');
}

if ($pid === 0) {
    DDTrace\Internal\handle_fork();
    $child = DDTrace\ffe_send_exposure($event, 'f', 'a', 'u', 'on');
    echo 'child=' . ($child ? 'true' : 'false') . "\n";
    DDTrace\ffe_reset_exposure_state();
    exit(0);
}

pcntl_wait($status);

$parentSecond = DDTrace\ffe_send_exposure($event, 'f', 'a', 'u', 'on');
echo 'parent_second=' . ($parentSecond ? 'true' : 'false') . "\n";

?>
--EXPECTF--
parent_first=true
child=true
parent_second=false
