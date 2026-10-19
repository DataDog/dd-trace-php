--TEST--
A child forked during a request gets the application on its own sidecar connection, so its telemetry is delivered
--SKIPIF--
<?php
if (!extension_loaded('pcntl')) die('skip: pcntl extension required');
if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: pecl run-tests does not support {PWD}");
if (getenv('USE_ZEND_ALLOC') === '0' && !getenv("SKIP_ASAN")) die('skip timing sensitive test - valgrind is too slow');
require __DIR__ . '/../includes/clear_skipif_telemetry.inc'
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=1
DD_TRACE_AGENT_TIMEOUT=200
DD_TRACE_RETRY_INTERVAL=1
--INI--
datadog.trace.agent_url="file://{PWD}/pcntl_fork_child-telemetry.out"
--FILE--
<?php

// The root span keeps the service of the request, so the remote config target does not change in the child.
DDTrace\start_span();

$pid = pcntl_fork();
if ($pid == 0) {
    dd_trace_internal_fn("mark_integration_loaded", "fork_child_integration", "1.2.3");
    dd_trace_internal_fn("finalize_telemetry");
    exit(0);
}
pcntl_waitpid($pid, $status);

for ($i = 0; $i < 300; ++$i) {
    ("us" . "leep")(100000);
    if (file_exists(__DIR__ . '/pcntl_fork_child-telemetry.out')) {
        foreach (file(__DIR__ . '/pcntl_fork_child-telemetry.out') as $l) {
            if ($l && $l[0] == '{') {
                $json = json_decode($l, true);
                $batch = $json["request_type"] == "message-batch" ? $json["payload"] : [$json];
                foreach ($batch as $json) {
                    $integrations = $json["payload"]["integrations"] ?? [];
                    foreach ($integrations as $integration) {
                        if ($integration["name"] == "fork_child_integration") {
                            var_dump($integration["enabled"]);
                            break 4;
                        }
                    }
                }
            }
        }
    }
}
if ($i == 300) {
    echo "The integration of the child never arrived\n";
}

DDTrace\close_span();

?>
--EXPECT--
bool(true)
--CLEAN--
<?php

@unlink(__DIR__ . '/pcntl_fork_child-telemetry.out');
