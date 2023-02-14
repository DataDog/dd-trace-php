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
        if (isset($span['service'])) {
            $values[] = $span['service'];
        }
        if (isset($span['resource'])) {
            $values[] = $span['resource'];
        }
        if (isset($span['type'])) {
            $values[] = $span['type'];
        }

        echo str_repeat(' ', $indent);
        if (isset($span['name'])) {
             echo $span['name'];
        }
        if (!empty($values)) {
            echo ' (' . implode(', ', $values) . ')';
        }
        if (isset($span['meta']['error.message'])) {
            echo ' (error: ' . $span['meta']['error.message'] . ')';
        }
        if (isset($span['meta'])) {
            if ($skipMeta) {
                unset($span['meta']['_dd.p.dm']);
            }
            echo PHP_EOL;
            foreach ($span['meta'] as $k => $v) {
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
