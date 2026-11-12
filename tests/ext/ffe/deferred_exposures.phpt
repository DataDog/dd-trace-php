--TEST--
FFE deferred exposure candidates retain allocation, variant and serial identity without queuing
--INI--
datadog.trace.generate_root_span=0
datadog.trace.enabled=0
datadog.trace.sidecar_trace_sender=0
datadog.instrumentation_telemetry_enabled=0
datadog.trace.log_file={PWD}/deferred_exposures.log
datadog.trace.log_level="debug,ddtrace=trace"
--FILE--
<?php
require __DIR__ . '/logging.inc';
$config = json_decode(file_get_contents(__DIR__ . '/dependent_flags.json'), true);
$config['flags']['root']['allocations'][0]['splits'][0]['serialId'] = 0;
DDTrace\Testing\ffe_load_config(json_encode($config));
$deferred = DDTrace\ffe_evaluate('root', DDTrace\FFE_BOOL, 'subject', ['plan' => 'pro'], false, true);
echo 'candidates='; var_dump($deferred->exposureCandidates === [
    ['flagKey' => 'parent', 'variant' => 'on', 'allocationKey' => 'parent-allocation', 'serialId' => 0, 'hasSerialId' => false],
    ['flagKey' => 'root', 'variant' => 'on', 'allocationKey' => 'root-allocation', 'serialId' => 0, 'hasSerialId' => true]
]);
echo 'deferred='; var_dump(strpos(read_log_snapshot(__DIR__ . '/deferred_exposures.log'), 'exposure queued') === false);
$failed = DDTrace\ffe_evaluate('missing', DDTrace\FFE_BOOL, 'subject', [], false, true);
echo 'failed='; var_dump($failed->exposureCandidates === [] && $failed->errorCode === 7);
$direct = DDTrace\ffe_evaluate('root', DDTrace\FFE_BOOL, 'subject', [], false);
echo 'direct='; var_dump($direct->exposureCandidates === [] && $direct->valueJson === 'true'
    && substr_count(read_log_snapshot(__DIR__ . '/deferred_exposures.log'), 'exposure queued') === 2);
?>
--EXPECT--
candidates=bool(true)
deferred=bool(true)
failed=bool(true)
direct=bool(true)
--CLEAN--
<?php @unlink(__DIR__ . '/deferred_exposures.log'); ?>
