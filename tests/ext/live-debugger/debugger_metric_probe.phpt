--TEST--
Installing a live debugger span probe
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
DD_DOGSTATSD_URL=unix:///tmp/ddtrace-test-metric_probe.socket
--FILE--
<?php

require __DIR__ . "/live_debugger.inc";
require __DIR__ . "/../dogstatsd/metrics_uds.inc";

$server = new UDSServer('/tmp/ddtrace-test-metric_probe.socket');

function foo() {
    return 123;
}

$span = await_probe_installation(function() {
    build_metric_probe(["where" => ["methodName" => "foo"], "metricName" => "foo", "kind" => "COUNT", "value" => ["json" => ["ref" => "@return"]]]);
    return \DDTrace\start_span(); // submit span data
});

$span->version = "1.2.3";

foo();

$server->dump(1);
$server->close();

?>
--CLEAN--
<?php
@unlink("/tmp/ddtrace-test-metric_probe.socket");
?>
--EXPECT--
dynamic.instrumentation.metric.probe.foo:123|c|#service:debugger_metric_probe.php,version:1.2.3
