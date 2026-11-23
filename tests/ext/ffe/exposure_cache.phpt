--TEST--
FFE exposure admission is shared by native and deferred evaluations
--ENV--
DD_SERVICE=php-ffe-native-and-deferred
DDTRACE_PHPT_EXACT_PEER=1
DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED=1
DD_REMOTE_CONFIG_ENABLED=0
DD_TRACE_CLI_ENABLED=1
--INI--
datadog.trace.log_file={PWD}/exposure_cache.log
datadog.trace.log_level="debug,ddtrace=trace"
datadog.trace.generate_root_span=0
--FILE--
<?php
require_once __DIR__ . '/stubs/exposure_sidecar.inc';
$scope = uniqid('admission-', true);
ini_set('datadog.trace.agent_test_session_token', $scope);
ffeAwaitExposureSidecar();
require __DIR__ . '/logging.inc';

function config($variant, $serialId) {
    return json_encode(array(
        'createdAt' => '2024-01-01T00:00:00Z',
        'environment' => array('name' => 'test'),
        'flags' => array('flag' => array(
            'key' => 'flag', 'enabled' => true, 'variationType' => 'STRING',
            'variations' => array($variant => array('key' => $variant, 'value' => $variant)),
            'allocations' => array(array(
                'key' => 'allocation', 'rules' => array(), 'doLog' => true,
                'splits' => array(array('variationKey' => $variant, 'serialId' => $serialId, 'shards' => array())),
            )),
        ), 'quiet' => array(
            'key' => 'quiet', 'enabled' => true, 'variationType' => 'STRING',
            'variations' => array('on' => array('key' => 'on', 'value' => 'on')),
            'allocations' => array(array(
                'key' => 'allocation', 'rules' => array(), 'doLog' => false,
                'splits' => array(array('variationKey' => 'on', 'shards' => array())),
            )),
        )),
    ));
}

function hit($subject) {
    return json_encode(DDTrace\ffe_evaluate('flag', DDTrace\FFE_STRING, $subject, array())->exposureCacheHit);
}

DDTrace\Testing\ffe_load_config(config('on', 1));
echo 'first=', hit('user'), "\n";
echo 'repeat=', hit('user'), "\n";
echo 'other_subject=', hit('other'), "\n";
DDTrace\Testing\ffe_load_config(config('off', 1));
echo 'variant_change=', hit('user'), "\n";
DDTrace\Testing\ffe_load_config(config('off', 2));
echo 'serial_change=', hit('user'), "\n";
echo 'no_log=', json_encode(DDTrace\ffe_evaluate('quiet', DDTrace\FFE_STRING, 'user', array())->exposureCacheHit), "\n";
echo 'missing=', json_encode(DDTrace\ffe_evaluate('missing', DDTrace\FFE_STRING, 'user', array())->exposureCacheHit), "\n";

// The OpenFeature provider defers the decision and records it itself.
echo 'deferred=', json_encode(DDTrace\ffe_evaluate('flag', DDTrace\FFE_STRING, 'later', array(), true, false)->exposureCacheHit), "\n";
echo 'recorded=', json_encode(DDTrace\Internal\record_ffe_exposure('flag', 'later', array('plan' => 'pro'), 'allocation', 'off', 2)), "\n";
echo 'recorded_repeat=', json_encode(DDTrace\Internal\record_ffe_exposure('flag', 'later', array(), 'allocation', 'off', 2)), "\n";
echo 'evaluated_after_record=', hit('later'), "\n";
echo 'recorded_without_serial=', json_encode(DDTrace\Internal\record_ffe_exposure('flag', 'later', array(), 'allocation', 'off')), "\n";
echo 'recorded_empty_variant=', json_encode(DDTrace\Internal\record_ffe_exposure('flag', 'later', array(), 'allocation', '')), "\n";
// Test agent sessions are separate destinations, like in the sidecar.
ini_set('datadog.trace.agent_test_session_token', $scope . '-other');
ffeAwaitExposureSidecar(true);
echo 'other_session=', json_encode(DDTrace\Internal\record_ffe_exposure('flag', 'later', array(), 'allocation', 'off')), "\n";
ini_set('datadog.trace.agent_test_session_token', '');

echo 'handed_off=', json_encode(DDTrace\Testing\flush_ffe_exposures()), "\n";
--EXPECT--
first=false
repeat=true
other_subject=false
variant_change=false
serial_change=false
no_log=null
missing=null
deferred=null
recorded="admitted"
recorded_repeat="repeat"
evaluated_after_record=true
recorded_without_serial="admitted"
recorded_empty_variant=null
other_session="admitted"
handed_off=true
--CLEAN--
<?php @unlink(__DIR__ . '/exposure_cache.log'); ?>
