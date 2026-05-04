--TEST--
FFE: ddtrace_sidecar_handle_fork resets exposure dedup in child (T9)
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

// Parent primes the dedup cache.
$parent_first = DDTrace\ffe_send_exposure($event, 'f', 'a', 'u', 'on');
echo "parent_first=" . ($parent_first ? 'true' : 'false') . "\n";

$pid = pcntl_fork();
if ($pid === -1) {
    die("fork failed");
}

if ($pid === 0) {
    // Child: same (flag, targeting, allocation, variant) as the parent's prime.
    // If the fork handler reset EXPOSURE_STATE, this is the child's first sighting
    // -> returns true. Without T9 it would observe the parent's cache -> false.
    DDTrace\Internal\handle_fork();
    $child = DDTrace\ffe_send_exposure($event, 'f', 'a', 'u', 'on');
    echo "child=" . ($child ? 'true' : 'false') . "\n";
    exit(0);
}

pcntl_wait($status);

// Parent: same exposure again -> still a duplicate because parent's cache is intact.
$parent_second = DDTrace\ffe_send_exposure($event, 'f', 'a', 'u', 'on');
echo "parent_second=" . ($parent_second ? 'true' : 'false') . "\n";

?>
--EXPECTF--
parent_first=true
child=true
parent_second=false
