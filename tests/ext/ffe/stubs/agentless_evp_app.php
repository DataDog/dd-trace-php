<?php
// Observe routing without a global synchronous flush: its 10s IPC read
// timeout can expire while the instrumented sidecar serves its first HTTP
// request. Dedicated flush tests exercise that barrier separately.
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
echo "submitted\n";
// Keep the application/sidecar alive until the HTTP fixture received every
// expected stream. A successful handoff alone does not prove delivery.
stream_set_timeout(STDIN, getenv('USE_ZEND_ALLOC') === '0' ? 120 : 45);
if (fgets(STDIN) !== "delivered\n") throw new RuntimeException('fixture did not confirm delivery');
echo "delivered\n";
