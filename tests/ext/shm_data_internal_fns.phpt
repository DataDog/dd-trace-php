--TEST--
get_loaded_remote_configs, get_agent_info, get_agent_sampling_config return correct content
--SKIPIF--
<?php include __DIR__ . '/includes/skipif_no_dev_env.inc'; ?>
<?php if (getenv('USE_ZEND_ALLOC') === '0' && !getenv('SKIP_ASAN')) die('skip: valgrind is too slow for timing-sensitive RC polling'); ?>
<?php if (PHP_VERSION_ID < 70400) die("skip: Before PHP 7.4, the skip-task would cause the sidecar to fetch the info already."); ?>
<?php
if (PHP_VERSION_ID >= 80100) {
    echo "nocache\n";
}
// Set agent /info BEFORE the test process starts so the sidecar fetches our data on first poll.
file_get_contents('http://request-replayer/set-agent-info', false, stream_context_create([
    'http' => [
        'method'  => 'PUT',
        'header'  => ['Content-Type: application/json',
                      'X-Datadog-Test-Session-Token: remote-config/shm_data_internal_fns'],
        'content' => json_encode([
            'version'         => '7.99.0-test',
            'client_drop_p0s' => true,
            'peer_tags'       => ['db.instance'],
        ]),
    ],
]));
?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_FLUSH_INTERVAL=333
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=0.01
DD_TRACE_SIDECAR_TRACE_SENDER=1
DD_DYNAMIC_INSTRUMENTATION_ENABLED=1
DD_TRACE_IGNORE_AGENT_SAMPLING_RATES=0
--INI--
datadog.service=shm_data_test
datadog.env=test
datadog.trace.agent_test_session_token=remote-config/shm_data_internal_fns
--FILE--
<?php

require __DIR__ . '/remote_config/remote_config.inc';
include __DIR__ . '/includes/request_replayer.inc';

reset_request_replayer();
$rr = new RequestReplayer();
$rr->replayRequest(); // consume any leftover traces from startup

dd_trace_internal_fn('await_agent_info', 5000);
$info = dd_trace_internal_fn('get_agent_info');
var_dump(is_array($info));
var_dump($info['version']         ?? 'missing');
var_dump($info['client_drop_p0s'] ?? false);
var_dump(in_array('db.instance', $info['peer_tags'] ?? []));

$rr->setResponse(['rate_by_service' => [
    'service:,env:'                  => 0.5,
    'service:shm_data_test,env:test' => 1.0,
]]);

\DDTrace\start_span();
\DDTrace\close_span();
dd_trace_internal_fn('synchronous_flush'); // ensure sidecar sends immediately
$rr->waitForDataAndReplay();

for ($i = 0; $i < 50; $i++) {
    $sampling = dd_trace_internal_fn('get_agent_sampling_config');
    if (!empty($sampling)) { break; }
    usleep(100000);
}
var_dump(is_array($sampling));
var_dump(isset($sampling['rate_by_service']));
var_dump((float)($sampling['rate_by_service']['service:shm_data_test,env:test'] ?? -1));

$apmPath = put_dynamic_config_file(['tracing_sample_rate' => 0.5], 'shm_data_test', 'test');
$probeId = "log1a2b3c4d-0000-0000-0000-000000000001";
put_rc_file(
    "datadog/2/LIVE_DEBUGGING/logProbe_{$probeId}/config",
    json_encode([
        "id"             => $probeId,
        "version"        => 0,
        "type"           => "LOG_PROBE",
        "language"       => "php",
        "where"          => ["typeName" => "Foo", "methodName" => "bar"],
        "segments"       => [],
        "captureSnapshot" => false,
    ])
);

\DDTrace\start_span(); // keep request alive for VM interrupt

// config_ids are the unique parts of the path, not full paths; search by entry type/id.
$apmFound = $probeFound = false;
for ($i = 0; $i < 40 && !($apmFound && $probeFound); $i++) {
    $loaded = dd_trace_internal_fn('get_loaded_remote_configs');
    foreach ($loaded as $entry) {
        if (($entry['type'] ?? '') === 'apm_tracing')                                  { $apmFound   = true; }
        if (($entry['type'] ?? '') === 'probe' && ($entry['id'] ?? '') === $probeId)   { $probeFound = true; }
    }
    if (!$apmFound || !$probeFound) { usleep(250000); }
}

var_dump($apmFound   ? 'APM_TRACING found' : 'APM_TRACING NOT found');
var_dump($probeFound ? 'probe found'       : 'probe NOT found');

foreach ($loaded as $entry) {
    if (($entry['type'] ?? '') === 'apm_tracing') { var_dump($entry['type']); break; }
}
foreach ($loaded as $entry) {
    if (($entry['type'] ?? '') === 'probe' && ($entry['id'] ?? '') === $probeId) {
        var_dump($entry['type']);
        var_dump($entry['id'] === $probeId);
        break;
    }
}

del_rc_file($apmPath);
del_rc_file("datadog/2/LIVE_DEBUGGING/logProbe_{$probeId}/config");

?>
--CLEAN--
<?php
require __DIR__ . '/remote_config/remote_config.inc';
reset_request_replayer();
?>
--EXPECT--
bool(true)
string(11) "7.99.0-test"
bool(true)
bool(true)
bool(true)
bool(true)
float(1)
string(17) "APM_TRACING found"
string(11) "probe found"
string(11) "apm_tracing"
string(5) "probe"
bool(true)
