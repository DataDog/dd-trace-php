--TEST--
The sidecar sampling reader follows test session token changes
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
<?php if (getenv('USE_ZEND_ALLOC') === '0' && !getenv('SKIP_ASAN')) die('skip timing sensitive test - valgrind is too slow'); ?>
--ENV--
DD_TRACE_LOG_LEVEL=warn
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_FLUSH_INTERVAL=333
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_TRACE_SIDECAR_TRACE_SENDER=1
DD_TRACE_SIDECAR_CONNECTION_MODE=thread
DD_TRACE_IGNORE_AGENT_SAMPLING_RATES=0
--INI--
datadog.trace.agent_test_session_token=background-sender/agent_sampling_sidecar_token_change-before
--FILE--
<?php
include __DIR__ . '/../includes/request_replayer.inc';

ini_set(
    'datadog.trace.agent_test_session_token',
    'background-sender/agent_sampling_sidecar_token_change-after'
);

$rr = new RequestReplayer();
$rr->replayRequest(); // cleanup possible leftover

$marker = 'service:agent-sampling-sidecar-token-change-' . getmypid() . ',env:test';
$rr->setResponse(['rate_by_service' => [$marker => 1]]);

\DDTrace\start_span();
\DDTrace\close_span();
dd_trace_internal_fn('synchronous_flush');
$rr->waitForDataAndReplay();

for ($i = 0; $i < 100; $i++) {
    $sampling = dd_trace_internal_fn('get_agent_sampling_config');
    if (isset($sampling['rate_by_service'][$marker])) {
        break;
    }
    usleep(100000);
}

var_dump((float) ($sampling['rate_by_service'][$marker] ?? -1));
?>
--EXPECT--
float(1)
