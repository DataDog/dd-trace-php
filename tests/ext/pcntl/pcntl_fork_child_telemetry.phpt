--TEST--
A child forked during a request gets the application on its own sidecar connection, so its telemetry is delivered
--SKIPIF--
<?php
if (!extension_loaded('pcntl')) die('skip: pcntl extension required');
if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: pecl run-tests does not support {PWD}");
if (getenv('USE_ZEND_ALLOC') === '0' && !getenv("SKIP_ASAN")) die('skip timing sensitive test - valgrind is too slow');
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=1
DD_TRACE_AGENT_TIMEOUT=200
DD_TRACE_RETRY_INTERVAL=1
DD_TRACE_AUTO_FLUSH_ENABLED=0
--INI--
datadog.trace.agent_url="file://{PWD}/tmp/pcntl_fork_child-telemetry/"
--FILE--
<?php

// Telemetry workers are shared by service/env, including across PHP ABIs. A
// previous run or SKIPIF must not send this child's integration to another file.
$integrationName = uniqid("fork_child_integration_", true);
ini_set('datadog.service', $integrationName);

// The root span keeps the service of the request, so the remote config target does not change in the child.
DDTrace\start_span();

$pid = pcntl_fork();
if ($pid < 0) {
    echo "Fork failed\n";
    exit(1);
}
if ($pid == 0) {
    dd_trace_internal_fn("mark_integration_loaded", $integrationName, "1.2.3");
    dd_trace_internal_fn("finalize_telemetry");
    exit(0);
}
pcntl_waitpid($pid, $status);
if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) != 0) {
    echo "The child failed\n";
    exit(1);
}

// Directory recording avoids the single-file writer's fork-inherited mutex.
// Each completed request is atomically renamed from .json.tmp to .json.
for ($i = 0; $i < 300; ++$i) {
    ("us" . "leep")(100000);
    foreach (glob(__DIR__ . '/tmp/pcntl_fork_child-telemetry/telemetry-*.json') as $file) {
        $json = json_decode(file_get_contents($file), true);
        if (!isset($json["request_type"])) {
            continue;
        }
        $batch = $json["request_type"] == "message-batch" ? $json["payload"] : [$json];
        foreach ($batch as $json) {
            foreach ($json["payload"]["integrations"] ?? [] as $integration) {
                if ($integration["name"] == $integrationName) {
                    var_dump($integration["enabled"]);
                    break 4;
                }
            }
        }
    }
}
if ($i == 300) {
    echo "The integration of the child never arrived\n";
}

DDTrace\close_span();
dd_trace_serialize_closed_spans();

?>
--EXPECT--
bool(true)
--CLEAN--
<?php

foreach (glob(__DIR__ . '/tmp/pcntl_fork_child-telemetry/telemetry-*.json') as $file) {
    @unlink($file);
}
@rmdir(__DIR__ . '/tmp/pcntl_fork_child-telemetry');
