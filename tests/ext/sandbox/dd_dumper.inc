<?php

function dd_dump_spans($skipMeta = false)
{
    $spans = dd_trace_serialize_closed_spans();
    $roots = $children = [];
    foreach ($spans as $span) {
        if (isset($span["parent_id"])) {
            $children[$span["parent_id"]][] = $span;
        } else {
            $roots[] = $span;
        }
    }

    $printSpan = function($span, $indent = 2) use ($skipMeta) {
        $values = [];
        if (($span['service'] ?? "") != "") {
            $values[] = $span['service'];
        }
        if (($span['resource'] ?? "") != "") {
            $values[] = $span['resource'];
        }
        if (($span['type'] ?? "") != "") {
            $values[] = $span['type'];
        }

        echo str_repeat(' ', $indent);
        if (isset($span['name'])) {
             echo $span['name'];
        }
        if (!empty($values)) {
            echo ' (' . implode(', ', $values) . ')';
        }
        if (isset($span['attributes']['error.message'])) {
            echo ' (error: ' . $span['attributes']['error.message'] . ')';
        }
        if (isset($span['attributes'])) {
            if ($skipMeta) {
                unset($span['attributes']['_dd.p.dm']);
            }
            echo PHP_EOL;
            unset($span["attributes"]["runtime-id"]);
            unset($span["attributes"]["_dd.tags.process"]);
            // The v1 introspection shape merges the old meta (strings) and metrics
            // (numbers) into a single attributes map. This dumper historically showed
            // only string meta, so keep string-valued attributes to preserve its view.
            foreach ($span['attributes'] as $k => $v) {
                if (!is_string($v)) {
                    continue;
                }
                echo str_repeat(' ', $indent) . '  ' . $k . ' => ' . $v . PHP_EOL;
            }
        } else {
            echo PHP_EOL;
        }
    };

    $sorter = function($a, $b) {
        return $a["start"] <=> $b["start"];
    };

    echo 'spans(\\DDTrace\\SpanData) (' . count($roots) . ') {' . PHP_EOL;
    $recurse = function ($span, $indent = 2) use (&$recurse, &$children, $printSpan, $sorter) {
        $printSpan($span, $indent);
        $sortedChildren = $children[$span['span_id']] ?? [];
        uasort($sortedChildren, $sorter);
        foreach ($sortedChildren as $child) {
            $recurse($child, $indent + 2);
        }
        unset($children[$span["span_id"]]);

    };
    usort($roots, $sorter);
    foreach ($roots as $root) {
        $recurse($root);
    }
    foreach ($children as $spans) {
        foreach ($spans as $span) {
            echo '  #[no_parent]', PHP_EOL;
            $printSpan($span);
        }
    }
    echo '}' . PHP_EOL;
}

function dd_clean_spans() {
    $spans = dd_trace_serialize_closed_spans();
    foreach ($spans as &$span) {
        if (isset($span['attributes'])) {
            unset($span['attributes']['_dd.tags.process']);
            if (empty($span['attributes'])) {
                unset($span['attributes']);
            }
        }
    }
    return $spans;
}
