--TEST--
The sidecar trace flusher sender informs about changes to the agent sample rate
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
<?php if (getenv('USE_ZEND_ALLOC') === '0' && !getenv("SKIP_ASAN")) die('skip: valgrind reports sendmsg(msg.msg_control) points to uninitialised byte(s), but it is unproblematic and outside our control in rust code'); ?>
--ENV--
DD_TRACE_LOG_LEVEL=info,startup=off
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_FLUSH_INTERVAL=333
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_TRACE_SIDECAR_TRACE_SENDER=1
DD_TRACE_IGNORE_AGENT_SAMPLING_RATES=0
--INI--
datadog.trace.agent_test_session_token=background-sender/agent_sampling_sidecar
--FILE--
<?php
include __DIR__ . '/../includes/request_replayer.inc';

$contents = [];
$filename = null;

// Wait until the sidecar has published this test invocation's response. Each
// invocation uses random markers so a repeated or parallel run cannot match a
// stale shared-memory file left by an earlier process.
function checkUpdated($marker) {
    if (PHP_OS === "Linux") {
        $retries = 100;
        do {
            foreach (glob("/dev/shm/*") as $f) {
                if (@filesize($f) < 5000) {
                    $file = @file_get_contents($f);
                    if (@strpos($file, $marker) !== false) {
                        global $contents, $filename;
                        $filename = $f;
                        $contents[] = bin2hex($file);
                        return;
                    }
                }
            }
            $fn = "us" . "leep"; // do not retry
            $fn(100000);
        } while (--$retries);
        foreach (glob("/dev/shm/*") as $f) {
            var_dump($f, bin2hex(file_get_contents($f)));
        }
        throw new RuntimeException("Timed out waiting for sidecar sampling configuration marker: {$marker}");
    }
}

function recordContents() {
    if (PHP_OS === "Linux") {
        global $contents, $filename;
        $contents[] = bin2hex(file_get_contents($filename));
    }
}

$rr = new RequestReplayer();
$rr->replayRequest(); // cleanup possible leftover

$errors = [];
$get_sampling = function($label, $expected) use ($rr, &$errors) {
    $root = json_decode($rr->waitForDataAndReplay()["body"], true);
    $spans = $root["chunks"][0]["spans"] ?? $root[0];
    $priority = $spans[0]["metrics"]["_sampling_priority_v1"];
    if ($priority != $expected) {
        $errors[] = "{$label} sampling priority: expected {$expected}, got {$priority}";
    }
    return $priority;
};

$nonce = getmypid() . "-" . bin2hex(random_bytes(8));
$firstMarker = "service:agent-sampling-sidecar-sync-{$nonce},env:first";
$secondMarker = "service:agent-sampling-sidecar-sync-{$nonce},env:second";

$rr->setResponse(["rate_by_service" => ["service:,env:" => 0, $firstMarker => 1]]);

\DDTrace\start_span();
\DDTrace\close_span();

echo "Initial sampling: {$get_sampling('Initial', 1)}\n";

checkUpdated($firstMarker);

$rr->setResponse(["rate_by_service" => ["service:,env:" => 0, "service:foo,env:none" => 1, $secondMarker => 0]]);

recordContents();
\DDTrace\start_span();
\DDTrace\close_span();
recordContents();

checkUpdated($secondMarker);

echo "Generic sampling: {$get_sampling('Generic', 0)}\n";

// reset it for other tests
$rr->setResponse(["rate_by_service" => []]);

recordContents();
$s = \DDTrace\start_span();
$s->service = "foo";
$s->env = "none";
\DDTrace\close_span();
recordContents();

echo "Specific sampling: {$get_sampling('Specific', 1)}\n";

if ($errors) {
    foreach ($errors as $error) {
        echo "{$error}\n";
    }
    if (PHP_OS === "Linux") {
        var_dump($contents);
    }
}

?>
--EXPECTF--
[ddtrace] [info] [%d] Flushing trace of size 1 to send-queue for http://request-replayer:80
Initial sampling: 1
[ddtrace] [info] [%d] Flushing trace of size 1 to send-queue for http://request-replayer:80
Generic sampling: 0
[ddtrace] [info] [%d] Flushing trace of size 1 to send-queue for http://request-replayer:80
Specific sampling: 1
[ddtrace] [info] [%d] No finished traces to be sent to the agent
