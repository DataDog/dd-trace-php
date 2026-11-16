--TEST--
A live debugger span decoration stringifies an object exposing no properties (SensitiveParameterValue)
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
<?php if (PHP_VERSION_ID < 80200) die('skip: SensitiveParameterValue was added in PHP 8.2'); ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_DYNAMIC_INSTRUMENTATION_ENABLED=1
DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=0.1
DD_DYNAMIC_INSTRUMENTATION_CAPTURE_TIMEOUT_MS=5000
DD_TRACE_AGENT_TEST_SESSION_TOKEN=live-debugger/span_decoration_sensitive_parameter
--FILE--
<?php

require __DIR__ . "/live_debugger.inc";

reset_request_replayer();

function foo($arg) {
}

await_probe_installation(function() {
    build_span_decoration_probe(["where" => ["methodName" => "foo"], "decorations" => [
        ["tags" => [["name" => "arg", "value" => ["segments" => [["json" => ["ref" => "arg"]]]]]]],
    ]]);

    \DDTrace\start_span(); // submit span data
});

\DDTrace\start_span();
foo(new SensitiveParameterValue("secret"));
var_dump(\DDTrace\active_span()->meta["arg"]);
foo([new SensitiveParameterValue("secret"), (object)["a" => 1]]);
var_dump(\DDTrace\active_span()->meta["arg"]);

?>
--CLEAN--
<?php
require __DIR__ . "/live_debugger.inc";
reset_request_replayer();
?>
--EXPECT--
string(27) "(SensitiveParameterValue){}"
string(47) "[(SensitiveParameterValue){}, (stdClass){a: 1}]"
