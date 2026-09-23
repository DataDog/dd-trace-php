--TEST--
v0.4 legacy _dd.span_links/events JSON prints floats like json_encode and objects as property maps
--SKIPIF--
<?php
if (strncasecmp(PHP_OS, "WIN", 3) == 0) die('skip: the in-process sender is not available on Windows');
if (PHP_VERSION_ID < 70100) die('skip: serialize_precision=-1 requires PHP 7.1');
include __DIR__ . '/../includes/skipif_no_dev_env.inc';
// Pre-seed a non-v1 /info so the in-process sender negotiates the v0.4 downgrade.
$ctx = stream_context_create(['http' => [
    'method' => 'PUT',
    'header' => ["Content-Type: application/json", "X-Datadog-Test-Session-Token: span_link_event_attributes_v04_wire"],
    'content' => json_encode(["endpoints" => ["/v0.4/traces", "/v0.6/stats", "/v0.7/config"], "client_drop_p0s" => false, "version" => "7.66.0"]),
]]);
if (@file_get_contents("http://request-replayer/set-agent-info", false, $ctx) === false) {
    die("skip: request-replayer not reachable");
}
?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_FLUSH_INTERVAL=333
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_TRACE_SIDECAR_TRACE_SENDER=0
--INI--
datadog.trace.agent_test_session_token=span_link_event_attributes_v04_wire
serialize_precision=-1
--FILE--
<?php
include __DIR__ . '/../includes/request_replayer.inc';
$rr = new RequestReplayer();
$rr->clearDumpedData();
dd_trace_internal_fn('await_agent_info');

class Pub { public $a = 1; protected $prot = 'p'; private $priv = 'x'; }

$floats = [1e20, 1e-5, 0.1, 1.5, 1e17, 123456789012345678.0, -0.0, 3.0];
$s = \DDTrace\start_span();
$s->name = "root";
$link = new \DDTrace\SpanLink();
$link->traceId = str_repeat('0', 31) . '1';
$link->spanId = str_repeat('0', 15) . '2';
$link->attributes = ['floats' => $floats];
$s->links[] = $link;
$s->events[] = new \DDTrace\SpanEvent('evt', ['f' => 1e20, 'floats' => $floats, 'obj' => new Pub, 'nested' => ['k' => [1e-5]]], 1);
\DDTrace\close_span();
dd_trace_internal_fn("synchronous_flush");

$req = $rr->waitForRequest(function ($r) { return strpos($r["uri"], "traces") !== false; });
$meta = json_decode($req["body"], true)[0][0]["meta"];
$expected = json_encode($floats);
echo $expected, "\n";
// Links carry nested values as a JSON string, events as raw JSON: both must print json_encode's floats.
var_dump(json_decode($meta["_dd.span_links"], true)[0]["attributes"]["floats"] === $expected);
var_dump(strpos($meta["events"], '"floats":' . $expected) !== false);
var_dump(strpos($meta["events"], '"f":1.0e+20') !== false);
$attrs = json_decode($meta["events"], true)[0]["attributes"];
var_dump($attrs["obj"], $attrs["nested"]);
?>
--EXPECT--
[1.0e+20,1.0e-5,0.1,1.5,1.0e+17,1.2345678901234568e+17,-0,3]
bool(true)
bool(true)
bool(true)
array(1) {
  ["a"]=>
  int(1)
}
array(1) {
  ["k"]=>
  array(1) {
    [0]=>
    float(1.0E-5)
  }
}
