--TEST--
Test remote config request payload
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=0.01
DD_EXPERIMENTAL_PROPAGATE_PROCESS_TAGS_ENABLED=1
--INI--
datadog.trace.agent_test_session_token=remote-config/check_payload
--FILE--
<?php

require __DIR__ . "/remote_config.inc";
include __DIR__ . '/../includes/request_replayer.inc';

reset_request_replayer();
$rr = new RequestReplayer();

// Start a span to trigger RC
\DDTrace\start_span();

$path = put_dynamic_config_file([
    "log_injection_enabled" => true,
]);

try {
    $request = $rr->waitForRequest(function($req) {
        return strpos($req["uri"], '/v0.7/config') !== false;
    });
    $body = json_decode($request["body"], true);
} catch (Exception $e) {
    echo "ERROR: No RC request found\n";
    exit(1);
}

if (!isset($body["client"]["client_tracer"]["process_tags"])) {
    echo "ERROR: Missing 'process_tags' field\n";
    exit(1);
}

$process_tags = $body["client"]["client_tracer"]["process_tags"];
foreach ($process_tags as $tag) {
    echo $tag . PHP_EOL;
}

del_rc_file($path);

?>
--CLEAN--
<?php
require __DIR__ . "/remote_config.inc";
reset_request_replayer();
?>
--EXPECTF--
entrypoint.basedir:remote_config
entrypoint.name:process_tags
entrypoint.type:script
entrypoint.workdir:%s
runtime.sapi:cli
