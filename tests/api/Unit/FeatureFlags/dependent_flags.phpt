--TEST--
FFE prerequisite observations are unique, preserve snapshot consent, and precede deferred root reporting
--ENV--
DD_METRICS_OTEL_ENABLED=false
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
require __DIR__ . '/../../../Common/FeatureFlags/ffe_api_bootstrap.inc';
$config = json_decode(file_get_contents(__DIR__ . '/../../../ext/ffe/dependent_flags.json'), true);
$submitted = array();
\DDTrace\install_hook('DDTrace\\Internal\\record_ffe_flag_evaluation', function ($hook) use (&$submitted) {
    $submitted[] = $hook->args;
});
$rootObservation = null;
$evaluator = \DDTrace\FeatureFlags\Internal\NativeEvaluator::create(false, function ($observation) use (&$rootObservation) {
    $rootObservation = $observation;
});
foreach (array(false, true) as $consent) {
    $config['observeFullEvaluationData'] = $consent;
    \DDTrace\Testing\ffe_load_config(json_encode($config));
    // A reload after native evaluation must not change the captured consent.
    $reloaded = $config;
    $reloaded['observeFullEvaluationData'] = !$consent;
    $reload = \DDTrace\install_hook('DDTrace\\ffe_evaluate', null, function () use ($reloaded) {
        \DDTrace\Testing\ffe_load_config(json_encode($reloaded));
    });
    $submitted = array();
    $details = $evaluator->evaluate('root', 'boolean', false, 'subject', array('plan' => 'pro'));
    \DDTrace\remove_hook($reload);
    echo $consent ? 'consent: ' : 'protected: ';
    var_dump($details->getValue() === true && count($submitted) === 1
        && $submitted[0][0] === 'parent' && $submitted[0][1] === 'on'
        && $submitted[0][4] === ($consent ? array('plan' => 'pro') : array())
        && $submitted[0][7] === $consent && $submitted[0][8] === $rootObservation[8]
        && $rootObservation[0] === 'root');
}
foreach (array('rejected' => 'parent', 'missing' => 'absent', 'requires-disabled' => 'disabled') as $key => $parent) {
    $submitted = array();
    $evaluator->evaluate($key, 'boolean', false, 'subject');
    echo $key, ': ';
    var_dump(count($submitted) === 1 && $submitted[0][0] === $parent);
}
foreach (array('disabled', 'unassigned') as $failure) {
    $config['flags']['parent']['enabled'] = $failure !== 'disabled';
    if ($failure === 'unassigned') {
        $config['flags']['parent']['allocations'] = array();
    }
    \DDTrace\Testing\ffe_load_config(json_encode($config));
    $submitted = array();
    $details = $evaluator->evaluate('root', 'boolean', true, 'subject');
    echo $failure . ' prerequisite error: ';
    var_dump($details->getValue() === true && $details->getErrorCode() === 'GENERAL'
        && count($submitted) === 1 && $submitted[0][0] === 'parent'
        && $submitted[0][1] === null && $submitted[0][5] === 'GENERAL'
        && $submitted[0][6] === true && $rootObservation[5] === 'GENERAL');
}
?>
--EXPECT--
protected: bool(true)
consent: bool(true)
rejected: bool(true)
missing: bool(true)
requires-disabled: bool(true)
disabled prerequisite error: bool(true)
unassigned prerequisite error: bool(true)
