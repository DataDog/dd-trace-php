--TEST--
FFE: ddog_ffe_flush_exposures drains the batch buffer (V2/V4 proxy)
--ENV--
DD_TRACE_ENABLED=0
--INI--
datadog.trace.generate_root_span=0
datadog.trace.agent_test_session_token=ffe-flush-drain
--FILE--
<?php

if (!function_exists('DDTrace\\ffe_send_exposure')) {
    die('skip: DDTrace\\ffe_send_exposure not available');
}

// Start from a known-empty state.
if (function_exists('DDTrace\\ffe_reset_exposure_state')) {
    DDTrace\ffe_reset_exposure_state();
}
DDTrace\ffe_set_service_context('svc-flush', 'test', '9.9.9');

$event = json_encode([
    'timestamp'  => 1713382853716,
    'flag'       => ['key' => 'demo-flag'],
    'allocation' => ['key' => 'alloc-a'],
    'variant'    => ['key' => 'on'],
    'subject'    => ['id' => 'user-1', 'attributes' => new stdClass()],
]);

// First enqueue should succeed.
var_dump(DDTrace\ffe_send_exposure($event, 'demo-flag', 'alloc-a', 'user-1', 'on'));

// Duplicate (same flag+targeting+allocation+variant) should dedup.
var_dump(DDTrace\ffe_send_exposure($event, 'demo-flag', 'alloc-a', 'user-1', 'on'));

// Flip the variant -> allocation/variant changed -> should re-emit.
var_dump(DDTrace\ffe_send_exposure($event, 'demo-flag', 'alloc-a', 'user-1', 'off'));

// Flush should return a non-empty JSON batch.
$payload = DDTrace\ffe_flush_exposures();
var_dump(is_string($payload) && strlen($payload) > 0);

$decoded = json_decode($payload, true);
var_dump($decoded['context']['service'] === 'svc-flush');
var_dump($decoded['context']['env'] === 'test');
var_dump($decoded['context']['version'] === '9.9.9');
var_dump(count($decoded['exposures']));

// After drain, subsequent flush returns null (empty buffer).
var_dump(DDTrace\ffe_flush_exposures());

?>
--EXPECT--
bool(true)
bool(false)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
int(2)
NULL
