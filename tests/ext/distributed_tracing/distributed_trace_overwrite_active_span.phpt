--TEST--
Setting a distributed tracing context if a span is already active
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
HTTP_X_DATADOG_TAGS=custom_tag=inherited
HTTP_X_DATADOG_ORIGIN=datadog
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--INI--
error_reporting=E_ALL
--FILE--
<?php

// Source: https://magp.ie/2015/09/30/convert-large-integer-to-hexadecimal-without-php-math-extension/
function largeBaseConvert($numString, $fromBase, $toBase)
{
    $chars = "0123456789abcdefghijklmnopqrstuvwxyz";
    $toString = substr($chars, 0, $toBase);

    $length = strlen($numString);
    $result = '';
    for ($i = 0; $i < $length; $i++) {
        $number[$i] = strpos($chars, $numString[$i]);
    }
    do {
        $divide = 0;
        $newLen = 0;
        for ($i = 0; $i < $length; $i++) {
            $divide = $divide * $fromBase + $number[$i];
            if ($divide >= $toBase) {
                $number[$newLen++] = (int)($divide / $toBase);
                $divide = $divide % $toBase;
            } elseif ($newLen > 0) {
                $number[$newLen++] = 0;
            }
        }
        $length = $newLen;
        $result = $toString[$divide] . $result;
    } while ($newLen != 0);

    return $result;
}

function dump_spans() {
    $chunk = dd_trace_serialize_closed_spans()['chunks'][0];
    $traceId = largeBaseConvert(ltrim(substr($chunk["trace_id"], 16), "0"), 16, 10);
    foreach ($chunk['spans'] as $span) {
        $meta = $span["attributes"] ?? [];
        unset(
            $meta["process_id"], $meta["runtime-id"], $meta["_dd.p.dm"], $meta["_dd.tags.process"], $meta["_dd.sdk.otlp_export"],
            $meta["_dd.agent_psr"], $meta["php.compilation.total_time_ms"],
            $meta["php.memory.peak_usage_bytes"], $meta["php.memory.peak_real_usage_bytes"]
        );
        echo "parent: ", $span["parent_id"] ?? 0, ", trace: $traceId, origin: ", $chunk["origin"] ?? "", ", meta: " . json_encode($meta) . "\n";
    }
    return $traceId;
}

DDTrace\start_span();
DDTrace\start_span();

var_dump(DDTrace\set_distributed_tracing_context("123", "321", "foo", ["a" => "b"]));
var_dump(DDTrace\current_context());
var_dump(DDTrace\current_context()["span_id"] != "123" && DDtrace\current_context()["span_id"] != "321");

DDTrace\close_span();
DDTrace\close_span();

dump_spans();

DDTrace\start_span();
DDTrace\start_span();

var_dump(DDTrace\set_distributed_tracing_context("0", "0", "", []));
var_dump(DDTrace\current_context());
$trace_id = DDTrace\trace_id();
var_dump($trace_id != 123);
var_dump(largeBaseConvert($trace_id, 10, 16) == DDTrace\root_span()->traceId);
$id = DDTrace\root_span()->id;
DDTrace\close_span();
DDTrace\close_span();

$traceId = dump_spans();
echo "all spans trace_id updated: "; var_dump($traceId == $id);

?>
--EXPECTF--
bool(true)

Deprecated: Function DDTrace\current_context() is deprecated in %s on line %d
array(7) {
  ["trace_id"]=>
  string(3) "123"
  ["span_id"]=>
  string(%d) "%d"
  ["version"]=>
  NULL
  ["env"]=>
  NULL
  ["distributed_tracing_origin"]=>
  string(3) "foo"
  ["distributed_tracing_parent_id"]=>
  string(3) "321"
  ["distributed_tracing_propagated_tags"]=>
  array(1) {
    ["a"]=>
    string(1) "b"
  }
}

Deprecated: Function DDTrace\current_context() is deprecated in %s on line %d

Deprecated: Function DDTrace\current_context() is deprecated in %s on line %d
bool(true)
parent: 321, trace: 123, origin: foo, meta: {"a":"b"}
parent: %d, trace: 123, origin: foo, meta: []
bool(true)

Deprecated: Function DDTrace\current_context() is deprecated in %s on line %d
array(5) {
  ["trace_id"]=>
  string(%d) "%d"
  ["span_id"]=>
  string(%d) "%d"
  ["version"]=>
  NULL
  ["env"]=>
  NULL
  ["distributed_tracing_propagated_tags"]=>
  array(0) {
  }
}

Deprecated: Function DDTrace\trace_id() is deprecated in %s on line %d
bool(true)
bool(true)
parent: 0, trace: %d, origin: , meta: []
parent: %d, trace: %d, origin: , meta: []
all spans trace_id updated: bool(true)
