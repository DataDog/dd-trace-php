--TEST--
Immutable (opcache SHM) arrays as span meta/metrics and link/event attributes serialize without being written to
--SKIPIF--
<?php if (!extension_loaded('Zend OPcache')) die('skip: opcache is required'); ?>
--ENV--
DD_TRACE_DEBUG_PRNG_SEED=42
--INI--
opcache.enable=1
opcache.enable_cli=1
opcache.protect_memory=1
opcache.file_update_protection=0
--FILE--
<?php
use DDTrace\SpanEvent;
use DDTrace\SpanLink;

// Literal arrays live read-only in opcache SHM (opcache.protect_memory=1).
$span = \DDTrace\start_span();
$span->name = 'foo';
$span->meta['flat'] = ['a', 'b'];
$span->meta['nested'] = ['k' => ['x' => [1, 2]], 'v' => 'w'];
$span->metrics['m'] = [1, 2.5];

$link = new SpanLink();
$link->traceId = "42";
$link->spanId = "6";
$link->attributes = ['list' => [1, 2], 'map' => ['a' => ['b' => true]]];
$span->links[] = $link;
$span->events[] = new SpanEvent('evt', ['list' => ['x', 'y'], 'map' => ['n' => [3]]], 1);
\DDTrace\close_span();

$spans = dd_trace_serialize_closed_spans();
echo json_encode([
    $spans[0]['attributes']['flat'],
    $spans[0]['attributes']['nested'],
    $spans[0]['attributes']['m'],
    $spans[0]['span_links'][0]['attributes'],
    $spans[0]['span_events'][0]['attributes'],
]), "\n";
var_dump(opcache_get_status(false)['opcache_enabled']);

?>
--EXPECT--
[["a","b"],{"k":{"x":["1","2"]},"v":"w"},[1,2.5],{"list":[1,2],"map":{"a":{"b":true}}},{"list":["x","y"],"map":{"n":[3]}}]
bool(true)
