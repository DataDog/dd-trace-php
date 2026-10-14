--TEST--
FFE timestamps describe evaluation entry, including defaults and mapping errors
--ENV--
DD_METRICS_OTEL_ENABLED=false
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
require __DIR__ . '/stubs/ffe_api_bootstrap.inc';

$submitted = array();
$enteredNativeAt = 0;
$reloadDuringMapping = false;
$config = array(
    'createdAt' => '2026-05-22T00:00:00Z',
    'environment' => array('name' => 'test'),
    'observeFullEvaluationData' => true,
    'flags' => array('flag' => array(
        'key' => 'flag', 'enabled' => true, 'variationType' => 'STRING',
        'variations' => array('blue' => array('key' => 'blue', 'value' => 'blue')),
        'allocations' => array(array(
            'key' => 'allocation', 'rules' => array(), 'doLog' => false,
            'splits' => array(array('variationKey' => 'blue', 'shards' => array())),
        )),
    )),
);
\DDTrace\install_hook('DDTrace\\ffe_evaluate', function () use (&$enteredNativeAt) {
    $enteredNativeAt = (int) (microtime(true) * 1000);
    // A timestamp taken after native evaluation would be observably too late.
    usleep(3000);
}, function ($hook) use (&$reloadDuringMapping, &$config) {
    if ($reloadDuringMapping) {
        $config['observeFullEvaluationData'] = false;
        \DDTrace\Testing\ffe_load_config(json_encode($config));
        $hook->returned->valueJson = '{invalid-json';
    }
});
\DDTrace\install_hook('DDTrace\\Internal\\record_ffe_flag_evaluation', function ($hook) use (&$submitted) {
    $submitted[] = $hook->args;
});

$evaluator = \DDTrace\FeatureFlags\Internal\NativeEvaluator::create(false);
$cases = array(
    array('no config', 'flag', 'string', 'PROVIDER_NOT_READY', false, 'fallback'),
    array('success', 'flag', 'string', null, true, 'blue'),
    array('type error', 'flag', 'boolean', 'TYPE_MISMATCH', true, false),
    array('missing flag', 'missing', 'string', 'FLAG_NOT_FOUND', true, 'fallback'),
    array('mapping error after reload', 'flag', 'string', 'PARSE_ERROR', true, 'fallback'),
    array('protected after reload', 'flag', 'string', null, false, 'blue'),
    array('null native result', "bad-\xff", 'string', 'PROVIDER_NOT_READY', false, 'fallback'),
);
foreach ($cases as $i => $case) {
    list($label, $flag, $type, $error, $consent, $expectedValue) = $case;
    if ($i === 1) \DDTrace\Testing\ffe_load_config(json_encode($config));
    $reloadDuringMapping = $i === 4;
    $before = (int) (microtime(true) * 1000);
    $details = $evaluator->evaluate($flag, $type, $type === 'boolean' ? false : 'fallback');
    $args = $submitted[$i];
    echo $label, ': ';
    var_dump(count($submitted) === $i + 1
        && $details->getValue() === $expectedValue
        && $args[5] === $error && $args[7] === $consent
        && isset($args[8]) && is_int($args[8])
        && $args[8] >= $before && $args[8] <= $enteredNativeAt);
}
?>
--EXPECT--
no config: bool(true)
success: bool(true)
type error: bool(true)
missing flag: bool(true)
mapping error after reload: bool(true)
protected after reload: bool(true)
null native result: bool(true)
