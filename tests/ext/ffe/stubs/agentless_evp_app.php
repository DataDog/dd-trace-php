<?php
// Drain startup configuration before submitting a best-effort observation.
// This is a routing test, not a shutdown or load-shedding test.
dd_trace_synchronous_flush();
$failed = getenv('PHP_FFE_TRANSPORT_FAILURE') === 'true';
if (!$failed && !\DDTrace\Testing\ffe_load_config(file_get_contents(__DIR__ . '/agentless_evp_config.json'))) {
    throw new RuntimeException('configuration rejected');
}
foreach (explode(',', getenv('PHP_FFE_TRANSPORT_TOKENS')) as $token) {
    ini_set('datadog.trace.agent_test_session_token', $token);
    $result = \DDTrace\ffe_evaluate('transport-flag', \DDTrace\FFE_STRING, 'transport-user-' . $token, array());
    if (!$failed && (!$result || $result->valueJson !== '"blue"')) throw new RuntimeException('evaluation failed');
    // Native error code 6 is PROVIDER_NOT_READY.
    if ($failed && (\DDTrace\ffe_has_config() || !$result || $result->errorCode !== 6)) {
        throw new RuntimeException('expected provider-not-ready evaluation');
    }
    if (!\DDTrace\Internal\record_ffe_flag_evaluation(
        'transport-flag', $failed ? null : 'blue', null, 'transport-user-' . $token, array(),
        $failed ? 'PROVIDER_NOT_READY' : null, $failed, false, 1700000000123
    )) throw new RuntimeException('evaluation count submission rejected');
    if (!$failed && !\DDTrace\Testing\flush_ffe_exposures()) throw new RuntimeException('exposure handoff failed');
}
dd_trace_synchronous_flush();
echo "delivered\n";
