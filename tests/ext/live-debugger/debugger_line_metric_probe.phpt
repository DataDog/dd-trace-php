--TEST--
Live debugger metric probes on a line fire before or after it, and at both ends of a range
--SKIPIF--
<?php
include __DIR__ . '/../includes/skipif_no_dev_env.inc';
if (strncasecmp(PHP_OS, "WIN", 3) == 0) die('skip: There are no unix sockets on Windows');
if (!extension_loaded('sockets')) die('skip: the sockets extension is required for this test');
if (PHP_VERSION_ID < 70200) die('skip: this test triggers a bug in PHP < 7.2 (See https://github.com/php/php-src/pull/3408)');
?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_DOGSTATSD_URL=unix:///tmp/ddtrace-test-line_metric_probe.socket
DD_DYNAMIC_INSTRUMENTATION_ENABLED=1
DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=0.1
DD_VERSION=1.2.3
DD_TRACE_AGENT_TEST_SESSION_TOKEN=live-debugger/line_metric_probe
--FILE--
<?php

require __DIR__ . "/live_debugger.inc";
require __DIR__ . "/../dogstatsd/metrics_uds.inc";

reset_request_replayer();

$server = new UDSServer('/tmp/ddtrace-test-line_metric_probe.socket');

function counter($n) {
    $total = $n * 10; // line:assign
    return $total; // line:return
}

await_probe_installation(function () {
    $file = basename(__FILE__);
    $assign = marker_line(__FILE__, "assign");
    $return = marker_line(__FILE__, "return");
    // Before the line, $total does not exist yet.
    build_metric_probe(["where" => ["sourceFile" => $file, "lines" => ["$assign"]], "metricName" => "before", "kind" => "COUNT", "value" => ["json" => ["ref" => "n"]]]);
    build_metric_probe(["where" => ["sourceFile" => $file, "lines" => ["$assign"]], "evaluateAt" => "EXIT", "metricName" => "after", "kind" => "GAUGE", "value" => ["json" => ["ref" => "total"]]]);
    build_metric_probe(["where" => ["sourceFile" => $file, "lines" => ["$assign-$return"]], "metricName" => "range", "kind" => "COUNT", "value" => ["json" => ["ref" => "n"]]]);

    return \DDTrace\start_span(); // submit span data
}, 3);

var_dump(counter(2));

// Four metrics through a sidecar shared with the rest of the suite can take longer than the default wait.
$server->dump(4, 5 * UDSSERVER_ITERATIONS);
$server->close();

?>
--CLEAN--
<?php
require __DIR__ . "/live_debugger.inc";
reset_request_replayer();
@unlink("/tmp/ddtrace-test-line_metric_probe.socket");
?>
--EXPECT--
int(20)
dynamic.instrumentation.metric.probe.after:20|g|#env:none,service:debugger_line_metric_probe.php,version:1.2.3,x-datadog-test-session-token:live-debugger/line_metric_probe
dynamic.instrumentation.metric.probe.before:2|c|#env:none,service:debugger_line_metric_probe.php,version:1.2.3,x-datadog-test-session-token:live-debugger/line_metric_probe
dynamic.instrumentation.metric.probe.range:2|c|#env:none,service:debugger_line_metric_probe.php,version:1.2.3,x-datadog-test-session-token:live-debugger/line_metric_probe
dynamic.instrumentation.metric.probe.range:2|c|#env:none,service:debugger_line_metric_probe.php,version:1.2.3,x-datadog-test-session-token:live-debugger/line_metric_probe
