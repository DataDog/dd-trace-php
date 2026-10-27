--TEST--
v0.4 native span_events carry typed values, nested ones under dotted keys, when the agent advertises span_events
--SKIPIF--
<?php
if (strncasecmp(PHP_OS, "WIN", 3) == 0) die('skip: the in-process sender is not available on Windows');
if (PHP_VERSION_ID < 70100) die('skip: serialize_precision=-1 requires PHP 7.1');
include __DIR__ . '/../includes/skipif_no_dev_env.inc';
// Pre-seed a non-v1 /info with span_events so the in-process sender downgrades to v0.4 with native events.
$ctx = stream_context_create(['http' => [
    'method' => 'PUT',
    'header' => ["Content-Type: application/json", "X-Datadog-Test-Session-Token: span_link_event_attributes_v04_wire_native_events"],
    'content' => json_encode(["endpoints" => ["/v0.4/traces", "/v0.6/stats", "/v0.7/config"], "client_drop_p0s" => false, "version" => "7.66.0", "span_events" => true]),
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
datadog.trace.agent_test_session_token=span_link_event_attributes_v04_wire_native_events
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
$span = json_decode($req["body"], true)[0][0];
// Native links carry nested values as a JSON string, as without span_events.
var_dump(!isset($span["meta"]["_dd.span_links"]) && json_decode($span["span_links"][0]["attributes"]["floats"], true) == $floats);
// Native events replace the legacy events meta.
var_dump(isset($span["meta"]["events"]));
$event = $span["span_events"][0];
var_dump($event["name"], $event["time_unix_nano"]);
$attrs = $event["attributes"];
ksort($attrs);
var_dump(array_keys($attrs));
// Floats are typed doubles (type 3), arrays of scalars stay arrays (type 4), object members are dotted keys.
var_dump($attrs["f"]["type"], $attrs["f"]["double_value"] == 1e20);
var_dump($attrs["floats"]["type"], array_column($attrs["floats"]["array_value"]["values"], "type") === array_fill(0, count($floats), 3));
var_dump(array_column($attrs["floats"]["array_value"]["values"], "double_value") == $floats);
var_dump($attrs["obj.a"] == ["type" => 2, "int_value" => 1]);
var_dump($attrs["nested.k"]["type"], $attrs["nested.k"]["array_value"]["values"][0]["type"], $attrs["nested.k"]["array_value"]["values"][0]["double_value"] == 1e-5);
?>
--EXPECT--
bool(true)
bool(false)
string(3) "evt"
int(1)
array(4) {
  [0]=>
  string(1) "f"
  [1]=>
  string(6) "floats"
  [2]=>
  string(8) "nested.k"
  [3]=>
  string(5) "obj.a"
}
int(3)
bool(true)
int(4)
bool(true)
bool(true)
bool(true)
int(4)
int(3)
bool(true)
