--TEST--
Live debugger span probes on lines cover a range, or a single line, and close on every exit
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_DYNAMIC_INSTRUMENTATION_ENABLED=1
DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=0.1
DD_TRACE_AGENT_TEST_SESSION_TOKEN=live-debugger/line_span_probe
--INI--
pcre.jit=0
--FILE--
<?php

require __DIR__ . "/live_debugger.inc";

reset_request_replayer();

function ranged($n) {
    $a = $n + 1; // line:ranged-from
    $name = \DDTrace\active_span()->name;
    if ($n < 0) {
        throw new Exception("out");
    }
    return $name; // line:ranged-till
}

function single() {
    return \DDTrace\active_span()->name; // line:single
}

function gen() {
    $sent = yield 1; // line:gen-from
    yield \DDTrace\active_span()->name . " $sent"; // line:gen-till
}

class Wrapped {
    function method() {
        // The line span is already active on the first line of its range.
        $span = \DDTrace\active_span(); // line:wrapped-from
        return "{$span->resource} in {$span->parent->resource}"; // line:wrapped-till
    }
}

$names = [];
foreach (["ranged-from", "ranged-till", "single", "gen-from", "gen-till", "wrapped-from", "wrapped-till"] as $marker) {
    $names[marker_line(__FILE__, $marker)] = $marker;
}
$line = function ($marker) { return marker_line(__FILE__, $marker); };

$root = await_probe_installation(function () use ($line) {
    $file = basename(__FILE__);
    build_span_probe(["where" => ["sourceFile" => $file, "lines" => [$line("ranged-from") . "-" . $line("ranged-till")]]]);
    build_span_probe(["where" => ["sourceFile" => $file, "lines" => [$line("single")]]]);
    build_span_probe(["where" => ["sourceFile" => $file, "lines" => [$line("gen-from") . "-" . $line("gen-till")]]]);
    build_span_probe(["where" => ["sourceFile" => $file, "lines" => [$line("wrapped-from") . "-" . $line("wrapped-till")]]]);
    build_span_probe(["where" => ["typeName" => "Wrapped", "methodName" => "method"]]);

    return \DDTrace\start_span(); // submit span data
}, 5);

var_dump(ranged(1));
try {
    ranged(-1);
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
}
var_dump(single());

$gen = gen();
var_dump($gen->current());
// Between yields the generator's span must not be the caller's active span.
var_dump(\DDTrace\active_span() === $root);
var_dump($gen->send("resumed"));
$gen->next();

echo preg_replace('/\S+:(\d+)-(\d+)/', 'FILE:$1-$2', (new Wrapped)->method()), "\n";

\DDTrace\close_span();

$spans = dd_trace_serialize_closed_spans();
$by_id = [];
foreach ($spans as $span) {
    $by_id[$span["span_id"]] = $span;
}
$lines = [];
foreach ($spans as $span) {
    if ($span["name"] != "dd.dynamic.span") {
        continue;
    }
    $resource = preg_replace_callback('/^.*:(\d+)(?:-(\d+))?$/', function ($m) use ($names) {
        return isset($m[2]) ? "{$names[$m[1]]}-{$names[$m[2]]}" : $names[$m[1]];
    }, $span["resource"]);
    $parent = isset($by_id[$span["parent_id"]]) ? $by_id[$span["parent_id"]]["name"] . " " . $by_id[$span["parent_id"]]["resource"] : "?";
    $lines[] = sprintf("%s (parent: %s, probe %s%s)", $resource, $parent, $span["meta"]["debugger.probeid"], empty($span["error"]) ? "" : ", error: " . $span["meta"]["error.message"]);
}
sort($lines);
echo implode("\n", $lines), "\n";

?>
--CLEAN--
<?php
require __DIR__ . "/live_debugger.inc";
reset_request_replayer();
?>
--EXPECTF--
string(15) "dd.dynamic.span"
out
string(15) "dd.dynamic.span"
int(1)
bool(true)
string(23) "dd.dynamic.span resumed"
FILE:%d-%d in Wrapped.method
Wrapped.method (parent: %s, probe 5)
gen-from-gen-till (parent: %s, probe 3)
ranged-from-ranged-till (parent: %s, probe 1)
ranged-from-ranged-till (parent: %s, probe 1, error: %s)
single (parent: %s, probe 2)
wrapped-from-wrapped-till (parent: dd.dynamic.span Wrapped.method, probe 4)
