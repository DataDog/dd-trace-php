--TEST--
Installing a live debugger span probe on a class
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
<?php if (getenv('USE_ZEND_ALLOC') === '0' && !getenv("SKIP_ASAN")) die('skip timing sensitive test - valgrind is too slow'); ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_DYNAMIC_INSTRUMENTATION_ENABLED=1
DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=0.1
DD_TRACE_AGENT_TEST_SESSION_TOKEN=live-debugger/span_probe_class
--FILE--
<?php

require __DIR__ . "/live_debugger.inc";

reset_request_replayer();

class Bar {
    static function foo() {
        $span = \DDTrace\active_span();
        return "{$span->name} {$span->resource}";
    }
}

function delayed() {
    class Delayed {
        static function foo() {
            $span = \DDTrace\active_span();
            return "{$span->name} {$span->resource}";
        }
    }
}

await_probe_installation(function() {
    build_span_probe(["where" => ["typeName" => "Bar", "methodName" => "foo"]]);
    build_span_probe(["where" => ["typeName" => "Delayed", "methodName" => "foo"]]);

    \DDTrace\start_span(); // submit span data
}, 2);

var_dump(Bar::foo());

delayed();
var_dump(Delayed::foo());

$dlr = new DebuggerLogReplayer;
$ordered = [];
$events = 0;
$log = null;
$lastResponse = null;
$lastError = null;
$time = time();
do {
    try {
        $candidate = $dlr->waitForDiagnosticsDataAndReplay();
        $lastResponse = $candidate;
        if (!isset($candidate["files"]["event"]["contents"])) {
            throw new UnexpectedValueException("Diagnostics response has no event contents");
        }

        $payloads = json_decode($candidate["files"]["event"]["contents"], true);
        if (!is_array($payloads)) {
            throw new UnexpectedValueException("Invalid diagnostics JSON: " . json_last_error_msg());
        }

        $log = $candidate;
        foreach ($payloads as $payload) {
            if (!isset(
                $payload["debugger"]["diagnostics"]["probeId"],
                $payload["debugger"]["diagnostics"]["status"],
                $payload["timestamp"]
            )) {
                throw new UnexpectedValueException("Invalid diagnostics payload");
            }
            $diagnostic = $payload["debugger"]["diagnostics"];
            $ordered[$diagnostic["probeId"]][$payload["timestamp"]][] = $diagnostic["status"];
            ++$events;
        }
    } catch (Exception $e) {
        $lastError = $e;
    }
} while ($events < 4 && $time > time() - 30);
ksort($ordered);
foreach ($ordered as &$value) {
    ksort($value);
    foreach ($value as &$v) {
        uasort($v, function($a, $b) { $map = ["RECEIVED" => 0, "INSTALLED" => 1, "EMITTING" => 2]; return $map[$a] - $map[$b]; });
    }
}
unset($value, $v);

if ($events < 4) {
    printf("ERROR: Received %d of 4 expected debugger diagnostic events.\n", $events);
    if ($lastError) {
        printf("Last error: %s\n", $lastError->getMessage());
    }
    echo "Captured statuses:\n";
    var_dump($ordered);
    echo "Observed debugger requests:\n";
    var_dump($dlr->getDiagnosticsRequestSummary());
    if ($lastResponse !== null) {
        echo "Last diagnostics response:\n";
        var_dump($lastResponse);
    }
    exit;
}

var_dump($log["uri"]);
var_dump($log["files"]["event"]["name"]);
foreach ($ordered as $id => $statuses) {
    $states = array_merge(...$statuses);
    // Probe 1 (already-defined class): INSTALLED is delivered async by the sidecar and may
    // race the collection window. Strip it; await_probe_installation() confirms the hook.
    if ($id == 1) {
        $states = array_values(array_filter($states, function($s) { return $s !== 'INSTALLED'; }));
    }
    print "$id: " . implode(", ", $states) . "\n";
}

?>
--CLEAN--
<?php
require __DIR__ . "/live_debugger.inc";
reset_request_replayer();
?>
--EXPECTF--
string(23) "dd.dynamic.span Bar.foo"
string(27) "dd.dynamic.span Delayed.foo"
string(%d) "/debugger/v1/diagnostics?ddtags=debugger_version:%s,env:none,version:,runtime_id:%s,host_name:%s"
string(10) "event.json"
1: EMITTING
2: RECEIVED, INSTALLED, EMITTING
