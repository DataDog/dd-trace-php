--TEST--
Remote Config and live debugger hooks remain valid through tracer request shutdown
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=0.1
DD_TRACE_AGENT_TEST_SESSION_TOKEN=live-debugger/rshutdown_remote_config
--INI--
datadog.trace.enabled=1
datadog.autofinish_spans=1
--FILE--
<?php

require __DIR__ . "/live_debugger.inc";

reset_request_replayer();
ini_set('datadog.logs_injection', '0');

function instrumented(): void {}

final class StartSpanDuringShutdown
{
    public function __destruct()
    {
        $span = \DDTrace\start_span();
        $span->onClose[] = function () {
            // The span is closed from tracer RSHUTDOWN. Remote Config and its
            // live debugger subscriber must still be active.
            var_dump(count(dd_trace_internal_fn('get_loaded_remote_configs')));
            echo "onClose completed\n";
        };
    }
}

put_dynamic_config_file([
    "log_injection_enabled" => true,
    "dynamic_instrumentation_enabled" => true,
]);

await_probe_installation(function () {
    build_span_probe(["where" => ["methodName" => "instrumented"]]);
});

$trigger = new StartSpanDuringShutdown();

echo "request body complete\n";

?>
--CLEAN--
<?php
require __DIR__ . "/live_debugger.inc";
reset_request_replayer();
?>
--EXPECT--
request body complete
int(2)
onClose completed
