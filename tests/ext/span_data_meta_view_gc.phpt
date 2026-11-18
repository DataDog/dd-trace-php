--TEST--
Cached tag-view snapshots do not keep closed spans and their object graphs alive
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php
class TagGraph {
    public static $destroyed = 0;
    public $span;
    public function __destruct() { ++self::$destroyed; }
}

function makeCycle($indirect, $child, $properties, $keepSnapshot = false) {
    $root = DDTrace\start_span();
    $span = $child ? DDTrace\start_span() : $root;
    $graph = new TagGraph;
    if ($indirect) {
        $graph->span = $span;
    }
    $span->attributes = ['graph' => $graph];
    if (!$indirect) {
        $span->attributes['self'] = $span;
    }
    $meta = $span->meta;
    $metrics = $span->metrics;
    $snapshot = (array) $meta;
    // Exercise both inline declared properties and a materialized properties table.
    if ($properties) {
        $props = get_object_vars($span);
        unset($props);
    }
    gc_collect_cycles();
    echo 'live graph: ', $snapshot['graph'] === $graph ? 'yes' : 'no', "\n";

    // Isolate the back-reference retained by the pre-7.4 view's snapshot cache.
    $span->attributes = [];
    unset($graph, $meta, $metrics);
    if (!$keepSnapshot) {
        unset($snapshot);
    }
    DDTrace\close_span();
    if ($child) {
        DDTrace\close_span();
    }
    dd_trace_serialize_closed_spans();
    if ($keepSnapshot) {
        return $snapshot;
    }
}

foreach ([false, true] as $indirect) {
    foreach ([false, true] as $child) {
        foreach ([false, true] as $properties) {
            $before = TagGraph::$destroyed;
            makeCycle($indirect, $child, $properties);
            gc_collect_cycles();
            echo 'collected graph: ', TagGraph::$destroyed - $before, "\n";
        }
    }
}

// A snapshot retained by user code must still keep its contents alive through GC.
$before = TagGraph::$destroyed;
$snapshot = makeCycle(true, true, false, true);
gc_collect_cycles();
echo 'retained snapshot: ', TagGraph::$destroyed - $before, "\n";
var_dump($snapshot['graph']->span instanceof DDTrace\SpanData);
unset($snapshot);
gc_collect_cycles();
echo 'released snapshot: ', TagGraph::$destroyed - $before, "\n";
?>
--EXPECT--
live graph: yes
collected graph: 1
live graph: yes
collected graph: 1
live graph: yes
collected graph: 1
live graph: yes
collected graph: 1
live graph: yes
collected graph: 1
live graph: yes
collected graph: 1
live graph: yes
collected graph: 1
live graph: yes
collected graph: 1
live graph: yes
retained snapshot: 0
bool(true)
released snapshot: 1
