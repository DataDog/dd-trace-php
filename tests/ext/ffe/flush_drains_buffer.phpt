--TEST--
FFE: native exposure flush drains the batch buffer
--SKIPIF--
<?php
if (!function_exists('DDTrace\\ffe_send_exposure')) die('skip: DDTrace\\ffe_send_exposure not available');
?>
--ENV--
DD_TRACE_ENABLED=0
--INI--
datadog.trace.generate_root_span=0
--FILE--
<?php

DDTrace\ffe_reset_exposure_state();
DDTrace\ffe_set_service_context('svc-flush', 'test', '9.9.9');

$event = json_encode([
    'timestamp'  => 1713382853716,
    'flag'       => ['key' => 'demo-flag'],
    'allocation' => ['key' => 'alloc-a'],
    'variant'    => ['key' => 'on'],
    'subject'    => ['id' => 'user-1', 'attributes' => new stdClass()],
]);

var_dump(DDTrace\ffe_send_exposure($event, 'demo-flag', 'alloc-a', 'user-1', 'on'));
var_dump(DDTrace\ffe_send_exposure($event, 'demo-flag', 'alloc-a', 'user-1', 'on'));
var_dump(DDTrace\ffe_send_exposure($event, 'demo-flag', 'alloc-a', 'user-1', 'off'));

$payload = DDTrace\ffe_flush_exposures();
var_dump(is_string($payload) && strlen($payload) > 0);

$decoded = json_decode($payload, true);
var_dump($decoded['context']['service'] === 'svc-flush');
var_dump($decoded['context']['env'] === 'test');
var_dump($decoded['context']['version'] === '9.9.9');
var_dump(count($decoded['exposures']));
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
