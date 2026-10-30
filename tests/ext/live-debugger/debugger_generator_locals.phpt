--TEST--
Debugger generator exit probes do not inspect freed locals or the destroying caller's scope
--SKIPIF--
<?php
include __DIR__ . '/../includes/skipif_no_dev_env.inc';
if (PHP_VERSION_ID < 80000) die('skip: PHP 7 delivers generator ends before freeing the frame');
?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_DYNAMIC_INSTRUMENTATION_ENABLED=1
DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=0.1
DD_DYNAMIC_INSTRUMENTATION_CAPTURE_TIMEOUT_MS=5000
DD_TRACE_AGENT_TEST_SESSION_TOKEN=live-debugger/generator_locals
--FILE--
<?php

require __DIR__ . '/live_debugger.inc';
reset_request_replayer();

class GeneratorTarget {
    public function values($arg, $materialize) {
        $local = 'local';
        if ($materialize) {
            get_defined_vars();
        }
        yield $arg;
        return 'finished';
    }
}

class GeneratorCaller {
    public function destroy($materialize) {
        $arg = $local = $callerOnly = 'caller';
        $generator = (new GeneratorTarget)->values('argument', $materialize);
        $generator->current();
        unset($generator);
    }
}

$names = ['arg', 'local', 'this', 'callerOnly', '@return'];
await_probe_installation(function () use ($names) {
    $segments = $expressions = [];
    foreach ($names as $name) {
        if ($segments) {
            $segments[] = ['str' => '/'];
        }
        $expression = ['json' => ['isDefined' => ['ref' => $name]]];
        $segments[] = $expression;
        $expressions[] = ['name' => $name, 'expr' => $expression];
    }
    $expressions[] = ['name' => 'result', 'expr' => ['json' => ['ref' => '@return']]];
    build_log_probe([
        'where' => ['typeName' => 'GeneratorTarget', 'methodName' => 'values'],
        'when' => ['json' => ['not' => ['isDefined' => ['ref' => 'callerOnly']]]],
        'captureSnapshot' => true,
        // Capturing probes default to one snapshot per second; this test expects three.
        'sampling' => ['snapshotsPerSecond' => 100],
        'captureExpressions' => $expressions,
        'segments' => $segments,
    ]);
    DDTrace\start_span();
});

$caller = new GeneratorCaller;
$caller->destroy(false);
$caller->destroy(true);

$generator = (new GeneratorTarget)->values('argument', false);
$generator->current();
$generator->next();

$replayer = new DebuggerLogReplayer;
$rows = [];
while (count($rows) < 3) {
    $request = $replayer->waitForDebuggerDataAndReplay();
    foreach (json_decode($request['body'], true) as $payload) {
        $snapshot = $payload['debugger']['snapshot'];
        if (!isset($snapshot['captures']['return'])) {
            continue;
        }
        $capture = $snapshot['captures']['return'];
        $arguments = array_keys($capture['arguments']);
        $locals = array_keys($capture['locals'] ?? []);
        sort($arguments);
        sort($locals);
        $fields = [];
        foreach ($names as $name) {
            $fields[] = $capture['captureExpressions'][$name]['value'];
        }
        $rows[] = json_encode([
            $payload['message'], $arguments, $locals, $fields,
            $capture['captureExpressions']['result'] === $capture['arguments']['@return'],
        ]);
    }
}
sort($rows);
echo implode("\n", $rows), "\n";
?>
--CLEAN--
<?php
require __DIR__ . '/live_debugger.inc';
reset_request_replayer();
?>
--EXPECT--
["false\/false\/false\/false\/true",["@return"],[],["false","false","false","false","true"],true]
["false\/false\/false\/false\/true",["@return"],[],["false","false","false","false","true"],true]
["true\/true\/true\/false\/true",["@return","arg","materialize","this"],["local"],["true","true","true","false","true"],true]
